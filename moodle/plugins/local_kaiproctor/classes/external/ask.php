<?php
// A learner asking where something is.
//
// Answers are built only from pages this user can already open, and the
// service is only called when something matched. Advisory throughout: nothing
// here reads a grade, and nothing here writes anything.

namespace local_kaiproctor\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;
use local_kaiproctor\assistant;

class ask extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'question' => new external_value(PARAM_TEXT, 'What the learner typed'),
        ]);
    }

    public static function execute(string $question): array {
        global $USER;

        // A local model can take a minute. Waiting on curl is not charged
        // against max_execution_time on Linux, but depending on that is
        // depending on a platform detail nobody reading this would check.
        \core_php_time_limit::raise(360);

        $params = self::validate_parameters(self::execute_parameters(),
            ['question' => $question]);

        // System context: the assistant searches across the learner's own
        // courses, so there is no single course context to validate against.
        // What bounds it is site_index, which only ever returns pages this
        // user can open.
        self::validate_context(\context_system::instance());

        $result = assistant::answer($params['question'], (int) $USER->id);

        if (empty($result['ok'])) {
            return [
                'ok' => false,
                'errorcode' => $result['error']['code'] ?? 'unknown',
                'message' => $result['error']['message'] ?? '',
                'sources' => [],
            ];
        }

        return [
            'ok' => true,
            'answer' => $result['answer'],
            'model' => $result['model'] ?? '',
            // Returned so the page can show the links as links, rather than
            // leaving the learner to pick them out of a paragraph.
            'sources' => array_map(static fn($source) => [
                'title' => $source['title'],
                'url' => $source['url'],
                'kind' => $source['kind'],
            ], $result['sources'] ?? []),
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'ok' => new external_value(PARAM_BOOL, 'Whether an answer was produced'),
            'errorcode' => new external_value(PARAM_ALPHANUMEXT, 'Error code', VALUE_OPTIONAL),
            'message' => new external_value(PARAM_RAW, 'What went wrong', VALUE_OPTIONAL),
            'answer' => new external_value(PARAM_RAW, 'The answer', VALUE_OPTIONAL),
            'model' => new external_value(PARAM_RAW, 'Which model wrote it', VALUE_OPTIONAL),
            'sources' => new external_multiple_structure(
                new external_single_structure([
                    'title' => new external_value(PARAM_TEXT, 'Page title'),
                    'url' => new external_value(PARAM_URL, 'Where it is'),
                    'kind' => new external_value(PARAM_ALPHA, 'What kind of page'),
                ])
            ),
        ]);
    }
}
