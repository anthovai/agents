<?php
// Site-wide proctoring figures, for administrators.

require_once(__DIR__ . '/../../config.php');

require_login();

$context = context_system::instance();
require_capability('local/kaiproctor:manage', $context);

$PAGE->set_url(new moodle_url('/local/kaiproctor/stats.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('stats:title', 'local_kaiproctor'));
$PAGE->set_heading(get_string('stats:title', 'local_kaiproctor'));

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_kaiproctor/stats', \local_kaiproctor\stats::build());
echo $OUTPUT->footer();
