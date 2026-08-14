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

mtrace('service wiring:');
// Where the two side-car services live, and the secret shared with them. These
// were being set by hand, which meant a fresh site looked installed and then
// failed at the first face check with an unhelpful error.
//
// Only filled in when empty, so re-seeding a site somebody has configured
// properly does not overwrite their settings with the development defaults.
$sharedkey = getenv('PROCTOR_API_KEY') ?: 'change-me';
foreach ([
    'faceserviceurl' => 'http://face-service:9000',
    'apikey' => $sharedkey,
    // The reviewer service, which holds the prompts and refuses payloads that
    // carry anything derived from a face. Left switched off; aienabled is the
    // administrator's decision, and off is the shipped default.
    'aibaseurl' => 'http://ai-service:9100',
    'aiapikey' => $sharedkey,
] as $name => $value) {
    if ((string) get_config('local_kaiproctor', $name) === '') {
        set_config($name, $value, 'local_kaiproctor');
        mtrace("  set {$name}");
    }
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
    // When a timed attempt's clock runs out, grade whatever was answered
    // rather than abandoning the whole paper. Moodle's code default is
    // autoabandon, which turns a timing rule into a grading rule: two
    // learners with the same answers score differently because one pressed
    // submit ten seconds earlier. The old system graded on what was
    // answered and recorded timed_out; autosubmit is that behaviour.
    'overduehandling' => 'autosubmit',
]);
mtrace('  review options enabled, timed-out attempts auto-submit');

// add_moduleinfo does not always route through the access rule's save_settings,
// so the flag is written explicitly rather than assumed.
if (!$DB->record_exists('quizaccess_kaiproctor', ['quizid' => $quiz->id])) {
    $DB->insert_record('quizaccess_kaiproctor', (object) ['quizid' => $quiz->id, 'enabled' => 1]);
    mtrace('  proctoring enabled on the quiz');
} else {
    mtrace('  proctoring already enabled on the quiz');
}

$cm = get_coursemodule_from_instance('quiz', $quiz->id);

mtrace('high-stakes quiz (Safe Exam Browser + face proctoring):');
// SEB is the only open-source thing that locks the machine itself. Our
// browser-side lockdown detects and reports; it cannot stop Alt+Tab, a second
// monitor, or a phone. For an exam where that matters, both run together:
// SEB owns the machine, we own the identity and the evidence.
$sebquiz = $DB->get_record('quiz', ['course' => $course->id, 'name' => 'ข้อสอบความเสี่ยงสูง (SEB)']);
if ($sebquiz) {
    mtrace("  quiz already exists (id {$sebquiz->id})");
} else {
    $moduleinfo = add_moduleinfo((object) [
        'course' => $course->id,
        'name' => 'ข้อสอบความเสี่ยงสูง (SEB)',
        'intro' => 'ต้องเปิดด้วย Safe Exam Browser เท่านั้น และยืนยันตัวตนผ่านกล้อง',
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
        'quizpassword' => '',
        'subnet' => '',
        'delay1' => 0,
        'delay2' => 0,
        'browsersecurity' => '-',
        'attempts' => 0,
        'kaiproctorenabled' => 1,
    ], $course);
    $sebquiz = $DB->get_record('quiz', ['id' => $moduleinfo->instance]);
    mtrace("  created quiz (id {$sebquiz->id}, cmid {$moduleinfo->coursemodule})");
}

$DB->update_record('quiz', (object) [
    'id' => $sebquiz->id,
    'reviewattempt' => $reviewall,
    'reviewcorrectness' => $reviewall,
    'reviewmarks' => $reviewall,
    'reviewspecificfeedback' => $reviewall,
    'reviewgeneralfeedback' => $reviewall,
    'reviewrightanswer' => $reviewall,
    'reviewoverallfeedback' => $reviewall,
]);

$sebcm = get_coursemodule_from_instance('quiz', $sebquiz->id);

if (!$DB->record_exists('quizaccess_kaiproctor', ['quizid' => $sebquiz->id])) {
    $DB->insert_record('quizaccess_kaiproctor',
        (object) ['quizid' => $sebquiz->id, 'enabled' => 1]);
    mtrace('  face proctoring enabled');
}

if (\quizaccess_seb\seb_quiz_settings::get_record(['quizid' => $sebquiz->id])) {
    mtrace('  SEB already configured');
} else {
    // USE_SEB_CONFIG_MANUALLY: Moodle builds the .seb file and its Config Key
    // itself. That is the part the earlier prototype approximated by hand and
    // got wrong — core does the real cryptography.
    $sebsettings = new \quizaccess_seb\seb_quiz_settings(0, (object) [
        'quizid' => $sebquiz->id,
        'cmid' => $sebcm->id,
        'requiresafeexambrowser' => \quizaccess_seb\settings_provider::USE_SEB_CONFIG_MANUALLY,
        'showsebtaskbar' => 0,
        'showwificontrol' => 0,
        'showreloadbutton' => 0,
        'showtime' => 1,
        'showkeyboardlayout' => 0,
        'allowuserquitseb' => 1,
        'quitpassword' => '',
        'linkquitseb' => '',
        'userconfirmquit' => 1,
        'enableaudiocontrol' => 0,
        'muteonstartup' => 0,
        'allowspellchecking' => 0,
        'allowreloadinexam' => 0,
        'activateurlfiltering' => 0,
        'filterembeddedcontent' => 0,
        'expressionsallowed' => '',
        'regexallowed' => '',
        'expressionsblocked' => '',
        'regexblocked' => '',
        'allowedbrowserexamkeys' => '',
    ]);
    $sebsettings->save();
    mtrace('  SEB configured (manual config, Moodle generates the .seb file and Config Key)');
}

mtrace('questions:');
{
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

    $existingcount = $DB->count_records_sql(
        "SELECT COUNT(1)
           FROM {question_bank_entries} qbe
          WHERE qbe.questioncategoryid = :cat",
        ['cat' => $category->id]
    );

if ($existingcount > 0) {
    mtrace("  question bank already holds {$existingcount} questions — not importing again");
} else {
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
        mtrace('  imported the question bank');
    }
}

    {
        $questions = $DB->get_records_sql(
            "SELECT q.id
               FROM {question} q
               JOIN {question_versions} qv ON qv.questionid = q.id
               JOIN {question_bank_entries} qbe ON qbe.id = qv.questionbankentryid
              WHERE qbe.questioncategoryid = :cat
           ORDER BY q.id",
            ['cat' => $category->id]
        );
        foreach ([$quiz, $sebquiz] as $target) {
            if ($DB->record_exists('quiz_slots', ['quizid' => $target->id])) {
                continue;
            }
            foreach ($questions as $question) {
                quiz_add_quiz_question($question->id, $target);
            }
            // Adding slots does not recalculate the total; without this the
            // quiz refuses every attempt with "none of the questions have a
            // grade". quiz_update_sumgrades() looks like the function for this
            // but has been a no-op deprecation shim since 4.2.
            \mod_quiz\quiz_settings::create($target->id)
                ->get_grade_calculator()
                ->recompute_quiz_sumgrades();
            mtrace("  added " . count($questions) . " questions to quiz {$target->id}");
        }
    }
}

mtrace('blueprint quiz (random questions by difficulty):');
// Reproducibility only means anything when the paper varies. This quiz draws
// one question per difficulty from a tagged bank, which is the difficulty
// blueprint the original system had, expressed the way Moodle expresses it.
{
    require_once($CFG->dirroot . '/question/format.php');
    require_once($CFG->dirroot . '/question/format/xml/format.php');

    $bankcm = \core_question\local\bank\question_bank_helper::get_default_open_instance_system_type(
        $course, true);
    $bankcontext = context_module::instance($bankcm->id);
    $category = question_get_default_category($bankcontext->id, true);

    $tagged = $DB->count_records_sql(
        "SELECT COUNT(DISTINCT ti.itemid)
           FROM {tag_instance} ti
           JOIN {tag} t ON t.id = ti.tagid
          WHERE ti.itemtype = 'question' AND t.rawname IN ('easy', 'medium', 'hard')");

    if ($tagged >= 6) {
        mtrace("  bank already holds {$tagged} tagged questions");
    } else {
        // Two per difficulty, so a slot has something to choose between.
        $questions = [];
        $index = 0;
        foreach (['easy' => 'ง่าย', 'medium' => 'ปานกลาง', 'hard' => 'ยาก'] as $tag => $label) {
            for ($n = 1; $n <= 2; $n++) {
                $index++;
                $questions[] = [
                    'id' => "blueprint-{$tag}-{$n}",
                    'difficulty' => $tag,
                    'text' => "ข้อสอบระดับ{$label} ข้อที่ {$n}: ระบบคุมสอบบันทึกอะไรไว้เป็นหลักฐาน",
                    'choices' => [
                        "คำตอบถูกของข้อ {$index}",
                        "คำตอบผิดที่หนึ่งของข้อ {$index}",
                        "คำตอบผิดที่สองของข้อ {$index}",
                        "คำตอบผิดที่สามของข้อ {$index}",
                    ],
                    'answer' => 0,
                ];
            }
        }

        $result = \local_kaiproctor\pdf_import::import($questions, $category, $bankcontext, $course);
        mtrace('  imported ' . $result['imported'] . ' tagged questions');
    }

    $blueprintquiz = $DB->get_record('quiz',
        ['course' => $course->id, 'name' => 'ข้อสอบสุ่มตามระดับความยาก']);

    if ($blueprintquiz) {
        mtrace("  quiz already exists (id {$blueprintquiz->id})");
    } else {
        $moduleinfo = add_moduleinfo((object) [
            'course' => $course->id,
            'name' => 'ข้อสอบสุ่มตามระดับความยาก',
            'intro' => 'สุ่มข้อสอบระดับง่าย ปานกลาง และยาก อย่างละหนึ่งข้อ ต่างคนต่างชุด',
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
            'quizpassword' => '',
            'subnet' => '',
            'delay1' => 0,
            'delay2' => 0,
            'browsersecurity' => '-',
            'attempts' => 0,
            'kaiproctorenabled' => 1,
        ], $course);
        $blueprintquiz = $DB->get_record('quiz', ['id' => $moduleinfo->instance]);

        $DB->update_record('quiz', (object) [
            'id' => $blueprintquiz->id,
            'reviewattempt' => $reviewall,
            'reviewcorrectness' => $reviewall,
            'reviewmarks' => $reviewall,
            'reviewspecificfeedback' => $reviewall,
            'reviewgeneralfeedback' => $reviewall,
            'reviewrightanswer' => $reviewall,
            'reviewoverallfeedback' => $reviewall,
        ]);

        if (!$DB->record_exists('quizaccess_kaiproctor', ['quizid' => $blueprintquiz->id])) {
            $DB->insert_record('quizaccess_kaiproctor',
                (object) ['quizid' => $blueprintquiz->id, 'enabled' => 1]);
        }

        // Adding slots is a capability-checked action and a CLI script has no
        // user, so it acts explicitly as the admin.
        \core\session\manager::set_user(get_admin());

        $structure = \mod_quiz\quiz_settings::create($blueprintquiz->id)->get_structure();
        foreach (['easy', 'medium', 'hard'] as $tag) {
            $tagrecord = $DB->get_record('tag', ['rawname' => $tag]);
            $structure->add_random_questions(0, 1, [
                'filter' => [
                    'category' => [
                        'jointype' => 1,
                        'values' => [$category->id],
                        'filteroptions' => ['includesubcategories' => false],
                    ],
                    'qtagids' => [
                        'jointype' => 2,
                        'values' => [$tagrecord->id],
                    ],
                ],
            ]);
        }

        \mod_quiz\quiz_settings::create($blueprintquiz->id)
            ->get_grade_calculator()->recompute_quiz_sumgrades();

        $cmid = get_coursemodule_from_instance('quiz', $blueprintquiz->id)->id;
        mtrace("  created quiz (id {$blueprintquiz->id}, cmid {$cmid}) with 3 random slots");
    }
}

mtrace('');
mtrace('done. sign in at ' . $CFG->wwwroot);
mtrace('  learner    / Learn!2345');
mtrace('  learner2   / Learn!2345');
mtrace('  instructor / Teach!2345');
mtrace('  quiz: ' . $CFG->wwwroot . '/mod/quiz/view.php?id=' . $cm->id);

mtrace('interactive video (ours):');
// A module's lib.php is not autoloaded — the functions in it are found by name
// only after Moodle has included the file for that module.
require_once($CFG->dirroot . '/mod/kaivideo/lib.php');
// A working example of mod_kaivideo, beside the third-party one, so the two can
// be compared without switching anything off.
$kaivideoname = 'วิดีโอแบบมีปฏิสัมพันธ์ (KAISER)';
$existing = $DB->get_record('kaivideo', ['course' => $course->id, 'name' => $kaivideoname]);

if ($existing) {
    mtrace("  already present (id {$existing->id})");
    $kaivideo = $existing;
    $kaivideocm = get_coursemodule_from_instance('kaivideo', $kaivideo->id);
} else {
    $module = $DB->get_record('modules', ['name' => 'kaivideo'], '*', MUST_EXIST);
    $instance = (object) [
        'course' => $course->id,
        'name' => $kaivideoname,
        'intro' => '<p>วิดีโอที่หยุดถามคำถามระหว่างทาง ต้องตอบก่อนจึงจะเล่นต่อ</p>',
        'introformat' => FORMAT_HTML,
        'videourl' => $CFG->wwwroot . '/local/kaiproctor/samples/lesson.mp4',
        'mustanswer' => 1,
        'allowreview' => 1,
        'grade' => 100,
    ];
    $instance->id = kaivideo_add_instance($instance);

    $kaivideocm = (object) [
        'course' => $course->id,
        'module' => $module->id,
        'instance' => $instance->id,
        'section' => 0,
        'visible' => 1,
        'completion' => COMPLETION_TRACKING_NONE,
    ];
    $kaivideocm->coursemodule = add_course_module($kaivideocm);
    course_add_cm_to_section($course->id, $kaivideocm->coursemodule, 0);

    $kaivideo = $DB->get_record('kaivideo', ['id' => $instance->id], '*', MUST_EXIST);
    $kaivideocm = get_coursemodule_from_id('kaivideo', $kaivideocm->coursemodule);
    mtrace("  created cmid {$kaivideocm->id}");
}

// One of each kind, early enough that a test does not have to watch a whole
// video to reach the last one. Seeded rather than left to a test to create,
// because a type that only ever exists inside a test is a type nobody has
// looked at on a real page.
$questions = [
    [
        'attime' => 3,
        'type' => 'choice',
        'questiontext' => 'ระหว่างเรียนบทเรียนที่มีการเฝ้าดู ผู้เรียนต้องทำอย่างไร',
        'choices' => ['อยู่หน้ากล้องตลอดเวลา', 'ปิดกล้องได้ถ้าเสียงยังดังอยู่',
            'สลับไปหน้าอื่นได้ตามต้องการ'],
        'answers' => [0],
        'feedback' => 'ระบบตรวจว่ามีคนอยู่หน้ากล้องเป็นระยะ',
    ],
    [
        'attime' => 8,
        'type' => 'choice',
        'questiontext' => 'ถ้าออกจากหน้าต่างบทเรียน จะเกิดอะไรขึ้น',
        'choices' => ['ไม่เกิดอะไร', 'ระบบบันทึกเหตุการณ์ไว้'],
        'answers' => [1],
        'feedback' => 'ทุกครั้งที่ออกจากหน้าต่างถูกบันทึกเป็นหลักฐาน',
    ],
    [
        'attime' => 13,
        'type' => 'multichoice',
        'questiontext' => 'ระบบบันทึกเหตุการณ์ใดไว้เป็นหลักฐานบ้าง (เลือกได้หลายข้อ)',
        'choices' => ['ออกจากหน้าต่างบทเรียน', 'ไม่พบใบหน้าหน้ากล้อง',
            'สีเสื้อของผู้เรียน', 'เวลาที่เริ่มและจบบทเรียน'],
        'answers' => [0, 1, 3],
        'feedback' => 'สิ่งที่บันทึกคือเหตุการณ์ ไม่ใช่ภาพหรือลักษณะของผู้เรียน',
    ],
    [
        'attime' => 18,
        'type' => 'shorttext',
        'questiontext' => 'ข้อมูลใบหน้าถูกเก็บไว้ในรูปแบบใด (ตอบเป็นคำเดียว)',
        'answers' => ['เวกเตอร์', 'vector', 'embedding'],
        'feedback' => 'เก็บเป็นเวกเตอร์ตัวเลข ไม่ใช่รูปถ่าย',
    ],
    [
        'attime' => 23,
        'type' => 'info',
        'questiontext' => 'ส่วนถัดไปเป็นขั้นตอนการยืนยันตัวตนก่อนเริ่มทำข้อสอบ',
        'feedback' => 'เตรียมบัตรประจำตัวและตรวจว่ามีแสงพอ',
    ],
];

// Per item rather than all-or-nothing. The first version skipped the whole
// block once any item existed, so adding a kind of question to this list left
// every already-seeded environment without it — including the one the tests
// run against, where it looked like the new type was broken.
foreach ($questions as $question) {
    $already = $DB->record_exists_select('kaivideo_item',
        'kaivideoid = :id AND attime = :attime',
        ['id' => $kaivideo->id, 'attime' => $question['attime']]);
    if ($already) {
        mtrace("  already have one at {$question['attime']}s");
        continue;
    }
    \mod_kaivideo\timeline::save((int) $kaivideo->id, $question);
    mtrace("  {$question['type']} at {$question['attime']}s");
}

mtrace('interactive video (YouTube backend):');
// The second backend, seeded so it is exercised rather than assumed. Big Buck
// Bunny, Blender Foundation, Creative Commons — a video we are allowed to point
// at, which matters even in a demo.
$ytname = 'วิดีโอ YouTube (KAISER)';
$ytvideo = $DB->get_record('kaivideo', ['course' => $course->id, 'name' => $ytname]);

if ($ytvideo) {
    $ytcm = get_coursemodule_from_instance('kaivideo', $ytvideo->id);
    mtrace("  already present (cmid {$ytcm->id})");
} else {
    $module = $DB->get_record('modules', ['name' => 'kaivideo'], '*', MUST_EXIST);
    $instance = (object) [
        'course' => $course->id,
        'name' => $ytname,
        'intro' => '<p>วิดีโอจาก YouTube ที่หยุดถามคำถามระหว่างทาง</p>',
        'introformat' => FORMAT_HTML,
        'videourl' => 'https://www.youtube.com/watch?v=aqz-KE-bpKQ',
        'mustanswer' => 1,
        'allowreview' => 1,
        'grade' => 100,
    ];
    $instance->id = kaivideo_add_instance($instance);

    $ytcm = (object) [
        'course' => $course->id,
        'module' => $module->id,
        'instance' => $instance->id,
        'section' => 0,
        'visible' => 1,
        'completion' => COMPLETION_TRACKING_NONE,
    ];
    $ytcm->coursemodule = add_course_module($ytcm);
    course_add_cm_to_section($course->id, $ytcm->coursemodule, 0);

    \mod_kaivideo\timeline::save((int) $instance->id, [
        'attime' => 4,
        'type' => 'choice',
        'questiontext' => 'วิดีโอนี้เล่นผ่านอะไร',
        'choices' => ['YouTube', 'ไฟล์ในเครื่อง'],
        'answers' => [0],
        'feedback' => 'เล่นผ่าน iframe ของ YouTube ด้วย IFrame API',
    ]);
    mtrace("  created cmid {$ytcm->coursemodule}");
}

mtrace('interactive video (Vimeo and HLS):');
// The other two backends. Both point at material we are allowed to point at:
// Blender's open movies on Vimeo, and Unified Streaming's public demo stream,
// which exists to be linked in exactly this way.
//
// Neither is reachable from a sealed network, and the tests that drive them
// skip rather than fail when that is the case. A test that lies about the
// environment is worse than one that says it could not run.
$streams = [
    [
        'name' => 'วิดีโอ Vimeo (KAISER)',
        // Big Buck Bunny on Blender's own Vimeo account: Creative Commons, and
        // the same film the YouTube activity points at, so the two are
        // comparable. Vimeo's long-standing demo id (76979871) is gone — its
        // oEmbed lookup 404s, and the player then fails in a way that reads
        // like our code rather than like a dead video.
        //
        // It will not actually embed on http://localhost:8080: Vimeo answers
        // an embed request from an unrecognised host with a 401 behind a
        // Cloudflare challenge, whatever the video's own settings say. That is
        // worth having seeded anyway — it is exactly the failure a customer
        // meets when their domain is not on a video's allowed list, and the
        // test drives that path rather than pretending it did not happen.
        'url' => 'https://vimeo.com/1084537',
        'intro' => '<p>วิดีโอจาก Vimeo ที่หยุดถามคำถามระหว่างทาง</p>',
        'question' => 'วิดีโอนี้เล่นผ่านผู้ให้บริการรายใด',
        'choices' => ['Vimeo', 'YouTube'],
    ],
    [
        'name' => 'วิดีโอสตรีม HLS (KAISER)',
        'url' => 'https://demo.unified-streaming.com/k8s/features/stable/'
            . 'video/tears-of-steel/tears-of-steel.ism/.m3u8',
        'intro' => '<p>สตรีมแบบ HLS ที่เล่นใน video element ปกติ</p>',
        'question' => 'สตรีมนี้เล่นด้วยอะไร',
        'choices' => ['video element ปกติ', 'iframe ของผู้ให้บริการ'],
    ],
];

foreach ($streams as $stream) {
    $existing = $DB->get_record('kaivideo',
        ['course' => $course->id, 'name' => $stream['name']]);
    if ($existing) {
        $streamcm = get_coursemodule_from_instance('kaivideo', $existing->id);
        mtrace("  {$stream['name']}: already present (cmid {$streamcm->id})");
        continue;
    }

    $module = $DB->get_record('modules', ['name' => 'kaivideo'], '*', MUST_EXIST);
    $instance = (object) [
        'course' => $course->id,
        'name' => $stream['name'],
        'intro' => $stream['intro'],
        'introformat' => FORMAT_HTML,
        'videourl' => $stream['url'],
        'mustanswer' => 1,
        'allowreview' => 1,
        'grade' => 100,
    ];
    $instance->id = kaivideo_add_instance($instance);

    $streamcm = (object) [
        'course' => $course->id,
        'module' => $module->id,
        'instance' => $instance->id,
        'section' => 0,
        'visible' => 1,
        'completion' => COMPLETION_TRACKING_NONE,
    ];
    $streamcm->coursemodule = add_course_module($streamcm);
    course_add_cm_to_section($course->id, $streamcm->coursemodule, 0);

    \mod_kaivideo\timeline::save((int) $instance->id, [
        'attime' => 4,
        'type' => 'choice',
        'questiontext' => $stream['question'],
        'choices' => $stream['choices'],
        'answers' => [0],
        'feedback' => 'กฎเรื่องคำถามถึงกำหนดเหมือนกันทุกที่มา',
    ]);
    mtrace("  {$stream['name']}: created cmid {$streamcm->coursemodule}");
}

mtrace('interactive video (uploaded file):');
// The third source. Seeded because "the video lives in Moodle" is the option
// most teachers can actually take, and an option that only ever exists in a
// form is one nobody has watched play.
$upname = 'วิดีโอที่อัปโหลดไว้ใน Moodle (KAISER)';
$uploaded = $DB->get_record('kaivideo', ['course' => $course->id, 'name' => $upname]);

// Find-or-create, then make sure the file and the question are there either
// way. The all-or-nothing version left a half-built activity behind the first
// time this block threw part way down — present, so skipped ever after, with
// no video in it and no question on its timeline.
if ($uploaded) {
    $upcm = get_coursemodule_from_instance('kaivideo', $uploaded->id);
    mtrace("  already present (cmid {$upcm->id})");
    $upcm->coursemodule = $upcm->id;
    $instance = $uploaded;
} else {
    $module = $DB->get_record('modules', ['name' => 'kaivideo'], '*', MUST_EXIST);
    $instance = (object) [
        'course' => $course->id,
        'name' => $upname,
        'intro' => '<p>วิดีโอที่เก็บไว้ใน Moodle เอง ไม่ได้ชี้ไปที่ที่อยู่ภายนอก</p>',
        'introformat' => FORMAT_HTML,
        'videourl' => '',
        'mustanswer' => 1,
        'allowreview' => 1,
        'grade' => 100,
    ];
    $instance->id = kaivideo_add_instance($instance);

    $upcm = (object) [
        'course' => $course->id,
        'module' => $module->id,
        'instance' => $instance->id,
        'section' => 0,
        'visible' => 1,
        'completion' => COMPLETION_TRACKING_NONE,
    ];
    $upcm->coursemodule = add_course_module($upcm);
    course_add_cm_to_section($course->id, $upcm->coursemodule, 0);

    mtrace("  created cmid {$upcm->coursemodule}");
}

// Straight into the file area from disk, which is what the form's file picker
// ends up doing. Same sample the URL-backed activity points at, so the two are
// comparable: the only difference is where it is served from.
$upcontext = context_module::instance($upcm->coursemodule);
if (\mod_kaivideo\source::stored_file($upcontext->id)) {
    mtrace('  video already in its file area');
} else {
    get_file_storage()->create_file_from_pathname((object) [
        'contextid' => $upcontext->id,
        'component' => 'mod_kaivideo',
        'filearea' => \mod_kaivideo\source::AREA,
        'itemid' => 0,
        'filepath' => '/',
        'filename' => 'lesson.mp4',
    ], $CFG->dirroot . '/local/kaiproctor/samples/lesson.mp4');
    mtrace('  lesson.mp4 copied into its file area');
}

if ($DB->record_exists('kaivideo_item', ['kaivideoid' => $instance->id])) {
    mtrace('  question already on its timeline');
} else {
    \mod_kaivideo\timeline::save((int) $instance->id, [
        'attime' => 3,
        'type' => 'choice',
        'questiontext' => 'วิดีโอนี้ถูกเก็บไว้ที่ไหน',
        'choices' => ['ใน Moodle นี้เอง', 'บนเว็บภายนอก'],
        'answers' => [0],
        'feedback' => 'ไฟล์อยู่ในระบบไฟล์ของ Moodle และส่งผ่าน pluginfile.php',
    ]);
    mtrace('  question at 3s');
}

mtrace('interactive video (monitored):');
// A separate activity for the proctoring tests, rather than switching
// monitoring on and off on the one everybody else uses. A camera prompt and an
// overlay appearing mid-question would break the interactive-video tests for
// reasons that have nothing to do with what they check.
$watchedname = 'บทเรียนวิดีโอที่มีการเฝ้าดู';
$watched = $DB->get_record('kaivideo', ['course' => $course->id, 'name' => $watchedname]);

if ($watched) {
    $watchedcm = get_coursemodule_from_instance('kaivideo', $watched->id);
    mtrace("  already present (cmid {$watchedcm->id})");
} else {
    $module = $DB->get_record('modules', ['name' => 'kaivideo'], '*', MUST_EXIST);
    $instance = (object) [
        'course' => $course->id,
        'name' => $watchedname,
        'intro' => '<p>บทเรียนวิดีโอที่ระบบเฝ้าดูตลอดการเรียน</p>',
        'introformat' => FORMAT_HTML,
        'videourl' => $CFG->wwwroot . '/local/kaiproctor/samples/lesson.mp4',
        // No questions on this one: what it exists to exercise is the
        // monitoring, and a question panel opening over an overlay is a
        // different test.
        'mustanswer' => 0,
        'allowreview' => 1,
        'grade' => 0,
    ];
    $instance->id = kaivideo_add_instance($instance);

    $watchedcm = (object) [
        'course' => $course->id,
        'module' => $module->id,
        'instance' => $instance->id,
        'section' => 0,
        'visible' => 1,
        'completion' => COMPLETION_TRACKING_NONE,
    ];
    $watchedcm->coursemodule = add_course_module($watchedcm);
    course_add_cm_to_section($course->id, $watchedcm->coursemodule, 0);
    $watchedcm = get_coursemodule_from_id('kaivideo', $watchedcm->coursemodule);
    mtrace("  created cmid {$watchedcm->id}");
}

\local_kaiproctor\monitored::set((int) $watchedcm->id, true);
mtrace('  monitoring is on for it');

mtrace('tidying the course front page:');
// All six video activities exist because each is the only cover for one video
// source, so none can go without losing a test. What they should not do is sit
// in the section a customer is shown first — "interactive video" six times
// reads as an unfinished course rather than as a feature list.
$demosection = 'ตัวอย่างที่มาของวิดีโอ (สำหรับทดสอบระบบ)';
$section = $DB->get_record('course_sections',
    ['course' => $course->id, 'name' => $demosection]);

if (!$section) {
    $section = course_create_section($course->id);
    $DB->set_field('course_sections', 'name', $demosection, ['id' => $section->id]);
    $DB->set_field('course_sections', 'summary',
        '<p>แต่ละอันสาธิตที่มาของวิดีโอคนละแบบ และมีเทสต์อัตโนมัติผูกอยู่ '
        . 'ห้ามลบ — ถ้าลบ เทสต์ของที่มานั้นจะไม่มีอะไรให้ตรวจ</p>',
        ['id' => $section->id]);
    mtrace("  created section: {$demosection}");
}

// The two a customer is meant to look at stay where they are.
$keepinplace = ['วิดีโอแบบมีปฏิสัมพันธ์ (KAISER)', 'บทเรียนวิดีโอที่มีการเฝ้าดู'];
$moved = 0;
foreach ($DB->get_records('kaivideo', ['course' => $course->id]) as $video) {
    if (in_array($video->name, $keepinplace, true)) {
        continue;
    }
    $cm = get_coursemodule_from_instance('kaivideo', $video->id);
    if ($cm && (int) $cm->section !== (int) $section->id) {
        moveto_module($cm, $section);
        $moved++;
    }
}
rebuild_course_cache($course->id, true);
mtrace("  {$moved} backend demo(s) moved out of the front section");
