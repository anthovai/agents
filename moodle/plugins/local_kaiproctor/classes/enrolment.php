<?php
// Face enrolment: the learner's reference embedding.
//
// Only one row per learner is active. Superseded rows stay until purged so an
// auditor can see that a learner re-enrolled and when.

namespace local_kaiproctor;

defined('MOODLE_INTERNAL') || die();

class enrolment {

    /** The learner's active embedding, or null if they have not enrolled. */
    public static function get_active(int $userid): ?\stdClass {
        global $DB;

        $records = $DB->get_records('local_kaiproctor_face',
            ['userid' => $userid, 'active' => 1], 'timecreated DESC', '*', 0, 1);
        return $records ? reset($records) : null;
    }

    public static function has_enrolled(int $userid): bool {
        return self::get_active($userid) !== null;
    }

    /**
     * Store a new embedding, retiring any previous one.
     *
     * @param array $challenge the active-liveness record that gated this
     *        enrolment — kept verbatim as evidence that a live person enrolled
     */
    public static function store(int $userid, string $embedding, int $dimensions,
                                 string $modelpack, array $challenge): int {
        global $DB;

        $transaction = $DB->start_delegated_transaction();

        $DB->set_field('local_kaiproctor_face', 'active', 0, ['userid' => $userid]);
        $id = $DB->insert_record('local_kaiproctor_face', (object) [
            'userid' => $userid,
            'embedding' => $embedding,
            'dimensions' => $dimensions,
            'modelpack' => $modelpack,
            'challenge' => json_encode($challenge, JSON_UNESCAPED_UNICODE),
            'active' => 1,
            'timecreated' => time(),
        ]);

        $transaction->allow_commit();
        return $id;
    }
}
