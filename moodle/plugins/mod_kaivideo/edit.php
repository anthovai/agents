<?php
// Building the timeline.
//
// The video is on the same page as the form on purpose: an author choosing
// "ask this at 01:24" needs to see what is on screen at 01:24, and a form on
// its own turns that into arithmetic against a separate tab.

require_once(__DIR__ . '/../../config.php');
require_once(__DIR__ . '/edit_form.php');

$cmid = required_param('cmid', PARAM_INT);
$delete = optional_param('delete', 0, PARAM_INT);
$edit = optional_param('edit', 0, PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'kaivideo');
require_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/kaivideo:edititems', $context);

$video = $DB->get_record('kaivideo', ['id' => $cm->instance], '*', MUST_EXIST);
$url = new moodle_url('/mod/kaivideo/edit.php', ['cmid' => $cmid]);

$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_title(get_string('editquestions', 'mod_kaivideo'));
$PAGE->set_heading(format_string($video->name));

if ($delete) {
    require_sesskey();
    // Scoped to this activity: an id from elsewhere must not be deletable by
    // somebody who happens to be able to edit this one.
    $item = $DB->get_record('kaivideo_item',
        ['id' => $delete, 'kaivideoid' => $video->id], '*', MUST_EXIST);
    \mod_kaivideo\timeline::delete((int) $item->id);
    kaivideo_update_grades($video);
    redirect($url, get_string('questiondeleted', 'mod_kaivideo'), null,
        \core\output\notification::NOTIFY_SUCCESS);
}

$existing = null;
if ($edit) {
    $record = $DB->get_record('kaivideo_item',
        ['id' => $edit, 'kaivideoid' => $video->id], '*', MUST_EXIST);
    $choices = json_decode($record->choices, true) ?: [];
    $answers = json_decode($record->answers, true) ?: [];

    $existing = (object) [
        'itemid' => $record->id,
        'attime' => (float) $record->attime,
        'type' => $record->type,
        'questiontext' => $record->questiontext,
        'feedback' => $record->feedback,
        // Only one of these two is meaningful for a given type, and the form
        // hides the other. Both are filled in anyway so that an author who
        // switches type to look at it and switches back has lost nothing.
        'acceptedanswers' => $record->type === 'shorttext'
            ? implode("\n", $answers) : '',
    ];
    foreach ($choices as $index => $text) {
        $existing->{'choice' . $index} = $text;
        $existing->{'correct' . $index} = in_array($index, $answers, true) ? 1 : 0;
    }
}

$form = new mod_kaivideo_edit_form($url, ['cmid' => $cmid]);
if ($existing) {
    $form->set_data($existing);
}

if ($form->is_cancelled()) {
    redirect($url);
} else if ($data = $form->get_data()) {
    // The indexes sent to timeline::save count filled options only. The form
    // numbers its boxes 0-5 including the empty ones, so an author who leaves
    // box 2 blank and ticks box 3 would otherwise mark the wrong answer
    // correct once the blanks are dropped.
    $choices = [];
    $answers = [];
    for ($index = 0; $index < \mod_kaivideo\timeline::MAX_CHOICES; $index++) {
        $text = trim((string) ($data->{'choice' . $index} ?? ''));
        if ($text === '') {
            continue;
        }
        if (!empty($data->{'correct' . $index})) {
            $answers[] = count($choices);
        }
        $choices[] = $text;
    }

    if ($data->type === 'shorttext') {
        $answers = mod_kaivideo_edit_form::accepted_lines(
            (string) ($data->acceptedanswers ?? ''));
    }

    try {
        \mod_kaivideo\timeline::save((int) $video->id, [
            'attime' => $data->attime,
            'type' => $data->type,
            'questiontext' => $data->questiontext,
            'choices' => $choices,
            'answers' => $answers,
            'feedback' => $data->feedback,
        ], $data->itemid ?: null);

        kaivideo_update_grades($video);
        redirect($url, get_string('questionsaved', 'mod_kaivideo'), null,
            \core\output\notification::NOTIFY_SUCCESS);
    } catch (moodle_exception $error) {
        // Shown rather than thrown: these are authoring mistakes — two
        // questions on the same second, a correct answer that was deleted —
        // and the author needs the form back with their text still in it.
        \core\notification::error($error->getMessage());
    }
}

$videourl = \mod_kaivideo\source::url($video, $context->id);
$source = \mod_kaivideo\source::describe($videourl);

if ($source['provider'] === \mod_kaivideo\source::HLS) {
    // The one source that cannot be declared in the markup. Same helper the
    // player uses, so there is one place that knows how a stream gets onto a
    // <video> element.
    $PAGE->requires->js_call_amd('mod_kaivideo/backend', 'attachStream',
        ['[data-region="preview"]', $videourl]);
}

echo $OUTPUT->header();

// The preview keeps the provider's own controls, unlike the player: this page
// is for scrubbing around to find a frame, not for taking the lesson, and there
// is nothing here to skip past.
echo $OUTPUT->render_from_template('mod_kaivideo/edit', [
    'videourl' => $videourl,
    'isnative' => in_array($source['provider'], \mod_kaivideo\source::NATIVE, true),
    'filesrc' => $source['provider'] === \mod_kaivideo\source::FILE ? $videourl : '',
    'embedurl' => \mod_kaivideo\source::embed_url($source),
    'items' => array_map(static function($item) use ($cmid, $url) {
        $item['editurl'] = (new moodle_url($url, ['edit' => $item['id']]))->out(false);
        $item['deleteurl'] = (new moodle_url($url,
            ['delete' => $item['id'], 'sesskey' => sesskey()]))->out(false);
        return $item;
    }, \mod_kaivideo\timeline::for_editing((int) $video->id)),
    'viewurl' => (new moodle_url('/mod/kaivideo/view.php', ['id' => $cmid]))->out(false),
]);

$form->display();

echo $OUTPUT->footer();
