<?php
// All functions are ajax-callable and none are exposed to external tokens:
// they exist for the browser modules running inside a logged-in session, and
// every one of them either reads or writes biometric data.

defined('MOODLE_INTERNAL') || die();

$functions = [
    'local_kaiproctor_analyze_frame' => [
        'classname' => 'local_kaiproctor\external\analyze_frame',
        'description' => 'Presence, head pose and liveness for one camera frame.',
        'type' => 'read',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_kaiproctor_enrol_face' => [
        'classname' => 'local_kaiproctor\external\enrol_face',
        'description' => 'Store the learner\'s reference face embedding.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_kaiproctor_verify_frame' => [
        'classname' => 'local_kaiproctor\external\verify_frame',
        'description' => 'Check a live frame against the learner\'s enrolled face.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_kaiproctor_log_event' => [
        'classname' => 'local_kaiproctor\external\log_event',
        'description' => 'Record an attention signal raised in the browser.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'local_kaiproctor_store_evidence' => [
        'classname' => 'local_kaiproctor\external\store_evidence',
        'description' => 'Store a proctoring snapshot or clip.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
];
