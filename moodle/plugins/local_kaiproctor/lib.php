<?php
// Navigation hooks.
//
// Without these the pages exist but are only reachable by typing a URL, which
// is not a usable enrolment flow for a learner.
//
// The attention monitor used to be started from local_kaiproctor_before_footer()
// here. It now lives in classes/hooks.php: once a component registers a callback
// on before_footer_html_generation, Moodle stops calling that component's legacy
// before_footer function — so adding the assistant's launcher silently switched
// monitoring off, and three tests caught it.

defined('MOODLE_INTERNAL') || die();

/**
 * Offer staff a "monitor this activity" link on activities we can watch.
 *
 * @param cm_info $cm
 */
function local_kaiproctor_extend_navigation_course_module(
    navigation_node $node,
    stdClass $course,
    cm_info $cm
) {
    if (!\local_kaiproctor\monitored::is_supported($cm->modname)) {
        return;
    }
    if (!has_capability('local/kaiproctor:manage', $cm->context)
            && !has_capability('moodle/course:manageactivities', $cm->context)) {
        return;
    }

    $node->add(
        get_string('activity:settings', 'local_kaiproctor'),
        new moodle_url('/local/kaiproctor/monitor.php', ['cmid' => $cm->id]),
        navigation_node::TYPE_SETTING,
        null,
        'local_kaiproctor_monitor'
    );
}

/**
 * Serve a stored snapshot or clip.
 *
 * Evidence is biometric data, so the capability is checked in the context the
 * evidence was captured in — being able to see one learner's evidence in one
 * quiz must not imply being able to see everybody's everywhere. A learner may
 * always see their own.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool false if the file was not served
 */
function local_kaiproctor_pluginfile($course, $cm, $context, $filearea, $args,
                                     $forcedownload, array $options = []) {
    global $DB, $USER;

    if ($filearea !== \local_kaiproctor\evidence::FILEAREA) {
        return false;
    }

    require_login();

    $itemid = (int) array_shift($args);
    $filename = array_pop($args);

    $record = $DB->get_record('local_kaiproctor_evidence',
        ['contextid' => $context->id, 'itemid' => $itemid, 'filename' => $filename]);
    if (!$record) {
        return false;
    }

    if ((int) $record->userid !== (int) $USER->id) {
        require_capability('local/kaiproctor:viewevidence', $context);
    }

    $file = get_file_storage()->get_file($context->id, \local_kaiproctor\evidence::COMPONENT,
        \local_kaiproctor\evidence::FILEAREA, $itemid, '/', $filename);
    if (!$file || $file->is_directory()) {
        return false;
    }

    // Never cached in a shared proxy: this is somebody's face.
    send_stored_file($file, 0, 0, true, $options);
    return true;
}

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

    // No menu entry for the assistant. It has a launcher on every page now, and
    // a menu item pointing at the same thing is a second door to one room.

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

    if (has_capability('local/kaiproctor:manage', context_system::instance())) {
        $node->add(
            get_string('stats:title', 'local_kaiproctor'),
            new moodle_url('/local/kaiproctor/stats.php'),
            navigation_node::TYPE_CUSTOM,
            null,
            'local_kaiproctor_stats',
            new pix_icon('i/report', '')
        );
    }
}

/**
 * Offer the PDF question import inside a course.
 *
 * It belongs here rather than in site administration: questions go into a
 * course's bank, and the person with the PDF is usually the teacher.
 *
 * @param navigation_node $navigation
 * @param stdClass $course
 * @param context_course $context
 */
function local_kaiproctor_extend_navigation_course(
    navigation_node $navigation,
    stdClass $course,
    context_course $context
) {
    if (!has_capability('moodle/question:add', $context)) {
        return;
    }

    $navigation->add(
        get_string('import:title', 'local_kaiproctor'),
        new moodle_url('/local/kaiproctor/import.php', ['courseid' => $course->id]),
        navigation_node::TYPE_SETTING,
        null,
        'local_kaiproctor_import',
        new pix_icon('i/import', '')
    );
}
