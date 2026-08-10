<?php
// Audit trail for attention signals raised in the browser.

namespace local_kaiproctor\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_kaiproctor\event\attention_event;

class log_event extends external_api {

    /** Signals the browser modules are allowed to raise. An unknown type is
     * rejected so a tampered client cannot flood the log with junk types. */
    const ALLOWED = [
        'monitor_started', 'monitor_stopped',
        'tab_hidden', 'window_blur', 'fullscreen_exit', 'fullscreen_denied',
        'browser_shortcut', 'devtools_suspected', 'app_switch', 'tab_switch',
        'print_screen', 'context_menu', 'copy_attempt', 'paste_attempt',
        'click_confirm_ok', 'click_confirm_timeout', 'resumed',
        'mouse_idle', 'face_absent', 'multiple_faces',
        'presence_ok', 'presence_error', 'identity_check', 'face_mismatch',
        'face_review', 'verify_error',
        'clip_started', 'clip_uploaded', 'clip_error', 'clip_skipped',
        'session_terminated',
    ];

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Context the event belongs to'),
            'type' => new external_value(PARAM_ALPHANUMEXT, 'Signal type'),
            'detail' => new external_value(PARAM_RAW, 'JSON detail', VALUE_DEFAULT, '{}'),
            'videotime' => new external_value(PARAM_INT, 'Position in the lesson video, seconds', VALUE_DEFAULT, -1),
            'sessionid' => new external_value(PARAM_INT, 'The sitting this belongs to, 0 if none', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(int $contextid, string $type, string $detail = '{}',
                                   int $videotime = -1, int $sessionid = 0): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'contextid' => $contextid,
            'type' => $type,
            'detail' => $detail,
            'videotime' => $videotime,
            'sessionid' => $sessionid,
        ]);

        $context = \context::instance_by_id($params['contextid']);
        self::validate_context($context);

        $sessionid = \local_kaiproctor\session::validate(
            $params['sessionid'] ?: null, $USER->id, $context);
        if ($sessionid) {
            \local_kaiproctor\session::touch($sessionid);
        }

        if (!in_array($params['type'], self::ALLOWED, true)) {
            return ['ok' => false, 'errorcode' => 'unknown_type'];
        }

        $decoded = json_decode($params['detail'], true);

        // Resuming after any signal is a normal part of the flow and shares the
        // signal's name with a prefix; it is logged under the base type with a
        // marker rather than being added to ALLOWED twice over.
        attention_event::build($context, $USER->id, [
            'type' => $params['type'],
            'detail' => is_array($decoded) ? $decoded : [],
            'videotime' => $params['videotime'] >= 0 ? $params['videotime'] : null,
            'sessionid' => $sessionid,
        ])->trigger();

        return ['ok' => true];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Whether the event was recorded'),
            'errorcode' => new external_value(PARAM_ALPHANUMEXT, 'Error code', VALUE_OPTIONAL),
        ]);
    }
}
