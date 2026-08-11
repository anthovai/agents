<?php
// One question on the timeline.

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class mod_kaivideo_edit_form extends moodleform {

    public function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'cmid', $this->_customdata['cmid']);
        $mform->setType('cmid', PARAM_INT);

        $mform->addElement('hidden', 'itemid', 0);
        $mform->setType('itemid', PARAM_INT);

        $mform->addElement('header', 'questionheader',
            get_string('addquestion', 'mod_kaivideo'));

        // Seconds rather than mm:ss: the video element reports seconds, the
        // editor shows seconds, and one representation is one fewer thing to
        // get wrong. The list above renders mm:ss for reading.
        $mform->addElement('text', 'attime', get_string('attime', 'mod_kaivideo'),
            ['size' => 10]);
        $mform->setType('attime', PARAM_FLOAT);
        $mform->addRule('attime', null, 'required', null, 'client');
        $mform->addHelpButton('attime', 'attime', 'mod_kaivideo');

        $mform->addElement('textarea', 'questiontext',
            get_string('questiontext', 'mod_kaivideo'), ['rows' => 3, 'cols' => 60]);
        $mform->setType('questiontext', PARAM_TEXT);
        $mform->addRule('questiontext', null, 'required', null, 'client');

        $radios = [];
        for ($index = 0; $index < \mod_kaivideo\timeline::MAX_CHOICES; $index++) {
            $mform->addElement('text', 'choice' . $index,
                get_string('choicen', 'mod_kaivideo', $index + 1), ['size' => 50]);
            $mform->setType('choice' . $index, PARAM_TEXT);

            $radios[] = $mform->createElement('radio', 'correctchoice', '',
                get_string('choicen', 'mod_kaivideo', $index + 1), $index);
        }

        $mform->addGroup($radios, 'correctgroup',
            get_string('correctchoice', 'mod_kaivideo'), ['<br>'], false);
        $mform->setDefault('correctchoice', 0);
        $mform->addHelpButton('correctgroup', 'correctchoice', 'mod_kaivideo');

        $mform->addElement('textarea', 'feedback',
            get_string('feedback', 'mod_kaivideo'), ['rows' => 2, 'cols' => 60]);
        $mform->setType('feedback', PARAM_TEXT);

        $this->add_action_buttons(true, get_string('savequestion', 'mod_kaivideo'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if ((float) $data['attime'] < 0) {
            $errors['attime'] = get_string('error:negativetime', 'mod_kaivideo');
        }

        $filled = 0;
        for ($index = 0; $index < \mod_kaivideo\timeline::MAX_CHOICES; $index++) {
            if (trim((string) ($data['choice' . $index] ?? '')) !== '') {
                $filled++;
            }
        }
        if ($filled < \mod_kaivideo\timeline::MIN_CHOICES) {
            $errors['choice0'] = get_string('error:toofewchoices', 'mod_kaivideo');
        }

        // The commonest authoring mistake: mark choice 4 correct, then clear
        // it. Caught here as well as in timeline::save, because a form error
        // keeps their typing and an exception does not.
        $correct = (int) ($data['correctchoice'] ?? 0);
        if (trim((string) ($data['choice' . $correct] ?? '')) === '') {
            $errors['correctgroup'] = get_string('error:badcorrectchoice', 'mod_kaivideo');
        }

        return $errors;
    }
}
