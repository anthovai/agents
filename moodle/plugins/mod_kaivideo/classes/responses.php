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
     * @param int $choice index into the item's choices
     * @return array {correct, correctchoice, feedback}
     */
    public static function answer(int $itemid, int $userid, int $choice,
            bool $mayretry = false): array {
        global $DB;

        $item = $DB->get_record('kaivideo_item', ['id' => $itemid], '*', MUST_EXIST);
        $choices = json_decode($item->choices, true) ?: [];

        if ($choice < 0 || $choice >= count($choices)) {
            throw new \moodle_exception('error:badchoice', 'mod_kaivideo');
        }

        $correct = ((int) $item->correctchoice === $choice);

        $DB->insert_record('kaivideo_response', (object) [
            'itemid' => $itemid,
            'userid' => $userid,
            'choice' => $choice,
            'correct' => $correct ? 1 : 0,
            'timecreated' => time(),
        ]);

        // A wrong answer with another attempt still to come is the one case
        // where the answer stays hidden. Showing it and then offering "try
        // again" makes the second attempt free, which is not a second attempt
        // at anything — it is a button that awards a mark.
        //
        // Once they are right, or once there is no retry, the explanation is
        // the whole point of asking mid-lesson, so it is released.
        if (!$correct && $mayretry) {
            return ['correct' => false, 'correctchoice' => -1, 'feedback' => ''];
        }

        return [
            'correct' => $correct,
            'correctchoice' => (int) $item->correctchoice,
            'feedback' => (string) $item->feedback,
        ];
    }

    /**
     * The latest answer to each question, for one learner.
     *
     * @return array itemid => {choice, correct}
     */
    public static function latest(int $kaivideoid, int $userid): array {
        global $DB;

        $records = $DB->get_records_sql(
            "SELECT r.id, r.itemid, r.choice, r.correct
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
                'choice' => (int) $record->choice,
                'correct' => (bool) $record->correct,
            ];
        }
        return $latest;
    }

    /**
     * Fraction of the questions answered correctly, or null when there are
     * none to answer.
     *
     * Unanswered counts as wrong rather than as absent. A learner who skipped
     * half the video has not earned the same mark as one who answered
     * everything, and treating the gaps as neutral would give them that.
     */
    public static function fraction(int $kaivideoid, int $userid): ?float {
        global $DB;

        $total = $DB->count_records('kaivideo_item', ['kaivideoid' => $kaivideoid]);
        if (!$total) {
            return null;
        }

        $right = 0;
        foreach (self::latest($kaivideoid, $userid) as $answer) {
            $right += $answer['correct'] ? 1 : 0;
        }
        return $right / $total;
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
        $answers = self::latest($kaivideoid, $userid);
        $fraction = self::fraction($kaivideoid, $userid);

        return [
            'answered' => count($answers),
            'correct' => count(array_filter($answers, static fn($a) => $a['correct'])),
            'fraction' => $fraction,
            'progress' => self::progress($kaivideoid, $userid),
        ];
    }
}
