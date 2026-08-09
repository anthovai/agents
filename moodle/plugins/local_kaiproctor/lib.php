<?php
// Navigation hooks.
//
// Without these the pages exist but are only reachable by typing a URL, which
// is not a usable enrolment flow for a learner.

defined('MOODLE_INTERNAL') || die();

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
