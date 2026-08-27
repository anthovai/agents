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
     * Put face enrolment in the user menu.
     *
     * It had a node under global_navigation, which Boost renders somewhere
     * between nowhere and the bottom of a drawer nobody opens — a learner
     * looking for it could not find it, and the only reliable way in was
     * being refused entry to a proctored quiz and following the link in the
     * refusal. That is a fine safety net and a terrible front door: it makes
     * the first experience of the feature a rejection.
     *
     * The user menu is where the rest of "things that are mine" live —
     * profile, grades, preferences — and enrolling your own face is one of
     * those. It is also the one menu a learner already knows to open, being
     * where they log out from.
     *
     * @param \core_user\hook\extend_user_menu $hook
     */
    public static function add_enrolment_to_user_menu(
            \core_user\hook\extend_user_menu $hook): void {
        global $USER;

        if (!isloggedin() || isguestuser() || during_initial_install()) {
            return;
        }

        // Same capability the page itself checks, in the same context: a
        // menu item that leads to "you may not do this" is worse than none.
        if (!has_capability('local/kaiproctor:enrolface',
                \context_user::instance($USER->id))) {
            return;
        }

        $hook->add_navitem((object) [
            'itemtype' => 'link',
            'url' => new \moodle_url('/local/kaiproctor/enrol.php'),
            'title' => get_string('enrol:title', 'local_kaiproctor'),
        ]);
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
        global $PAGE;

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

        // Only what the page cannot ask for later.
        //
        // The policy is deliberately NOT here. It used to be — every interval
        // and threshold, copied into the page — and nothing in the browser
        // ever read it: the monitor asks the server as it opens the sitting,
        // so that the rules it enforces and the rules recorded against that
        // sitting are the same object. The copy was dead weight that had
        // started to rot, still reading `strictlockdown` for a lesson after
        // lessons stopped being governed by it, and a test was asserting
        // against it as though it proved the wiring worked.
        $PAGE->requires->js_call_amd('local_kaiproctor/monitor_activity', 'init', [[
            'contextid' => $PAGE->context->id,
            'returnurl' => (new \moodle_url('/course/view.php',
                ['id' => $cm->course]))->out(false),
        ]]);
    }
}
