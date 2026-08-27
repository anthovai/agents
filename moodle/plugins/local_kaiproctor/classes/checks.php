<?php
// Recording identity / presence check results.
//
// The threshold in force is written into every row rather than resolved from
// configuration when the row is read back. An admin who raises the threshold
// next month must not silently rewrite the meaning of last month's decisions.
//
// Which threshold, though, has to be the one the decision was actually made
// against. Callers pass it back from the service's reply for that reason: the
// row used to be stamped with whatever the setting said at the time, and the
// setting was not what the service was deciding on. A record that names the
// wrong rule is worse than one that names none, because it will be quoted.

namespace local_kaiproctor;

defined('MOODLE_INTERNAL') || die();

class checks {

    public static function record(
        int $userid,
        \context $context,
        string $kind,
        string $decision,
        ?float $similarity = null,
        ?float $livenessscore = null,
        ?string $modelpack = null,
        ?int $attemptid = null,
        array $detail = [],
        ?int $sessionid = null,
        ?float $threshold = null
    ): int {
        global $DB;

        // Only for a check that did not reach the service — a presence
        // failure, an enrolment. Anything the service decided arrives with
        // the threshold it decided on.
        if ($threshold === null) {
            $threshold = face_client::configured_threshold('matchthreshold');
        }

        return $DB->insert_record('local_kaiproctor_check', (object) [
            'userid' => $userid,
            'contextid' => $context->id,
            'attemptid' => $attemptid,
            'sessionid' => $sessionid,
            'kind' => $kind,
            'decision' => $decision,
            'similarity' => $similarity,
            'livenessscore' => $livenessscore,
            'threshold' => $threshold,
            'modelpack' => $modelpack,
            'detail' => $detail ? json_encode($detail, JSON_UNESCAPED_UNICODE) : null,
            'timecreated' => time(),
        ]);
    }

    /** Checks for one learner in one context, oldest first — the audit view. */
    public static function for_user(int $userid, \context $context, ?int $attemptid = null): array {
        global $DB;

        $conditions = ['userid' => $userid, 'contextid' => $context->id];
        if ($attemptid !== null) {
            $conditions['attemptid'] = $attemptid;
        }
        return $DB->get_records('local_kaiproctor_check', $conditions, 'timecreated ASC');
    }
}
