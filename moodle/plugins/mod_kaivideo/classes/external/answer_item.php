<?php
// The learner commits to an answer, and only then finds out.

namespace mod_kaivideo\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

class answer_item extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'The activity'),
            'itemid' => new external_value(PARAM_INT, 'The timeline question'),
            'choice' => new external_value(PARAM_INT, 'Index of the chosen answer'),
        ]);
    }

    public static function execute(int $cmid, int $itemid, int $choice): array {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(),
            ['cmid' => $cmid, 'itemid' => $itemid, 'choice' => $choice]);

        [$course, $cm] = get_course_and_cm_from_cmid($params['cmid'], 'kaivideo');
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/kaivideo:answer', $context);

        // The item has to belong to this activity. Without this the cmid is
        // decoration and any learner could answer any question on the site,
        // including one in a course they are not in.
        $item = $DB->get_record('kaivideo_item',
            ['id' => $params['itemid'], 'kaivideoid' => $cm->instance], '*', MUST_EXIST);

        $video = $DB->get_record('kaivideo', ['id' => $cm->instance], '*', MUST_EXIST);

        $result = \mod_kaivideo\responses::answer((int) $item->id, (int) $USER->id,
            $params['choice'], (bool) $video->allowreview);
        kaivideo_update_grades($video, (int) $USER->id);

        return [
            'correct' => $result['correct'],
            'correctchoice' => $result['correctchoice'],
            'feedback' => $result['feedback'],
        ];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'correct' => new external_value(PARAM_BOOL, 'Whether the choice was right'),
            'correctchoice' => new external_value(PARAM_INT, 'Which one was right'),
            'feedback' => new external_value(PARAM_RAW, 'What to tell them'),
        ]);
    }
}
