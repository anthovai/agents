<?php
// Drafting a summary of a sitting for the person who has to review it.
//
// A reviewer facing a hundred sittings cannot read every event log, so most
// go unread and the one that mattered is missed. A short written summary of
// what happened, with the unusual parts named, is what makes the pile
// reviewable at all.
//
// What this deliberately does not do is decide. The model never sees a face,
// never sees a score, and never returns a pass or a fail. It sees counts and
// event names — the same things already printed on the report — and writes
// them up. The decision stays with the rules and the person.

namespace local_kaiproctor;

defined('MOODLE_INTERNAL') || die();

class ai_reviewer {

    /**
     * Everything the model is allowed to be told about a sitting.
     *
     * Built as an explicit whitelist rather than by removing fields from a
     * record: a field added to the table later would otherwise start being
     * sent to a third party without anybody deciding that it should.
     *
     * @param int $sessionid
     * @return array|null
     */
    public static function gather(int $sessionid): ?array {
        global $DB;

        $session = $DB->get_record('local_kaiproctor_session', ['id' => $sessionid]);
        if (!$session) {
            return null;
        }

        $checks = $DB->get_records('local_kaiproctor_check', ['sessionid' => $sessionid]);
        $decisions = [];
        foreach ($checks as $check) {
            $key = $check->kind . ':' . $check->decision;
            $decisions[$key] = ($decisions[$key] ?? 0) + 1;
        }

        $events = [];
        foreach ($DB->get_records_select('logstore_standard_log',
                'userid = :userid AND ' . $DB->sql_like('eventname', ':pattern'),
                ['userid' => $session->userid, 'pattern' => '%kaiproctor%'],
                'timecreated ASC') as $record) {
            $other = json_decode($record->other, true) ?: [];
            if ((int) ($other['sessionid'] ?? 0) !== $sessionid) {
                continue;
            }
            $type = $other['type'] ?? 'unknown';
            $events[$type] = ($events[$type] ?? 0) + 1;
        }

        $evidence = $DB->get_records_sql(
            "SELECT reason, COUNT(1) AS total
               FROM {local_kaiproctor_evidence}
              WHERE sessionid = :sessionid
           GROUP BY reason",
            ['sessionid' => $sessionid]
        );

        return [
            'status' => $session->status,
            'reason' => $session->reason,
            'minutes' => $session->timeend
                ? (int) round(($session->timeend - $session->timestart) / 60) : null,
            // Counts by decision, never the similarity scores that produced
            // them: a score is derived from somebody's face.
            'checks' => $decisions,
            'events' => $events,
            'evidence' => array_map(static fn($row) => (int) $row->total, $evidence),
            'policy' => json_decode($session->policy, true) ?: [],
        ];
    }

    /**
     * Ask for a summary of one sitting.
     *
     * @param int $sessionid
     * @return array {ok, summary} or {ok:false, error}
     */
    public static function summarise(int $sessionid): array {
        $facts = self::gather($sessionid);
        if (!$facts) {
            return ['ok' => false, 'error' => ['code' => 'unknown_session', 'message' => '']];
        }

        // The prompt is not here any more. It lives in the reviewer service,
        // where a customer running their own copy of this plugin cannot edit
        // it — which is the point of the service existing.
        return ai_client::call('/summarise', ['sitting' => $facts]);
    }

    /**
     * Ask whether imported questions look damaged.
     *
     * This is the one place the AI earns its keep beyond summarising: PDF text
     * extraction can leave Thai vowels and tone marks in the wrong order, and
     * a reader who knows Thai spots it instantly while a regular expression
     * never will. It flags for a human; it does not edit anything.
     *
     * @param array $questions as returned by pdf_import::parse()
     * @return array {ok, findings}
     */
    public static function check_questions(array $questions): array {
        $sample = array_slice($questions, 0, 20);
        if (!$sample) {
            return ['ok' => false, 'error' => ['code' => 'nothing_to_check', 'message' => '']];
        }

        $material = [];
        foreach ($sample as $question) {
            $material[] = [
                'id' => (string) ($question['id'] ?? ''),
                'text' => (string) ($question['text'] ?? ''),
                'choices' => array_values($question['choices'] ?? []),
            ];
        }

        return ai_client::call('/check-questions', ['questions' => $material]);
    }
}
