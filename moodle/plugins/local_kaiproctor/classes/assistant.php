<?php
// Answering "how do I get to..." with a link that actually works.
//
// Retrieval happens here and generation happens in the reviewer service, and
// that split is the whole design. Two consequences follow from it:
//
//   1. Nothing the learner cannot open is ever sent, because the list comes
//      from site_index, which asks Moodle rather than a copy of Moodle.
//
//   2. When nothing matches, no model is called at all. A language model asked
//      a question with no supporting material will answer it anyway, from
//      whatever it learned elsewhere, and a confident answer about a page that
//      does not exist on this site is the failure mode worth engineering
//      against. Refusing before the call is cheaper and more reliable than
//      asking a model to refuse.

namespace local_kaiproctor;

defined('MOODLE_INTERNAL') || die();

class assistant {

    /** How many pages the model is shown. Enough to choose from, few enough
     *  that the good match is not buried. */
    const CONTEXT_SIZE = 8;

    /** Below this, "no match" is the honest answer.
     *
     *  Measured, not chosen: see reports/ASK-CALIBRATION.txt and the sweep in
     *  tests/support/calibrate-ask.php. 0.14 is the highest threshold that
     *  turns away every off-topic question in the set while keeping recall at
     *  its best — sitting at the top of a plateau rather than at its edge, so
     *  a differently worded question does not flip the outcome.
     *
     *  Zero off-topic acceptance is treated as a constraint rather than as one
     *  side of a trade, because "a question with no matching page never
     *  reaches a model" is a claim this feature makes, and a threshold that
     *  admits one in ten only approximates it.
     *
     *  Recompute against questions real learners typed before relying on it:
     *  25 questions cannot resolve better than about 4 percentage points.
     *
     *  Re-measured when grades were added: questions about a result often name
     *  no page at all ("ยังสอบได้อีกกี่ครั้ง"), so the words for a result now
     *  reach the quizzes, and the threshold moved from 0.14 to 0.10. */
    const MIN_SCORE = 0.10;

    public static function is_available(): bool {
        return ai_client::is_configured();
    }

    /**
     * @param string $question as typed
     * @param int $userid whose view of the site to search
     * @return array {ok, answer, sources} or {ok:false, error}
     */
    public static function answer(string $question, int $userid): array {
        global $CFG;

        $question = trim($question);
        if ($question === '') {
            return self::fail('empty_question', '');
        }

        $ranked = self::rank($question, site_index::for_user($userid));
        if (!$ranked) {
            return self::fail('no_match',
                get_string('ask:nomatch', 'local_kaiproctor'));
        }

        $context = [];
        foreach (array_slice($ranked, 0, self::CONTEXT_SIZE) as $item) {
            // Fetched now rather than read from the cached index: somebody who
            // has just finished a quiz must not be told last week's mark.
            // Only for the few pages an answer will actually use, and only
            // ever for the person asking.
            $facts = self::facts_for($item, $userid);
            if ($facts) {
                $item['facts'] = $facts;
            }

            // Absolute links, because the answer is prose the learner reads and
            // may copy elsewhere; a bare path stops working the moment it
            // leaves the page.
            $item['url'] = $CFG->wwwroot . $item['url'];
            // Scoring and lookup detail, not something the model has any use
            // for. The contract would refuse these fields; dropping them here
            // keeps that refusal meaning a genuine mistake.
            unset($item['score'], $item['keywords'], $item['cmid'], $item['courseid']);
            $context[] = $item;
        }

        return ai_client::call('/ask', ['question' => $question, 'context' => $context]);
    }

    /**
     * The learner's own record for one page, when there is one to have.
     *
     * Quizzes only, for now: a grade and an attempt count are what people ask
     * about. Nothing here reads another user's row — $userid is the person who
     * typed the question, and it is the only user id that reaches the
     * gradebook.
     *
     * @param array $item an index entry
     * @param int $userid the person asking
     * @return array|null
     */
    protected static function facts_for(array $item, int $userid): ?array {
        if (($item['kind'] ?? '') !== 'quiz' || empty($item['cmid'])) {
            return null;
        }

        try {
            $modinfo = get_fast_modinfo((int) $item['courseid'], $userid);
            $cm = $modinfo->get_cm((int) $item['cmid']);
        } catch (\moodle_exception $error) {
            // The activity moved or vanished since the index was cached. A
            // navigation answer without the grade attached is still useful,
            // and better than a page that fails because of a stale cache.
            return null;
        }

        // Second check, cheap and worth it: uservisible was true when the
        // index was built, which is not the same as now.
        if (!$cm->uservisible) {
            return null;
        }

        return learner_facts::for_quiz($cm, $userid);
    }

    /**
     * The same lookup, reachable from the test-support CLI.
     *
     * A named seam rather than making facts_for() public: what is disclosed to
     * the model is worth a test, and the alternative is widening the real API
     * so a test can reach it.
     *
     * @param array $item
     * @param int $userid
     * @return array|null
     */
    public static function facts_for_testing(array $item, int $userid): ?array {
        return self::facts_for($item, $userid);
    }

    /**
     * Score the index against the question, best first.
     *
     * Character trigrams rather than words, because Thai is written without
     * spaces: any word-boundary scheme needs a dictionary, and getting that
     * wrong fails silently by simply never matching. Trigrams need no
     * dictionary and degrade gracefully — a partly-typed Thai word still
     * overlaps the title it belongs to.
     *
     * @return array items scoring above MIN_SCORE, each with 'score'
     */
    public static function rank(string $question, array $items,
            ?float $minscore = null): array {
        // Overridable so the threshold can be swept against a labelled set
        // rather than guessed. See tests/support/calibrate-ask.php.
        $minscore = $minscore ?? self::MIN_SCORE;
        $wanted = self::trigrams($question);
        if (!$wanted) {
            return [];
        }

        $scored = [];
        foreach ($items as $item) {
            // The title carries the answer; the summary is context and would
            // drown it if both counted equally.
            $title = self::overlap($wanted, self::trigrams($item['title']));
            $summary = self::overlap($wanted, self::trigrams($item['summary'] ?? ''));
            // Kind words weigh less than the title but more than the course
            // name a hundred pages share: "ข้อสอบ" should surface the quizzes
            // without letting the course they sit in outrank them.
            $kind = self::overlap($wanted, self::trigrams($item['keywords'] ?? ''));
            $score = $title + ($summary * 0.3) + ($kind * 0.35);

            if ($score >= $minscore) {
                $item['score'] = round($score, 4);
                $scored[] = $item;
            }
        }

        usort($scored, static fn($a, $b) => $b['score'] <=> $a['score']);
        return $scored;
    }

    /** Overlap as a fraction of the question, not of the title: a long title
     *  that happens to contain the question should not be penalised for its
     *  length. */
    protected static function overlap(array $wanted, array $have): float {
        if (!$wanted || !$have) {
            return 0.0;
        }
        return count(array_intersect_key($wanted, $have)) / count($wanted);
    }

    /** @return array trigram => true */
    protected static function trigrams(string $text): array {
        $text = \core_text::strtolower(trim(preg_replace('/\s+/u', ' ', $text)));
        $length = \core_text::strlen($text);
        if ($length < 3) {
            return $length ? [$text => true] : [];
        }

        $grams = [];
        for ($at = 0; $at <= $length - 3; $at++) {
            $grams[\core_text::substr($text, $at, 3)] = true;
        }
        return $grams;
    }

    protected static function fail(string $code, string $message): array {
        return ['ok' => false, 'error' => ['code' => $code, 'message' => $message]];
    }
}
