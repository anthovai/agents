<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'KAISER Proctor quiz access rule';
$string['enable'] = 'Proctor this quiz';
$string['enable_help'] = 'Requires the learner to confirm their identity by webcam before starting, and monitors attention for the duration of the attempt. The learner must have enrolled their face first.';
$string['description'] = 'This quiz is proctored: your identity is confirmed by webcam and your attention is monitored while you answer.';
$string['privacy:metadata'] = 'The KAISER Proctor quiz access rule stores only which quizzes are proctored. Personal data captured during an attempt is held by the KAISER Proctor plugin.';

$string['mustenrol'] = 'This quiz confirms your identity by camera, but you have not enrolled your face yet. <a href="{$a}">Enrol your face</a>, then return here.';
$string['preflight:header'] = 'Confirm your identity';
$string['preflight:intro'] = 'Before starting, look at the camera and follow the instructions. You cannot begin until your identity is confirmed.';
$string['preflight:verify'] = 'Confirm identity';
$string['preflight:verified'] = 'Identity confirmed. You may start the attempt.';
$string['preflight:failed'] = 'Your identity could not be confirmed. Make sure the room is well lit and only you are in frame, then try again.';
$string['preflight:notverified'] = 'You must confirm your identity before starting this attempt.';
