<?php
// What the class actually did, for the person who has to fix the lesson.
//
// The per-learner table is the one everybody asks for and the less useful of
// the two. A teacher looking at thirty rows of percentages learns that some
// people did better than others, which they knew.
//
// The per-question table is why this page exists. "Eighteen of twenty picked
// answer 3 on the question at 04:12" is not a fact about those eighteen
// learners; it is a fact about the four minutes of video before it, and it is
// the only thing here that tells somebody what to go and change.

namespace mod_kaivideo;

defined('MOODLE_INTERNAL') || die();

class report {

    /** Below this share of wrong answers, a question is not worth flagging. */
    const STRUGGLE_THRESHOLD = 0.5;

    /**
     * Both views of one activity.
     *
     * @param int $kaivideoid
     * @param int $courseid to scope the participant list
     * @return array
     */
    public static function build(int $kaivideoid, int $courseid): array {
        return [
            'questions' => self::per_question($kaivideoid),
            'learners' => self::per_learner($kaivideoid, $courseid),
        ];
    }

    /**
     * How each question fared, worst first.
     *
     * Only the most recent answer per learner counts, matching the grade: a
     * question everybody got right on their second go is not a question that
     * needs rewriting.
     */
    public static function per_question(int $kaivideoid): array {
        global $DB;

        $items = $DB->get_records('kaivideo_item', ['kaivideoid' => $kaivideoid],
            'attime ASC, id ASC');
        if (!$items) {
            return [];
        }

        // One pass over the answers, newest last, so the array ends up holding
        // each learner's latest attempt per item.
        $latest = [];
        foreach ($DB->get_records_sql(
                "SELECT r.id, r.itemid, r.userid, r.choice, r.correct
                   FROM {kaivideo_response} r
                   JOIN {kaivideo_item} i ON i.id = r.itemid
                  WHERE i.kaivideoid = :kaivideoid
               ORDER BY r.id ASC", ['kaivideoid' => $kaivideoid]) as $response) {
            $latest[(int) $response->itemid][(int) $response->userid] = $response;
        }

        $rows = [];
        foreach ($items as $item) {
            $answers = $latest[(int) $item->id] ?? [];
            $choices = json_decode($item->choices, true) ?: [];

            $tally = array_fill(0, count($choices), 0);
            $right = 0;
            foreach ($answers as $answer) {
                $index = (int) $answer->choice;
                if (isset($tally[$index])) {
                    $tally[$index]++;
                }
                $right += $answer->correct ? 1 : 0;
            }

            $total = count($answers);
            $breakdown = [];
            foreach ($choices as $index => $text) {
                $breakdown[] = [
                    'text' => $text,
                    'iscorrect' => ($index === (int) $item->correctchoice),
                    'chosen' => $tally[$index],
                    'share' => $total ? round($tally[$index] / $total * 100) : 0,
                ];
            }

            // The commonest wrong answer, which is usually the misconception
            // worth addressing rather than the question being unclear.
            $commonestwrong = null;
            $most = 0;
            foreach ($breakdown as $option) {
                if (!$option['iscorrect'] && $option['chosen'] > $most) {
                    $most = $option['chosen'];
                    $commonestwrong = $option['text'];
                }
            }

            $rows[] = [
                'attime' => (float) $item->attime,
                'attimelabel' => timeline::clock((float) $item->attime),
                'questiontext' => $item->questiontext,
                'answered' => $total,
                'correct' => $right,
                'correctshare' => $total ? round($right / $total * 100) : null,
                'breakdown' => $breakdown,
                'commonestwrong' => $commonestwrong,
                // Flagged rather than merely sorted, because a teacher skimming
                // a table reads the badges and not the numbers.
                'struggled' => $total > 0
                    && ($right / $total) < self::STRUGGLE_THRESHOLD,
                'unanswered' => ($total === 0),
            ];
        }

        // Hardest first. A report ordered by timestamp buries the problem in
        // the middle of the list.
        usort($rows, static function($a, $b) {
            return ($a['correctshare'] ?? 101) <=> ($b['correctshare'] ?? 101);
        });

        return $rows;
    }

    /**
     * One row per learner who has touched it.
     *
     * Deliberately not every enrolled user: a list padded with people who have
     * not opened the activity is a list nobody scrolls to the bottom of.
     */
    public static function per_learner(int $kaivideoid, int $courseid): array {
        global $DB;

        $total = $DB->count_records('kaivideo_item', ['kaivideoid' => $kaivideoid]);

        $userids = $DB->get_fieldset_sql(
            "SELECT DISTINCT userid FROM (
                 SELECT p.userid
                   FROM {kaivideo_progress} p
                  WHERE p.kaivideoid = :id1
                 UNION
                 SELECT r.userid
                   FROM {kaivideo_response} r
                   JOIN {kaivideo_item} i ON i.id = r.itemid
                  WHERE i.kaivideoid = :id2
             ) touched",
            ['id1' => $kaivideoid, 'id2' => $kaivideoid]);

        if (!$userids) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);
        $users = $DB->get_records_select('user', "id $insql", $params);

        $rows = [];
        foreach ($users as $user) {
            $summary = responses::summary($kaivideoid, (int) $user->id);
            $rows[] = [
                'fullname' => fullname($user),
                'answered' => $summary['answered'],
                'total' => $total,
                'correct' => $summary['correct'],
                'sharelabel' => $summary['fraction'] === null
                    ? '-' : round($summary['fraction'] * 100) . '%',
                'furthest' => timeline::clock($summary['progress']['furthest']),
                'finished' => $summary['progress']['finished'],
            ];
        }

        usort($rows, static fn($a, $b) => strcoll($a['fullname'], $b['fullname']));
        return $rows;
    }
}
