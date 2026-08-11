<?php
// When this activity counts as done.
//
// "Viewed" is the default and it is nearly worthless here: opening a video and
// closing it is not watching a lesson. The two rules below are the ones a
// course designer actually means when they tick completion on an interactive
// video — answered everything, or reached the end — and they are separate
// because they fail differently. Somebody can answer every question and stop
// halfway through the last five minutes; somebody else can let it play to the
// end in another tab and answer nothing.

namespace mod_kaivideo\completion;

defined('MOODLE_INTERNAL') || die();

use core_completion\activity_custom_completion;

class custom_completion extends activity_custom_completion {

    public function get_state(string $rule): int {
        global $DB;

        $this->validate_rule($rule);

        $video = $DB->get_record('kaivideo', ['id' => $this->cm->instance], '*', MUST_EXIST);

        if ($rule === 'completionanswerall') {
            $total = $DB->count_records('kaivideo_item', ['kaivideoid' => $video->id]);
            if (!$total) {
                // A video with no questions cannot be completed by answering
                // them. Saying "done" would make the rule mean nothing on
                // exactly the activities where an author forgot to add any.
                return COMPLETION_INCOMPLETE;
            }
            $answered = count(\mod_kaivideo\responses::latest(
                (int) $video->id, (int) $this->userid));
            return $answered >= $total ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
        }

        // completionwatched
        $progress = \mod_kaivideo\responses::progress((int) $video->id, (int) $this->userid);
        return $progress['finished'] ? COMPLETION_COMPLETE : COMPLETION_INCOMPLETE;
    }

    public static function get_defined_custom_rules(): array {
        return ['completionanswerall', 'completionwatched'];
    }

    public function get_custom_rule_descriptions(): array {
        return [
            'completionanswerall' => get_string('completionanswerall_desc', 'mod_kaivideo'),
            'completionwatched' => get_string('completionwatched_desc', 'mod_kaivideo'),
        ];
    }

    public function get_sort_order(): array {
        return [
            'completionview',
            'completionanswerall',
            'completionwatched',
        ];
    }
}
