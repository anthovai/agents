<?php
// Scheduled task: close sittings that nobody ended.
//
// A learner who shuts the laptop mid-lesson never sends the closing call. The
// row would sit at "active" indefinitely, which reads as "still in progress"
// to whoever audits it later. Marking it abandoned records what is actually
// known: monitoring stopped, and not because the sitting finished.

namespace local_kaiproctor\task;

defined('MOODLE_INTERNAL') || die();

class close_stale_sessions extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('task:closestalesessions', 'local_kaiproctor');
    }

    public function execute() {
        $closed = \local_kaiproctor\session::close_stale();
        mtrace("local_kaiproctor: closed {$closed} abandoned sessions");
    }
}
