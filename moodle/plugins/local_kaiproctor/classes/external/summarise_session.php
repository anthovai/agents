<?php
// Ask the model to draft a summary of one sitting, for the reviewer looking
// at it. Advisory only — nothing here writes a decision anywhere.

namespace local_kaiproctor\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;
use local_kaiproctor\ai_reviewer;

class summarise_session extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'sessionid' => new external_value(PARAM_INT, 'The sitting to summarise'),
        ]);
    }

    public static function execute(int $sessionid): array {
        global $DB, $USER;

        // Same reason as the assistant: the model is allowed to be slow,
        // so the request has to be allowed to wait for it.
        \core_php_time_limit::raise(360);

        $params = self::validate_parameters(self::execute_parameters(),
            ['sessionid' => $sessionid]);

        $session = $DB->get_record('local_kaiproctor_session',
            ['id' => $params['sessionid']], '*', MUST_EXIST);

        $context = \context::instance_by_id($session->contextid);
        self::validate_context($context);

        // Summaries are for whoever is entitled to read the evidence. A
        // learner reading their own is fine; anybody else needs the capability.
        if ((int) $session->userid !== (int) $USER->id) {
            require_capability('local/kaiproctor:viewevidence', $context);
        }

        $result = ai_reviewer::summarise($params['sessionid']);
        if (empty($result['ok'])) {
            return [
                'ok' => false,
                'errorcode' => $result['error']['code'] ?? 'unknown',
                'message' => $result['error']['message'] ?? '',
            ];
        }

        return ['ok' => true, 'summary' => $result['summary'], 'model' => $result['model']];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Whether a summary was produced'),
            'errorcode' => new external_value(PARAM_ALPHANUMEXT, 'Error code', VALUE_OPTIONAL),
            'message' => new external_value(PARAM_RAW, 'What went wrong', VALUE_OPTIONAL),
            'summary' => new external_value(PARAM_RAW, 'The draft', VALUE_OPTIONAL),
            'model' => new external_value(PARAM_RAW, 'Which model wrote it', VALUE_OPTIONAL),
        ]);
    }
}
