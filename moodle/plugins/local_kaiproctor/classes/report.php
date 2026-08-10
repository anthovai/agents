<?php
// Assembles the evidence trail for one learner in one context.
//
// This is what an auditor or a disputing learner actually looks at. Two things
// it deliberately does not do: resolve the threshold from configuration (each
// check carries the one that was in force), and describe the rules from
// today's settings (each sitting carries its own snapshot). Both would let a
// later settings change rewrite the meaning of an old decision.

namespace local_kaiproctor;

defined('MOODLE_INTERNAL') || die();

class report {

    /** Human-readable summary of a policy snapshot. */
    protected static function describe_policy(?array $policy): array {
        if (!$policy) {
            return [];
        }

        $rows = [
            ['label' => get_string('settings:presenceminutes', 'local_kaiproctor'),
             'value' => self::interval($policy['presenceminutes'] ?? null)],
            ['label' => get_string('settings:verifyminutes', 'local_kaiproctor'),
             'value' => self::interval($policy['verifyminutes'] ?? null)],
            ['label' => get_string('settings:clickconfirmminutes', 'local_kaiproctor'),
             'value' => self::interval($policy['clickconfirmminutes'] ?? null)],
            ['label' => get_string('settings:mouseidleminutes', 'local_kaiproctor'),
             'value' => self::interval($policy['mouseidleminutes'] ?? null)],
            ['label' => get_string('settings:randomclipsperhour', 'local_kaiproctor'),
             'value' => (string) ($policy['randomclipsperhour'] ?? '—')],
            ['label' => get_string('settings:blurallowance', 'local_kaiproctor'),
             'value' => (string) ($policy['blurallowance'] ?? '—')],
            ['label' => get_string('settings:strictlockdown', 'local_kaiproctor'),
             'value' => self::yesno($policy['strictlockdown'] ?? null)],
            // The settings label, not the table heading used for checks: in a
            // list of policy values "threshold" alone is ambiguous.
            ['label' => get_string('settings:matchthreshold', 'local_kaiproctor'),
             'value' => isset($policy['matchthreshold'])
                 ? format_float($policy['matchthreshold'], 4) : '—'],
            ['label' => get_string('settings:reviewmin', 'local_kaiproctor'),
             'value' => isset($policy['reviewmin'])
                 ? format_float($policy['reviewmin'], 4) : '—'],
        ];

        return $rows;
    }

    protected static function interval(?float $minutes): string {
        if ($minutes === null) {
            return '—';
        }
        if ((float) $minutes <= 0.0) {
            return get_string('report:checkoff', 'local_kaiproctor');
        }
        return get_string('report:everyminutes', 'local_kaiproctor', format_float($minutes, 1));
    }

    protected static function yesno(?bool $value): string {
        if ($value === null) {
            return '—';
        }
        return $value ? get_string('yes') : get_string('no');
    }

    /**
     * @param int $userid
     * @param \context $context
     * @return array template context for local_kaiproctor/report
     */
    public static function build(int $userid, \context $context): array {
        global $DB;

        $user = \core_user::get_user($userid, '*', MUST_EXIST);
        $enrolment = enrolment::get_active($userid);

        // Everything is grouped by sitting. Anything recorded before sittings
        // existed, or outside one, is collected at the end rather than hidden.
        $sessions = session::for_user($userid, $context);

        $checksby = [];
        foreach (checks::for_user($userid, $context) as $check) {
            $checksby[(int) $check->sessionid][] = self::check_row($check);
        }

        $evidenceby = [];
        foreach ($DB->get_records('local_kaiproctor_evidence',
                ['userid' => $userid, 'contextid' => $context->id], 'timecreated ASC') as $item) {
            $evidenceby[(int) $item->sessionid][] = [
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

        $eventsby = [];
        $records = $DB->get_records_select('logstore_standard_log',
            'userid = :userid AND contextid = :contextid AND ' . $DB->sql_like('eventname', ':pattern'),
            ['userid' => $userid, 'contextid' => $context->id, 'pattern' => '%kaiproctor%'],
            'timecreated ASC'
        );
        foreach ($records as $record) {
            $other = json_decode($record->other, true) ?: [];
            $eventsby[(int) ($other['sessionid'] ?? 0)][] = [
                'time' => userdate($record->timecreated),
                'type' => $other['type'] ?? 'unknown',
                'videotime' => $other['videotime'] ?? null,
                'detail' => empty($other['detail']) ? '' : json_encode($other['detail'], JSON_UNESCAPED_UNICODE),
            ];
        }

        $rendered = [];
        foreach ($sessions as $record) {
            $id = (int) $record->id;
            $rendered[] = [
                'id' => $id,
                'status' => $record->status,
                'statuslabel' => get_string('session:' . $record->status, 'local_kaiproctor'),
                'ended' => $record->status !== session::STATUS_COMPLETED
                    && $record->status !== session::STATUS_ACTIVE,
                'active' => $record->status === session::STATUS_ACTIVE,
                'reason' => $record->reason,
                'timestart' => userdate($record->timestart),
                'timeend' => $record->timeend ? userdate($record->timeend) : '',
                'duration' => $record->timeend
                    ? format_time($record->timeend - $record->timestart) : '',
                'policy' => self::describe_policy(json_decode($record->policy, true)),
                'checks' => $checksby[$id] ?? [],
                'haschecks' => !empty($checksby[$id]),
                'evidence' => $evidenceby[$id] ?? [],
                'hasevidence' => !empty($evidenceby[$id]),
                'events' => $eventsby[$id] ?? [],
                'hasevents' => !empty($eventsby[$id]),
            ];
        }

        // Records with no sitting — from before sittings existed, or written
        // by something that never opened one. They are rendered through the
        // same tables as a real sitting: showing them in a shorter form would
        // quietly drop the similarity and threshold an auditor needs, which is
        // exactly the data this page exists to show.
        if (!empty($checksby[0]) || !empty($evidenceby[0]) || !empty($eventsby[0])) {
            $rendered[] = [
                'id' => 0,
                'isorphan' => true,
                'status' => '',
                'statuslabel' => get_string('report:orphans', 'local_kaiproctor'),
                'ended' => false,
                'active' => false,
                'reason' => null,
                'timestart' => '',
                'timeend' => '',
                'duration' => '',
                'policy' => [],
                'checks' => $checksby[0] ?? [],
                'haschecks' => !empty($checksby[0]),
                'evidence' => $evidenceby[0] ?? [],
                'hasevidence' => !empty($evidenceby[0]),
                'events' => $eventsby[0] ?? [],
                'hasevents' => !empty($eventsby[0]),
            ];
        }

        return [
            'fullname' => fullname($user),
            'enrolled' => (bool) $enrolment,
            'enrolledon' => $enrolment ? userdate($enrolment->timecreated) : '',
            'modelpack' => $enrolment ? $enrolment->modelpack : '',
            'sessions' => $rendered,
            'hassessions' => (bool) $rendered,
        ];
    }

    protected static function check_row(\stdClass $check): array {
        return [
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
}
