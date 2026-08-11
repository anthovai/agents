<?php
// What goes into a course backup.
//
// Declaring FEATURE_BACKUP_MOODLE2 without writing this does not degrade
// gracefully: backing up any course containing the activity dies with "class
// not found", and the failure is attributed to the backup, not to us. Found by
// running a backup rather than by reading the docs, which is the only way that
// kind of mistake gets found.

defined('MOODLE_INTERNAL') || die();

class backup_kaivideo_activity_structure_step extends backup_activity_structure_step {

    protected function define_structure() {
        $userinfo = $this->get_setting_value('userinfo');

        $kaivideo = new backup_nested_element('kaivideo', ['id'], [
            'name', 'intro', 'introformat', 'videourl', 'mustanswer',
            'allowreview', 'grade', 'timecreated', 'timemodified',
        ]);

        $items = new backup_nested_element('items');
        $item = new backup_nested_element('item', ['id'], [
            'attime', 'type', 'questiontext', 'choices', 'correctchoice',
            'feedback', 'timecreated',
        ]);

        $responses = new backup_nested_element('responses');
        $response = new backup_nested_element('response', ['id'], [
            'userid', 'choice', 'correct', 'timecreated',
        ]);

        $progresses = new backup_nested_element('progresses');
        $progress = new backup_nested_element('progress', ['id'], [
            'userid', 'furthest', 'finished', 'timemodified',
        ]);

        $kaivideo->add_child($items);
        $items->add_child($item);
        $item->add_child($responses);
        $responses->add_child($response);

        $kaivideo->add_child($progresses);
        $progresses->add_child($progress);

        $kaivideo->set_source_table('kaivideo', ['id' => backup::VAR_ACTIVITYID]);
        $item->set_source_table('kaivideo_item', ['kaivideoid' => backup::VAR_PARENTID],
            'attime ASC, id ASC');

        // Answers and watch position are somebody's record, so they travel
        // only when the backup was asked to include user data.
        if ($userinfo) {
            $response->set_source_table('kaivideo_response',
                ['itemid' => backup::VAR_PARENTID]);
            $progress->set_source_table('kaivideo_progress',
                ['kaivideoid' => backup::VAR_PARENTID]);
        }

        $response->annotate_ids('user', 'userid');
        $progress->annotate_ids('user', 'userid');

        $kaivideo->annotate_files('mod_kaivideo', 'intro', null);

        return $this->prepare_activity_structure($kaivideo);
    }
}
