<?php
// Turn proctoring on or off for one activity.
//
// Deliberately a page of ours rather than a field added to each activity's
// settings form: the activities we watch belong to other plugins, and editing
// their forms means maintaining a fork of every one of them.

require_once(__DIR__ . '/../../config.php');

$cmid = required_param('cmid', PARAM_INT);
$enable = optional_param('enable', null, PARAM_BOOL);

[$course, $cm] = get_course_and_cm_from_cmid($cmid);
require_login($course, false, $cm);

$context = context_module::instance($cm->id);
if (!has_capability('local/kaiproctor:manage', $context)
        && !has_capability('moodle/course:manageactivities', $context)) {
    throw new required_capability_exception($context,
        'moodle/course:manageactivities', 'nopermissions', '');
}

if (!\local_kaiproctor\monitored::is_supported($cm->modname)) {
    throw new moodle_exception('activity:unsupported', 'local_kaiproctor', '', $cm->modname);
}

$url = new moodle_url('/local/kaiproctor/monitor.php', ['cmid' => $cmid]);
$PAGE->set_url($url);
$PAGE->set_cm($cm, $course);
$PAGE->set_title(get_string('activity:settings', 'local_kaiproctor'));
$PAGE->set_heading($course->fullname);

if ($enable !== null) {
    require_sesskey();
    \local_kaiproctor\monitored::set($cm->id, (bool) $enable);
    redirect($url, get_string('activity:saved', 'local_kaiproctor'));
}

$monitored = \local_kaiproctor\monitored::is_monitored($cm->id);

echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($cm->name));

echo html_writer::tag('p', get_string('activity:explain', 'local_kaiproctor'));

if ($monitored) {
    echo $OUTPUT->notification(get_string('activity:on', 'local_kaiproctor'), 'success');
    echo $OUTPUT->single_button(
        new moodle_url($url, ['enable' => 0, 'sesskey' => sesskey()]),
        get_string('activity:turnoff', 'local_kaiproctor'),
        'post'
    );
} else {
    echo $OUTPUT->notification(get_string('activity:off', 'local_kaiproctor'), 'info');
    echo $OUTPUT->single_button(
        new moodle_url($url, ['enable' => 1, 'sesskey' => sesskey()]),
        get_string('activity:turnon', 'local_kaiproctor'),
        'post'
    );
}

echo $OUTPUT->footer();
