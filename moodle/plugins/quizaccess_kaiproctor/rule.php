<?php
// Skeleton access rule. The gating logic is stubbed deliberately: it goes in
// once local_kaiproctor can enrol a face and call the service, so that this
// plugin never half-blocks an attempt it cannot actually verify.

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/quiz/accessrule/accessrulebase.php');

use mod_quiz\local\access_rule_base;
use mod_quiz\quiz_settings;

class quizaccess_kaiproctor extends access_rule_base {

    public static function make(quiz_settings $quizobj, $timenow, $canignoretimelimits) {
        if (empty($quizobj->get_quiz()->kaiproctorenabled)) {
            return null;
        }
        return new self($quizobj, $timenow);
    }

    public static function add_settings_form_fields(
        mod_quiz_mod_form $quizform,
        MoodleQuickForm $mform
    ) {
        $mform->addElement(
            'selectyesno',
            'kaiproctorenabled',
            get_string('enable', 'quizaccess_kaiproctor')
        );
        $mform->addHelpButton('kaiproctorenabled', 'enable', 'quizaccess_kaiproctor');
        $mform->setDefault('kaiproctorenabled', 0);
    }

    public static function save_settings($quiz) {
        global $DB;

        $DB->delete_records('quizaccess_kaiproctor', ['quizid' => $quiz->id]);
        if (!empty($quiz->kaiproctorenabled)) {
            $DB->insert_record('quizaccess_kaiproctor', (object) [
                'quizid' => $quiz->id,
                'enabled' => 1,
            ]);
        }
    }

    public static function delete_settings($quiz) {
        global $DB;
        $DB->delete_records('quizaccess_kaiproctor', ['quizid' => $quiz->id]);
    }

    public static function get_settings_sql($quizid) {
        return [
            'kp.enabled AS kaiproctorenabled',
            'LEFT JOIN {quizaccess_kaiproctor} kp ON kp.quizid = quiz.id',
            [],
        ];
    }

    public function description() {
        return get_string('description', 'quizaccess_kaiproctor');
    }

    public function is_preflight_check_required($attemptid) {
        // TODO: require a passed active-liveness check before the attempt
        // starts. Returning false until local_kaiproctor can perform one —
        // a rule that blocks without being able to unblock is worse than none.
        return false;
    }
}
