<?php
// Upload a snapshot or a random evidence clip.

namespace local_kaiproctor\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_kaiproctor\evidence;

class store_evidence extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'Context the evidence belongs to'),
            'kind' => new external_value(PARAM_ALPHA, 'snapshot or clip'),
            'reason' => new external_value(PARAM_ALPHANUMEXT, 'Why it was captured'),
            'data' => new external_value(PARAM_RAW, 'Base64-encoded media'),
            'attemptid' => new external_value(PARAM_INT, 'Quiz attempt id, 0 if none', VALUE_DEFAULT, 0),
        ]);
    }

    public static function execute(int $contextid, string $kind, string $reason,
                                   string $data, int $attemptid = 0): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'contextid' => $contextid,
            'kind' => $kind,
            'reason' => $reason,
            'data' => $data,
            'attemptid' => $attemptid,
        ]);

        $context = \context::instance_by_id($params['contextid']);
        self::validate_context($context);

        if (!in_array($params['kind'], ['snapshot', 'clip'], true)) {
            return ['ok' => false, 'errorcode' => 'invalid_kind'];
        }

        $bytes = base64_decode($params['data'], true);
        if ($bytes === false || $bytes === '') {
            return ['ok' => false, 'errorcode' => 'invalid_data'];
        }

        try {
            $id = evidence::store($USER->id, $context, $params['kind'],
                $params['reason'], $bytes, $params['attemptid'] ?: null);
        } catch (\moodle_exception $e) {
            return ['ok' => false, 'errorcode' => $e->errorcode];
        }

        return ['ok' => true, 'evidenceid' => $id];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Whether the evidence was stored'),
            'errorcode' => new external_value(PARAM_ALPHANUMEXT, 'Error code', VALUE_OPTIONAL),
            'evidenceid' => new external_value(PARAM_INT, 'Stored evidence id', VALUE_OPTIONAL),
        ]);
    }
}
