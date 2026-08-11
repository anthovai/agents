<?php
// Test-support CLI: lets the Playwright suite read and reset proctoring state
// without reaching into the database from Python.
//
// Copied into the Moodle container by conftest.py and run there. It lives
// above the web root, so it is not reachable over HTTP.

define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
// A CLI script gets none of a web request's incidental includes, and \curl is
// defined in filelib rather than somewhere the class autoloader will find.
require_once($CFG->libdir . '/filelib.php');

global $DB;

$command = $argv[1] ?? 'help';

/**
 * @param string $username
 * @return stdClass
 */
function kp_user(string $username): stdClass {
    global $DB, $CFG;
    return $DB->get_record('user',
        ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id], '*', MUST_EXIST);
}

switch ($command) {
    case 'health':
        // Everything the suite asserts about the stack before it starts.
        require_once($CFG->libdir . '/filelib.php');
        $curl = new curl(['ignoresecurity' => true]);
        $curl->setHeader('X-Proctor-Key: ' . get_config('local_kaiproctor', 'apikey'));
        $face = json_decode($curl->get(
            rtrim(get_config('local_kaiproctor', 'faceserviceurl'), '/') . '/health'), true);

        echo json_encode([
            'moodle_release' => $CFG->release,
            'local_version' => get_config('local_kaiproctor', 'version'),
            'quizaccess_version' => get_config('quizaccess_kaiproctor', 'version'),
            'faceservice_ok' => $face['ok'] ?? false,
            'faceservice_models' => $face['models_present'] ?? [],
            'liveness_available' => $face['liveness_available'] ?? false,
            'match_threshold' => $face['thresholds']['match'] ?? null,
            'webservices' => array_values($DB->get_fieldset_select('external_functions',
                'name', $DB->sql_like('name', ':p'), ['p' => 'local_kaiproctor%'])),
            'sitepolicyhandler' => get_config('core', 'sitepolicyhandler'),
            'policies' => count(\tool_policy\api::list_policies()),
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        break;

    case 'events':
        // Audit trail for one learner, newest last — the shape face-re's
        // eventlog artefacts had, so the two are comparable.
        $user = kp_user($argv[2]);
        $rows = $DB->get_records_select('logstore_standard_log',
            'userid = :userid AND ' . $DB->sql_like('eventname', ':pattern'),
            ['userid' => $user->id, 'pattern' => '%kaiproctor%'], 'id ASC');
        foreach ($rows as $row) {
            $other = json_decode($row->other, true) ?: [];
            printf("%s  ctx=%-4d %-22s videotime=%-5s %s\n",
                date('H:i:s', $row->timecreated),
                $row->contextid,
                $other['type'] ?? '?',
                var_export($other['videotime'] ?? null, true),
                json_encode($other['detail'] ?? [], JSON_UNESCAPED_UNICODE));
        }
        if (!$rows) {
            echo "(no proctoring events for {$argv[2]})\n";
        }
        break;

    case 'checks':
        $user = kp_user($argv[2]);
        $rows = $DB->get_records('local_kaiproctor_check', ['userid' => $user->id], 'id ASC');
        foreach ($rows as $row) {
            printf("%s  ctx=%-4d %-10s %-14s sim=%-8s live=%-8s threshold=%s\n",
                date('H:i:s', $row->timecreated), $row->contextid, $row->kind, $row->decision,
                var_export($row->similarity, true), var_export($row->livenessscore, true),
                var_export($row->threshold, true));
        }
        if (!$rows) {
            echo "(no checks for {$argv[2]})\n";
        }
        break;

    case 'evidence':
        $user = kp_user($argv[2]);
        $rows = $DB->get_records('local_kaiproctor_evidence', ['userid' => $user->id], 'id ASC');
        foreach ($rows as $row) {
            printf("%s  ctx=%-4d %-9s %-28s %s\n", date('H:i:s', $row->timecreated),
                $row->contextid, $row->kind, $row->reason, $row->filename);
        }
        if (!$rows) {
            echo "(no evidence for {$argv[2]})\n";
        }
        break;

    case 'count':
        // count <table> <username>
        $user = kp_user($argv[3]);
        echo $DB->count_records('local_kaiproctor_' . $argv[2], ['userid' => $user->id]) . "\n";
        break;

    case 'reset':
        // Clear one learner's proctoring state so tests do not inherit each
        // other's rows. Deliberately does not touch other users.
        $user = kp_user($argv[2]);
        $DB->delete_records('local_kaiproctor_check', ['userid' => $user->id]);
        foreach ($DB->get_records('local_kaiproctor_evidence', ['userid' => $user->id]) as $item) {
            $file = get_file_storage()->get_file($item->contextid, 'local_kaiproctor',
                'evidence', $item->itemid, '/', $item->filename);
            if ($file) {
                $file->delete();
            }
        }
        $DB->delete_records('local_kaiproctor_evidence', ['userid' => $user->id]);
        $DB->delete_records('local_kaiproctor_face', ['userid' => $user->id]);
        $DB->delete_records('local_kaiproctor_session', ['userid' => $user->id]);
        $DB->delete_records_select('logstore_standard_log',
            'userid = :userid AND ' . $DB->sql_like('eventname', ':pattern'),
            ['userid' => $user->id, 'pattern' => '%kaiproctor%']);
        echo "reset {$argv[2]}\n";
        break;

    case 'seed-enrolment':
        // A stand-in embedding so gate behaviour can be exercised without a
        // camera. It would never survive a real comparison, and must not be
        // mistaken for a genuine enrolment.
        $user = kp_user($argv[2]);
        $vector = pack('f*', ...array_fill(0, 128, 0.0883883476));
        \local_kaiproctor\enrolment::store($user->id, base64_encode($vector), 128,
            'yunet+sface', ['seeded' => true, 'note' => 'test stand-in, not a real face']);
        echo "seeded enrolment for {$argv[2]}\n";
        break;

    case 'seed-pass':
        // seed-pass <username> <cmid> — what verify_frame writes after a
        // successful camera check.
        $user = kp_user($argv[2]);
        $cm = get_coursemodule_from_id('quiz', (int) $argv[3], 0, false, MUST_EXIST);
        $context = context_module::instance($cm->id);
        \local_kaiproctor\checks::record($user->id, $context, 'identity', 'pass',
            0.7123, 0.9412, 'yunet+sface');
        echo "seeded passing check for {$argv[2]} in context {$context->id}\n";
        break;

    case 'context-id':
        // context-id <cmid>
        $cm = get_coursemodule_from_id('quiz', (int) $argv[2], 0, false, MUST_EXIST);
        echo context_module::instance($cm->id)->id . "\n";
        break;

    case 'user-context-id':
        echo context_user::instance(kp_user($argv[2])->id)->id . "\n";
        break;

    case 'user-id':
        echo kp_user($argv[2])->id . "\n";
        break;

    case 'purge-attempts':
        // Let a learner start the proctored quiz again from a clean slate.
        $user = kp_user($argv[2]);
        $DB->delete_records('quiz_attempts', ['userid' => $user->id]);
        echo "purged quiz attempts for {$argv[2]}\n";
        break;

    case 'sessions':
        // sessions <username> — newest first, with the policy snapshot parsed.
        $user = kp_user($argv[2]);
        $out = [];
        foreach ($DB->get_records('local_kaiproctor_session',
                ['userid' => $user->id], 'id DESC') as $record) {
            $out[] = [
                'id' => (int) $record->id,
                'status' => $record->status,
                'reason' => $record->reason,
                'attemptid' => $record->attemptid ? (int) $record->attemptid : null,
                'timestart' => (int) $record->timestart,
                'timeend' => $record->timeend ? (int) $record->timeend : null,
                'policy' => json_decode($record->policy, true),
            ];
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        echo "\n";
        break;

    case 'current-policy':
        echo json_encode(\local_kaiproctor\session::current_policy(), JSON_UNESCAPED_UNICODE);
        echo "\n";
        break;

    case 'set-setting':
        // set-setting <name> <value>
        set_config($argv[2], $argv[3], 'local_kaiproctor');
        echo "set {$argv[2]}={$argv[3]}\n";
        break;

    case 'age-session':
        // age-session <username> <hours> — backdate the last sign of life so
        // the cleanup task sees the sitting as abandoned.
        $user = kp_user($argv[2]);
        $when = time() - ((int) $argv[3] * HOURSECS);
        $DB->set_field('local_kaiproctor_session', 'timemodified', $when,
            ['userid' => $user->id, 'status' => 'active']);
        echo "backdated by {$argv[3]}h\n";
        break;

    case 'run-stale-task':
        echo 'closed ' . \local_kaiproctor\session::close_stale() . " abandoned sessions\n";
        break;

    case 'unfiled':
        // How many checks and evidence rows have no sitting attached.
        $user = kp_user($argv[2]);
        $checks = $DB->count_records_select('local_kaiproctor_check',
            'userid = :userid AND sessionid IS NULL', ['userid' => $user->id]);
        $evidence = $DB->count_records_select('local_kaiproctor_evidence',
            'userid = :userid AND sessionid IS NULL', ['userid' => $user->id]);
        echo ($checks + $evidence) . "\n";
        break;

    case 'filed-under':
        // filed-under <username> <sessionid>
        $user = kp_user($argv[2]);
        $conditions = ['userid' => $user->id, 'sessionid' => (int) $argv[3]];
        echo ($DB->count_records('local_kaiproctor_check', $conditions)
            + $DB->count_records('local_kaiproctor_evidence', $conditions)) . "\n";
        break;

    case 'parse-pdf':
        \core\session\manager::set_user(get_admin());
        echo json_encode(\local_kaiproctor\pdf_import::parse(
            file_get_contents('/tmp/sample.pdf')), JSON_UNESCAPED_UNICODE);
        echo "\n";
        break;

    case 'parse-pdf-counts':
        \core\session\manager::set_user(get_admin());
        $parsed = \local_kaiproctor\pdf_import::parse(file_get_contents('/tmp/sample.pdf'));
        echo json_encode(\local_kaiproctor\pdf_import::difficulty_counts(
            $parsed['questions'] ?? []), JSON_UNESCAPED_UNICODE);
        echo "\n";
        break;

    case 'parse-pdf-garbage':
        \core\session\manager::set_user(get_admin());
        echo json_encode(\local_kaiproctor\pdf_import::parse(
            'this is not a pdf at all'), JSON_UNESCAPED_UNICODE);
        echo "\n";
        break;

    case 'import-pdf':
        \core\session\manager::set_user(get_admin());
        $course = $DB->get_record('course', ['shortname' => 'KP-DEMO'], '*', MUST_EXIST);
        $parsed = \local_kaiproctor\pdf_import::parse(file_get_contents('/tmp/sample.pdf'));
        $bankcm = \core_question\local\bank\question_bank_helper::get_default_open_instance_system_type(
            $course, true);
        $bankcontext = context_module::instance($bankcm->id);
        $category = question_get_default_category($bankcontext->id, true);
        echo json_encode(\local_kaiproctor\pdf_import::import(
            $parsed['questions'] ?? [], $category, $bankcontext, $course),
            JSON_UNESCAPED_UNICODE);
        echo "\n";
        break;

    case 'bank-state':
        $tags = [];
        foreach ($DB->get_records_sql(
                "SELECT t.rawname, COUNT(1) AS total
                   FROM {tag} t
                   JOIN {tag_instance} ti ON ti.tagid = t.id AND ti.itemtype = :itemtype
               GROUP BY t.rawname", ['itemtype' => 'question']) as $row) {
            $tags[$row->rawname] = (int) $row->total;
        }
        echo json_encode([
            'entries' => $DB->count_records('question_bank_entries'),
            'tags' => $tags,
        ], JSON_UNESCAPED_UNICODE);
        echo "\n";
        break;

    case 'get-setting':
        echo (string) get_config('local_kaiproctor', $argv[2]) . "\n";
        break;

    case 'seed-evidence':
        // A snapshot for a learner, so retention behaviour can be exercised
        // without driving a camera.
        $user = kp_user($argv[2]);
        $image = imagecreatetruecolor(64, 64);
        ob_start();
        imagejpeg($image);
        $bytes = ob_get_clean();
        \local_kaiproctor\evidence::store($user->id, context_user::instance($user->id),
            'snapshot', 'seeded_for_test', $bytes);
        echo "seeded evidence for {$argv[2]}\n";
        break;

    case 'draw-probe':
        // draw-probe <cmid> <username> <attemptnumber>
        //
        // Starts an attempt with the seed the rule would use, reads the paper,
        // then deletes it again. The deletion is what makes the reproducibility
        // check possible at all: the same attempt number has to be drawable
        // twice.
        require_once($CFG->dirroot . '/mod/quiz/locallib.php');
        $cm = get_coursemodule_from_id('quiz', (int) $argv[2], 0, false, MUST_EXIST);
        $quizobj = \mod_quiz\quiz_settings::create_for_cmid($cm->id);
        $user = kp_user($argv[3]);
        $attemptnumber = (int) $argv[4];

        \core\session\manager::set_user($user);

        // Any attempt already sitting on that number would collide; a probe
        // has to start from nothing to be able to redraw the same one.
        $existing = $DB->get_records('quiz_attempts', [
            'quiz' => $quizobj->get_quizid(),
            'userid' => $user->id,
            'attempt' => $attemptnumber,
        ]);
        foreach ($existing as $old) {
            $DB->delete_records('local_kaiproctor_draw', ['attemptid' => $old->id]);
            $DB->delete_records('quiz_attempts', ['id' => $old->id]);
        }

        $seed = \local_kaiproctor\exam_draw::seed_for(
            $user->id, $quizobj->get_quizid(), $attemptnumber);
        \local_kaiproctor\exam_draw::apply_seed($seed);

        $attempt = quiz_prepare_and_start_new_attempt($quizobj, $attemptnumber, null);
        $questionids = \local_kaiproctor\exam_draw::questions_in_attempt($attempt);
        $DB->delete_records('quiz_attempts', ['id' => $attempt->id]);

        echo json_encode(['seed' => $seed, 'questionids' => $questionids]);
        echo "\n";
        break;

    case 'draw-record':
        // draw-record <username> — the most recent recorded draw.
        $user = kp_user($argv[2]);
        $record = $DB->get_record_sql(
            'SELECT * FROM {local_kaiproctor_draw} WHERE userid = :userid ORDER BY id DESC',
            ['userid' => $user->id], IGNORE_MULTIPLE);
        if (!$record) {
            echo "null\n";
            break;
        }
        echo json_encode(\local_kaiproctor\exam_draw::describe((int) $record->attemptid),
            JSON_UNESCAPED_UNICODE);
        echo "\n";
        break;

    case 'tamper-seed':
        // Rewrite a stored seed to something nobody's identifiers produce, so
        // the report's recalculation has something to catch.
        $user = kp_user($argv[2]);
        $record = $DB->get_record_sql(
            'SELECT * FROM {local_kaiproctor_draw} WHERE userid = :userid ORDER BY id DESC',
            ['userid' => $user->id], IGNORE_MULTIPLE);
        $DB->set_field('local_kaiproctor_draw', 'seed', 424242, ['id' => $record->id]);
        echo "tampered\n";
        break;

    case 'ai-configured':
        // Whether a model is reachable, asked directly of the service rather
        // than through the plugin's on/off switch: a test that needs a model
        // should skip when there is no model, not when a setting is off.
        $base = rtrim((string) get_config('local_kaiproctor', 'aibaseurl'), '/')
            ?: 'http://ai-service:9100';
        $curl = new \curl(['ignoresecurity' => true]);
        $health = json_decode($curl->get($base . '/health', [],
            ['CURLOPT_TIMEOUT' => 10, 'CURLOPT_CONNECTTIMEOUT' => 5]), true);
        echo !empty($health['backend_reachable']) ? "yes\n" : "no\n";
        break;

    case 'ai-state':
        echo json_encode([
            'enabled' => (string) get_config('local_kaiproctor', 'aienabled'),
            'baseurl' => (string) get_config('local_kaiproctor', 'aibaseurl'),
            // What the plugin ships with, not what this environment has been
            // set to: the guarantee is that it arrives switched off.
            'defaultenabled' => '0',
        ], JSON_UNESCAPED_UNICODE);
        echo "\n";
        break;

    case 'ai-health':
        echo json_encode(\local_kaiproctor\ai_client::health(), JSON_UNESCAPED_UNICODE);
        echo "\n";
        break;

    case 'ai-payload':
        // Exactly what would be handed to the service for this learner's most
        // recent sitting — the thing the boundary test inspects.
        $user = kp_user($argv[2]);
        $session = $DB->get_record_sql(
            'SELECT * FROM {local_kaiproctor_session} WHERE userid = :userid ORDER BY id DESC',
            ['userid' => $user->id], IGNORE_MULTIPLE);
        echo json_encode(\local_kaiproctor\ai_reviewer::gather((int) $session->id),
            JSON_UNESCAPED_UNICODE);
        echo "\n";
        break;

    case 'ai-summarise-latest':
        $user = kp_user($argv[2]);
        $session = $DB->get_record_sql(
            'SELECT * FROM {local_kaiproctor_session} WHERE userid = :userid ORDER BY id DESC',
            ['userid' => $user->id], IGNORE_MULTIPLE);
        echo json_encode(\local_kaiproctor\ai_reviewer::summarise((int) $session->id),
            JSON_UNESCAPED_UNICODE);
        echo "\n";
        break;

    case 'ai-strings':
        echo json_encode([
            'note' => get_string('ai:summarynote', 'local_kaiproctor'),
            'title' => get_string('ai:summarytitle', 'local_kaiproctor'),
        ], JSON_UNESCAPED_UNICODE);
        echo "\n";
        break;

    case 'ai-prompt':
        // The instructions now live in the service, which publishes them so an
        // auditor can read the guardrails without being handed the source —
        // and so this test can read them from outside the process that uses
        // them, which is the only place the check is worth anything.
        $base = rtrim((string) get_config('local_kaiproctor', 'aibaseurl'), '/');
        $curl = new \curl(['ignoresecurity' => true]);
        $body = json_decode($curl->get($base . '/prompts', [],
            ['CURLOPT_TIMEOUT' => 10, 'CURLOPT_CONNECTTIMEOUT' => 5]), true);
        echo implode("\n\n", $body['prompts'] ?? []);
        echo "\n";
        break;

    case 'seed-private-course':
        // seed-private-course <username> — a course only this user is in.
        //
        // The assistant must never reveal that a course exists to somebody who
        // cannot open it, and that cannot be tested on a site where everybody
        // is enrolled in everything.
        require_once($CFG->dirroot . '/course/lib.php');
        require_once($CFG->dirroot . '/lib/enrollib.php');
        $user = kp_user($argv[2]);
        $shortname = 'KP-PRIVATE-' . $user->id;

        $course = $DB->get_record('course', ['shortname' => $shortname]);
        if (!$course) {
            $category = $DB->get_record('course_categories', [], '*', IGNORE_MULTIPLE);
            $course = create_course((object) [
                'fullname' => 'คอร์สลับเฉพาะบุคคล',
                'shortname' => $shortname,
                'category' => $category->id,
                'format' => 'topics',
                'visible' => 1,
            ]);
        }

        $manual = enrol_get_plugin('manual');
        $instance = $DB->get_record('enrol',
            ['courseid' => $course->id, 'enrol' => 'manual'], '*', IGNORE_MULTIPLE);
        $studentrole = $DB->get_record('role', ['shortname' => 'student']);
        $manual->enrol_user($instance, $user->id, $studentrole->id);

        echo json_encode(['courseid' => $course->id,
            'fullname' => $course->fullname], JSON_UNESCAPED_UNICODE);
        echo "\n";
        break;

    case 'ask-score':
        // How the shipped MIN_SCORE performs against the labelled set, so a
        // change to the scoring that quietly breaks retrieval fails a test
        // rather than being noticed by a learner. The sweep that chose the
        // number lives in calibrate-ask.php; this only reports the number in
        // force.
        $user = kp_user($argv[2]);
        $fixtures = json_decode(file_get_contents(__DIR__ . '/ask-questions.json'), true);
        $index = \local_kaiproctor\site_index::for_user((int) $user->id);
        $size = \local_kaiproctor\assistant::CONTEXT_SIZE;

        $found = $top1 = 0;
        $missed = [];
        foreach ($fixtures['onTopic'] as $case) {
            $shown = array_slice(
                \local_kaiproctor\assistant::rank($case['q'], $index), 0, $size);
            $hit = false;
            foreach ($shown as $position => $item) {
                if (strpos($item['url'], $case['expects']) !== false) {
                    $hit = true;
                    $top1 += ($position === 0) ? 1 : 0;
                    break;
                }
            }
            $hit ? $found++ : $missed[] = $case['q'];
        }

        $accepted = [];
        foreach ($fixtures['offTopic'] as $case) {
            if (\local_kaiproctor\assistant::rank($case['q'], $index)) {
                $accepted[] = $case['q'];
            }
        }

        echo json_encode([
            'threshold' => \local_kaiproctor\assistant::MIN_SCORE,
            'ontopic' => count($fixtures['onTopic']),
            'offtopic' => count($fixtures['offTopic']),
            'recall' => round($found / count($fixtures['onTopic']), 4),
            'top1' => round($top1 / count($fixtures['onTopic']), 4),
            'falseaccept' => round(count($accepted) / count($fixtures['offTopic']), 4),
            'missed' => $missed,
            'wronglyaccepted' => $accepted,
        ], JSON_UNESCAPED_UNICODE);
        echo "\n";
        break;

    case 'ai-console':
        echo json_encode(\local_kaiproctor\ai_console::build(), JSON_UNESCAPED_UNICODE);
        echo "\n";
        break;

    case 'ai-islocal':
        // ai-islocal <url> — whether that endpoint counts as "on hardware you
        // control", which is what the console's warning turns on.
        echo \local_kaiproctor\ai_console::is_local($argv[2]) ? "yes\n" : "no\n";
        break;

    case 'seed-grade':
        // seed-grade <username> <cmid> <grade> — put a mark in the gradebook
        // without sitting the exam.
        //
        // Written through grade_update rather than by inserting a row, so what
        // the assistant reads is the same gradebook the learner sees on the
        // grade report. An assistant that disagrees with the grade report is
        // worse than one that says nothing.
        require_once($CFG->libdir . '/gradelib.php');
        $user = kp_user($argv[2]);
        $cm = get_coursemodule_from_id('quiz', (int) $argv[3], 0, false, MUST_EXIST);
        $quiz = $DB->get_record('quiz', ['id' => $cm->instance], '*', MUST_EXIST);

        grade_update('mod/quiz', $cm->course, 'mod', 'quiz', $quiz->id, 0, [
            'userid' => $user->id,
            'rawgrade' => (float) $argv[4],
        ]);
        echo "graded {$argv[2]} {$argv[4]}/{$quiz->grade} on cmid {$argv[3]}\n";
        break;

    case 'set-passmark':
        // set-passmark <cmid> <mark> — the teacher's rule, which the gradebook
        // applies. The assistant reports its answer; it does not decide it.
        require_once($CFG->libdir . '/gradelib.php');
        $cm = get_coursemodule_from_id('quiz', (int) $argv[2], 0, false, MUST_EXIST);
        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'quiz',
            'iteminstance' => $cm->instance, 'courseid' => $cm->course]);
        $item->gradepass = (float) $argv[3];
        $item->update();
        echo "pass mark {$argv[3]} on cmid {$argv[2]}\n";
        break;

    case 'ask-facts':
        // ask-facts <username> <question> — exactly the payload that would go
        // to the model, so a test can read what is disclosed without a model
        // being involved.
        $user = kp_user($argv[2]);
        $ranked = \local_kaiproctor\assistant::rank($argv[3] ?? '',
            \local_kaiproctor\site_index::for_user((int) $user->id));
        $out = [];
        foreach (array_slice($ranked, 0, \local_kaiproctor\assistant::CONTEXT_SIZE) as $item) {
            $out[] = [
                'title' => $item['title'],
                'kind' => $item['kind'],
                'facts' => \local_kaiproctor\assistant::facts_for_testing($item, (int) $user->id),
            ];
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        echo "\n";
        break;

    case 'kaivideo-backup-restore':
        // Round-trip the demo course and report what survived.
        //
        // Worth a permanent test because the first version of this module
        // declared FEATURE_BACKUP_MOODLE2 without the classes that implement
        // it, and backing up ANY course containing the activity died with
        // "class not found". Nothing in the module's own behaviour would have
        // shown that; only running a backup did.
        require_once($CFG->dirroot . '/backup/util/includes/backup_includes.php');
        require_once($CFG->dirroot . '/backup/util/includes/restore_includes.php');
        require_once($CFG->dirroot . '/course/lib.php');

        $source = $DB->get_record('course', ['shortname' => 'KP-DEMO'], '*', MUST_EXIST);
        $admin = $DB->get_record('user', ['username' => 'admin'], '*', MUST_EXIST);

        $bc = new backup_controller(backup::TYPE_1COURSE, $source->id,
            backup::FORMAT_MOODLE, backup::INTERACTIVE_NO, backup::MODE_GENERAL,
            $admin->id);
        $bc->get_plan()->get_setting('users')->set_value(true);
        $bc->execute_plan();
        $results = $bc->get_results();
        $folder = 'kaivideo-roundtrip';
        $results['backup_destination']->extract_to_pathname(
            get_file_packer('application/vnd.moodle.backup'),
            $CFG->tempdir . '/backup/' . $folder);
        $bc->destroy();

        $DB->delete_records('course', ['shortname' => 'KP-ROUNDTRIP']);
        $target = create_course((object) [
            'fullname' => 'round trip', 'shortname' => 'KP-ROUNDTRIP', 'category' => 1,
        ]);

        $rc = new restore_controller($folder, $target->id, backup::INTERACTIVE_NO,
            backup::MODE_GENERAL, $admin->id, backup::TARGET_NEW_COURSE);
        $rc->execute_precheck();
        $rc->execute_plan();
        $rc->destroy();

        $out = ['activities' => 0, 'questions' => 0, 'answers' => 0];
        foreach ($DB->get_records('kaivideo', ['course' => $target->id]) as $copy) {
            $out['activities']++;
            $out['questions'] += $DB->count_records('kaivideo_item',
                ['kaivideoid' => $copy->id]);
            $out['answers'] += $DB->count_records_sql(
                "SELECT COUNT(1) FROM {kaivideo_response} r
                   JOIN {kaivideo_item} i ON i.id = r.itemid
                  WHERE i.kaivideoid = ?", [$copy->id]);
        }

        delete_course($target->id, false);
        echo json_encode($out, JSON_UNESCAPED_UNICODE);
        echo "\n";
        break;

    case 'kaivideo-timeline':
        // kaivideo-timeline <cmid> — the timeline WITH the answers, which is
        // what a test needs to know which button to press. The player is never
        // given this; see mod_kaivideo\timeline::for_player.
        $cm = get_coursemodule_from_id('kaivideo', (int) $argv[2], 0, false, MUST_EXIST);
        echo json_encode(\mod_kaivideo\timeline::for_editing((int) $cm->instance),
            JSON_UNESCAPED_UNICODE);
        echo "\n";
        break;

    case 'kaivideo-reset':
        // kaivideo-reset <username> <cmid>
        $user = kp_user($argv[2]);
        $cm = get_coursemodule_from_id('kaivideo', (int) $argv[3], 0, false, MUST_EXIST);
        $items = $DB->get_fieldset_select('kaivideo_item', 'id', 'kaivideoid = ?',
            [$cm->instance]);
        if ($items) {
            [$insql, $params] = $DB->get_in_or_equal($items, SQL_PARAMS_NAMED);
            $params['userid'] = $user->id;
            $DB->delete_records_select('kaivideo_response',
                "itemid $insql AND userid = :userid", $params);
        }
        $DB->delete_records('kaivideo_progress',
            ['kaivideoid' => $cm->instance, 'userid' => $user->id]);
        echo "reset {$argv[2]} on cmid {$argv[3]}\n";
        break;

    case 'kaivideo-state':
        // kaivideo-state <username> <cmid> — what was recorded, including the
        // gradebook, so a test can check the mark rather than the intention.
        require_once($CFG->libdir . '/gradelib.php');
        $user = kp_user($argv[2]);
        $cm = get_coursemodule_from_id('kaivideo', (int) $argv[3], 0, false, MUST_EXIST);
        $video = $DB->get_record('kaivideo', ['id' => $cm->instance], '*', MUST_EXIST);

        $summary = \mod_kaivideo\responses::summary((int) $video->id, (int) $user->id);
        $grades = grade_get_grades($video->course, 'mod', 'kaivideo', $video->id,
            [$user->id]);
        $item = $grades->items[0] ?? null;

        echo json_encode([
            'answered' => $summary['answered'],
            'correct' => $summary['correct'],
            'fraction' => $summary['fraction'],
            'attempts' => $DB->count_records_sql(
                "SELECT COUNT(1) FROM {kaivideo_response} r
                   JOIN {kaivideo_item} i ON i.id = r.itemid
                  WHERE i.kaivideoid = ? AND r.userid = ?",
                [$video->id, $user->id]),
            'grade' => $item ? (float) $item->grades[$user->id]->grade : null,
            'grademax' => $item ? (float) $item->grademax : null,
            'progress' => $summary['progress'],
        ], JSON_UNESCAPED_UNICODE);
        echo "\n";
        break;

    case 'monitored-kinds':
        echo implode(',', \local_kaiproctor\monitored::SUPPORTED) . "\n";
        break;

    case 'ask-index':
        // Every page the assistant would consider for this learner. The test
        // that matters reads this as one user and checks another user's course
        // is absent.
        $user = kp_user($argv[2]);
        echo json_encode(\local_kaiproctor\site_index::for_user((int) $user->id),
            JSON_UNESCAPED_UNICODE);
        echo "\n";
        break;

    case 'ask-rank':
        // Retrieval only, no model: which pages a question matches, and how
        // well. Separating this from the answer keeps a retrieval regression
        // from being blamed on the model, and vice versa.
        $user = kp_user($argv[2]);
        $question = $argv[3] ?? '';
        $ranked = \local_kaiproctor\assistant::rank(
            $question, \local_kaiproctor\site_index::for_user((int) $user->id));
        echo json_encode(array_slice($ranked, 0, 8), JSON_UNESCAPED_UNICODE);
        echo "\n";
        break;

    case 'ask':
        $user = kp_user($argv[2]);
        $question = $argv[3] ?? '';
        echo json_encode(
            \local_kaiproctor\assistant::answer($question, (int) $user->id),
            JSON_UNESCAPED_UNICODE);
        echo "\n";
        break;

    case 'ask-purge-index':
        // The index is cached per user; a test that enrols somebody and then
        // asks would otherwise read a list built before the enrolment.
        $cache = \cache::make_from_params(\cache_store::MODE_APPLICATION,
            'local_kaiproctor', 'siteindex');
        $cache->purge();
        echo "purged\n";
        break;

    case 'seb-info':
        // seb-info <cmid>
        $cm = get_coursemodule_from_id('quiz', (int) $argv[2], 0, false, MUST_EXIST);
        $settings = \quizaccess_seb\seb_quiz_settings::get_record(['quizid' => $cm->instance]);
        echo json_encode([
            'requiresafeexambrowser' => $settings ? (int) $settings->get('requiresafeexambrowser') : 0,
            'configkey' => $settings ? (string) $settings->get_config_key() : '',
            'configbytes' => $settings ? strlen((string) $settings->get_config()) : 0,
            'kaiproctorenabled' => $DB->record_exists('quizaccess_kaiproctor',
                ['quizid' => $cm->instance, 'enabled' => 1]),
        ], JSON_UNESCAPED_UNICODE);
        echo "\n";
        break;

    case 'monitored':
        // monitored <cmid>
        echo \local_kaiproctor\monitored::is_monitored((int) $argv[2]) ? "yes\n" : "no\n";
        break;

    case 'set-monitored':
        // set-monitored <cmid> <0|1>
        \local_kaiproctor\monitored::set((int) $argv[2], (bool) (int) $argv[3]);
        echo "set\n";
        break;

    case 'correct-answers':
        // The correct option text for each question in a quiz, so a test can
        // answer correctly even though Moodle shuffles the options per learner.
        // These never leave the server during an attempt; reading them here is
        // the test harness cheating on purpose.
        $cm = get_coursemodule_from_id('quiz', (int) $argv[2], 0, false, MUST_EXIST);
        $answers = $DB->get_records_sql(
            "SELECT qa.id, qa.answer
               FROM {quiz_slots} qs
               JOIN {question_references} qr
                    ON qr.itemid = qs.id AND qr.component = 'mod_quiz'
                   AND qr.questionarea = 'slot'
               JOIN {question_bank_entries} qbe ON qbe.id = qr.questionbankentryid
               JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
               JOIN {question} q ON q.id = qv.questionid
               JOIN {question_answers} qa ON qa.question = q.id
              WHERE qs.quizid = :quizid AND qa.fraction > 0
           ORDER BY qs.slot",
            ['quizid' => $cm->instance]
        );
        echo json_encode(array_values(array_map(
            static fn($a) => strip_tags($a->answer), $answers)), JSON_UNESCAPED_UNICODE);
        echo "\n";
        break;

    case 'attempt-grade':
        // attempt-grade <username> <cmid>
        $user = kp_user($argv[2]);
        $cm = get_coursemodule_from_id('quiz', (int) $argv[3], 0, false, MUST_EXIST);
        $attempt = $DB->get_record_sql(
            'SELECT * FROM {quiz_attempts} WHERE userid = :userid AND quiz = :quiz
              ORDER BY id DESC',
            ['userid' => $user->id, 'quiz' => $cm->instance], IGNORE_MULTIPLE
        );
        $quiz = $DB->get_record('quiz', ['id' => $cm->instance]);
        echo json_encode([
            'state' => $attempt->state ?? null,
            'sumgrades' => $attempt ? (float) $attempt->sumgrades : null,
            'maxgrades' => (float) $quiz->sumgrades,
        ], JSON_UNESCAPED_UNICODE);
        echo "\n";
        break;

    case 'policy-info':
        $out = [];
        foreach (\tool_policy\api::list_current_versions() as $version) {
            $out[] = [
                'name' => $version->name,
                'revision' => $version->revision,
                'type' => (int) $version->type,
                'audience' => (int) $version->audience,
                'optional' => (int) $version->optional,
                'iscompulsory' => (int) $version->optional === \tool_policy\policy_version::AGREEMENT_COMPULSORY,
            ];
        }
        echo json_encode($out, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        break;

    case 'accept-policy':
        // Agree to every current policy on the learner's behalf, so tests that
        // are about something else are not all blocked by the consent gate.
        //
        // Accepting for somebody else needs tool/policy:acceptbehalf, and a
        // CLI script has no session, so it acts explicitly as the admin.
        \core\session\manager::set_user(get_admin());
        $user = kp_user($argv[2]);
        $versionids = [];
        foreach (\tool_policy\api::list_current_versions() as $version) {
            $versionids[] = $version->id;
        }
        if ($versionids) {
            \tool_policy\api::accept_policies($versionids, $user->id, 'accepted by the test suite');
        }
        echo "accepted " . count($versionids) . " policies for {$argv[2]}\n";
        break;

    case 'revoke-policy':
        // Put the learner back to not-yet-consented so the gate can be tested.
        \core\session\manager::set_user(get_admin());
        $user = kp_user($argv[2]);
        $versionids = [];
        foreach (\tool_policy\api::list_current_versions() as $version) {
            $versionids[] = $version->id;
        }
        if ($versionids) {
            \tool_policy\api::revoke_acceptance($versionids[0], $user->id, 'revoked by the test suite');
            $DB->delete_records_list('tool_policy_acceptances', 'policyversionid', $versionids);
            $DB->set_field('user', 'policyagreed', 0, ['id' => $user->id]);
        }
        echo "revoked consent for {$argv[2]}\n";
        break;

    case 'privacy-delete':
        // Drive the plugin's own Privacy API provider, the way Moodle does
        // when a privacy officer approves an erasure request. Deleting the
        // rows directly would prove nothing about the provider.
        $user = kp_user($argv[2]);
        $contextlist = \local_kaiproctor\privacy\provider::get_contexts_for_userid($user->id);
        $approved = new \core_privacy\local\request\approved_contextlist(
            \core_user::get_user($user->id), 'local_kaiproctor', $contextlist->get_contextids());
        \local_kaiproctor\privacy\provider::delete_data_for_user($approved);
        echo "privacy deletion run for {$argv[2]}\n";
        break;

    case 'run-purge-task':
        $removed = \local_kaiproctor\evidence::purge_expired();
        echo "purged {$removed}\n";
        break;

    case 'age-evidence':
        // age-evidence <username> <days> — backdate evidence so the retention
        // task has something expired to find.
        $user = kp_user($argv[2]);
        $when = time() - ((int) $argv[3] * DAYSECS);
        $DB->set_field('local_kaiproctor_evidence', 'timecreated', $when, ['userid' => $user->id]);
        echo "backdated evidence for {$argv[2]} by {$argv[3]} days\n";
        break;

    default:
        echo "usage: kp-query.php <health|events|checks|evidence|count|reset|seed-enrolment|"
           . "seed-pass|context-id|user-context-id|user-id|purge-attempts|run-purge-task|age-evidence>\n";
}
