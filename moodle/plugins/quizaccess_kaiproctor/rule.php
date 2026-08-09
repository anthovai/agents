<?php
// Quiz access rule: prove who you are before the attempt starts, and stay
// watched while it runs.
//
// The preflight check is deliberately not satisfied by anything the browser
// sends back. The learner's camera work produces a row in
// local_kaiproctor_check written server-side by the verify_frame web service;
// this rule reads that row. A tampered client can lie to itself all it likes
// and still not get past validate_preflight_check().

defined('MOODLE_INTERNAL') || die();

// access_rule_base is autoloaded — the old accessrulebase.php was removed when
// it moved into mod_quiz\local, so requiring it fatals the whole quiz module.
use mod_quiz\form\preflight_check_form;
use mod_quiz\local\access_rule_base;
use mod_quiz\quiz_settings;

class quizaccess_kaiproctor extends access_rule_base {

    /** How recent a passing identity check has to be to open an attempt. */
    const CHECK_MAX_AGE = 180;

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

    /**
     * A learner with no enrolled face cannot be identified at all, so the
     * attempt is refused outright rather than being failed at the preflight
     * stage with an error they cannot act on from inside the form.
     */
    public function prevent_access() {
        global $USER;

        if (\local_kaiproctor\enrolment::has_enrolled($USER->id)) {
            return false;
        }

        $link = new moodle_url('/local/kaiproctor/enrol.php');
        return get_string('mustenrol', 'quizaccess_kaiproctor', $link->out(false));
    }

    public function is_preflight_check_required($attemptid) {
        global $SESSION;

        // Once passed, it stays passed for this attempt; identity is then
        // re-checked periodically by the monitor rather than at every page.
        return empty($SESSION->passedkaiproctor[$this->quiz->id]);
    }

    public function add_preflight_check_form_fields(
        preflight_check_form $quizform,
        MoodleQuickForm $mform,
        $attemptid
    ) {
        global $PAGE;

        $mform->addElement('header', 'kaiproctorheader',
            get_string('preflight:header', 'quizaccess_kaiproctor'));
        $mform->addElement('static', 'kaiproctorintro', '',
            get_string('preflight:intro', 'quizaccess_kaiproctor'));

        $mform->addElement('html', $PAGE->get_renderer('core')->render_from_template(
            'quizaccess_kaiproctor/preflight', []
        ));

        // Purely so the form can show progress; validation ignores it.
        $mform->addElement('hidden', 'kaiproctorattempted', 0);
        $mform->setType('kaiproctorattempted', PARAM_INT);

        // Validation errors are attached here rather than to the hidden field:
        // MoodleQuickForm renders no feedback for hidden elements, so the
        // learner would be refused with nothing on screen explaining why.
        $mform->addElement('static', 'kaiproctorcheck', '', '');

        $PAGE->requires->js_call_amd('quizaccess_kaiproctor/preflight', 'init', [[
            'contextid' => $this->quizobj->get_context()->id,
            'attemptid' => (int) $attemptid,
        ]]);
    }

    public function validate_preflight_check($data, $files, $errors, $attemptid) {
        global $USER;

        if ($this->has_recent_pass($USER->id)) {
            return $errors;
        }

        $errors['kaiproctorcheck'] =
            get_string('preflight:notverified', 'quizaccess_kaiproctor');
        return $errors;
    }

    public function notify_preflight_check_passed($attemptid) {
        global $SESSION;
        $SESSION->passedkaiproctor[$this->quiz->id] = true;
    }

    public function current_attempt_finished() {
        global $SESSION;
        unset($SESSION->passedkaiproctor[$this->quiz->id]);
    }

    /**
     * Was there a passing identity check for this learner in the last few
     * minutes, in this quiz's context?
     *
     * @param int $userid
     * @return bool
     */
    protected function has_recent_pass(int $userid): bool {
        global $DB;

        return $DB->record_exists_select(
            'local_kaiproctor_check',
            'userid = :userid AND contextid = :contextid AND kind = :kind
                 AND decision = :decision AND timecreated >= :cutoff',
            [
                'userid' => $userid,
                'contextid' => $this->quizobj->get_context()->id,
                'kind' => 'identity',
                'decision' => 'pass',
                'cutoff' => time() - self::CHECK_MAX_AGE,
            ]
        );
    }

    /**
     * Run the attention monitor for the length of the attempt.
     *
     * There is no lesson video on a quiz page, so a hidden stand-in element is
     * handed to the monitor: pausing it is a no-op, while every other signal —
     * focus loss, presence, identity, idle, random clips — still applies.
     */
    public function setup_attempt_page($page) {
        $page->requires->js_call_amd('quizaccess_kaiproctor/attempt', 'init', [[
            'contextid' => $this->quizobj->get_context()->id,
            'strictlockdown' => (bool) get_config('local_kaiproctor', 'strictlockdown'),
            'blurallowance' => (int) get_config('local_kaiproctor', 'blurallowance'),
            'presenceminutes' => (float) get_config('local_kaiproctor', 'presenceminutes'),
            'verifyminutes' => (float) get_config('local_kaiproctor', 'verifyminutes'),
            'mouseidleminutes' => (float) get_config('local_kaiproctor', 'mouseidleminutes'),
            'randomclipsperhour' => (float) get_config('local_kaiproctor', 'randomclipsperhour'),
            'clipseconds' => (float) get_config('local_kaiproctor', 'clipseconds'),
            'desktopnotification' => (bool) get_config('local_kaiproctor', 'desktopnotification'),
            'returnurl' => (new moodle_url('/mod/quiz/view.php',
                ['id' => $this->quizobj->get_cmid()]))->out(false),
        ]]);
    }
}
