<?php
defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => 'local_kaiproctor\task\purge_evidence',
        // Nightly, off-peak. Retention is measured in days, so the hour it
        // runs at does not matter beyond staying out of the way.
        'blocking' => 0,
        'minute' => '17',
        'hour' => '3',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
    [
        // Sittings nobody closed — a shut laptop, a dropped connection. Left
        // alone they read as "still in progress" to anyone auditing later.
        'classname' => 'local_kaiproctor\\task\\close_stale_sessions',
        'blocking' => 0,
        'minute' => '*/15',
        'hour' => '*',
        'day' => '*',
        'month' => '*',
        'dayofweek' => '*',
    ],
];
