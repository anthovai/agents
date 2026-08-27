<?php
// Open a proctoring session and hand back the rules that will be enforced.
//
// The policy is built on the server and returned to the browser, rather than
// the browser being trusted to say what policy it was using. That direction
// matters: the recorded snapshot is the one an auditor will read, so it has
// to come from the same place the enforcement decisions come from.

namespace local_kaiproctor\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_kaiproctor\session;

class start_session extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Where the sitting takes place'),
            'attemptid' => new external_value(PARAM_INT, 'Quiz attempt id, 0 if none', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(int $contextid, int $attemptid = 0): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'contextid' => $contextid,
            'attemptid' => $attemptid,
        ]);

        $context = \context::instance_by_id($params['contextid']);
        self::validate_context($context);

        $attemptid = $params['attemptid'] ?: null;
        $record = session::start($USER->id, $context, $attemptid);
        $policy = json_decode($record->policy, true) ?: session::current_policy($attemptid);

        return [
            'ok' => true,
            'sessionid' => (int) $record->id,
            'enrolled' => \local_kaiproctor\enrolment::has_enrolled($USER->id),
            // Seconds, matching the policy this reads from.
            //
            // These were 'presenceminutes' and friends, read straight out of
            // $policy by name. When the policy moved to seconds those keys
            // stopped existing, (float) null came out as 0.0, and 0 is how
            // this system spells "check disabled" — so every check silently
            // switched itself off and the settings page appeared to do
            // nothing. Nothing errored; the browser was handed a policy that
            // told it to watch for nothing.
            'presenceseconds' => (float) $policy['presenceseconds'],
            'verifyseconds' => (float) $policy['verifyseconds'],
            'clickconfirmseconds' => (float) $policy['clickconfirmseconds'],
            'clickconfirmgracesec' => (float) $policy['clickconfirmgracesec'],
            'mouseidleseconds' => (float) $policy['mouseidleseconds'],
            // ?? because a session already open when this shipped stored a
            // policy snapshot without these keys; such a sitting gets no
            // warning rather than a PHP notice over a cosmetic feature.
            'mouseidlewarnsec' => (float) ($policy['mouseidlewarnsec'] ?? 0),
            'presencewarnsec' => (float) ($policy['presencewarnsec'] ?? 0),
            'randomclipsperhour' => (float) $policy['randomclipsperhour'],
            'clipseconds' => (float) $policy['clipseconds'],
            'blurallowance' => (int) $policy['blurallowance'],
            'strictlockdown' => (bool) $policy['strictlockdown'],
            'desktopnotification' => (bool) $policy['desktopnotification'],
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Whether a sitting was opened'),
            'sessionid' => new external_value(PARAM_INT, 'The sitting to file everything against'),
            'enrolled' => new external_value(PARAM_BOOL, 'Whether identity checks can run for this learner'),
            'presenceseconds' => new external_value(PARAM_FLOAT, 'Presence check interval, seconds'),
            'verifyseconds' => new external_value(PARAM_FLOAT, 'Identity check interval, seconds'),
            'clickconfirmseconds' => new external_value(PARAM_FLOAT, 'Confirmation interval, seconds'),
            'clickconfirmgracesec' => new external_value(PARAM_FLOAT, 'Confirmation grace period, seconds'),
            'mouseidleseconds' => new external_value(PARAM_FLOAT, 'Idle tolerance, seconds'),
            'mouseidlewarnsec' => new external_value(PARAM_FLOAT, 'Idle countdown lead time'),
            'presencewarnsec' => new external_value(PARAM_FLOAT, 'Presence countdown lead time'),
            'randomclipsperhour' => new external_value(PARAM_FLOAT, 'Random clips per hour'),
            'clipseconds' => new external_value(PARAM_FLOAT, 'Clip length'),
            'blurallowance' => new external_value(PARAM_INT, 'Focus losses tolerated'),
            'strictlockdown' => new external_value(PARAM_BOOL, 'End the sitting on a breach'),
            'desktopnotification' => new external_value(PARAM_BOOL, 'Raise OS notifications'),
        ]);
    }
}
