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

mtrace('consent policy:');
// PDPA section 26 requires explicit consent before biometric data is collected.
// tool_policy is Moodle's own mechanism for that — versioned, timestamped, and
// wired into the Privacy API — so the plugin does not carry a consent table of
// its own. Switching the site policy handler over is what makes it enforced.
set_config('sitepolicyhandler', 'tool_policy');

$policyname = 'ความยินยอมการเก็บข้อมูลชีวมิติ (PDPA)';
$existingpolicy = false;
foreach (\tool_policy\api::list_policies() as $policy) {
    // The name lives on the version, not on the policy. Comparing $policy->name
    // silently never matched, so every re-run published another copy of the
    // same policy and learners were asked to consent twice.
    foreach ([$policy->currentversion ?? null, ...array_values($policy->draftversions ?? [])] as $version) {
        if ($version && $version->name === $policyname) {
            $existingpolicy = true;
            break 2;
        }
    }
}

if ($existingpolicy) {
    mtrace('  policy already exists');
} else {
    // api::form_policydoc_add() takes the shape the policy form submits, so
    // summary and content arrive as editor arrays, not plain strings.
    $policydoc = (object) [
        'name' => $policyname,
        'type' => \tool_policy\policy_version::TYPE_PRIVACY,
        'audience' => \tool_policy\policy_version::AUDIENCE_LOGGEDIN,
        'agreementstyle' => \tool_policy\policy_version::AGREEMENTSTYLE_CONSENTPAGE,
        'optional' => \tool_policy\policy_version::AGREEMENT_COMPULSORY,
        'revision' => '1.0',
        'summary_editor' => [
            'text' => 'ระบบเก็บภาพใบหน้าและค่าที่แทนใบหน้าของท่าน เพื่อยืนยันว่าผู้เรียนคือผู้ถือใบอนุญาตจริง',
            'format' => FORMAT_HTML,
            'itemid' => 0,
        ],
        'content_editor' => [
            'format' => FORMAT_HTML,
            'itemid' => 0,
            'text' => '<p>เพื่อให้การอบรมนี้ใช้เป็นหลักฐานได้ ระบบจำเป็นต้องเก็บ:</p>
            <ul>
                <li>ค่าที่แทนใบหน้าของท่าน (ไม่เก็บภาพถ่ายต้นฉบับ)</li>
                <li>ภาพนิ่งและคลิปสั้นระหว่างการเรียนและการสอบ</li>
                <li>ผลการตรวจตัวตนแต่ละครั้ง พร้อมเกณฑ์ที่ใช้ตัดสิน</li>
                <li>บันทึกเหตุการณ์ระหว่างเรียน เช่น การออกจากหน้าต่างเรียน</li>
            </ul>
            <p>ข้อมูลชีวมิติเป็นข้อมูลส่วนบุคคลอ่อนไหวตาม พ.ร.บ. คุ้มครองข้อมูลส่วนบุคคล
            มาตรา 26 ท่านมีสิทธิขอเข้าถึง ขอสำเนา และขอให้ลบข้อมูลของท่านได้ตลอดเวลา
            ผ่านเมนูคำขอเกี่ยวกับข้อมูลส่วนบุคคล</p>
            <p>หากท่านไม่ให้ความยินยอม ระบบจะไม่สามารถยืนยันตัวตนได้
            จึงไม่สามารถออกหลักฐานการอบรมให้ได้</p>',
        ],
    ];
    $version = \tool_policy\api::form_policydoc_add($policydoc);
    \tool_policy\api::make_current($version->get('id'));
    mtrace('  created and published the PDPA consent policy');
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

mtrace('capabilities:');
// Viewing biometric evidence is manager-only by default, deliberately: it is
// not something every teacher on a site should see. For this course the
// teacher running the exam does need it, so it is granted explicitly here
// rather than by loosening the plugin's default.
if (!$DB->record_exists('role_capabilities', [
        'roleid' => $teacherrole->id,
        'capability' => 'local/kaiproctor:viewevidence',
        'contextid' => $coursecontext->id])) {
    assign_capability('local/kaiproctor:viewevidence', CAP_ALLOW,
        $teacherrole->id, $coursecontext->id, true);
    $coursecontext->mark_dirty();
    mtrace('  granted local/kaiproctor:viewevidence to the teacher in this course');
} else {
    mtrace('  teacher already has local/kaiproctor:viewevidence here');
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

// Review options. Creating a quiz through the mod form fills these in from
// defaults; creating one in code leaves every one at zero, which shows the
// learner "Review not permitted" and hides the grade they just earned.
// 0x11110 is Moodle's "during, immediately after, later while open, after
// close" — the whole point of a training quiz is that people see their result.
$reviewall = 0x10000 | 0x01000 | 0x00100 | 0x00010;
$DB->update_record('quiz', (object) [
    'id' => $quiz->id,
    'reviewattempt' => $reviewall,
    'reviewcorrectness' => $reviewall,
    'reviewmarks' => $reviewall,
    'reviewspecificfeedback' => $reviewall,
    'reviewgeneralfeedback' => $reviewall,
    'reviewrightanswer' => $reviewall,
    'reviewoverallfeedback' => $reviewall,
]);
mtrace('  review options enabled');

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
