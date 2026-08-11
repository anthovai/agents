<?php
// The timeline: which questions interrupt the video, and when.
//
// This is the half of an interactive video that is data rather than interface,
// and it is deliberately separated from the player for the same reason the
// face pipeline is a service: it can be read, tested and argued about without
// a browser. The player is the only part that has to be UI.
//
// One rule shapes the whole file. The correct answer never leaves the server
// before the learner has answered. A timeline handed to the browser with the
// key in it is not an assessment, it is a quiz with the answers printed on the
// back, and no amount of client-side care fixes that.

namespace mod_kaivideo;

defined('MOODLE_INTERNAL') || die();

class timeline {

    /** Two questions closer than this are indistinguishable to a viewer. */
    const MIN_GAP = 0.5;

    const MAX_CHOICES = 6;
    const MIN_CHOICES = 2;

    /**
     * The timeline as the browser may see it: no correct answers.
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
                'questiontext' => $record->questiontext,
                'choices' => json_decode($record->choices, true) ?: [],
                // correctchoice and feedback are absent on purpose. They are
                // returned by responses::answer(), after a choice is in.
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
            $items[] = [
                'id' => (int) $record->id,
                'attime' => (float) $record->attime,
                'attimelabel' => self::clock((float) $record->attime),
                'type' => $record->type,
                'questiontext' => $record->questiontext,
                'choices' => json_decode($record->choices, true) ?: [],
                'correctchoice' => (int) $record->correctchoice,
                'feedback' => (string) $record->feedback,
            ];
        }
        return $items;
    }

    /**
     * Add or replace one question.
     *
     * @param int $kaivideoid
     * @param array $data attime, questiontext, choices, correctchoice, feedback
     * @param int|null $itemid to replace, or null to add
     * @return int the item id
     * @throws \moodle_exception on anything that would produce a broken question
     */
    public static function save(int $kaivideoid, array $data, ?int $itemid = null): int {
        global $DB;

        $attime = round((float) ($data['attime'] ?? 0), 2);
        if ($attime < 0) {
            throw new \moodle_exception('error:negativetime', 'mod_kaivideo');
        }

        $text = trim((string) ($data['questiontext'] ?? ''));
        if ($text === '') {
            throw new \moodle_exception('error:noquestion', 'mod_kaivideo');
        }

        // Blank choices are dropped rather than rejected: the editor offers a
        // fixed number of boxes and an author filling three of six has not
        // made a mistake.
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

        $correct = (int) ($data['correctchoice'] ?? 0);
        if ($correct < 0 || $correct >= count($choices)) {
            // Reachable by deleting the choice that was marked correct, which
            // is an easy thing to do and produces a question nobody can pass.
            throw new \moodle_exception('error:badcorrectchoice', 'mod_kaivideo');
        }

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
            'type' => 'question',
            'questiontext' => $text,
            'choices' => json_encode(array_values($choices), JSON_UNESCAPED_UNICODE),
            'correctchoice' => $correct,
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
     * Delete one question, and the answers to it.
     *
     * Responses are removed with it rather than orphaned: a response whose
     * question no longer exists cannot be reported on, cannot be regraded, and
     * would quietly count towards nothing while still holding somebody's
     * answer. Grades are recalculated by the caller.
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
