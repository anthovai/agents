<?php
// Things this plugin adds to pages belonging to other people.

namespace local_kaiproctor;

defined('MOODLE_INTERNAL') || die();

class hooks {

    /** Pages where a floating panel would be in the way rather than useful. */
    const AVOID_LAYOUTS = ['embedded', 'popup', 'print', 'maintenance', 'redirect',
        'login', 'secure'];

    /**
     * Put the assistant on every page, as a button rather than a page.
     *
     * A learner who cannot find something is, by definition, on the wrong page
     * already. Sending them to a separate page to ask is asking them to
     * navigate their way out of a navigation problem.
     *
     * Rendered only where it can be used: switched on, logged in, and not on a
     * layout where a floating panel would cover something that matters —
     * 'secure' most of all, which is what a quiz under Safe Exam Browser uses.
     *
     * @param \core\hook\output\before_footer_html_generation $hook
     */
    public static function add_assistant_launcher(
            \core\hook\output\before_footer_html_generation $hook): void {
        global $PAGE, $OUTPUT;

        if (!isloggedin() || isguestuser() || during_initial_install()) {
            return;
        }
        if (in_array($PAGE->pagelayout, self::AVOID_LAYOUTS, true)) {
            return;
        }
        if (!assistant::is_available()) {
            return;
        }

        // Its own page still exists and still works; this is a way in, not a
        // replacement, and anything that cannot render the panel can link there.
        $hook->add_html($OUTPUT->render_from_template('local_kaiproctor/ask_widget', [
            'fullpageurl' => (new \moodle_url('/local/kaiproctor/ask.php'))->out(false),
        ]));

        $PAGE->requires->js_call_amd('local_kaiproctor/ask_widget', 'init');
    }

    /**
     * Start the attention monitor on an activity flagged as monitored.
     *
     * Moved here from a legacy before_footer callback, and not by choice:
     * registering any hook callback for a component makes Moodle skip that
     * component's legacy footer function entirely. Adding the assistant's
     * launcher therefore switched monitoring off across the site, which is the
     * kind of interaction that is invisible in review and obvious in a test.
     *
     * Nothing here is specific to one activity type — flag a page, a URL, an
     * H5P activity or one of our videos and the same thing happens.
     *
     * @param \core\hook\output\before_footer_html_generation $hook
     */
    public static function start_monitor(
            \core\hook\output\before_footer_html_generation $hook): void {
        global $PAGE, $USER;

        if (!isloggedin() || isguestuser() || CLI_SCRIPT) {
            return;
        }

        $cm = $PAGE->cm;
        if (!$cm || !monitored::is_supported($cm->modname)) {
            return;
        }

        // Only the learner's own view is monitored. A teacher opening the
        // activity to check it is not sitting an assessment.
        if (has_capability('moodle/course:manageactivities', $PAGE->context)) {
            return;
        }

        if (!monitored::is_monitored($cm->id)) {
            return;
        }

        $PAGE->requires->js_call_amd('local_kaiproctor/monitor_activity', 'init', [[
            'contextid' => $PAGE->context->id,
            'enrolled' => enrolment::has_enrolled($USER->id),
            'returnurl' => (new \moodle_url('/course/view.php',
                ['id' => $cm->course]))->out(false),
            'strictlockdown' => (bool) get_config('local_kaiproctor', 'strictlockdown'),
            'blurallowance' => (int) get_config('local_kaiproctor', 'blurallowance'),
            'presenceminutes' => (float) get_config('local_kaiproctor', 'presenceminutes'),
            'verifyminutes' => (float) get_config('local_kaiproctor', 'verifyminutes'),
            'clickconfirmminutes' => (float) get_config('local_kaiproctor', 'clickconfirmminutes'),
            'clickconfirmgracesec' => (float) get_config('local_kaiproctor', 'clickconfirmgracesec'),
            'mouseidleminutes' => (float) get_config('local_kaiproctor', 'mouseidleminutes'),
            'randomclipsperhour' => (float) get_config('local_kaiproctor', 'randomclipsperhour'),
            'clipseconds' => (float) get_config('local_kaiproctor', 'clipseconds'),
            'desktopnotification' => (bool) get_config('local_kaiproctor', 'desktopnotification'),
        ]]);
    }
}
