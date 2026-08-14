<?php
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        // Fires once the attempt and its questions exist, which is the first
        // moment there is a paper to write down.
        'eventname' => '\mod_quiz\event\attempt_started',
        'callback' => 'local_kaiproctor\observer::attempt_started',
    ],
    [
        // The monitored flag belongs to this plugin, so the activity being
        // deleted cannot clean it up. Left behind, the row is not merely
        // untidy: course-module ids are reused by restore, and a new activity
        // landing on a recycled id would arrive silently proctored.
        'eventname' => '\core\event\course_module_deleted',
        'callback' => 'local_kaiproctor\observer::course_module_deleted',
    ],
];
