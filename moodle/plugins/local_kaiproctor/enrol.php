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
// The capability is checked against the learner's own user context above —
// enrolling a face is something you do for yourself. The *page* context is the
// site, because setting it to the user context makes Boost decorate the page
// with a profile header: the learner's avatar and a "message" link, on a tool
// page that has nothing to do with either.
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('enrol:title', 'local_kaiproctor'));
$PAGE->set_heading(get_string('enrol:title', 'local_kaiproctor'));

$existing = \local_kaiproctor\enrolment::get_active($USER->id);

$PAGE->requires->js_call_amd('local_kaiproctor/enrol_page', 'init', [[
    'alreadyenrolled' => (bool) $existing,
    // The learner's own context, not the page's. The page is rendered in the
    // system context so Boost does not decorate it with a profile header, and
    // logging the notice against the system context would ask a learner to
    // write somewhere they have no business writing.
    'contextid' => $context->id,
    // Read from the setting the purge task runs on, so the notice cannot
    // promise a retention period nobody enforces.
    'retentiondays' => (int) get_config('local_kaiproctor', 'retentiondays'),
]]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_kaiproctor/enrol', [
    'alreadyenrolled' => (bool) $existing,
    'enrolledon' => $existing ? userdate($existing->timecreated) : '',
]);
echo $OUTPUT->footer();
