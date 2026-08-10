<?php
// Asking where something is on this site.

require_once(__DIR__ . '/../../config.php');

require_login();

$context = context_system::instance();

$PAGE->set_url(new moodle_url('/local/kaiproctor/ask.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('ask:title', 'local_kaiproctor'));
$PAGE->set_heading(get_string('ask:title', 'local_kaiproctor'));

echo $OUTPUT->header();

if (!\local_kaiproctor\assistant::is_available()) {
    // Said plainly rather than hidden: a learner who was told the assistant
    // exists and finds nothing assumes the site is broken.
    echo $OUTPUT->notification(get_string('ask:notavailable', 'local_kaiproctor'),
        \core\output\notification::NOTIFY_INFO);
    echo $OUTPUT->footer();
    exit;
}

$PAGE->requires->js_call_amd('local_kaiproctor/ask_page', 'init');
echo $OUTPUT->render_from_template('local_kaiproctor/ask', [
    'sesskey' => sesskey(),
]);

echo $OUTPUT->footer();
