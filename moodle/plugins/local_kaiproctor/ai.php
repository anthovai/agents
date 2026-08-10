<?php
// Turning the AI on, with the facts that decide whether you may.

require_once(__DIR__ . '/../../config.php');

require_login();

$context = context_system::instance();
require_capability('local/kaiproctor:manage', $context);

$url = new moodle_url('/local/kaiproctor/ai.php');
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('ai:console', 'local_kaiproctor'));
$PAGE->set_heading(get_string('ai:console', 'local_kaiproctor'));

$turnon = optional_param('enable', null, PARAM_BOOL);
if ($turnon !== null) {
    // POST plus sesskey: switching this on decides whether learner activity
    // leaves the organisation, which is not something a link somebody was sent
    // should be able to do on their behalf.
    require_sesskey();
    if (!confirm_sesskey() || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new moodle_exception('invalidrequest');
    }

    set_config('aienabled', $turnon ? 1 : 0, 'local_kaiproctor');
    redirect($url, get_string($turnon ? 'ai:turnedon' : 'ai:turnedoff',
        'local_kaiproctor'), null, \core\output\notification::NOTIFY_SUCCESS);
}

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_kaiproctor/ai',
    \local_kaiproctor\ai_console::build());
echo $OUTPUT->footer();
