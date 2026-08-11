<?php
// How far the learner has watched.
//
// Advisory: it decides completion and nothing else, and the browser is the
// only thing that knows where the playhead is. A learner who wants to claim
// they watched more than they did can already do so with the seek bar, which
// is why the grade comes from the answers rather than from this.

namespace mod_kaivideo\external;

defined('MOODLE_INTERNAL') || die();

use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

class record_progress extends external_api {

    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'cmid' => new external_value(PARAM_INT, 'The activity'),
            'seconds' => new external_value(PARAM_FLOAT, 'Playhead position'),
            'finished' => new external_value(PARAM_BOOL, 'Whether the video ended',
                VALUE_DEFAULT, false),
        ]);
    }

    public static function execute(int $cmid, float $seconds, bool $finished): array {
        global $USER;

        $params = self::validate_parameters(self::execute_parameters(),
            ['cmid' => $cmid, 'seconds' => $seconds, 'finished' => $finished]);

        [$course, $cm] = get_course_and_cm_from_cmid($params['cmid'], 'kaivideo');
        $context = \context_module::instance($cm->id);
        self::validate_context($context);
        require_capability('mod/kaivideo:view', $context);

        \mod_kaivideo\responses::reached((int) $cm->instance, (int) $USER->id,
            $params['seconds'], $params['finished']);

        $progress = \mod_kaivideo\responses::progress((int) $cm->instance, (int) $USER->id);
        return ['furthest' => $progress['furthest'], 'finished' => $progress['finished']];
    }

    public static function execute_returns(): external_single_structure {
        return new external_single_structure([
            'furthest' => new external_value(PARAM_FLOAT, 'Furthest point reached'),
            'finished' => new external_value(PARAM_BOOL, 'Whether it has been finished'),
        ]);
    }
}
