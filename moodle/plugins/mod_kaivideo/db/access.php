<?php
// Who may do what.

defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'mod/kaivideo:addinstance' => [
        'riskbitmask' => RISK_XSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => ['editingteacher' => CAP_ALLOW, 'manager' => CAP_ALLOW],
        'clonepermissionsfrom' => 'moodle/course:manageactivities',
    ],
    'mod/kaivideo:view' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => ['student' => CAP_ALLOW, 'teacher' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW, 'manager' => CAP_ALLOW],
    ],
    'mod/kaivideo:answer' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => ['student' => CAP_ALLOW],
    ],
    // Editing the timeline means seeing the correct answers, so it is a
    // separate capability from viewing the activity.
    'mod/kaivideo:edititems' => [
        'riskbitmask' => RISK_XSS,
        'captype' => 'write',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => ['editingteacher' => CAP_ALLOW, 'manager' => CAP_ALLOW],
    ],
    'mod/kaivideo:viewreport' => [
        'captype' => 'read',
        'contextlevel' => CONTEXT_MODULE,
        'archetypes' => ['teacher' => CAP_ALLOW, 'editingteacher' => CAP_ALLOW,
            'manager' => CAP_ALLOW],
    ],
];
