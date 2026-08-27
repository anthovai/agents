<?php
// How the class did, per question and per learner.

require_once(__DIR__ . '/../../config.php');

$cmid = required_param('cmid', PARAM_INT);

[$course, $cm] = get_course_and_cm_from_cmid($cmid, 'kaivideo');
require_login($course, true, $cm);

$context = context_module::instance($cm->id);
require_capability('mod/kaivideo:viewreport', $context);

$video = $DB->get_record('kaivideo', ['id' => $cm->instance], '*', MUST_EXIST);

$PAGE->set_url(new moodle_url('/mod/kaivideo/report.php', ['cmid' => $cmid]));
$PAGE->set_context($context);
$PAGE->set_title(get_string('report', 'mod_kaivideo'));
$PAGE->set_heading(format_string($video->name));

$data = \mod_kaivideo\report::build((int) $video->id, (int) $course->id);
$data['viewurl'] = (new moodle_url('/mod/kaivideo/view.php', ['id' => $cmid]))->out(false);
$data['hascategories'] = !empty($data['categories']);
$data['hasquestions'] = !empty($data['questions']);
$data['haslearners'] = !empty($data['learners']);

echo $OUTPUT->header();
echo $OUTPUT->render_from_template('mod_kaivideo/report', $data);
echo $OUTPUT->footer();
