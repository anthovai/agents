<?php
// Answering, scoring and how far somebody has got.
//
// Marking happens here and only here. The browser is told whether it was right
// after the fact; it is never in a position to decide, because a player that
// knows the answer is a player a learner can read.
//
// Every attempt is kept. The grade uses the most recent one, but a teacher
// asking "did they guess twice and then get it" has to be able to find out,
// and a table that overwrites cannot answer that.

namespace mod_kaivideo;

defined('MOODLE_INTERNAL') || die();

class responses {

    /**
     * Record one answer and say what it was worth.
     *
     * @param int $itemid
     * @param int $userid
     * @param string $response what the learner sent: a JSON array of option
     *     indexes for the choice types, or the typed text for shorttext
     * @param bool $mayretry whether another attempt is still available
     * @return array {correct, answers, feedback}
     */
    public static function answer(int $itemid, int $userid, string $response,
            bool $mayretry = false): array {
        global $DB;

        $item = $DB->get_record('kaivideo_item', ['id' => $itemid], '*', MUST_EXIST);
        $expected = json_decode($item->answers, true) ?: [];

        [$stored, $correct] = self::judge($item, $expected, $response);

        $DB->insert_record('kaivideo_response', (object) [
            'itemid' => $itemid,
            'userid' => $userid,
            'response' => $stored,
            'correct' => $correct ? 1 : 0,
            'timecreated' => time(),
        ]);

        // A wrong answer with another attempt still to come is the one case
        // where the answer stays hidden. Showing it and then offering "try
        // again" makes the second attempt free, which is not a second attempt
        // at anything — it is a button that awards a mark.
        if (!$correct && $mayretry) {
            return ['correct' => false, 'answers' => [], 'feedback' => ''];
        }

        return [
            'correct' => $correct,
            'answers' => $expected,
            'feedback' => (string) $item->feedback,
        ];
    }

    /**
     * Is it right, and what should be kept as a record of what they said?
     *
     * @param \stdClass $item
     * @param array $expected
     * @param string $response
     * @return array [stored, correct]
     */
    protected static function judge(\stdClass $item, array $expected,
            string $response): array {
        if ($item->type === 'info') {
            // Acknowledged, not answered. Recorded so a report can say they
            // reached it, and always counted as correct because there was
            // nothing to get wrong.
            return ['', true];
        }

        if ($item->type === 'shorttext') {
            $typed = timeline::normalise($response);
            return [$typed, in_array($typed, $expected, true)];
        }

        $chosen = json_decode($response, true);
        if (!is_array($chosen)) {
            throw new \moodle_exception('error:badchoice', 'mod_kaivideo');
        }

        $choices = json_decode($item->choices, true) ?: [];
        $clean = [];
        foreach ($chosen as $index) {
            $index = (int) $index;
            if ($index < 0 || $index >= count($choices)) {
                throw new \moodle_exception('error:badchoice', 'mod_kaivideo');
            }
            if (!in_array($index, $clean, true)) {
                $clean[] = $index;
            }
        }
        sort($clean);

        if ($item->type === 'choice' && count($clean) !== 1) {
            throw new \moodle_exception('error:badchoice', 'mod_kaivideo');
        }

        // All of them, and nothing else. No partial credit: half a mark for
        // half the boxes invites an argument about the scheme that neither the
        // learner nor the teacher can settle from the record.
        return [json_encode($clean), $clean === $expected];
    }

    /**
     * The latest answer to each item, for one learner.
     *
     * @return array itemid => {response, correct}
     */
    public static function latest(int $kaivideoid, int $userid): array {
        global $DB;

        $records = $DB->get_records_sql(
            "SELECT r.id, r.itemid, r.response, r.correct
               FROM {kaivideo_response} r
               JOIN {kaivideo_item} i ON i.id = r.itemid
              WHERE i.kaivideoid = :kaivideoid AND r.userid = :userid
           ORDER BY r.id ASC",
            ['kaivideoid' => $kaivideoid, 'userid' => $userid]);

        // Ascending, overwriting: the last row for an item wins, which is the
        // most recent answer.
        $latest = [];
        foreach ($records as $record) {
            $latest[(int) $record->itemid] = [
                'response' => (string) $record->response,
                'correct' => (bool) $record->correct,
            ];
        }
        return $latest;
    }

    /**
     * Fraction of the graded items answered correctly, or null when there are
     * none to answer.
     *
     * Info cards are not in the denominator. Reading a message is not a
     * question, and counting it would inflate everybody's mark by however many
     * an author happened to add.
     *
     * Unanswered counts as wrong rather than as absent: a learner who skipped
     * half the video has not earned the same mark as one who answered
     * everything, and treating the gaps as neutral would give them that.
     */
    public static function fraction(int $kaivideoid, int $userid): ?float {
        global $DB;

        [$insql, $params] = $DB->get_in_or_equal(timeline::GRADED, SQL_PARAMS_NAMED);
        $params['kaivideoid'] = $kaivideoid;

        $graded = $DB->get_fieldset_select('kaivideo_item', 'id',
            "kaivideoid = :kaivideoid AND type $insql", $params);
        if (!$graded) {
            return null;
        }

        $answers = self::latest($kaivideoid, $userid);
        $right = 0;
        foreach ($graded as $itemid) {
            $right += !empty($answers[(int) $itemid]['correct']) ? 1 : 0;
        }
        return $right / count($graded);
    }

    /**
     * Note how far the learner has watched.
     *
     * Never moves backwards: rewatching a section, or seeking back to check
     * something, is not losing progress.
     */
    public static function reached(int $kaivideoid, int $userid, float $seconds,
            bool $finished = false): void {
        global $DB;

        $record = $DB->get_record('kaivideo_progress',
            ['kaivideoid' => $kaivideoid, 'userid' => $userid]);

        if (!$record) {
            $DB->insert_record('kaivideo_progress', (object) [
                'kaivideoid' => $kaivideoid,
                'userid' => $userid,
                'furthest' => max(0, round($seconds, 2)),
                'finished' => $finished ? 1 : 0,
                'timemodified' => time(),
            ]);
            return;
        }

        $record->furthest = max((float) $record->furthest, round($seconds, 2));
        // Finished stays finished. Watching it again does not un-complete it.
        $record->finished = ($finished || $record->finished) ? 1 : 0;
        $record->timemodified = time();
        $DB->update_record('kaivideo_progress', $record);
    }

    /** @return array {furthest, finished} */
    public static function progress(int $kaivideoid, int $userid): array {
        global $DB;

        $record = $DB->get_record('kaivideo_progress',
            ['kaivideoid' => $kaivideoid, 'userid' => $userid]);

        return [
            'furthest' => $record ? (float) $record->furthest : 0.0,
            'finished' => $record ? (bool) $record->finished : false,
        ];
    }

    /** Everything one learner did, for the activity's report. */
    public static function summary(int $kaivideoid, int $userid): array {
        global $DB;

        $answers = self::latest($kaivideoid, $userid);
        $fraction = self::fraction($kaivideoid, $userid);

        // "Correct" counts graded items only. An info card records as correct
        // because there is nothing to get wrong, but counting it here put
        // "ตอบถูก 4" next to "คะแนน 75%" — which is 3 of 4 — in the teacher's
        // table: two figures about the same learner that cannot be reconciled
        // by looking at them. "Answered" still counts every item, because it
        // sits next to the timeline's total and means how far they worked
        // through, not how well.
        [$insql, $params] = $DB->get_in_or_equal(timeline::GRADED, SQL_PARAMS_NAMED);
        $params['kaivideoid'] = $kaivideoid;
        $graded = array_map('intval', $DB->get_fieldset_select('kaivideo_item',
            'id', "kaivideoid = :kaivideoid AND type $insql", $params));

        $correct = 0;
        foreach ($answers as $itemid => $answer) {
            if ($answer['correct'] && in_array((int) $itemid, $graded, true)) {
                $correct++;
            }
        }

        return [
            'answered' => count($answers),
            'correct' => $correct,
            'fraction' => $fraction,
            'progress' => self::progress($kaivideoid, $userid),
        ];
    }
}
