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
$string['preflight:cameraoff'] = 'The camera turns on when you press "Confirm identity" below.';
$string['preflight:checking'] = 'Comparing with your enrolled face…';
$string['preflight:stepverify'] = 'Match face';
$string['preflight:verified'] = 'Identity confirmed. You may start the attempt.';
// Named causes. The generic message below is the last resort, not the default:
// telling everyone to check the lighting sends people to fix the wrong thing.
$string['preflight:nomatch'] = 'This face does not match the one on file. If this is your account, enrol your face again and retry.';
$string['preflight:spoof'] = 'You could not be confirmed as a live person. Look at the camera yourself rather than holding up a photograph or another screen.';
$string['preflight:review'] = 'The comparison was not clear enough to decide. Move so your face is more clearly visible, then try again.';
$string['preflight:noface'] = 'The camera could not find a face. Sit facing the camera with nothing covering your face, then try again.';
$string['preflight:toosmall'] = 'Your face is too small in the frame. Move closer to the camera and try again.';
$string['preflight:multiplefaces'] = 'More than one face is in frame. Make sure only you are visible — a photograph or a screen behind you counts too.';
$string['preflight:timeout'] = 'The poses were not completed in time. Try again, turning your head slowly as instructed.';
$string['preflight:notenrolled'] = 'You have not enrolled your face yet. You must enrol before you can sit this quiz.';
$string['preflight:servicefailed'] = 'The check could not run because the face service did not respond. This is not something you can fix — please tell your site administrator.';
$string['preflight:failed'] = 'Your identity could not be confirmed. Please try again.';
$string['preflight:notverified'] = 'You must confirm your identity before starting this attempt.';
