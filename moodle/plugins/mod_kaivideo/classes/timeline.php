<?php
// The timeline: what interrupts the video, and when.
//
// This is the half of an interactive video that is data rather than interface,
// and it is deliberately separated from the player for the same reason the face
// pipeline is a service: it can be read, tested and argued about without a
// browser. The player is the only part that has to be UI.
//
// One rule shapes the whole file. The answer never leaves the server before the
// learner has answered. A timeline handed to the browser with the key in it is
// not an assessment, it is a quiz with the answers printed on the back, and no
// amount of client-side care fixes that.

namespace mod_kaivideo;

defined('MOODLE_INTERNAL') || die();

class timeline {

    /** Two interruptions closer than this are indistinguishable to a viewer. */
    const MIN_GAP = 0.5;

    const MAX_CHOICES = 6;
    const MIN_CHOICES = 2;

    /** Longest accepted answer for a typed question. */
    const MAX_ANSWER = 120;

    /**
     * What an interruption can be.
     *
     *   choice        one right answer out of several
     *   multichoice   several right answers, all of them required
     *   shorttext     the learner types it, matched against a list
     *   info          not a question: the video stops and says something
     *
     * 'info' is here because it is what authors reach for most and it is the
     * one thing a timeline of questions cannot express: stop, make a point,
     * carry on. Without it people write a question with one obvious answer,
     * which is a worse version of the same thing.
     */
    const TYPES = ['choice', 'multichoice', 'shorttext', 'info'];

    /** Types the learner answers, as opposed to acknowledges. */
    const GRADED = ['choice', 'multichoice', 'shorttext'];

    /**
     * The timeline as the browser may see it: no answers.
     *
     * @param int $kaivideoid
     * @return array
     */
    public static function for_player(int $kaivideoid): array {
        global $DB;

        $items = [];
        foreach ($DB->get_records('kaivideo_item', ['kaivideoid' => $kaivideoid],
                'attime ASC, id ASC') as $record) {
            $items[] = [
                'id' => (int) $record->id,
                'attime' => (float) $record->attime,
                'type' => $record->type,
                'graded' => in_array($record->type, self::GRADED, true),
                'questiontext' => $record->questiontext,
                // A typed question shows no options, and an info card shows
                // none either — sending them would be sending the answer.
                'choices' => in_array($record->type, ['choice', 'multichoice'], true)
                    ? (json_decode($record->choices, true) ?: []) : [],
                // An info card has nothing to withhold: its whole content is
                // the message, and there is no answer to protect.
                'feedback' => $record->type === 'info' ? (string) $record->feedback : '',
            ];
        }
        return $items;
    }

    /** The full record, for staff who are allowed to see the answers. */
    public static function for_editing(int $kaivideoid): array {
        global $DB;

        $items = [];
        foreach ($DB->get_records('kaivideo_item', ['kaivideoid' => $kaivideoid],
                'attime ASC, id ASC') as $record) {
            $choices = json_decode($record->choices, true) ?: [];
            $answers = json_decode($record->answers, true) ?: [];

            $items[] = [
                'id' => (int) $record->id,
                'attime' => (float) $record->attime,
                'attimelabel' => self::clock((float) $record->attime),
                'type' => $record->type,
                'typelabel' => get_string('type:' . $record->type, 'mod_kaivideo'),
                'questiontext' => $record->questiontext,
                'choices' => $choices,
                'answers' => $answers,
                'answerlabel' => self::describe_answer($record->type, $choices, $answers),
                'feedback' => (string) $record->feedback,
            ];
        }
        return $items;
    }

    /**
     * The answer in words, for a list a human reads.
     *
     * @param string $type
     * @param array $choices
     * @param array $answers
     * @return string
     */
    public static function describe_answer(string $type, array $choices,
            array $answers): string {
        if ($type === 'info') {
            return '-';
        }
        if ($type === 'shorttext') {
            return implode(' / ', $answers);
        }

        $named = [];
        foreach ($answers as $index) {
            if (isset($choices[$index])) {
                $named[] = $choices[$index];
            }
        }
        return implode(' + ', $named);
    }

    /**
     * Add or replace one item.
     *
     * @param int $kaivideoid
     * @param array $data attime, type, questiontext, choices, answers, feedback
     * @param int|null $itemid to replace, or null to add
     * @return int the item id
     * @throws \moodle_exception on anything that would produce a broken item
     */
    public static function save(int $kaivideoid, array $data, ?int $itemid = null): int {
        global $DB;

        $type = (string) ($data['type'] ?? 'choice');
        if (!in_array($type, self::TYPES, true)) {
            throw new \moodle_exception('error:badtype', 'mod_kaivideo');
        }

        $attime = round((float) ($data['attime'] ?? 0), 2);
        if ($attime < 0) {
            throw new \moodle_exception('error:negativetime', 'mod_kaivideo');
        }

        $text = trim((string) ($data['questiontext'] ?? ''));
        if ($text === '') {
            throw new \moodle_exception('error:noquestion', 'mod_kaivideo');
        }

        [$choices, $answers] = self::validate_answer($type, $data);

        $clash = $DB->get_records_select('kaivideo_item',
            'kaivideoid = :id AND attime > :low AND attime < :high',
            ['id' => $kaivideoid, 'low' => $attime - self::MIN_GAP,
             'high' => $attime + self::MIN_GAP]);
        unset($clash[$itemid]);
        if ($clash) {
            throw new \moodle_exception('error:tooclose', 'mod_kaivideo');
        }

        $record = (object) [
            'kaivideoid' => $kaivideoid,
            'attime' => $attime,
            'type' => $type,
            'questiontext' => $text,
            'choices' => json_encode(array_values($choices), JSON_UNESCAPED_UNICODE),
            'answers' => json_encode(array_values($answers), JSON_UNESCAPED_UNICODE),
            'feedback' => trim((string) ($data['feedback'] ?? '')),
        ];

        if ($itemid) {
            $record->id = $itemid;
            $DB->update_record('kaivideo_item', $record);
            return $itemid;
        }

        $record->timecreated = time();
        return (int) $DB->insert_record('kaivideo_item', $record);
    }

    /**
     * Whatever the type needs, checked for the ways it can be wrong.
     *
     * @param string $type
     * @param array $data
     * @return array [choices, answers]
     */
    protected static function validate_answer(string $type, array $data): array {
        if ($type === 'info') {
            // Nothing to check. An info card's message is its question text.
            return [[], []];
        }

        if ($type === 'shorttext') {
            $accepted = [];
            foreach ((array) ($data['answers'] ?? []) as $answer) {
                $answer = self::normalise((string) $answer);
                if ($answer !== '' && !in_array($answer, $accepted, true)) {
                    $accepted[] = $answer;
                }
            }
            if (!$accepted) {
                throw new \moodle_exception('error:noacceptedanswer', 'mod_kaivideo');
            }
            foreach ($accepted as $answer) {
                if (\core_text::strlen($answer) > self::MAX_ANSWER) {
                    throw new \moodle_exception('error:answertoolong', 'mod_kaivideo');
                }
            }
            return [[], $accepted];
        }

        // Blank options are dropped rather than rejected: the editor offers a
        // fixed number of boxes and an author filling three of six has not made
        // a mistake.
        $choices = [];
        foreach ((array) ($data['choices'] ?? []) as $choice) {
            $choice = trim((string) $choice);
            if ($choice !== '') {
                $choices[] = $choice;
            }
        }
        if (count($choices) < self::MIN_CHOICES) {
            throw new \moodle_exception('error:toofewchoices', 'mod_kaivideo');
        }
        if (count($choices) > self::MAX_CHOICES) {
            throw new \moodle_exception('error:toomanychoices', 'mod_kaivideo');
        }

        $answers = [];
        foreach ((array) ($data['answers'] ?? []) as $index) {
            $index = (int) $index;
            // Reachable by marking option 5 correct and then clearing it, which
            // is easy to do and produces a question nobody can pass.
            if ($index < 0 || $index >= count($choices)) {
                throw new \moodle_exception('error:badcorrectchoice', 'mod_kaivideo');
            }
            if (!in_array($index, $answers, true)) {
                $answers[] = $index;
            }
        }

        if (!$answers) {
            throw new \moodle_exception('error:badcorrectchoice', 'mod_kaivideo');
        }
        if ($type === 'choice' && count($answers) > 1) {
            throw new \moodle_exception('error:onlyoneanswer', 'mod_kaivideo');
        }
        if ($type === 'multichoice' && count($answers) === count($choices)) {
            // Every option correct is not a question. Caught because it is a
            // symptom of ticking boxes while editing rather than a design.
            throw new \moodle_exception('error:allanswerscorrect', 'mod_kaivideo');
        }

        sort($answers);
        return [$choices, $answers];
    }

    /**
     * How a typed answer is compared.
     *
     * Whitespace and Latin case are forgiven; nothing else is. Stripping Thai
     * tone marks would make ผู้ and ผู the same word, which is not leniency —
     * it is accepting a misspelling as correct. Authors who want variants list
     * them instead, which keeps the decision with the person who knows the
     * subject.
     *
     * @param string $text
     * @return string
     */
    public static function normalise(string $text): string {
        $text = trim(preg_replace('/\s+/u', ' ', $text));
        return \core_text::strtolower($text);
    }

    /**
     * Delete one item, and the answers to it.
     *
     * Responses go with it rather than being orphaned: a response whose
     * question no longer exists cannot be reported on, cannot be regraded, and
     * would quietly count towards nothing while still holding somebody's
     * answer.
     */
    public static function delete(int $itemid): void {
        global $DB;

        $DB->delete_records('kaivideo_response', ['itemid' => $itemid]);
        $DB->delete_records('kaivideo_item', ['id' => $itemid]);
    }

    /** mm:ss, for a human reading a list of timestamps. */
    public static function clock(float $seconds): string {
        $seconds = max(0, (int) round($seconds));
        return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
    }
}
