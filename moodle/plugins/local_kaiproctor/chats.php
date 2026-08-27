<?php
// Where somebody manages their own conversations with the assistant.
//
// Their own, and only their own. There is no capability that opens this page
// onto another account: a transcript of what a person asked is a record of
// what they did not know, and the audience for that is them.
//
// Everything here is destructive or exporting, so everything here is a POST
// with a sesskey. A conversation deleted by a crafted link is a conversation
// nobody meant to delete.

require(__DIR__ . '/../../config.php');

use local_kaiproctor\chat_history;

require_login();
$context = context_user::instance($USER->id);

$PAGE->set_url(new moodle_url('/local/kaiproctor/chats.php'));
$PAGE->set_context($context);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('chat:history', 'local_kaiproctor'));
$PAGE->set_heading(get_string('chat:history', 'local_kaiproctor'));

$action = optional_param('action', '', PARAM_ALPHA);
$convoid = optional_param('id', 0, PARAM_INT);

if ($action && confirm_sesskey()) {
    // Ownership is checked inside every one of these; the id came from a form.
    if ($action === 'delete' && $convoid) {
        chat_history::delete($USER->id, $convoid);
        redirect($PAGE->url, get_string('chat:deleted', 'local_kaiproctor'));
    }
    if ($action === 'deleteall') {
        chat_history::delete_all($USER->id);
        redirect($PAGE->url, get_string('chat:deleted', 'local_kaiproctor'));
    }
    if ($action === 'rename' && $convoid) {
        $title = required_param('title', PARAM_TEXT);
        chat_history::rename($USER->id, $convoid, $title);
        redirect($PAGE->url);
    }
    if ($action === 'download' && $convoid) {
        $body = chat_history::read($USER->id, $convoid);
        if ($body === '') {
            throw new moodle_exception('invalidrecord', 'error');
        }
        // The file they were promised, as a file. Being able to take it away
        // is most of what owning it means.
        send_file($body, 'conversation-' . $convoid . '.md', 0, 0, true, true,
            'text/markdown; charset=utf-8');
    }
}

$conversations = chat_history::listing($USER->id);
$used = chat_history::used($USER->id);

echo $OUTPUT->header();
echo html_writer::tag('p', get_string('chat:history_desc', 'local_kaiproctor'),
    ['class' => 'text-muted']);

echo html_writer::tag('p', get_string('chat:usage', 'local_kaiproctor', (object) [
    'used' => display_size($used),
    'quota' => display_size(chat_history::quota()),
]), ['class' => 'small text-muted']);

if (!$conversations) {
    echo $OUTPUT->notification(get_string('chat:none', 'local_kaiproctor'),
        \core\output\notification::NOTIFY_INFO);
    echo $OUTPUT->footer();
    exit;
}

$table = new html_table();
$table->head = [
    get_string('chat:history', 'local_kaiproctor'),
    get_string('lastmodified'),
    '',
];
$table->attributes['class'] = 'generaltable';

foreach ($conversations as $convo) {
    $buttons = '';
    foreach ([['download', 'chat:download'], ['delete', 'chat:delete']] as [$act, $label]) {
        $confirm = $act === 'delete'
            ? ['onclick' => 'return confirm('
                . json_encode(get_string('chat:confirmdelete', 'local_kaiproctor')) . ')']
            : [];
        $buttons .= html_writer::tag('form',
            html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',
                'value' => sesskey()])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',
                'value' => $act])
            . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',
                'value' => $convo->id])
            . html_writer::tag('button',
                get_string($label, 'local_kaiproctor'),
                ['type' => 'submit', 'class' => 'btn btn-sm btn-link'] + $confirm),
            ['method' => 'post', 'action' => $PAGE->url->out(false),
             'class' => 'd-inline']);
    }

    $rename = html_writer::tag('form',
        html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',
            'value' => sesskey()])
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',
            'value' => 'rename'])
        . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'id',
            'value' => $convo->id])
        . html_writer::empty_tag('input', ['type' => 'text', 'name' => 'title',
            'value' => $convo->title, 'class' => 'form-control form-control-sm d-inline',
            'style' => 'width:22rem', 'maxlength' => 255])
        . html_writer::tag('button', get_string('chat:rename', 'local_kaiproctor'),
            ['type' => 'submit', 'class' => 'btn btn-sm btn-link']),
        ['method' => 'post', 'action' => $PAGE->url->out(false)]);

    $table->data[] = [
        $rename . html_writer::tag('div',
            get_string('chat:turns', 'local_kaiproctor', $convo->turns)
            . ' · ' . display_size($convo->bytes),
            ['class' => 'small text-muted']),
        userdate($convo->timemodified),
        $buttons,
    ];
}

echo html_writer::table($table);

echo html_writer::tag('form',
    html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'sesskey',
        'value' => sesskey()])
    . html_writer::empty_tag('input', ['type' => 'hidden', 'name' => 'action',
        'value' => 'deleteall'])
    . html_writer::tag('button', get_string('chat:deleteall', 'local_kaiproctor'),
        ['type' => 'submit', 'class' => 'btn btn-outline-danger btn-sm',
         'onclick' => 'return confirm('
            . json_encode(get_string('chat:confirmdeleteall', 'local_kaiproctor')) . ')']),
    ['method' => 'post', 'action' => $PAGE->url->out(false), 'class' => 'mt-3']);

echo $OUTPUT->footer();
