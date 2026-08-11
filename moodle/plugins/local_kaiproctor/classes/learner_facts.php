<?php
// What a learner may be told about their own exams.
//
// Read straight out of the gradebook and the quiz settings, for one user: the
// person asking. Never for anybody else, and never derived — if a percentage
// is going to appear in an answer, it is computed here, in PHP, and handed
// over as a finished number. The model is not allowed to do arithmetic on
// somebody's result, and ai-service/app/guard.py enforces that by refusing an
// answer containing a figure that was never supplied.
//
// Reporting "you passed" is not the same act as the proctoring reviewer
// deciding whether somebody cheated. The pass mark is a rule a teacher set and
// the gradebook already applied; repeating it is reporting, not judging. The
// reviewer's rule stands untouched: no model decides anything about conduct.

namespace local_kaiproctor;

defined('MOODLE_INTERNAL') || die();

class learner_facts {

    /**
     * Facts about one quiz for one learner, or null when there are none.
     *
     * @param \cm_info $cm the quiz, already checked as visible to this user
     * @param int $userid the person asking, and only ever them
     * @return array|null
     */
    public static function for_quiz(\cm_info $cm, int $userid): ?array {
        global $DB, $CFG;

        require_once($CFG->libdir . '/gradelib.php');

        $quiz = $DB->get_record('quiz', ['id' => $cm->instance]);
        if (!$quiz) {
            return null;
        }

        $facts = [];

        // Settings a learner reasonably asks about before sitting down.
        if ($quiz->timeopen) {
            $facts['opens'] = userdate($quiz->timeopen, get_string('strftimedatetimeshort'));
        }
        if ($quiz->timeclose) {
            $facts['closes'] = userdate($quiz->timeclose, get_string('strftimedatetimeshort'));
        }
        if ($quiz->timelimit) {
            $facts['timelimitminutes'] = (int) round($quiz->timelimit / 60);
        }
        // Moodle stores "unlimited" as 0. Passing that through would have the
        // model telling a learner they are allowed zero attempts, so the fact
        // is simply absent when there is no limit — and the model is told to
        // say it does not have a number rather than invent one.
        if ((int) $quiz->attempts > 0) {
            $facts['attemptsallowed'] = (int) $quiz->attempts;
        }

        $facts['attemptsused'] = $DB->count_records_select('quiz_attempts',
            'quiz = :quiz AND userid = :userid AND state = :state AND preview = 0',
            ['quiz' => $quiz->id, 'userid' => $userid, 'state' => 'finished']);

        // The grade, from the gradebook rather than from the attempts table:
        // the gradebook is what the learner sees elsewhere on the site, and an
        // assistant that disagrees with the grade report is worse than one
        // that says nothing.
        $grades = grade_get_grades($cm->course, 'mod', 'quiz', $quiz->id, [$userid]);
        $item = $grades->items[0] ?? null;
        $grade = $item->grades[$userid] ?? null;

        if ($item && $grade && $grade->grade !== null && $grade->grade !== '') {
            $facts['grade'] = self::number((float) $grade->grade);
            $facts['gradeoutof'] = self::number((float) $item->grademax);

            if ((float) $item->grademax > 0) {
                // Computed here so the model never has to. A percentage it
                // worked out itself would be a number nobody can trace.
                $facts['gradepercent'] = self::number(
                    (float) $grade->grade / (float) $item->grademax * 100);
            }

            if (!empty($item->gradepass) && (float) $item->gradepass > 0) {
                $facts['passmark'] = self::number((float) $item->gradepass);
                // The gradebook already applied the teacher's rule; repeating
                // its answer is reporting, not deciding.
                $facts['passed'] = (float) $grade->grade >= (float) $item->gradepass;
            }
        } else if ($facts['attemptsused'] === 0) {
            $facts['notattempted'] = true;
        }

        return $facts ?: null;
    }

    /** Trim trailing zeros: "8" reads better than "8.00000" in a sentence. */
    protected static function number(float $value): float {
        return round($value, 2) + 0;
    }
}
