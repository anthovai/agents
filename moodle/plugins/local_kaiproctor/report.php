<?php
// Evidence trail for one learner, in the context the evidence was captured in.
//
// Staff need the viewevidence capability in that context; a learner can always
// open their own without it, because a person disputing a decision has to be
// able to see what it was based on.

require_once(__DIR__ . '/../../config.php');

$userid = required_param('userid', PARAM_INT);
$contextid = required_param('contextid', PARAM_INT);

$context = context::instance_by_id($contextid);

// A module context needs its course module attached, not just the context:
// setting the context alone leaves $PAGE without a $cm and Moodle throws
// "the course you passed to $PAGE->set_cm does not correspond to the $cm"
// as soon as the layout tries to render course navigation.
if ($context instanceof context_module) {
    [$course, $cm] = get_course_and_cm_from_cmid($context->instanceid);
    require_login($course, false, $cm);
    $PAGE->set_cm($cm, $course);
} else {
    require_login();
    $PAGE->set_context($context);
}

global $USER;
if ((int) $userid !== (int) $USER->id) {
    require_capability('local/kaiproctor:viewevidence', $context);
}

$url = new moodle_url('/local/kaiproctor/report.php',
    ['userid' => $userid, 'contextid' => $contextid]);
$PAGE->set_url($url);
$PAGE->set_pagelayout('report');
$PAGE->set_title(get_string('report:title', 'local_kaiproctor'));
$PAGE->set_heading(get_string('report:title', 'local_kaiproctor'));

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_kaiproctor/report',
    \local_kaiproctor\report::build($userid, $context));
echo $OUTPUT->footer();
