<?php
// Evidence storage: snapshots and short clips captured during a session.
//
// Bytes go into the Moodle file API rather than a directory of our own, so
// that the Privacy API's export and erasure paths reach them for free — that
// is the whole reason this project sits on Moodle.

namespace local_kaiproctor;

defined('MOODLE_INTERNAL') || die();

class evidence {

    const COMPONENT = 'local_kaiproctor';
    const FILEAREA = 'evidence';

    /** Guard against a hostile or broken client filling the disk. */
    const MAX_SNAPSHOT_BYTES = 8 * 1024 * 1024;
    const MAX_CLIP_BYTES = 32 * 1024 * 1024;

    /**
     * @param string $kind 'snapshot' or 'clip'
     * @param string $reason why it was captured — kept for the audit view
     * @return int the evidence row id
     * @throws \moodle_exception when the payload is too large or the kind is unknown
     */
    public static function store(
        int $userid,
        \context $context,
        string $kind,
        string $reason,
        string $bytes,
        ?int $attemptid = null,
        ?int $sessionid = null
    ): int {
        global $DB;

        $limits = ['snapshot' => self::MAX_SNAPSHOT_BYTES, 'clip' => self::MAX_CLIP_BYTES];
        if (!isset($limits[$kind])) {
            throw new \moodle_exception('invalidevidencekind', 'local_kaiproctor', '', $kind);
        }
        if (strlen($bytes) > $limits[$kind]) {
            throw new \moodle_exception('evidencetoolarge', 'local_kaiproctor');
        }

        $extension = $kind === 'clip' ? 'webm' : 'jpg';

        // The row is inserted first so its id can be the itemid. Deriving the
        // itemid from MAX(itemid)+1 looked equivalent and was not: once rows
        // are deleted the counter restarts and collides with any file left
        // behind, and the file API then refuses the write.
        $transaction = $DB->start_delegated_transaction();

        $id = $DB->insert_record('local_kaiproctor_evidence', (object) [
            'userid' => $userid,
            'contextid' => $context->id,
            'attemptid' => $attemptid,
            'sessionid' => $sessionid,
            'kind' => $kind,
            'reason' => $reason,
            'itemid' => 0,
            'filename' => '',
            'timecreated' => time(),
        ]);

        $filename = sprintf('%s-%d.%s', $kind, $id, $extension);
        $DB->update_record('local_kaiproctor_evidence', (object) [
            'id' => $id,
            'itemid' => $id,
            'filename' => $filename,
        ]);

        get_file_storage()->create_file_from_string([
            'contextid' => $context->id,
            'component' => self::COMPONENT,
            'filearea' => self::FILEAREA,
            'itemid' => $id,
            'filepath' => '/',
            'filename' => $filename,
            'userid' => $userid,
        ], $bytes);

        // A row without its file is a claim that evidence exists when it does
        // not, so the insert is rolled back if the write throws.
        $transaction->allow_commit();
        return $id;
    }

    /**
     * Delete a learner's evidence, rows and files together.
     *
     * @param int $userid
     * @param int|null $contextid limit to one context, or null for all of them
     * @return int how many records were removed
     */
    public static function delete_for_user(int $userid, ?int $contextid = null): int {
        global $DB;

        $conditions = ['userid' => $userid];
        if ($contextid !== null) {
            $conditions['contextid'] = $contextid;
        }

        $records = $DB->get_records('local_kaiproctor_evidence', $conditions);
        if (!$records) {
            return 0;
        }

        $fs = get_file_storage();
        foreach ($records as $record) {
            $file = $fs->get_file($record->contextid, self::COMPONENT, self::FILEAREA,
                $record->itemid, '/', $record->filename);
            if ($file) {
                $file->delete();
            }
            $DB->delete_records('local_kaiproctor_evidence', ['id' => $record->id]);
        }

        return count($records);
    }

    /**
     * Delete evidence older than the configured retention, rows and files
     * together — a row without its file, or a file without its row, is worse
     * than keeping neither.
     *
     * @return int how many records were removed
     */
    public static function purge_expired(): int {
        global $DB;

        $days = (int) get_config('local_kaiproctor', 'retentiondays');
        if ($days <= 0) {
            return 0;
        }

        $cutoff = time() - ($days * DAYSECS);
        $expired = $DB->get_records_select('local_kaiproctor_evidence',
            'timecreated < :cutoff', ['cutoff' => $cutoff]);
        if (!$expired) {
            return 0;
        }

        $fs = get_file_storage();
        foreach ($expired as $record) {
            $file = $fs->get_file($record->contextid, self::COMPONENT, self::FILEAREA,
                $record->itemid, '/', $record->filename);
            if ($file) {
                $file->delete();
            }
            $DB->delete_records('local_kaiproctor_evidence', ['id' => $record->id]);
        }

        return count($expired);
    }
}
