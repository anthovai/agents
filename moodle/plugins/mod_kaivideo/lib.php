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
 * @return int
 */
function kaivideo_add_instance($data) {
    global $DB;

    $data->timecreated = time();
    $data->timemodified = time();
    $data->id = $DB->insert_record('kaivideo', $data);

    kaivideo_grade_item_update($data);
    return $data->id;
}

/**
 * @param stdClass $data from mod_form
 * @return bool
 */
function kaivideo_update_instance($data) {
    global $DB;

    $data->id = $data->instance;
    $data->timemodified = time();
    $DB->update_record('kaivideo', $data);

    kaivideo_grade_item_update($data);
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
 * The "Interactive video" entry in the activity's own menu.
 *
 * @param settings_navigation $settings
 * @param navigation_node $node
 */
function kaivideo_extend_settings_navigation($settings, $node) {
    global $PAGE;

    if (!has_capability('mod/kaivideo:edititems', $PAGE->cm->context)) {
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
}
