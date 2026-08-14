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
    $answered[] = ['itemid' => $itemid, 'response' => $answer['response'],
        'correct' => $answer['correct']];
}

// The address the player loads, which for an uploaded video is a pluginfile
// URL built against this context rather than anything stored on the record.
$videourl = \mod_kaivideo\source::url($video, $context->id);
$source = \mod_kaivideo\source::describe($videourl);

$PAGE->requires->js_call_amd('mod_kaivideo/player', 'init', [[
    'cmid' => (int) $cm->id,
    'provider' => $source['provider'],
    'videoid' => $source['videoid'],
    // The stream address, for the one backend that has to attach its source
    // rather than declare it in the markup.
    'streamurl' => $source['provider'] === \mod_kaivideo\source::HLS ? $videourl : '',
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
    'videourl' => $videourl,
    'provider' => $source['provider'],
    'isnative' => in_array($source['provider'], \mod_kaivideo\source::NATIVE, true),
    'isyoutube' => ($source['provider'] === \mod_kaivideo\source::YOUTUBE),
    'isvimeo' => ($source['provider'] === \mod_kaivideo\source::VIMEO),
    // An HLS playlist is not a src: handing a .m3u8 to a <video> that cannot
    // decode it makes the browser report "no supported sources" before video.js
    // has had a chance to attach. It arrives through the config instead.
    'filesrc' => $source['provider'] === \mod_kaivideo\source::FILE ? $videourl : '',
    // Questions, not interruptions: telling a learner there are eight when
    // three of them are info cards sets them up to expect a longer test.
    'questioncount' => count(array_filter($timeline, static fn($i) => $i['graded'])),
    'mustanswer' => (bool) $video->mustanswer,
    'canedit' => $canedit,
    'editurl' => (new moodle_url('/mod/kaivideo/edit.php', ['cmid' => $cm->id]))->out(false),
    'noquestions' => empty($timeline),
]);

echo $OUTPUT->footer();
