<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = [
    // Learners enrol their own face; nobody enrols it for them.
    'local/kaiproctor:enrolface' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'user' => CAP_ALLOW,
        ],
    ],

    // Biometric evidence is deliberately not visible to every teacher by
    // default — a site decides who may look at it.
    'local/kaiproctor:viewevidence' => [
        'captype' => 'read',
        'riskbitmask' => RISK_PERSONAL,
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],

    'local/kaiproctor:manage' => [
        'captype' => 'write',
        'riskbitmask' => RISK_CONFIG | RISK_PERSONAL,
        'contextlevel' => CONTEXT_SYSTEM,
        'archetypes' => [
            'manager' => CAP_ALLOW,
        ],
    ],
];
