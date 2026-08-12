<?php
// Where the plugin attaches itself to pages it does not own.

defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        // The assistant's launcher, on every page rather than only on its own.
        // Somebody stuck on a page is stuck there; making them navigate to a
        // help page to ask where things are is the shape of the problem.
        'hook' => core\hook\output\before_footer_html_generation::class,
        'callback' => 'local_kaiproctor\hooks::add_assistant_launcher',
    ],
    [
        // Monitoring, which used to be a legacy before_footer callback. It has
        // to be here now: a component with any hook callback stops getting its
        // legacy footer function called at all.
        'hook' => core\hook\output\before_footer_html_generation::class,
        'callback' => 'local_kaiproctor\hooks::start_monitor',
    ],
];
