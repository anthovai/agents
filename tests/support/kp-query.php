<?php
// Test-support CLI: lets the Playwright suite read and reset proctoring state
// without reaching into the database from Python.
//
// Copied into the Moodle container by conftest.py and run there. It lives
// above the web root, so it is not reachable over HTTP.

define('CLI_SCRIPT', true);
require('/var/www/html/config.php');

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
            'webservices' => $DB->count_records_select('external_functions',
                $DB->sql_like('name', ':p'), ['p' => 'local_kaiproctor%']),
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
