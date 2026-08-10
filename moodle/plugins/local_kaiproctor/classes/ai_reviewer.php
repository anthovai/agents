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

        $system = <<<'PROMPT'
You summarise online exam monitoring records for a human reviewer at a Thai
training provider. Write in Thai.

You are given counts of what a monitoring system recorded during one sitting.
You are NOT given any image, any face measurement, or any identifying detail,
and you must not ask for any.

Write at most six short sentences:
  1. What happened during the sitting, plainly.
  2. Which parts, if any, a reviewer should look at, and why.

Rules you must follow:
  - Do not state or imply that the learner cheated, or that they did not. You
    cannot know that, and a reviewer deciding it needs to weigh the evidence
    themselves.
  - Do not recommend passing, failing, or disciplining anybody.
  - If the record shows nothing unusual, say so in one sentence rather than
    inventing concerns.
  - Say when the record is too thin to say much, rather than filling the gap.
PROMPT;

        $user = "บันทึกการเรียน 1 ครั้ง:\n" .
            json_encode($facts, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        $result = ai_client::ask($system, $user);
        if (empty($result['ok'])) {
            return $result;
        }

        return [
            'ok' => true,
            'summary' => trim($result['content']),
            'model' => $result['model'] ?? '',
        ];
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

        $system = <<<'PROMPT'
You are proof-reading Thai multiple-choice questions that were extracted from a
PDF. Extraction sometimes puts Thai vowels and tone marks in the wrong order
within a word, so a word can look almost right but be misspelled.

For each question you are given, decide only whether the Thai text looks
damaged. Reply as a JSON array, one object per problem found:

  [{"id": "<question id>", "problem": "<what looks wrong, in Thai>"}]

Reply with [] if nothing looks damaged. Judge only the spelling and the shape
of the words. Do not comment on whether a question is a good question, and do
not rewrite anything — a person will fix what you point at.
PROMPT;

        $material = [];
        foreach ($sample as $question) {
            $material[] = [
                'id' => $question['id'] ?? '',
                'text' => $question['text'] ?? '',
                'choices' => $question['choices'] ?? [],
            ];
        }

        $result = ai_client::ask($system,
            json_encode($material, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        if (empty($result['ok'])) {
            return $result;
        }

        // The model was asked for JSON but is not trusted to have obeyed; a
        // malformed reply is reported as no findings rather than crashing an
        // import screen.
        $findings = json_decode(self::extract_json($result['content']), true);

        return [
            'ok' => true,
            'findings' => is_array($findings) ? $findings : [],
            'raw' => $result['content'],
        ];
    }

    /** Pull the JSON array out of a reply that may be wrapped in prose. */
    protected static function extract_json(string $content): string {
        $start = strpos($content, '[');
        $end = strrpos($content, ']');
        if ($start === false || $end === false || $end < $start) {
            return '[]';
        }
        return substr($content, $start, $end - $start + 1);
    }
}
