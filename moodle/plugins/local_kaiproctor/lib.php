<?php
// Navigation hooks.
//
// Without these the pages exist but are only reachable by typing a URL, which
// is not a usable enrolment flow for a learner.

defined('MOODLE_INTERNAL') || die();

/**
 * Add the proctoring pages to the site navigation.
 *
 * @param global_navigation $navigation
 */
function local_kaiproctor_extend_navigation(global_navigation $navigation) {
    global $USER;

    if (!isloggedin() || isguestuser()) {
        return;
    }

    $context = context_user::instance($USER->id);
    if (!has_capability('local/kaiproctor:enrolface', $context)) {
        return;
    }

    $node = $navigation->add(
        get_string('pluginname', 'local_kaiproctor'),
        null,
        navigation_node::TYPE_CONTAINER,
        null,
        'local_kaiproctor'
    );

    $node->add(
        get_string('enrol:title', 'local_kaiproctor'),
        new moodle_url('/local/kaiproctor/enrol.php'),
        navigation_node::TYPE_CUSTOM,
        null,
        'local_kaiproctor_enrol',
        new pix_icon('i/user', '')
    );

    // The lesson page is only offered once a video exists to play.
    if (trim((string) get_config('local_kaiproctor', 'lessonvideourl')) !== '') {
        $node->add(
            get_string('lesson:title', 'local_kaiproctor'),
            new moodle_url('/local/kaiproctor/lesson.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_kaiproctor_lesson',
            new pix_icon('i/course', '')
        );
    }
}
