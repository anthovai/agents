<?php
defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        // Fires once the attempt and its questions exist, which is the first
        // moment there is a paper to write down.
        'eventname' => '\mod_quiz\event\attempt_started',
        'callback' => 'local_kaiproctor\observer::attempt_started',
    ],
];
