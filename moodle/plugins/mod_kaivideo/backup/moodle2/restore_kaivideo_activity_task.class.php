<?php
// The restore task for one interactive video.

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/mod/kaivideo/backup/moodle2/restore_kaivideo_stepslib.php');

class restore_kaivideo_activity_task extends restore_activity_task {

    protected function define_my_settings() {
    }

    protected function define_my_steps() {
        $this->add_step(new restore_kaivideo_activity_structure_step(
            'kaivideo_structure', 'kaivideo.xml'));
    }

    public static function define_decode_contents() {
        return [new restore_decode_content('kaivideo', ['intro'], 'kaivideo')];
    }

    public static function define_decode_rules() {
        return [
            new restore_decode_rule('KAIVIDEOVIEWBYID',
                '/mod/kaivideo/view.php?id=$1', 'course_module'),
            new restore_decode_rule('KAIVIDEOINDEX',
                '/mod/kaivideo/index.php?id=$1', 'course'),
        ];
    }

    public static function define_restore_log_rules() {
        return [];
    }
}
