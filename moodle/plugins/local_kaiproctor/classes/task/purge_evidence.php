<?php
// Scheduled task: drop evidence past its retention date.
//
// PDPA requires that biometric evidence is not kept longer than the purpose
// needs. Leaving this to an admin remembering to click something is not a
// retention policy.

namespace local_kaiproctor\task;

defined('MOODLE_INTERNAL') || die();

class purge_evidence extends \core\task\scheduled_task {

    public function get_name(): string {
        return get_string('task:purgeevidence', 'local_kaiproctor');
    }

    public function execute() {
        $removed = \local_kaiproctor\evidence::purge_expired();
        mtrace("local_kaiproctor: purged {$removed} expired evidence records");
    }
}
