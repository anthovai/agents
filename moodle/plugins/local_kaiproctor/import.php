<?php
// Import a Thai licence-exam PDF into a course question bank.
//
// Two steps on purpose: parse and show what was found, then import only if the
// person uploading agrees with it. These PDFs vary, and silently importing a
// misparsed pack into a live question bank is much harder to undo than it is
// to prevent.

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/formslib.php');

$courseid = required_param('courseid', PARAM_INT);

$course = get_course($courseid);
require_login($course);

$context = context_course::instance($course->id);
require_capability('moodle/question:add', $context);

$url = new moodle_url('/local/kaiproctor/import.php', ['courseid' => $courseid]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('import:title', 'local_kaiproctor'));
$PAGE->set_heading($course->fullname);

/**
 * Upload form. Deliberately minimal: the only decision worth asking about is
 * which PDF, and everything else is derived or confirmed on the next screen.
 */
class local_kaiproctor_import_form extends moodleform {
    protected function definition() {
        $mform = $this->_form;

        $mform->addElement('static', 'intro', '',
            get_string('import:intro', 'local_kaiproctor'));

        $mform->addElement('filepicker', 'pdf',
            get_string('import:file', 'local_kaiproctor'), null,
            ['accepted_types' => ['.pdf'], 'maxbytes' => 32 * 1024 * 1024]);
        $mform->addRule('pdf', null, 'required');

        $mform->addElement('hidden', 'courseid', $this->_customdata['courseid']);
        $mform->setType('courseid', PARAM_INT);

        $this->add_action_buttons(true, get_string('import:parse', 'local_kaiproctor'));
    }
}

$form = new local_kaiproctor_import_form($url, ['courseid' => $courseid]);

if ($form->is_cancelled()) {
    redirect(new moodle_url('/course/view.php', ['id' => $courseid]));
}

// -------------------------------------------------------------------- import
// The second step: the parsed questions come back through the session, so the
// PDF is only parsed once no matter how long the reviewer takes to decide.
$confirm = optional_param('confirm', 0, PARAM_BOOL);
if ($confirm && confirm_sesskey()) {
    $parsed = $SESSION->kaiproctor_import ?? null;
    if (empty($parsed['questions'])) {
        redirect($url, get_string('import:expired', 'local_kaiproctor'), null,
            \core\output\notification::NOTIFY_ERROR);
    }
    unset($SESSION->kaiproctor_import);

    $bankcm = \core_question\local\bank\question_bank_helper::get_default_open_instance_system_type(
        $course, true
    );
    $bankcontext = context_module::instance($bankcm->id);
    $category = question_get_default_category($bankcontext->id, true);

    $result = \local_kaiproctor\pdf_import::import(
        $parsed['questions'], $category, $bankcontext, $course);

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('import:title', 'local_kaiproctor'));

    if ($result['ok']) {
        echo $OUTPUT->notification(
            get_string('import:done', 'local_kaiproctor', (object) [
                'imported' => $result['imported'],
                'skipped' => $result['skipped'],
            ]),
            \core\output\notification::NOTIFY_SUCCESS
        );
        echo $OUTPUT->single_button(
            new moodle_url('/question/edit.php', ['courseid' => $courseid]),
            get_string('import:openbank', 'local_kaiproctor'), 'get'
        );
    } else {
        echo $OUTPUT->notification(
            get_string('import:failed', 'local_kaiproctor') . ' ' . implode(' ', $result['messages']),
            \core\output\notification::NOTIFY_ERROR
        );
    }

    echo $OUTPUT->footer();
    die;
}

// --------------------------------------------------------------------- parse
if ($data = $form->get_data()) {
    $bytes = $form->get_file_content('pdf');

    $parsed = \local_kaiproctor\pdf_import::parse((string) $bytes);

    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('import:title', 'local_kaiproctor'));

    if (empty($parsed['ok'])) {
        $code = $parsed['error']['code'] ?? 'unknown';
        $message = $parsed['error']['message'] ?? '';
        // The service's messages are already written for the person holding
        // the PDF ("no answer key", "looks like a scan"), so they are shown
        // rather than replaced with something vaguer.
        echo $OUTPUT->notification(
            get_string('import:parsefailed', 'local_kaiproctor', s($code)) . ' ' . s($message),
            \core\output\notification::NOTIFY_ERROR
        );
        $form->display();
        echo $OUTPUT->footer();
        die;
    }

    $questions = $parsed['questions'] ?? [];
    $counts = \local_kaiproctor\pdf_import::difficulty_counts($questions);
    $SESSION->kaiproctor_import = ['questions' => $questions];

    echo $OUTPUT->notification($parsed['note'] ?? '', \core\output\notification::NOTIFY_INFO);

    $table = new html_table();
    $table->head = [
        get_string('import:count', 'local_kaiproctor'),
        get_string('import:easy', 'local_kaiproctor'),
        get_string('import:medium', 'local_kaiproctor'),
        get_string('import:hard', 'local_kaiproctor'),
    ];
    $table->data = [[count($questions), $counts['easy'], $counts['medium'], $counts['hard']]];
    echo html_writer::table($table);

    echo $OUTPUT->heading(get_string('import:preview', 'local_kaiproctor'), 4);
    echo html_writer::tag('p', get_string('import:previewnote', 'local_kaiproctor'),
        ['class' => 'text-muted']);

    // Three questions is enough to see whether the parse went wrong: a broken
    // one is obvious immediately, and a wall of 300 is not a review.
    foreach (array_slice($questions, 0, 3) as $question) {
        echo html_writer::start_tag('div', ['class' => 'card mb-2']);
        echo html_writer::start_tag('div', ['class' => 'card-body']);
        echo html_writer::tag('p', s($question['text'] ?? ''), ['class' => 'fw-bold']);
        echo html_writer::start_tag('ol', ['type' => 'a']);
        foreach ($question['choices'] ?? [] as $index => $choice) {
            $correct = $index === (int) ($question['answer'] ?? -1);
            echo html_writer::tag('li', s($choice),
                ['class' => $correct ? 'fw-bold text-success' : '']);
        }
        echo html_writer::end_tag('ol');
        echo html_writer::end_tag('div');
        echo html_writer::end_tag('div');
    }

    echo $OUTPUT->single_button(
        new moodle_url($url, ['confirm' => 1, 'sesskey' => sesskey()]),
        get_string('import:confirm', 'local_kaiproctor', count($questions)), 'post'
    );
    echo $OUTPUT->footer();
    die;
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('import:title', 'local_kaiproctor'));
$form->display();
echo $OUTPUT->footer();
