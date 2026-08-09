<?php
// Recording identity / presence check results.
//
// The threshold in force is written into every row rather than resolved from
// configuration when the row is read back. An admin who raises the threshold
// next month must not silently rewrite the meaning of last month's decisions.

namespace local_kaiproctor;

defined('MOODLE_INTERNAL') || die();

class checks {

    public static function record(
        int $userid,
        \context $context,
        string $kind,
        string $decision,
        ?float $similarity = null,
        ?float $livenessscore = null,
        ?string $modelpack = null,
        ?int $attemptid = null,
        array $detail = []
    ): int {
        global $DB;

        return $DB->insert_record('local_kaiproctor_check', (object) [
            'userid' => $userid,
            'contextid' => $context->id,
            'attemptid' => $attemptid,
            'kind' => $kind,
            'decision' => $decision,
            'similarity' => $similarity,
            'livenessscore' => $livenessscore,
            'threshold' => (float) get_config('local_kaiproctor', 'matchthreshold'),
            'modelpack' => $modelpack,
            'detail' => $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null,
            'timecreated' => time(),
        ]);
    }

    /** Checks for one learner in one context, oldest first — the audit view. */
    public static function for_user(int $userid, \context $context, ?int $attemptid = null): array {
        global $DB;

        $conditions = ['userid' => $userid, 'contextid' => $context->id];
        if ($attemptid !== null) {
            $conditions['attemptid'] = $attemptid;
        }
        return $DB->get_records('local_kaiproctor_check', $conditions, 'timecreated ASC');
    }
}
