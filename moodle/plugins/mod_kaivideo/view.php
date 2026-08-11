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

$source = \mod_kaivideo\source::describe($video->videourl);

$PAGE->requires->js_call_amd('mod_kaivideo/player', 'init', [[
    'cmid' => (int) $cm->id,
    'provider' => $source['provider'],
    'videoid' => $source['videoid'],
    'timeline' => $timeline,
    'answered' => $answered,
    'mustanswer' => (bool) $video->mustanswer,
    'allowreview' => (bool) $video->allowreview,
    'resumeat' => $progress['furthest'],
]]);

echo $OUTPUT->header();

// The description is not printed here. Moodle's activity header already
// renders it, and printing it again put the same paragraph on the page twice.

echo $OUTPUT->render_from_template('mod_kaivideo/player', [
    'videourl' => $video->videourl,
    'provider' => $source['provider'],
    'isfile' => ($source['provider'] === \mod_kaivideo\source::FILE),
    'questioncount' => count($timeline),
    'mustanswer' => (bool) $video->mustanswer,
    'canedit' => $canedit,
    'editurl' => (new moodle_url('/mod/kaivideo/edit.php', ['cmid' => $cm->id]))->out(false),
    'noquestions' => empty($timeline),
]);

echo $OUTPUT->footer();
