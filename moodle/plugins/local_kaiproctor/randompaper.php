<?php
// Build a quiz that draws its questions at random from the course's bank.
//
// Moodle can already do this: a quiz slot can hold "a random question from
// this category" instead of a fixed one. What it cannot do is add thirty of
// them without thirty trips through a modal, and the filter has to be set
// each time. A teacher with a bank of three hundred imported questions and an
// exam of thirty is the ordinary case here, not the exotic one.
//
// The bank is the course's own, so a paper never draws from another course's
// questions. That is not enforced here — it follows from which bank the
// import wrote to — but it is the property this page depends on.

require_once(__DIR__ . '/../../config.php');
require_once($CFG->libdir . '/formslib.php');

$courseid = required_param('courseid', PARAM_INT);

$course = get_course($courseid);
require_login($course);

$context = context_course::instance($course->id);
require_capability('moodle/question:useall', $context);

$url = new moodle_url('/local/kaiproctor/randompaper.php', ['courseid' => $courseid]);
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('admin');
$PAGE->set_title(get_string('paper:title', 'local_kaiproctor'));
$PAGE->set_heading($course->fullname);

// The bank the import writes to, which is the one a paper draws from.
$bankcm = \core_question\local\bank\question_bank_helper::get_default_open_instance_system_type(
    $course, true
);
$bankcontext = context_module::instance($bankcm->id);
$category = question_get_default_category($bankcontext->id, true);
$available = \local_kaiproctor\random_paper::available($category);

$quizzes = \local_kaiproctor\random_paper::quizzes_in($course);

/**
 * Which quiz, and how many questions. Nothing else is a decision: the filter
 * is the course's own bank, and the mark per question is Moodle's default.
 */
class local_kaiproctor_paper_form extends moodleform {
    protected function definition() {
        $mform = $this->_form;
        $data = $this->_customdata;

        $mform->addElement('static', 'intro', '',
            get_string('paper:intro', 'local_kaiproctor', $data['available']));

        $mform->addElement('select', 'quizid',
            get_string('paper:quiz', 'local_kaiproctor'), $data['quizzes']);
        $mform->addRule('quizid', null, 'required');

        $mform->addElement('text', 'count',
            get_string('paper:count', 'local_kaiproctor'), ['size' => 5]);
        $mform->setType('count', PARAM_INT);
        $mform->addRule('count', null, 'required');
        $mform->addHelpButton('count', 'paper:count', 'local_kaiproctor');

        $mform->addElement('advcheckbox', 'replace',
            get_string('paper:replace', 'local_kaiproctor'), '',
            null, [0, 1]);
        $mform->setDefault('replace', 1);
        $mform->addHelpButton('replace', 'paper:replace', 'local_kaiproctor');

        $mform->addElement('hidden', 'courseid', $data['courseid']);
        $mform->setType('courseid', PARAM_INT);

        $this->add_action_buttons(true, get_string('paper:build', 'local_kaiproctor'));
    }

    /**
     * A paper larger than the bank is a paper that cannot be drawn: Moodle
     * would leave the surplus slots empty at attempt time, which the learner
     * discovers and the teacher does not.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $available = $this->_customdata['available'];

        if ($data['count'] < 1) {
            $errors['count'] = get_string('paper:atleastone', 'local_kaiproctor');
        } else if ($data['count'] > $available) {
            $errors['count'] = get_string('paper:toomany', 'local_kaiproctor', $available);
        }
        return $errors;
    }
}

$form = new local_kaiproctor_paper_form($url, [
    'courseid' => $courseid,
    'quizzes' => $quizzes,
    'available' => $available,
]);

if ($form->is_cancelled()) {
    redirect(new moodle_url('/course/view.php', ['id' => $courseid]));
}

if ($data = $form->get_data()) {
    $result = \local_kaiproctor\random_paper::build(
        (int) $data->quizid, (int) $data->count, (bool) $data->replace, $category);

    $cmid = get_coursemodule_from_instance('quiz', (int) $data->quizid)->id;
    redirect(
        new moodle_url('/mod/quiz/edit.php', ['cmid' => $cmid]),
        get_string('paper:done', 'local_kaiproctor', (object) [
            'added' => $result['added'],
            'removed' => $result['removed'],
        ]),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('paper:title', 'local_kaiproctor'));

if (!$quizzes) {
    echo $OUTPUT->notification(get_string('paper:noquizzes', 'local_kaiproctor'),
        \core\output\notification::NOTIFY_WARNING);
} else if (!$available) {
    echo $OUTPUT->notification(get_string('paper:nobank', 'local_kaiproctor'),
        \core\output\notification::NOTIFY_WARNING);
} else {
    $form->display();
}

echo $OUTPUT->footer();
