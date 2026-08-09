<?php
// Assembles the evidence trail for one learner in one context.
//
// This is what an auditor or a disputing learner actually looks at, so it
// deliberately reports the threshold that was in force at the time of each
// check rather than the one configured today.

namespace local_kaiproctor;

defined('MOODLE_INTERNAL') || die();

class report {

    /**
     * @param int $userid
     * @param \context $context
     * @return array template context for local_kaiproctor/report
     */
    public static function build(int $userid, \context $context): array {
        global $DB;

        $user = \core_user::get_user($userid, '*', MUST_EXIST);
        $enrolment = enrolment::get_active($userid);

        $checks = [];
        foreach (checks::for_user($userid, $context) as $check) {
            $checks[] = [
                'time' => userdate($check->timecreated),
                'kind' => $check->kind,
                'decision' => $check->decision,
                'failed' => in_array($check->decision, ['fail', 'fail_liveness', 'absent'], true),
                'similarity' => $check->similarity === null ? '—' : format_float($check->similarity, 4),
                'liveness' => $check->livenessscore === null ? '—' : format_float($check->livenessscore, 4),
                'threshold' => $check->threshold === null ? '—' : format_float($check->threshold, 4),
                'modelpack' => $check->modelpack ?: '—',
            ];
        }

        $evidence = [];
        foreach ($DB->get_records('local_kaiproctor_evidence',
                ['userid' => $userid, 'contextid' => $context->id], 'timecreated ASC') as $item) {
            $evidence[] = [
                'time' => userdate($item->timecreated),
                'kind' => $item->kind,
                'isclip' => $item->kind === 'clip',
                'reason' => $item->reason,
                'url' => \moodle_url::make_pluginfile_url(
                    $context->id, evidence::COMPONENT, evidence::FILEAREA,
                    $item->itemid, '/', $item->filename
                )->out(false),
            ];
        }

        // Attention signals live in the standard log store, so the timeline is
        // read from there rather than duplicated into a table of our own.
        $events = [];
        $records = $DB->get_records_select('logstore_standard_log',
            'userid = :userid AND contextid = :contextid AND ' . $DB->sql_like('eventname', ':pattern'),
            ['userid' => $userid, 'contextid' => $context->id, 'pattern' => '%kaiproctor%'],
            'timecreated ASC'
        );
        foreach ($records as $record) {
            $other = json_decode($record->other, true) ?: [];
            $events[] = [
                'time' => userdate($record->timecreated),
                'type' => $other['type'] ?? 'unknown',
                'videotime' => $other['videotime'] ?? null,
                'detail' => empty($other['detail']) ? '' : json_encode($other['detail'], JSON_UNESCAPED_UNICODE),
            ];
        }

        return [
            'fullname' => fullname($user),
            'enrolled' => (bool) $enrolment,
            'enrolledon' => $enrolment ? userdate($enrolment->timecreated) : '',
            'modelpack' => $enrolment ? $enrolment->modelpack : '',
            'checks' => $checks,
            'haschecks' => (bool) $checks,
            'evidence' => $evidence,
            'hasevidence' => (bool) $evidence,
            'events' => $events,
            'hasevents' => (bool) $events,
        ];
    }
}
