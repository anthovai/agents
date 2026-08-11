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
    $existing = (object) [
        'itemid' => $record->id,
        'attime' => (float) $record->attime,
        'questiontext' => $record->questiontext,
        'correctchoice' => (int) $record->correctchoice,
        'feedback' => $record->feedback,
    ];
    foreach ($choices as $index => $text) {
        $existing->{'choice' . $index} = $text;
    }
}

$form = new mod_kaivideo_edit_form($url, ['cmid' => $cmid]);
if ($existing) {
    $form->set_data($existing);
}

if ($form->is_cancelled()) {
    redirect($url);
} else if ($data = $form->get_data()) {
    $choices = [];
    for ($index = 0; $index < \mod_kaivideo\timeline::MAX_CHOICES; $index++) {
        $choices[] = $data->{'choice' . $index} ?? '';
    }

    try {
        \mod_kaivideo\timeline::save((int) $video->id, [
            'attime' => $data->attime,
            'questiontext' => $data->questiontext,
            'choices' => $choices,
            'correctchoice' => $data->correctchoice,
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

echo $OUTPUT->header();

echo $OUTPUT->render_from_template('mod_kaivideo/edit', [
    'videourl' => $video->videourl,
    'items' => array_map(static function($item) use ($cmid, $url) {
        $item['editurl'] = (new moodle_url($url, ['edit' => $item['id']]))->out(false);
        $item['deleteurl'] = (new moodle_url($url,
            ['delete' => $item['id'], 'sesskey' => sesskey()]))->out(false);
        $item['answer'] = $item['choices'][$item['correctchoice']] ?? '';
        return $item;
    }, \mod_kaivideo\timeline::for_editing((int) $video->id)),
    'viewurl' => (new moodle_url('/mod/kaivideo/view.php', ['id' => $cmid]))->out(false),
]);

$form->display();

echo $OUTPUT->footer();
