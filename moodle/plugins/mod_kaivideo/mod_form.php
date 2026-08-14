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

        // Upload first in the list, because it is the option most teachers can
        // actually take. Asking for a URL assumes somewhere to put the file,
        // which is a thing most people teaching a course do not have.
        $mform->addElement('select', 'sourcetype',
            get_string('sourcetype', 'mod_kaivideo'), [
                \mod_kaivideo\source::FILE => get_string('source:upload', 'mod_kaivideo'),
                'url' => get_string('source:url', 'mod_kaivideo'),
            ]);
        $mform->setDefault('sourcetype', \mod_kaivideo\source::FILE);
        $mform->addHelpButton('sourcetype', 'sourcetype', 'mod_kaivideo');

        $mform->addElement('filemanager', 'videofile',
            get_string('videofile', 'mod_kaivideo'), null, self::filemanager_options());
        $mform->addHelpButton('videofile', 'videofile', 'mod_kaivideo');
        $mform->hideIf('videofile', 'sourcetype', 'neq', \mod_kaivideo\source::FILE);

        $mform->addElement('url', 'videourl', get_string('videourl', 'mod_kaivideo'),
            ['size' => 64], ['usefilepicker' => false]);
        $mform->setType('videourl', PARAM_URL);
        $mform->addHelpButton('videourl', 'videourl', 'mod_kaivideo');
        $mform->hideIf('videourl', 'sourcetype', 'neq', 'url');

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
     * What the file picker will accept.
     *
     * One file, and video types only. `maxbytes => 0` inherits whatever the
     * site and course allow rather than inventing a third limit that an admin
     * would have to find out about by hitting it.
     *
     * @return array
     */
    public static function filemanager_options(): array {
        return [
            'subdirs' => 0,
            'maxfiles' => 1,
            'maxbytes' => 0,
            'accepted_types' => ['video'],
        ];
    }

    /**
     * Fill in the file picker, and work out which option the author chose.
     *
     * The choice is derived from what is there rather than stored: a column
     * saying "this uses an upload" can end up disagreeing with whether a file
     * exists, and the activity is then broken in a way the form cannot show.
     *
     * @param array $data
     */
    public function data_preprocessing(&$data) {
        $draft = file_get_submitted_draft_itemid('videofile');
        $context = $this->context ?? null;

        file_prepare_draft_area($draft, $context ? $context->id : null,
            'mod_kaivideo', \mod_kaivideo\source::AREA, 0,
            self::filemanager_options());
        $data['videofile'] = $draft;

        if (!empty($data['instance'])) {
            $uploaded = $context
                && \mod_kaivideo\source::stored_file($context->id) !== null;
            $data['sourcetype'] = $uploaded
                ? \mod_kaivideo\source::FILE : 'url';
        }
    }

    /**
     * @param array $data
     * @param array $files
     * @return array
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);

        if (($data['sourcetype'] ?? '') === 'url') {
            if (empty($data['videourl'])) {
                $errors['videourl'] = get_string('required');
            } else if (!\mod_kaivideo\source::is_playable($data['videourl'])) {
                // The commonest authoring mistake is pasting a page that
                // contains a video rather than the video. Caught here, because
                // otherwise it appears to the learner as an empty player with
                // nothing to explain it.
                $errors['videourl'] = get_string('error:notplayable', 'mod_kaivideo');
            }
            return $errors;
        }

        // An activity with neither a file nor an address is a black rectangle
        // with no explanation, so it is refused at the point somebody can still
        // do something about it.
        $draft = (int) ($data['videofile'] ?? 0);
        if (!$draft || !file_get_drafarea_files($draft)->list) {
            $errors['videofile'] = get_string('error:novideo', 'mod_kaivideo');
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
