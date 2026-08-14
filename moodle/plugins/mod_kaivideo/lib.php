<?php
// The activity module's obligations to Moodle core.

defined('MOODLE_INTERNAL') || die();

/**
 * @param string $feature
 * @return mixed
 */
function kaivideo_supports($feature) {
    switch ($feature) {
        case FEATURE_MOD_INTRO:
        case FEATURE_SHOW_DESCRIPTION:
        case FEATURE_BACKUP_MOODLE2:
        case FEATURE_GRADE_HAS_GRADE:
        case FEATURE_COMPLETION_TRACKS_VIEWS:
        case FEATURE_COMPLETION_HAS_RULES:
            return true;
        case FEATURE_GROUPS:
        case FEATURE_GROUPINGS:
            return false;
        case FEATURE_MOD_PURPOSE:
            return MOD_PURPOSE_CONTENT;
        default:
            return null;
    }
}

/**
 * @param stdClass $data from mod_form
 * @param mixed $mform
 * @return int
 */
function kaivideo_add_instance($data, $mform = null) {
    global $DB;

    kaivideo_settle_source($data);

    $data->timecreated = time();
    $data->timemodified = time();
    $data->id = $DB->insert_record('kaivideo', $data);

    kaivideo_save_video_file($data);
    kaivideo_grade_item_update($data);
    return $data->id;
}

/**
 * @param stdClass $data from mod_form
 * @param mixed $mform
 * @return bool
 */
function kaivideo_update_instance($data, $mform = null) {
    global $DB;

    kaivideo_settle_source($data);

    $data->id = $data->instance;
    $data->timemodified = time();
    $DB->update_record('kaivideo', $data);

    kaivideo_save_video_file($data);
    kaivideo_grade_item_update($data);
    return true;
}

/**
 * Make sure exactly one source survives the save.
 *
 * An uploaded file and a typed address can both be sitting in the form — an
 * author who uploaded a video and then switched to a URL still has the file in
 * their draft area. Whichever they did not choose is cleared here, so the
 * record and the file area cannot end up describing two different videos.
 *
 * Called with no sourcetype at all by the seed script and by anything else
 * creating an instance in code, where an address is the only thing on offer.
 *
 * @param stdClass $data
 */
function kaivideo_settle_source($data) {
    $chosen = $data->sourcetype ?? (empty($data->videofile) ? 'url' : \mod_kaivideo\source::FILE);

    if ($chosen === 'url') {
        $data->videofile = 0;
        return;
    }

    // The address column is emptied rather than left to go stale: source::url()
    // falls back to it whenever no file is present, and a leftover address
    // would silently become the video again if the file were ever removed.
    $data->videourl = '';
}

/**
 * Move the uploaded video out of the draft area, or clear what is there.
 *
 * @param stdClass $data with coursemodule and videofile
 */
function kaivideo_save_video_file($data) {
    if (empty($data->coursemodule)) {
        return;
    }

    require_once(__DIR__ . '/mod_form.php');
    $context = context_module::instance($data->coursemodule);

    if (empty($data->videofile)) {
        get_file_storage()->delete_area_files($context->id, 'mod_kaivideo',
            \mod_kaivideo\source::AREA, 0);
        return;
    }

    file_save_draft_area_files($data->videofile, $context->id, 'mod_kaivideo',
        \mod_kaivideo\source::AREA, 0, mod_kaivideo_mod_form::filemanager_options());
}

/**
 * Serve the uploaded video.
 *
 * @param stdClass $course
 * @param stdClass $cm
 * @param context $context
 * @param string $filearea
 * @param array $args
 * @param bool $forcedownload
 * @param array $options
 * @return bool false when it is not ours to serve
 */
function kaivideo_pluginfile($course, $cm, $context, $filearea, $args,
        $forcedownload, array $options = []) {
    if ($context->contextlevel != CONTEXT_MODULE
            || $filearea !== \mod_kaivideo\source::AREA) {
        return false;
    }

    // Enrolment is checked, not just login: a course video is course material,
    // and a URL that plays for anybody with an account is a URL that will be
    // passed around.
    require_login($course, true, $cm);
    if (!has_capability('mod/kaivideo:view', $context)) {
        return false;
    }

    // The itemid is the first thing in $args, not something to add back: it is
    // part of the address core has already split up for us. Building the path
    // with a literal 0 as well produced /video/0/0/lesson.mp4, which matches no
    // file — and the symptom was a 404 rendered as an HTML error page, which
    // the <video> element reported as "no supported sources".
    $itemid = (int) array_shift($args);
    $file = get_file_storage()->get_file_by_hash(sha1(
        "/{$context->id}/mod_kaivideo/" . \mod_kaivideo\source::AREA
        . "/{$itemid}/" . implode('/', $args)));
    if (!$file || $file->is_directory()) {
        return false;
    }

    // Never forced as a download, whatever was asked for. This file exists to
    // be played in a <video> element, and Moodle only byte-serves — which is
    // what makes seeking work at all — when it is not sending an attachment.
    send_stored_file($file, DAYSECS, 0, false, $options);
    return true;
}

/**
 * @param int $id instance id
 * @return bool
 */
function kaivideo_delete_instance($id) {
    global $DB;

    $video = $DB->get_record('kaivideo', ['id' => $id]);
    if (!$video) {
        return false;
    }

    // Responses first: they reference items, and leaving them behind would
    // keep somebody's answers after the activity holding them is gone.
    $items = $DB->get_fieldset_select('kaivideo_item', 'id', 'kaivideoid = ?', [$id]);
    if ($items) {
        $DB->delete_records_list('kaivideo_response', 'itemid', $items);
    }
    $DB->delete_records('kaivideo_item', ['kaivideoid' => $id]);
    $DB->delete_records('kaivideo_progress', ['kaivideoid' => $id]);
    $DB->delete_records('kaivideo', ['id' => $id]);

    grade_update('mod/kaivideo', $video->course, 'mod', 'kaivideo', $id, 0, null,
        ['deleted' => 1]);
    return true;
}

/**
 * Create or update the gradebook item.
 *
 * @param stdClass $video
 * @param mixed $grades
 * @return int
 */
function kaivideo_grade_item_update($video, $grades = null) {
    global $CFG;
    require_once($CFG->libdir . '/gradelib.php');

    $params = ['itemname' => $video->name];
    if ((int) $video->grade > 0) {
        $params['gradetype'] = GRADE_TYPE_VALUE;
        $params['grademax'] = (int) $video->grade;
        $params['grademin'] = 0;
    } else {
        $params['gradetype'] = GRADE_TYPE_NONE;
    }

    return grade_update('mod/kaivideo', $video->course, 'mod', 'kaivideo',
        $video->id, 0, $grades, $params);
}

/**
 * Push one learner's grade, or everybody's.
 *
 * @param stdClass $video
 * @param int $userid 0 for all
 */
function kaivideo_update_grades($video, $userid = 0) {
    global $DB;

    if ((int) $video->grade <= 0) {
        kaivideo_grade_item_update($video);
        return;
    }

    $userids = $userid
        ? [$userid]
        : $DB->get_fieldset_select('kaivideo_progress', 'DISTINCT userid',
            'kaivideoid = ?', [$video->id]);

    $grades = [];
    foreach ($userids as $id) {
        $fraction = \mod_kaivideo\responses::fraction($video->id, (int) $id);
        if ($fraction === null) {
            continue;
        }
        $grades[$id] = (object) [
            'userid' => $id,
            'rawgrade' => $fraction * (int) $video->grade,
        ];
    }

    if ($grades) {
        kaivideo_grade_item_update($video, $grades);
    } else {
        kaivideo_grade_item_update($video);
    }
}

/**
 * What the course cache holds about one of these.
 *
 * Without this, the completion checkboxes appear on the form, save happily, and
 * are then never evaluated: core reads the enabled rules out of the cached
 * module info, and a module that does not publish them is a module with no
 * custom rules as far as completion is concerned. The symptom is a rule that
 * silently does nothing, which is worse than one that visibly fails.
 *
 * @param stdClass $coursemodule
 * @return cached_cm_info|bool
 */
function kaivideo_get_coursemodule_info($coursemodule) {
    global $DB;

    $video = $DB->get_record('kaivideo', ['id' => $coursemodule->instance],
        'id, name, intro, introformat, completionanswerall, completionwatched');
    if (!$video) {
        return false;
    }

    $info = new cached_cm_info();
    $info->name = $video->name;

    if ($coursemodule->showdescription) {
        $info->content = format_module_intro('kaivideo', $video, $coursemodule->id, false);
    }

    // Only when the activity is set to automatic completion: publishing the
    // rules otherwise would have core evaluating conditions the teacher chose
    // to decide by hand.
    if ($coursemodule->completion == COMPLETION_TRACKING_AUTOMATIC) {
        $info->customdata['customcompletionrules']['completionanswerall'] =
            (int) $video->completionanswerall;
        $info->customdata['customcompletionrules']['completionwatched'] =
            (int) $video->completionwatched;
    }

    return $info;
}

/**
 * The "Interactive video" entry in the activity's own menu.
 *
 * @param settings_navigation $settings
 * @param navigation_node $node
 */
function kaivideo_extend_settings_navigation($settings, $node) {
    global $PAGE;

    if (!has_capability('mod/kaivideo:edititems', $PAGE->cm->context)) {
        // Still offer the report: a teacher who may read results but not
        // rewrite questions is a normal arrangement, and gating the whole
        // menu on editing hid the report from exactly those people.
        if (has_capability('mod/kaivideo:viewreport', $PAGE->cm->context)) {
            $node->add(
                get_string('report', 'mod_kaivideo'),
                new moodle_url('/mod/kaivideo/report.php', ['cmid' => $PAGE->cm->id]),
                navigation_node::TYPE_SETTING,
                null,
                'mod_kaivideo_report',
                new pix_icon('i/report', '')
            );
        }
        return;
    }

    $node->add(
        get_string('editquestions', 'mod_kaivideo'),
        new moodle_url('/mod/kaivideo/edit.php', ['cmid' => $PAGE->cm->id]),
        navigation_node::TYPE_SETTING,
        null,
        'mod_kaivideo_edit',
        new pix_icon('t/edit', '')
    );

    if (has_capability('mod/kaivideo:viewreport', $PAGE->cm->context)) {
        $node->add(
            get_string('report', 'mod_kaivideo'),
            new moodle_url('/mod/kaivideo/report.php', ['cmid' => $PAGE->cm->id]),
            navigation_node::TYPE_SETTING,
            null,
            'mod_kaivideo_report',
            new pix_icon('i/report', '')
        );
    }
}
