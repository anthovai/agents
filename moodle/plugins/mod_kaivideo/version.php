<?php
// Interactive video, written here rather than borrowed.
//
// A third-party plugin (mod_interactivevideo) did this well, and was installed
// alongside for comparison until ours covered everything the customer needs.
// What it could never be is ours: the customer has to be able to audit all
// three pieces they are buying, and a third-party GPL-3 plugin can be read but
// not answered for. We cannot promise a fix date on somebody else's tracker.
//
// So this is a smaller thing done properly rather than a larger thing wrapped.
// It plays a video, interrupts it at timestamps with questions, records what
// the learner answered, and can refuse to continue until they do — and every
// part of that is code we can show, support and change.

defined('MOODLE_INTERNAL') || die();

$plugin->component = 'mod_kaivideo';
$plugin->version   = 2026081700;
$plugin->requires  = 2024100700; // Moodle 4.5 LTS.
$plugin->dependencies = [
    // Monitoring is optional at runtime — an unproctored interactive video is
    // a perfectly good activity — but the two ship together and the adapter
    // that watches this player lives over there.
    'local_kaiproctor' => 2026081008,
];
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.1.0-dev';
