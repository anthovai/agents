<?php
// One item on the timeline.
//
// Four types share one form rather than having one form each. The reason is
// that authors change their mind — a question becomes an info card, a
// single-answer question grows a second right answer — and a separate form per
// type means retyping the text every time that happens. The parts that do not
// apply are hidden, not removed, so switching back brings the work back.

defined('MOODLE_INTERNAL') || die();

require_once($CFG->libdir . '/formslib.php');

class mod_kaivideo_edit_form extends moodleform {

    /** Types that show the option boxes. */
    const OPTIONTYPES = ['choice', 'multichoice'];

    public function definition() {
        $mform = $this->_form;

        $mform->addElement('hidden', 'cmid', $this->_customdata['cmid']);
        $mform->setType('cmid', PARAM_INT);

        $mform->addElement('hidden', 'itemid', 0);
        $mform->setType('itemid', PARAM_INT);

        $mform->addElement('header', 'questionheader',
            get_string('addquestion', 'mod_kaivideo'));

        $types = [];
        foreach (\mod_kaivideo\timeline::TYPES as $type) {
            $types[$type] = get_string('type:' . $type, 'mod_kaivideo');
        }
        $mform->addElement('select', 'type', get_string('type', 'mod_kaivideo'), $types);
        $mform->setDefault('type', 'choice');
        $mform->addHelpButton('type', 'type', 'mod_kaivideo');

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
        $mform->addHelpButton('questiontext', 'questiontext', 'mod_kaivideo');

        // One control for "which are correct", used by both option types, with
        // the count checked in validation. Radios for one type and checkboxes
        // for the other would mean two elements holding the same fact, and
        // switching type would silently carry the stale one.
        for ($index = 0; $index < \mod_kaivideo\timeline::MAX_CHOICES; $index++) {
            $group = [
                $mform->createElement('text', 'choice' . $index, '', ['size' => 46]),
                $mform->createElement('advcheckbox', 'correct' . $index, '',
                    get_string('iscorrect', 'mod_kaivideo')),
            ];
            $mform->addGroup($group, 'choicegroup' . $index,
                get_string('choicen', 'mod_kaivideo', $index + 1), ' ', false);
            $mform->setType('choice' . $index, PARAM_TEXT);
            $mform->hideIf('choicegroup' . $index, 'type', 'in', ['shorttext', 'info']);
        }
        $mform->addHelpButton('choicegroup0', 'choices', 'mod_kaivideo');

        $mform->addElement('textarea', 'acceptedanswers',
            get_string('acceptedanswers', 'mod_kaivideo'), ['rows' => 4, 'cols' => 60]);
        $mform->setType('acceptedanswers', PARAM_TEXT);
        $mform->addHelpButton('acceptedanswers', 'acceptedanswers', 'mod_kaivideo');
        $mform->hideIf('acceptedanswers', 'type', 'in', ['choice', 'multichoice', 'info']);

        $mform->addElement('textarea', 'feedback',
            get_string('feedback', 'mod_kaivideo'), ['rows' => 2, 'cols' => 60]);
        $mform->setType('feedback', PARAM_TEXT);
        $mform->addHelpButton('feedback', 'feedback', 'mod_kaivideo');

        $this->add_action_buttons(true, get_string('savequestion', 'mod_kaivideo'));
    }

    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if ((float) $data['attime'] < 0) {
            $errors['attime'] = get_string('error:negativetime', 'mod_kaivideo');
        }

        $type = (string) ($data['type'] ?? 'choice');

        // An info card has nothing left to check: its message is the question
        // text, which is required for every type already.
        if ($type === 'info') {
            return $errors;
        }

        if ($type === 'shorttext') {
            $accepted = self::accepted_lines((string) ($data['acceptedanswers'] ?? ''));
            if (!$accepted) {
                $errors['acceptedanswers'] =
                    get_string('error:noacceptedanswer', 'mod_kaivideo');
            }
            foreach ($accepted as $answer) {
                if (core_text::strlen($answer) > \mod_kaivideo\timeline::MAX_ANSWER) {
                    $errors['acceptedanswers'] =
                        get_string('error:answertoolong', 'mod_kaivideo');
                }
            }
            return $errors;
        }

        $filled = 0;
        $correct = 0;
        for ($index = 0; $index < \mod_kaivideo\timeline::MAX_CHOICES; $index++) {
            $text = trim((string) ($data['choice' . $index] ?? ''));
            if ($text !== '') {
                $filled++;
            }
            // The commonest authoring mistake: tick option 4, then clear its
            // text. Counted as correct only when it still has words in it, so
            // the error says what is actually wrong.
            if (!empty($data['correct' . $index]) && $text !== '') {
                $correct++;
            }
        }

        if ($filled < \mod_kaivideo\timeline::MIN_CHOICES) {
            $errors['choicegroup0'] = get_string('error:toofewchoices', 'mod_kaivideo');
        } else if (!$correct) {
            $errors['choicegroup0'] = get_string('error:badcorrectchoice', 'mod_kaivideo');
        } else if ($type === 'choice' && $correct > 1) {
            $errors['choicegroup0'] = get_string('error:onlyoneanswer', 'mod_kaivideo');
        } else if ($type === 'multichoice' && $correct === $filled) {
            $errors['choicegroup0'] = get_string('error:allanswerscorrect', 'mod_kaivideo');
        }

        return $errors;
    }

    /**
     * One accepted answer per line, blanks dropped.
     *
     * Lines rather than commas because a Thai answer may well contain a comma
     * and will never contain a newline the author did not type.
     *
     * @param string $text
     * @return array
     */
    public static function accepted_lines(string $text): array {
        $answers = [];
        foreach (preg_split('/\R/u', $text) as $line) {
            $line = trim($line);
            if ($line !== '' && !in_array($line, $answers, true)) {
                $answers[] = $line;
            }
        }
        return $answers;
    }
}
