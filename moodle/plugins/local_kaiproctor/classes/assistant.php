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
     *  Set by hand rather than measured, and it is the number most likely to
     *  need changing once real questions arrive: too low and the assistant
     *  answers from loosely related pages, too high and it shrugs at
     *  reasonable questions. */
    const MIN_SCORE = 0.08;

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
            // Absolute links, because the answer is prose the learner reads and
            // may copy elsewhere; a bare path stops working the moment it
            // leaves the page.
            $item['url'] = $CFG->wwwroot . $item['url'];
            unset($item['score']);
            $context[] = $item;
        }

        return ai_client::call('/ask', ['question' => $question, 'context' => $context]);
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
    public static function rank(string $question, array $items): array {
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
            $score = $title + ($summary * 0.3);

            if ($score >= self::MIN_SCORE) {
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
