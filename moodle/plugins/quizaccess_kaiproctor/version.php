<?php
// Quiz access rule: gates a quiz attempt on face verification and runs the
// attention monitor for its duration. All the heavy lifting lives in
// local_kaiproctor; this plugin is the hook into mod_quiz.

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'quizaccess_kaiproctor';
$plugin->version   = 2026081500;
$plugin->requires  = 2024100700; // Moodle 4.5 LTS.
$plugin->dependencies = [
    'local_kaiproctor' => 2026081003,
];
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.1.0-dev';
