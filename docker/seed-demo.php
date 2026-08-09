<?php
// Create the demo course, users and a proctored quiz.
//
// Copy to the Moodle root and run it from inside the moodle container:
//   docker compose cp docker/seed-demo.php moodle:/var/www/html/seed-demo.php
//   docker compose exec moodle php /var/www/html/seed-demo.php
//
// The Moodle root is above the web root since 5.0, so a file left there is not
// reachable over HTTP. It is safe to re-run: everything is looked up first.
//
// This is a development convenience, NOT production seeding — the passwords
// here are throwaway and the accounts are meant for a laptop, not a server.

define('CLI_SCRIPT', true);
require(__DIR__ . '/config.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/lib/enrollib.php');
// add_moduleinfo() lives in course/modlib.php, which course/lib.php does not pull in.
require_once($CFG->dirroot . '/course/modlib.php');

/**
 * @param string $username
 * @param string $password
 * @param string $firstname
 * @param string $lastname
 * @return stdClass
 */
function kp_ensure_user(string $username, string $password,
                        string $firstname, string $lastname): stdClass {
    global $DB, $CFG;

    $existing = $DB->get_record('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id]);
    if ($existing) {
        mtrace("  user {$username} already exists (id {$existing->id})");
        return $existing;
    }

    $user = (object) [
        'username' => $username,
        'password' => $password,
        'firstname' => $firstname,
        'lastname' => $lastname,
        'email' => $username . '@example.test',
        'auth' => 'manual',
        'confirmed' => 1,
        'mnethostid' => $CFG->mnet_localhost_id,
        'lang' => 'th',
    ];
    $user->id = user_create_user($user, true, false);
    mtrace("  created user {$username} (id {$user->id})");
    return $DB->get_record('user', ['id' => $user->id]);
}

mtrace('users:');
$learner = kp_ensure_user('learner', 'Learn!2345', 'สมชาย', 'ผู้เรียน');
$learner2 = kp_ensure_user('learner2', 'Learn!2345', 'สมหญิง', 'ผู้เรียน');
$instructor = kp_ensure_user('instructor', 'Teach!2345', 'อาจารย์', 'ผู้สอน');

mtrace('course:');
$course = $DB->get_record('course', ['shortname' => 'KP-DEMO']);
if (!$course) {
    $course = create_course((object) [
        'fullname' => 'หลักสูตรทดสอบระบบคุมสอบ',
        'shortname' => 'KP-DEMO',
        'category' => 1,
        'format' => 'topics',
        'numsections' => 1,
        'visible' => 1,
    ]);
    mtrace("  created course {$course->shortname} (id {$course->id})");
} else {
    mtrace("  course {$course->shortname} already exists (id {$course->id})");
}
$coursecontext = context_course::instance($course->id);

mtrace('enrolments:');
$manual = enrol_get_plugin('manual');
$instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', IGNORE_MULTIPLE);
$studentrole = $DB->get_record('role', ['shortname' => 'student']);
$teacherrole = $DB->get_record('role', ['shortname' => 'editingteacher']);

foreach ([[$learner, $studentrole], [$learner2, $studentrole], [$instructor, $teacherrole]] as [$user, $role]) {
    if (is_enrolled($coursecontext, $user)) {
        mtrace("  {$user->username} already enrolled");
        continue;
    }
    $manual->enrol_user($instance, $user->id, $role->id);
    mtrace("  enrolled {$user->username} as {$role->shortname}");
}

mtrace('quiz:');
$quizmodule = $DB->get_record('modules', ['name' => 'quiz']);
$existingquiz = $DB->get_record_sql(
    'SELECT q.* FROM {quiz} q WHERE q.course = :course AND q.name = :name',
    ['course' => $course->id, 'name' => 'ข้อสอบทดสอบระบบคุมสอบ']
);

if ($existingquiz) {
    mtrace("  quiz already exists (id {$existingquiz->id})");
    $quiz = $existingquiz;
} else {
    $quiz = (object) [
        'course' => $course->id,
        'name' => 'ข้อสอบทดสอบระบบคุมสอบ',
        'intro' => 'ข้อสอบสำหรับทดสอบการคุมสอบด้วยใบหน้า',
        'introformat' => FORMAT_HTML,
        'timelimit' => 0,
        'preferredbehaviour' => 'deferredfeedback',
        'grade' => 10,
        'sumgrades' => 0,
        'modulename' => 'quiz',
        'module' => $quizmodule->id,
        'section' => 1,
        'visible' => 1,
        'cmidnumber' => '',
        // Core access rules write their own fields straight into the quiz row.
        // The mod form always supplies them; creating a quiz in code does not.
        // 'quizpassword' rather than 'password': quiz_process_options() copies
        // quizpassword over password, so setting password alone is overwritten
        // with null and fails the NOT NULL constraint.
        'quizpassword' => '',
        'subnet' => '',
        'delay1' => 0,
        'delay2' => 0,
        'browsersecurity' => '-',
        'attempts' => 0,
        // The whole point of the demo: proctoring on.
        'kaiproctorenabled' => 1,
    ];
    $moduleinfo = add_moduleinfo($quiz, $course);
    $quiz = $DB->get_record('quiz', ['id' => $moduleinfo->instance]);
    mtrace("  created quiz (id {$quiz->id}, cmid {$moduleinfo->coursemodule})");
}

// add_moduleinfo does not always route through the access rule's save_settings,
// so the flag is written explicitly rather than assumed.
if (!$DB->record_exists('quizaccess_kaiproctor', ['quizid' => $quiz->id])) {
    $DB->insert_record('quizaccess_kaiproctor', (object) ['quizid' => $quiz->id, 'enabled' => 1]);
    mtrace('  proctoring enabled on the quiz');
} else {
    mtrace('  proctoring already enabled on the quiz');
}

$cm = get_coursemodule_from_instance('quiz', $quiz->id);

mtrace('questions:');
if ($DB->record_exists('quiz_slots', ['quizid' => $quiz->id])) {
    mtrace('  quiz already has questions');
} else {
    // Imported through the GIFT importer rather than written straight to the
    // question tables: it is the supported path, and it is the same one a
    // real question bank would arrive through.
    require_once($CFG->dirroot . '/question/format.php');
    require_once($CFG->dirroot . '/question/format/gift/format.php');
    require_once($CFG->dirroot . '/mod/quiz/locallib.php');

    $gift = <<<'GIFT'
    ::ข้อ 1:: ระบบคุมสอบนี้ยืนยันตัวตนด้วยอะไร {
        =ใบหน้า
        ~ลายนิ้วมือ
        ~รหัสผ่านอย่างเดียว
        ~เบอร์โทรศัพท์
    }

    ::ข้อ 2:: ถ้าออกจากหน้าต่างเรียนระหว่างสอบ ระบบจะทำอย่างไร {
        =บันทึกเหตุการณ์ไว้เป็นหลักฐาน
        ~ไม่ทำอะไรเลย
        ~ลบคำตอบทั้งหมด
        ~เพิ่มคะแนนให้
    }

    ::ข้อ 3:: การตรวจ liveness มีไว้เพื่ออะไร {
        =กันการใช้ภาพถ่ายหรือวิดีโอแทนคนจริง
        ~วัดความเร็วอินเทอร์เน็ต
        ~ตรวจสอบไวยากรณ์
        ~นับจำนวนข้อสอบ
    }
    GIFT;

    $tempfile = make_request_directory() . '/questions.gift';
    // The heredoc is indented for readability; the importer is not.
    file_put_contents($tempfile, preg_replace('/^ {4}/m', '', $gift));

    // Since Moodle 5.0 question categories belong to a qbank activity, not to
    // the course context — asking the course context for a default category
    // gets nothing back and everything downstream fails on a null context.
    $bankcm = \core_question\local\bank\question_bank_helper::get_default_open_instance_system_type(
        $course, true
    );
    $bankcontext = context_module::instance($bankcm->id);
    $category = question_get_default_category($bankcontext->id, true);

    $qformat = new qformat_gift();
    $qformat->setCategory($category);
    $qformat->setContexts([$bankcontext]);
    $qformat->setCourse($course);
    $qformat->setFilename($tempfile);
    $qformat->setRealfilename('questions.gift');
    $qformat->setMatchgrades('error');
    $qformat->setCatfromfile(false);
    $qformat->setContextfromfile(false);
    $qformat->setStoponerror(true);
    $qformat->setCattofile(false);
    $qformat->setContexttofile(false);

    if (!$qformat->importpreprocess() || !$qformat->importprocess() || !$qformat->importpostprocess()) {
        mtrace('  !! question import failed');
    } else {
        $questions = $DB->get_records_sql(
            "SELECT q.id
               FROM {question} q
               JOIN {question_versions} qv ON qv.questionid = q.id
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
              WHERE qbe.questioncategoryid = :cat
           ORDER BY q.id",
            ['cat' => $category->id]
        );
        foreach ($questions as $question) {
            quiz_add_quiz_question($question->id, $quiz);
        }
        // Adding slots does not recalculate the total; without this the quiz
        // refuses every attempt with "none of the questions have a grade".
        // quiz_update_sumgrades() looks like the function for this but has
        // been a no-op deprecation shim since 4.2.
        \mod_quiz\quiz_settings::create($quiz->id)
            ->get_grade_calculator()
            ->recompute_quiz_sumgrades();
        mtrace('  added ' . count($questions) . ' questions to the quiz');
    }
}

mtrace('');
mtrace('done. sign in at ' . $CFG->wwwroot);
mtrace('  learner    / Learn!2345');
mtrace('  learner2   / Learn!2345');
mtrace('  instructor / Teach!2345');
mtrace('  quiz: ' . $CFG->wwwroot . '/mod/quiz/view.php?id=' . $cm->id);
