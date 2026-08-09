<?php
// Face enrolment page.
//
// Enrolment is something the learner does for themselves — the capability is
// checked in their own user context, and there is deliberately no way for a
// member of staff to enrol somebody else's face from here.

require_once(__DIR__ . '/../../config.php');

require_login();

$context = context_user::instance($USER->id);
require_capability('local/kaiproctor:enrolface', $context);

$PAGE->set_url(new moodle_url('/local/kaiproctor/enrol.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('enrol:title', 'local_kaiproctor'));
$PAGE->set_heading(get_string('enrol:title', 'local_kaiproctor'));

$existing = \local_kaiproctor\enrolment::get_active($USER->id);

$PAGE->requires->js_call_amd('local_kaiproctor/enrol_page', 'init', [[
    'alreadyenrolled' => (bool) $existing,
]]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_kaiproctor/enrol', [
    'alreadyenrolled' => (bool) $existing,
    'enrolledon' => $existing ? userdate($existing->timecreated) : '',
]);
echo $OUTPUT->footer();
