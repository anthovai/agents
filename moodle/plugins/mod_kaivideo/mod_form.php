<?php
// Creating the activity. Questions are added afterwards, against the video,
// because choosing a timestamp without seeing the frame is guesswork.

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot . '/course/moodleform_mod.php');

class mod_kaivideo_mod_form extends moodleform_mod {

    public function definition() {
        $mform = $this->_form;

        $mform->addElement('text', 'name', get_string('name'), ['size' => 64]);
        $mform->setType('name', PARAM_TEXT);
        $mform->addRule('name', null, 'required', null, 'client');

        $this->standard_intro_elements();

        $mform->addElement('url', 'videourl', get_string('videourl', 'mod_kaivideo'),
            ['size' => 64], ['usefilepicker' => false]);
        $mform->setType('videourl', PARAM_URL);
        $mform->addRule('videourl', null, 'required', null, 'client');
        $mform->addHelpButton('videourl', 'videourl', 'mod_kaivideo');

        $mform->addElement('advcheckbox', 'mustanswer',
            get_string('mustanswer', 'mod_kaivideo'));
        $mform->setDefault('mustanswer', 1);
        $mform->addHelpButton('mustanswer', 'mustanswer', 'mod_kaivideo');

        $mform->addElement('advcheckbox', 'allowreview',
            get_string('allowreview', 'mod_kaivideo'));
        $mform->setDefault('allowreview', 1);
        $mform->addHelpButton('allowreview', 'allowreview', 'mod_kaivideo');

        $this->standard_grading_coursemodule_elements();
        $this->standard_coursemodule_elements();
        $this->add_action_buttons();
    }

    /**
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        // The commonest authoring mistake is pasting a page that contains a
        // video rather than the video. Caught here, because otherwise it
        // appears to the learner as an empty player with nothing to explain it.
        if (!empty($data['videourl'])
                && !\mod_kaivideo\source::is_playable($data['videourl'])) {
            $errors['videourl'] = get_string('error:notplayable', 'mod_kaivideo');
        }

        return $errors;
    }

    /**
     * The completion rules that mean something for a video.
     *
     * @return array of element names
     */
    public function add_completion_rules() {
        $mform = $this->_form;

        $mform->addElement('checkbox', 'completionanswerall',
            get_string('completionanswerall', 'mod_kaivideo'),
            get_string('completionanswerall_label', 'mod_kaivideo'));
        $mform->addHelpButton('completionanswerall', 'completionanswerall', 'mod_kaivideo');

        $mform->addElement('checkbox', 'completionwatched',
            get_string('completionwatched', 'mod_kaivideo'),
            get_string('completionwatched_label', 'mod_kaivideo'));
        $mform->addHelpButton('completionwatched', 'completionwatched', 'mod_kaivideo');

        return ['completionanswerall', 'completionwatched'];
    }

    /**
     * @param array $data
     * @return bool
     */
    public function completion_rule_enabled($data) {
        return !empty($data['completionanswerall']) || !empty($data['completionwatched']);
    }
}
