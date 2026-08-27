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
            // Either shape. Sittings recorded before intervals moved to
            // seconds carry *minutes keys, and rewriting them to match today's
            // settings is exactly what a policy snapshot exists to prevent —
            // so they are read in the unit they were written in.
            ['label' => get_string('settings:presenceseconds', 'local_kaiproctor'),
             'value' => self::seconds_of($policy, 'presence')],
            ['label' => get_string('settings:verifyseconds', 'local_kaiproctor'),
             'value' => self::seconds_of($policy, 'verify')],
            ['label' => get_string('settings:clickconfirmseconds', 'local_kaiproctor'),
             'value' => self::seconds_of($policy, 'clickconfirm')],
            ['label' => get_string('settings:mouseidleseconds', 'local_kaiproctor'),
             'value' => self::seconds_of($policy, 'mouseidle')],
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

    /**
     * One interval from a policy snapshot, in seconds, whichever unit it was
     * written in.
     *
     * Intervals moved from minutes to seconds. A sitting recorded before that
     * holds ``mouseidleminutes``; one recorded after holds
     * ``mouseidleseconds``. Converting the old ones on read is right and
     * rewriting them on disk would not be: the snapshot is the record of what
     * was enforced, and a record that changes when the code changes is not one.
     *
     * @param array $policy
     * @param string $base setting name without its unit suffix
     * @return string
     */
    protected static function seconds_of(array $policy, string $base): string {
        if (isset($policy[$base . 'seconds'])) {
            return self::interval((float) $policy[$base . 'seconds']);
        }
        if (isset($policy[$base . 'minutes'])) {
            return self::interval((float) $policy[$base . 'minutes'] * 60);
        }
        return '—';
    }

    protected static function interval(?float $seconds): string {
        if ($seconds === null) {
            return '—';
        }
        if ((float) $seconds <= 0.0) {
            return get_string('report:checkoff', 'local_kaiproctor');
        }
        return get_string('report:everyseconds', 'local_kaiproctor',
            format_float($seconds, 0));
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
            // An exam sitting also has a paper, and how it was drawn is part of
            // the same story as how it was watched.
            $draw = $record->attemptid ? exam_draw::describe((int) $record->attemptid) : null;
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
                'hasdraw' => (bool) $draw,
                'draw' => $draw ? self::draw_rows($draw) : null,
            ];
        }

        // A paper exists whether or not monitoring ever started: the camera
        // can fail after the attempt was drawn. Draws with no sitting to sit
        // under get their own card rather than vanishing from the report.
        $seen = [];
        foreach ($sessions as $record) {
            if ($record->attemptid) {
                $seen[(int) $record->attemptid] = true;
            }
        }

        foreach (self::draws_in_context($userid, $context) as $attemptid => $draw) {
            if (isset($seen[$attemptid])) {
                continue;
            }
            $rendered[] = [
                'id' => 0,
                'isdrawonly' => true,
                'status' => '',
                'statuslabel' => get_string('draw:papertitle', 'local_kaiproctor'),
                'ended' => false,
                'active' => false,
                'reason' => null,
                'timestart' => '',
                'timeend' => '',
                'duration' => '',
                'policy' => [],
                'checks' => [],
                'haschecks' => false,
                'evidence' => [],
                'hasevidence' => false,
                'events' => [],
                'hasevents' => false,
                'hasdraw' => true,
                'draw' => self::draw_rows($draw),
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

    /** Every recorded draw for this learner in this context, by attempt id. */
    protected static function draws_in_context(int $userid, \context $context): array {
        global $DB;

        if (!($context instanceof \context_module)) {
            return [];
        }

        $cm = get_coursemodule_from_id('quiz', $context->instanceid, 0, false, IGNORE_MISSING);
        if (!$cm) {
            return [];
        }

        $out = [];
        foreach ($DB->get_records('local_kaiproctor_draw',
                ['userid' => $userid, 'quizid' => $cm->instance], 'attemptnumber ASC') as $record) {
            $described = exam_draw::describe((int) $record->attemptid);
            if ($described) {
                $out[(int) $record->attemptid] = $described;
            }
        }
        return $out;
    }

    /** Presentation for one recorded draw. */
    protected static function draw_rows(array $draw): array {
        return [
            'seed' => $draw['seed'],
            'seedverified' => $draw['seedverified'],
            'attemptnumber' => $draw['attemptnumber'],
            'questioncount' => count($draw['questionids']),
            'questionids' => implode(', ', $draw['questionids']),
            'blueprint' => array_map(static fn($slot) => [
                'slot' => $slot['slot'],
                'rule' => ($slot['type'] ?? '') === 'random'
                    ? get_string('draw:randomfrom', 'local_kaiproctor',
                        implode(', ', $slot['tags'] ?: ['—']))
                    : get_string('draw:fixed', 'local_kaiproctor'),
            ], $draw['blueprint']),
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
