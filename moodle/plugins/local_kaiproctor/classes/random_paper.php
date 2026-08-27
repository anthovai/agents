<?php
// Filling a quiz with random draws from a course's question bank.
//
// The work is Moodle's — a quiz slot can already hold "a random question from
// this category", and mod_quiz picks a different one per attempt. What lives
// here is the part a teacher would otherwise do by hand thirty times over,
// plus the two facts a form needs to refuse an impossible request: how many
// questions there are to draw from, and which quizzes there are to fill.

namespace local_kaiproctor;

defined('MOODLE_INTERNAL') || die();

class random_paper {

    /**
     * How many questions a paper could draw from.
     *
     * Counts the latest ready version of each entry, which is what a random
     * slot actually selects from: counting rows in mdl_question would count
     * every draft and every superseded edit, and promise a bank bigger than
     * the one that exists.
     *
     * @param \stdClass $category the bank category questions were imported to
     * @return int
     */
    public static function available(\stdClass $category): int {
        global $DB;

        $categoryids = array_merge([$category->id], self::descendants($category->id));
        [$insql, $params] = $DB->get_in_or_equal($categoryids, SQL_PARAMS_NAMED);

        return (int) $DB->count_records_sql("
            SELECT COUNT(DISTINCT qbe.id)
              FROM {question_bank_entries} qbe
              JOIN {question_versions} qv ON qv.questionbankentryid = qbe.id
              JOIN {question} q ON q.id = qv.questionid
             WHERE qbe.questioncategoryid {$insql}
               AND qv.status = :ready
               AND q.parent = 0
        ", $params + ['ready' => \core_question\local\bank\question_version_status::QUESTION_STATUS_READY]);
    }

    /**
     * Every category beneath this one.
     *
     * A teacher who has filed their imports into subcategories still means
     * "the whole bank" when they say so, and the slot filter is told to
     * include them for the same reason.
     *
     * @param int $parentid
     * @return int[]
     */
    protected static function descendants(int $parentid): array {
        global $DB;

        $found = [];
        $children = $DB->get_fieldset_select('question_categories', 'id',
            'parent = :parent', ['parent' => $parentid]);
        foreach ($children as $childid) {
            $found[] = (int) $childid;
            $found = array_merge($found, self::descendants((int) $childid));
        }
        return $found;
    }

    /**
     * The quizzes in a course, as a select menu.
     *
     * @param \stdClass $course
     * @return array quiz id => name
     */
    public static function quizzes_in(\stdClass $course): array {
        $menu = [];
        foreach (get_fast_modinfo($course)->get_instances_of('quiz') as $cm) {
            if ($cm->uservisible || has_capability('moodle/course:manageactivities',
                    \context_module::instance($cm->id))) {
                $menu[$cm->instance] = format_string($cm->name);
            }
        }
        return $menu;
    }

    /**
     * Put random draws into a quiz.
     *
     * @param int $quizid
     * @param int $count how many questions the paper should hold
     * @param bool $replace clear what is there first
     * @param \stdClass $category the bank to draw from
     * @return array {added, removed}
     */
    public static function build(int $quizid, int $count, bool $replace,
                                 \stdClass $category): array {
        $settings = \mod_quiz\quiz_settings::create($quizid);
        \require_capability('mod/quiz:manage', $settings->get_context());

        $structure = $settings->get_structure();
        $removed = 0;

        if ($replace) {
            // Backwards: remove_slot renumbers everything after the one it
            // takes out, so walking forwards would skip every other slot.
            $slots = $structure->get_slots();
            for ($number = count($slots); $number >= 1; $number--) {
                $structure->remove_slot($number);
                $removed++;
            }
            // The structure it was holding described the quiz before all that.
            $structure = \mod_quiz\quiz_settings::create($quizid)->get_structure();
        }

        $structure->add_random_questions(0, $count, [
            'filter' => [
                'category' => [
                    'jointype' => 1,
                    'values' => [$category->id],
                    'filteroptions' => ['includesubcategories' => true],
                ],
            ],
        ]);

        // Without this the quiz is worth whatever it was worth before, which
        // for a quiz that just went from three questions to thirty is wrong
        // everywhere it is displayed.
        \mod_quiz\quiz_settings::create($quizid)
            ->get_grade_calculator()->recompute_quiz_sumgrades();

        return ['added' => $count, 'removed' => $removed];
    }
}
