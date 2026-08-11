<?php
// What the player is allowed to call.

defined('MOODLE_INTERNAL') || die();

$functions = [
    'mod_kaivideo_answer_item' => [
        'classname' => 'mod_kaivideo\external\answer_item',
        'description' => 'Record a learner\'s answer to one timeline question and return whether it was right.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
    'mod_kaivideo_record_progress' => [
        'classname' => 'mod_kaivideo\external\record_progress',
        'description' => 'Note how far a learner has watched.',
        'type' => 'write',
        'ajax' => true,
        'loginrequired' => true,
    ],
];
