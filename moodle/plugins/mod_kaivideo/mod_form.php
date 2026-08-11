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
}
