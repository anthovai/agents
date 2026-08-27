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
            'categories' => self::per_category($kaivideoid),
            'questions' => self::per_question($kaivideoid),
            'learners' => self::per_learner($kaivideoid, $courseid),
        ];
    }

    /**
     * How the class did on each topic, weakest first.
     *
     * The per-question table says which question is failing; this says which
     * subject is. They are different findings and lead to different actions —
     * one question everybody misses is usually a badly worded question, while
     * a whole topic sitting at 40% is a section of the video that did not
     * teach what it was meant to.
     *
     * Empty when nothing has been categorised, so a video whose author never
     * used the field does not grow a table with one nameless row in it.
     *
     * @param int $kaivideoid
     * @return array of {category, learners, answered, correct, correctshare, struggled}
     */
    public static function per_category(int $kaivideoid): array {
        global $DB;

        $named = $DB->count_records_select('kaivideo_item',
            'kaivideoid = :id AND category <> :blank',
            ['id' => $kaivideoid, 'blank' => '']);
        if (!$named) {
            return [];
        }

        [$insql, $params] = $DB->get_in_or_equal(timeline::GRADED, SQL_PARAMS_NAMED);
        $params['kaivideoid'] = $kaivideoid;

        // The item's category, not the answer's. This table describes the
        // video as it stands now — "which topic is weak" is a question about
        // the current lesson, so it groups by how the questions are filed
        // today. The copy kept on each answer is for the opposite job: showing
        // an individual's past result under the topic it was marked as.
        $rows = $DB->get_records_sql(
            "SELECT i.id, i.category, r.userid, r.correct
               FROM {kaivideo_item} i
          LEFT JOIN {kaivideo_response} r ON r.itemid = i.id
              WHERE i.kaivideoid = :kaivideoid AND i.type $insql
           ORDER BY r.id ASC", $params);

        // Newest answer per learner per item wins, matching the grade.
        $latest = [];
        $categoryof = [];
        foreach ($rows as $row) {
            $categoryof[(int) $row->id] = (string) $row->category;
            if ($row->userid !== null) {
                $latest[(int) $row->id][(int) $row->userid] = (bool) $row->correct;
            }
        }

        $totals = [];
        foreach ($categoryof as $itemid => $name) {
            if (!isset($totals[$name])) {
                $totals[$name] = ['category' => $name, 'answered' => 0,
                    'correct' => 0, 'people' => []];
            }
            foreach ($latest[$itemid] ?? [] as $userid => $wasright) {
                $totals[$name]['answered']++;
                $totals[$name]['correct'] += $wasright ? 1 : 0;
                $totals[$name]['people'][$userid] = true;
            }
        }

        $out = [];
        foreach ($totals as $row) {
            $share = $row['answered']
                ? round($row['correct'] / $row['answered'] * 100) : null;
            $out[] = [
                'category' => $row['category'] === ''
                    ? get_string('report:uncategorised', 'mod_kaivideo')
                    : $row['category'],
                'learners' => count($row['people']),
                'answered' => $row['answered'],
                'correct' => $row['correct'],
                'correctshare' => $share,
                // Rendered here rather than in the template. Mustache treats 0
                // as absent, so a topic the whole class got wrong displayed as
                // "-" — the mark meaning "nobody has answered this yet". The
                // worst result on the page was showing as no result at all.
                'sharelabel' => $share === null ? '-' : $share . '%',
                'struggled' => $share !== null
                    && ($share / 100) < self::STRUGGLE_THRESHOLD,
            ];
        }

        // Weakest first, for the same reason the question table is.
        usort($out, static function ($a, $b) {
            return ($a['correctshare'] ?? 101) <=> ($b['correctshare'] ?? 101);
        });

        return $out;
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

        // Info cards are left out: there is nothing to have got wrong, and
        // a row saying the class acknowledged a message is noise in a table
        // meant to show where the lesson is failing.
        [$insql, $params] = $DB->get_in_or_equal(timeline::GRADED, SQL_PARAMS_NAMED);
        $params['kaivideoid'] = $kaivideoid;
        $items = $DB->get_records_select('kaivideo_item',
            "kaivideoid = :kaivideoid AND type $insql", $params, 'attime ASC, id ASC');
        if (!$items) {
            return [];
        }

        // One pass over the answers, newest last, so the array ends up holding
        // each learner's latest attempt per item.
        $latest = [];
        foreach ($DB->get_records_sql(
                "SELECT r.id, r.itemid, r.userid, r.response, r.correct
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
            $expected = json_decode($item->answers, true) ?: [];

            $total = count($answers);
            $right = 0;
            foreach ($answers as $answer) {
                $right += $answer->correct ? 1 : 0;
            }

            $breakdown = $item->type === 'shorttext'
                ? self::typed_breakdown($answers, $expected, $total)
                : self::option_breakdown($answers, $choices, $expected, $total);

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
     * How the options were spread, for the choice types.
     *
     * @param array $answers rows for this item
     * @param array $choices option texts
     * @param array $expected indexes that are correct
     * @param int $total
     * @return array
     */
    protected static function option_breakdown(array $answers, array $choices,
            array $expected, int $total): array {
        $tally = array_fill(0, max(1, count($choices)), 0);

        foreach ($answers as $answer) {
            // A multiple-response answer counts towards every option it
            // contains: the question is which options attract people, not
            // which combinations they submitted.
            foreach ((json_decode((string) $answer->response, true) ?: []) as $index) {
                if (isset($tally[(int) $index])) {
                    $tally[(int) $index]++;
                }
            }
        }

        $breakdown = [];
        foreach ($choices as $index => $text) {
            $breakdown[] = [
                'text' => $text,
                'iscorrect' => in_array($index, $expected, true),
                'chosen' => $tally[$index],
                'share' => $total ? round($tally[$index] / $total * 100) : 0,
            ];
        }
        return $breakdown;
    }

    /**
     * What people actually typed, commonest first.
     *
     * The wrong answers are the point here. A typed question that half the
     * class fails usually fails in one particular way, and the way is only
     * visible if the words are kept.
     *
     * @param array $answers
     * @param array $expected accepted strings
     * @param int $total
     * @return array
     */
    protected static function typed_breakdown(array $answers, array $expected,
            int $total): array {
        $tally = [];
        foreach ($answers as $answer) {
            $typed = (string) $answer->response;
            $tally[$typed] = ($tally[$typed] ?? 0) + 1;
        }
        arsort($tally);

        $breakdown = [];
        foreach (array_slice($tally, 0, 8, true) as $typed => $count) {
            $breakdown[] = [
                'text' => $typed === '' ? get_string('report:blank', 'mod_kaivideo') : $typed,
                'iscorrect' => in_array($typed, $expected, true),
                'chosen' => $count,
                'share' => $total ? round($count / $total * 100) : 0,
            ];
        }
        return $breakdown;
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
