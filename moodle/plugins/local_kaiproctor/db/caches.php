<?php
// The per-user page index.
//
// Rebuilding it means walking every course the learner is in, so it is cached
// — but only briefly, and keyed by user. Sharing one index between users would
// be the fastest way to show somebody a course they are not enrolled in.

defined('MOODLE_INTERNAL') || die();

$definitions = [
    'siteindex' => [
        'mode' => cache_store::MODE_APPLICATION,
        'simplekeys' => true,
        'simpledata' => false,
        // Enrolments and activity visibility change under it; the stored
        // timestamp is what actually bounds staleness, see site_index.
        'staticacceleration' => true,
        'staticaccelerationsize' => 4,
    ],
];
