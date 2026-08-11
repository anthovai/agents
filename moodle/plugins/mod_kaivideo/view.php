<?php
// Watching it.

require_once(__DIR__ . '/../../config.php');

$cmid = required_param('id', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'kaivideo');
require_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/kaivideo:view', $context);

$video = $DB->get_record('kaivideo', ['id' => $cm->instance], '*', MUST_EXIST);

$PAGE->set_url(new moodle_url('/mod/kaivideo/view.php', ['id' => $cmid]));
$PAGE->set_context($context);
$PAGE->set_title(format_string($video->name));
$PAGE->set_heading(format_string($course->fullname));

$completion = new completion_info($course);
$completion->set_module_viewed($cm);

$canedit = has_capability('mod/kaivideo:edititems', $context);
$timeline = \mod_kaivideo\timeline::for_player((int) $video->id);
$progress = \mod_kaivideo\responses::progress((int) $video->id, (int) $USER->id);

// Answers a learner has already given, so returning to the page does not ask
// the same questions again as though the first sitting never happened.
$answered = [];
foreach (\mod_kaivideo\responses::latest((int) $video->id, (int) $USER->id) as $itemid => $answer) {
    $answered[] = ['itemid' => $itemid, 'choice' => $answer['choice'],
        'correct' => $answer['correct']];
}

$PAGE->requires->js_call_amd('mod_kaivideo/player', 'init', [[
    'cmid' => (int) $cm->id,
    'timeline' => $timeline,
    'answered' => $answered,
    'mustanswer' => (bool) $video->mustanswer,
    'allowreview' => (bool) $video->allowreview,
    'resumeat' => $progress['furthest'],
]]);

echo $OUTPUT->header();

if (trim($video->intro) !== '') {
    echo $OUTPUT->box(format_module_intro('kaivideo', $video, $cm->id), 'generalbox mod_introbox');
}

echo $OUTPUT->render_from_template('mod_kaivideo/player', [
    'videourl' => $video->videourl,
    'questioncount' => count($timeline),
    'mustanswer' => (bool) $video->mustanswer,
    'canedit' => $canedit,
    'editurl' => (new moodle_url('/mod/kaivideo/edit.php', ['cmid' => $cm->id]))->out(false),
    'noquestions' => empty($timeline),
]);

echo $OUTPUT->footer();
