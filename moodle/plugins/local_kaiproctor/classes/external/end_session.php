<?php
// Close a proctoring session.
//
// The client says how it ended, but only within a fixed vocabulary, and only
// once: session::end() ignores a second call, so a "completed" arriving after
// a "terminated" cannot quietly launder a cut-short sitting into a clean one.

namespace local_kaiproctor\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_kaiproctor\session;

class end_session extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'sessionid' => new external_value(PARAM_INT, 'The sitting to close'),
            'status' => new external_value(PARAM_ALPHA, 'completed or terminated'),
            'reason' => new external_value(PARAM_ALPHANUMEXT, 'What ended it', VALUE_DEFAULT, ''),
        ]);
    }

    public static function execute(int $sessionid, string $status, string $reason = ''): array {
        global $USER, $DB;

        $params = self::validate_parameters(self::execute_parameters(), [
            'sessionid' => $sessionid,
            'status' => $status,
            'reason' => $reason,
        ]);

        $record = $DB->get_record('local_kaiproctor_session',
            ['id' => $params['sessionid'], 'userid' => $USER->id]);
        if (!$record) {
            return ['ok' => false, 'errorcode' => 'unknown_session'];
        }

        $context = \context::instance_by_id($record->contextid);
        self::validate_context($context);

        // 'abandoned' belongs to the cleanup task, not to the browser: a client
        // must not be able to describe a sitting it cut short as one nobody
        // was present for.
        if (!in_array($params['status'], [session::STATUS_COMPLETED, session::STATUS_TERMINATED], true)) {
            return ['ok' => false, 'errorcode' => 'invalid_status'];
        }

        session::end($params['sessionid'], $params['status'], $params['reason'] ?: null);

        return ['ok' => true];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Whether the sitting was closed'),
            'errorcode' => new external_value(PARAM_ALPHANUMEXT, 'Error code', VALUE_OPTIONAL),
        ]);
    }
}
