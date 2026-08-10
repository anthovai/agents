<?php
// Event observers.

namespace local_kaiproctor;

defined('MOODLE_INTERNAL') || die();

class observer {

    /**
     * Record the paper a learner was given, as soon as the attempt exists.
     *
     * The seed was chosen and applied by quizaccess_kaiproctor just before the
     * draw; here the result is written down. Both halves matter: the seed shows
     * the paper was not picked, and the recorded question ids survive a bank
     * edit or a core upgrade that would stop the seed reproducing it.
     *
     * @param \mod_quiz\event\attempt_started $event
     */
    public static function attempt_started(\mod_quiz\event\attempt_started $event): void {
        global $SESSION, $DB;

        $attempt = $DB->get_record('quiz_attempts', ['id' => $event->objectid]);
        if (!$attempt || $attempt->preview) {
            return;
        }

        // Only quizzes this plugin is actually proctoring; anything else was
        // drawn by Moodle's own randomness and nothing here would be true of it.
        if (!$DB->record_exists('quizaccess_kaiproctor',
                ['quizid' => $attempt->quiz, 'enabled' => 1])) {
            return;
        }

        $seed = $SESSION->kaiproctordrawseed[$attempt->quiz] ?? null;
        if ($seed === null) {
            return;
        }
        unset($SESSION->kaiproctordrawseed[$attempt->quiz]);

        exam_draw::record($attempt, (int) $seed);
    }
}
