<?php
// Making the paper a learner receives reproducible, and provably not chosen.
//
// The original system drew each paper from a seed derived from who was sitting
// and which attempt it was, and stored the seed, so an auditor could recompute
// the exact paper. Moodle draws its random slots with shuffle(), which uses
// PHP's global Mersenne Twister — so seeding that engine immediately before the
// attempt is created makes the draw deterministic without touching core.
//
// Two separate guarantees, and they are worth keeping apart:
//
//   * The seed is a pure function of (learner, quiz, attempt number). It
//     therefore cannot have been picked after the fact to produce a convenient
//     paper — that is the property that matters in a dispute, and it holds
//     forever.
//
//   * Re-running the draw from that seed reproduces the paper, but only on the
//     same Moodle version with the same question bank. Banks get edited and
//     cores get upgraded, so the durable evidence is the recorded list of
//     question ids, not the ability to re-derive it.

namespace local_kaiproctor;

defined('MOODLE_INTERNAL') || die();

class exam_draw {

    /**
     * The seed for one learner's attempt.
     *
     * Deliberately derived only from identifiers an auditor already has, with
     * no site secret and no timestamp: anybody holding the learner id, the
     * quiz id and the attempt number can recompute it and check it against
     * what was stored.
     *
     * @param int $userid
     * @param int $quizid
     * @param int $attemptnumber
     * @return int
     */
    public static function seed_for(int $userid, int $quizid, int $attemptnumber): int {
        $digest = sha1("kaiproctor|{$userid}|{$quizid}|{$attemptnumber}");

        // 31 bits: mt_srand takes an int, and staying inside signed 32-bit
        // keeps the value identical on a 32-bit PHP build.
        return (int) hexdec(substr($digest, 0, 8)) & 0x7FFFFFFF;
    }

    /**
     * Seed the engine mod_quiz's random draw will use.
     *
     * Must be called immediately before the attempt is created; anything that
     * consumes randomness in between changes the result.
     *
     * @param int $seed
     */
    public static function apply_seed(int $seed): void {
        mt_srand($seed);
    }

    /**
     * Record what was drawn, once the attempt exists.
     *
     * @param \stdClass $attempt a quiz_attempts record
     * @param int $seed
     * @return int the draw record id
     */
    public static function record(\stdClass $attempt, int $seed): int {
        global $DB;

        if ($DB->record_exists('local_kaiproctor_draw', ['attemptid' => $attempt->id])) {
            return (int) $DB->get_field('local_kaiproctor_draw', 'id',
                ['attemptid' => $attempt->id]);
        }

        $questionids = self::questions_in_attempt($attempt);

        return $DB->insert_record('local_kaiproctor_draw', (object) [
            'attemptid' => $attempt->id,
            'userid' => $attempt->userid,
            'quizid' => $attempt->quiz,
            'attemptnumber' => $attempt->attempt,
            'seed' => $seed,
            'blueprint' => json_encode(self::blueprint_for($attempt->quiz), JSON_UNESCAPED_UNICODE),
            'questionids' => json_encode($questionids),
            'timecreated' => time(),
        ]);
    }

    /**
     * The question ids actually used, in slot order.
     *
     * @param \stdClass $attempt
     * @return array
     */
    public static function questions_in_attempt(\stdClass $attempt): array {
        $quba = \question_engine::load_questions_usage_by_activity($attempt->uniqueid);

        $ids = [];
        foreach ($quba->get_slots() as $slot) {
            $ids[] = (int) $quba->get_question($slot, false)->id;
        }
        return $ids;
    }

    /**
     * The rule the paper was drawn under: what each slot was allowed to pick.
     *
     * @param int $quizid
     * @return array
     */
    public static function blueprint_for(int $quizid): array {
        global $DB;

        $slots = $DB->get_records('quiz_slots', ['quizid' => $quizid], 'slot ASC');

        $blueprint = [];
        foreach ($slots as $slot) {
            $reference = $DB->get_record('question_set_references', [
                'itemid' => $slot->id,
                'component' => 'mod_quiz',
                'questionarea' => 'slot',
            ]);

            if (!$reference) {
                // A fixed question: the same for everybody, so the rule is
                // simply "this question".
                $blueprint[] = ['slot' => (int) $slot->slot, 'type' => 'fixed'];
                continue;
            }

            $condition = json_decode($reference->filtercondition, true) ?: [];
            $tags = [];
            foreach ($condition['filter']['qtagids']['values'] ?? [] as $tagid) {
                $name = $DB->get_field('tag', 'rawname', ['id' => $tagid]);
                if ($name) {
                    $tags[] = $name;
                }
            }

            $blueprint[] = [
                'slot' => (int) $slot->slot,
                'type' => 'random',
                'tags' => $tags,
                'category' => $condition['filter']['category']['values'][0] ?? null,
            ];
        }

        return $blueprint;
    }

    /**
     * Read back a recorded draw, and re-check the seed against the identifiers.
     *
     * The recheck is the point: it shows the stored seed really is the one the
     * rule produces for this learner and this attempt, rather than a number
     * written down afterwards.
     *
     * @param int $attemptid
     * @return array|null
     */
    public static function describe(int $attemptid): ?array {
        global $DB;

        $record = $DB->get_record('local_kaiproctor_draw', ['attemptid' => $attemptid]);
        if (!$record) {
            return null;
        }

        $expected = self::seed_for((int) $record->userid, (int) $record->quizid,
            (int) $record->attemptnumber);

        return [
            'seed' => (int) $record->seed,
            'expectedseed' => $expected,
            'seedverified' => (int) $record->seed === $expected,
            'blueprint' => json_decode($record->blueprint, true) ?: [],
            'questionids' => json_decode($record->questionids, true) ?: [],
            'attemptnumber' => (int) $record->attemptnumber,
        ];
    }
}
