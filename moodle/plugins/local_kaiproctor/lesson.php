<?php
// Monitored lesson page.
//
// This is the training-video equivalent of the face-re prototype's /present
// flow: the learner watches under the attention monitor, and every signal is
// recorded against their user context.
//
// A learner who has not enrolled a face can still be watched for presence —
// "is anybody there" does not need a reference — but identity re-checks are
// switched off rather than failing every ten minutes.

require_once(__DIR__ . '/../../config.php');

require_login();

$context = context_user::instance($USER->id);

$PAGE->set_url(new moodle_url('/local/kaiproctor/lesson.php'));
// The capability is checked against the learner's own user context above —
// enrolling a face is something you do for yourself. The *page* context is the
// site, because setting it to the user context makes Boost decorate the page
// with a profile header: the learner's avatar and a "message" link, on a tool
// page that has nothing to do with either.
$PAGE->set_context(context_system::instance());
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('lesson:title', 'local_kaiproctor'));
$PAGE->set_heading(get_string('lesson:title', 'local_kaiproctor'));

$videourl = trim((string) get_config('local_kaiproctor', 'lessonvideourl'));
if ($videourl === '') {
    // Without a video there is nothing to police; say so plainly rather than
    // rendering a player that silently does nothing.
    echo $OUTPUT->header();
    echo $OUTPUT->notification(get_string('lesson:novideo', 'local_kaiproctor'), 'warning');
    echo $OUTPUT->footer();
    die;
}

$enrolled = \local_kaiproctor\enrolment::has_enrolled($USER->id);

$PAGE->requires->js_call_amd('local_kaiproctor/lesson_page', 'init', [[
    'contextid' => $context->id,
    'enrolled' => $enrolled,
    'returnurl' => (new moodle_url('/my/'))->out(false),
    'strictlockdown' => (bool) get_config('local_kaiproctor', 'strictlockdown'),
    'blurallowance' => (int) get_config('local_kaiproctor', 'blurallowance'),
    'presenceminutes' => (float) get_config('local_kaiproctor', 'presenceminutes'),
    'verifyminutes' => (float) get_config('local_kaiproctor', 'verifyminutes'),
    'clickconfirmminutes' => (float) get_config('local_kaiproctor', 'clickconfirmminutes'),
    'clickconfirmgracesec' => (float) get_config('local_kaiproctor', 'clickconfirmgracesec'),
    'mouseidleminutes' => (float) get_config('local_kaiproctor', 'mouseidleminutes'),
    'randomclipsperhour' => (float) get_config('local_kaiproctor', 'randomclipsperhour'),
    'clipseconds' => (float) get_config('local_kaiproctor', 'clipseconds'),
    'desktopnotification' => (bool) get_config('local_kaiproctor', 'desktopnotification'),
]]);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('local_kaiproctor/lesson', [
    'videourl' => $videourl,
    'enrolled' => $enrolled,
]);
echo $OUTPUT->footer();
