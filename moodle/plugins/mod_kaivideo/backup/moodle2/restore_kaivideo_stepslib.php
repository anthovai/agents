<?php
// Putting one back.

defined('MOODLE_INTERNAL') || die();

class restore_kaivideo_activity_structure_step extends restore_activity_structure_step {

    protected function define_structure() {
        $paths = [];
        $userinfo = $this->get_setting_value('userinfo');

        $paths[] = new restore_path_element('kaivideo', '/activity/kaivideo');
        $paths[] = new restore_path_element('kaivideo_item',
            '/activity/kaivideo/items/item');

        if ($userinfo) {
            $paths[] = new restore_path_element('kaivideo_response',
                '/activity/kaivideo/items/item/responses/response');
            $paths[] = new restore_path_element('kaivideo_progress',
                '/activity/kaivideo/progresses/progress');
        }

        return $this->prepare_activity_structure($paths);
    }

    protected function process_kaivideo($data) {
        global $DB;

        $data = (object) $data;
        $data->course = $this->get_courseid();
        $data->timecreated = $this->apply_date_offset($data->timecreated);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $newid = $DB->insert_record('kaivideo', $data);
        $this->apply_activity_instance($newid);
    }

    protected function process_kaivideo_item($data) {
        global $DB;

        $data = (object) $data;
        $oldid = $data->id;
        $data->kaivideoid = $this->get_new_parentid('kaivideo');
        $data->timecreated = $this->apply_date_offset($data->timecreated);

        $newid = $DB->insert_record('kaivideo_item', $data);
        // Responses are restored against this mapping; without it they would
        // attach to whatever item happened to hold the old id.
        $this->set_mapping('kaivideo_item', $oldid, $newid);
    }

    protected function process_kaivideo_response($data) {
        global $DB;

        $data = (object) $data;
        $data->itemid = $this->get_new_parentid('kaivideo_item');
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->timecreated = $this->apply_date_offset($data->timecreated);

        $DB->insert_record('kaivideo_response', $data);
    }

    protected function process_kaivideo_progress($data) {
        global $DB;

        $data = (object) $data;
        $data->kaivideoid = $this->get_new_parentid('kaivideo');
        $data->userid = $this->get_mappingid('user', $data->userid);
        $data->timemodified = $this->apply_date_offset($data->timemodified);

        $DB->insert_record('kaivideo_progress', $data);
    }

    protected function after_execute() {
        $this->add_related_files('mod_kaivideo', 'intro', null);
        $this->add_related_files('mod_kaivideo', 'video', null);
    }
}
