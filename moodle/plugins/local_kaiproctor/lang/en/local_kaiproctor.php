<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'KAISER Proctor';
$string['privacy:metadata:face'] = 'A mathematical representation of the learner\'s face, used to confirm that the person taking the course is the person who enrolled. The original photograph is not kept.';
$string['privacy:metadata:face:userid'] = 'The learner the face representation belongs to.';
$string['privacy:metadata:face:embedding'] = 'The face representation itself.';
$string['privacy:metadata:face:timecreated'] = 'When the learner enrolled their face.';
$string['privacy:metadata:evidence'] = 'Photographs and short video clips captured during a monitored session as proof of who was present.';
$string['privacy:metadata:evidence:userid'] = 'The learner the evidence was captured from.';
$string['privacy:metadata:evidence:reason'] = 'Why the evidence was captured — a scheduled check, a random sample, or a policy violation.';
$string['privacy:metadata:evidence:timecreated'] = 'When the evidence was captured.';
$string['privacy:metadata:check'] = 'The result of each identity or presence check: the similarity score, the liveness score, and the decision that followed.';
$string['privacy:metadata:check:userid'] = 'The learner who was checked.';
$string['privacy:metadata:check:similarity'] = 'How closely the live image matched the enrolled face.';
$string['privacy:metadata:check:decision'] = 'The decision the system reached.';
$string['privacy:metadata:check:timecreated'] = 'When the check ran.';
$string['privacy:metadata:faceservice'] = 'Images are sent to the face service for analysis. The service is stateless and keeps nothing.';
$string['privacy:metadata:faceservice:image'] = 'The image being analysed.';

// Settings.
$string['settings:faceservice'] = 'Face service';
$string['settings:faceserviceurl'] = 'Face service URL';
$string['settings:faceserviceurl_desc'] = 'Base URL of the face service, e.g. http://face-service:9000. Keep it on an internal network — it must not be reachable from the public internet.';
$string['settings:apikey'] = 'Shared secret';
$string['settings:apikey_desc'] = 'Sent to the face service as the X-Proctor-Key header. Must match PROCTOR_API_KEY on the service.';
$string['settings:matching'] = 'Matching';
$string['settings:matchthreshold'] = 'Match threshold';
$string['settings:matchthreshold_desc'] = 'Cosine similarity at or above which a face counts as matched. Calibrate this against real enrolment photographs before going live — the default is the model author\'s reference value, not a calibrated one.';
$string['settings:reviewmin'] = 'Review threshold';
$string['settings:reviewmin_desc'] = 'Scores between this and the match threshold are treated as inconclusive: the learner is asked to reposition rather than being failed.';
$string['settings:retentiondays'] = 'Evidence retention (days)';
$string['settings:retentiondays_desc'] = 'Photographs and clips older than this are deleted by the scheduled purge task.';

$string['settings:policy'] = 'Monitoring policy';
$string['settings:policy_desc'] = 'How often each check runs during a monitored lesson. An interval of 0 switches that check off.';
$string['settings:presenceminutes'] = 'Presence check every (minutes)';
$string['settings:presenceminutes_desc'] = 'How often to confirm somebody is in front of the camera.';
$string['settings:verifyminutes'] = 'Identity check every (minutes)';
$string['settings:verifyminutes_desc'] = 'How often to confirm it is still the person who enrolled. Only runs for learners who have enrolled a face.';
$string['settings:clickconfirmminutes'] = 'Ask for confirmation every (minutes)';
$string['settings:clickconfirmminutes_desc'] = 'How often the learner must click to confirm they are still watching.';
$string['settings:clickconfirmgracesec'] = 'Confirmation grace period (seconds)';
$string['settings:clickconfirmgracesec_desc'] = 'How long they have to click before the video is paused.';
$string['settings:mouseidleminutes'] = 'Idle tolerance (minutes)';
$string['settings:mouseidleminutes_desc'] = 'Pause the video after this long with no mouse or keyboard activity.';
$string['settings:randomclipsperhour'] = 'Random clips per hour';
$string['settings:randomclipsperhour_desc'] = 'Average number of short camera clips kept as evidence each hour. Timing is randomised so it cannot be anticipated.';
$string['settings:clipseconds'] = 'Clip length (seconds)';
$string['settings:clipseconds_desc'] = 'How long each random evidence clip runs.';
$string['settings:blurallowance'] = 'Focus losses tolerated';
$string['settings:blurallowance_desc'] = 'How many times the learner may leave the lesson window before the session ends. 0 ends it the first time.';
$string['settings:strictlockdown'] = 'Strict mode';
$string['settings:strictlockdown_desc'] = 'End the session on a policy breach instead of pausing and letting the learner continue.';
$string['settings:desktopnotification'] = 'Desktop notifications';
$string['settings:desktopnotification_desc'] = 'Raise an operating-system notification when the learner leaves the lesson.';
$string['settings:lessonvideourl'] = 'Lesson video URL';
$string['settings:lessonvideourl_desc'] = 'The video played on the monitored lesson page.';

// Enrolment page.
$string['enrol:title'] = 'Enrol your face';
$string['enrol:intro'] = 'Your identity is confirmed by camera during monitored lessons. Follow the on-screen instructions: look straight at the camera, then turn your head as asked.';
$string['enrol:start'] = 'Start';
$string['enrol:success'] = 'Your face has been enrolled.';
$string['enrol:failed'] = 'Your face could not be enrolled. Make sure the room is well lit and only you are in frame, then try again.';
$string['enrol:timeout'] = 'You did not complete the movement in time. Try again, moving slowly.';
$string['enrol:replacing'] = 'You have already enrolled a face. Completing this will replace it.';
$string['enrol:existing'] = 'Face enrolled on {$a}.';

// Lesson page.
$string['lesson:title'] = 'Monitored lesson';
$string['lesson:start'] = 'Start the lesson';
$string['lesson:monitoring'] = 'Monitoring is active. Stay in front of the camera and do not leave this window.';
$string['lesson:notenrolled'] = 'You have not enrolled a face yet, so identity checks are switched off. Presence is still monitored.';
$string['lesson:novideo'] = 'No lesson video has been configured. An administrator must set the lesson video URL in the plugin settings.';

// Camera hints.
$string['hint:noface'] = 'Move so your face is clearly visible.';
$string['hint:multiplefaces'] = 'Only one face should be in frame.';
$string['hint:spoof'] = 'Possible spoofing detected.';
$string['error:nocamera'] = 'The camera could not be started. Allow camera access and make sure the page is served over HTTPS or localhost.';
$string['error:generic'] = 'Something went wrong. Please try again.';

// Capabilities.
$string['kaiproctor:enrolface'] = 'Enrol own face';
$string['kaiproctor:viewevidence'] = 'View proctoring evidence';
$string['kaiproctor:manage'] = 'Manage proctoring settings';

$string['task:purgeevidence'] = 'Purge expired proctoring evidence';
$string['event:attention'] = 'Proctoring signal';

// Errors.
$string['invalidevidencekind'] = 'Unknown evidence type: {$a}';
$string['evidencetoolarge'] = 'The captured evidence is larger than the allowed size.';

// Active liveness instructions.
$string['liveness:center'] = 'Look straight at the camera';
$string['liveness:left'] = 'Slowly turn your head to the left';
$string['liveness:right'] = 'Slowly turn your head to the right';

// Overlays.
$string['notification:title'] = 'Proctoring';
$string['paused:title'] = 'Video paused';
$string['paused:resume'] = 'Resume';
$string['confirm:title'] = 'Confirm you are still here';
$string['confirm:body'] = 'Press the button before the time runs out.';
$string['confirm:button'] = 'I am here';
$string['terminated:title'] = 'Session ended';
$string['terminated:close'] = 'Close the lesson';

// What each signal means to the learner.
$string['violation:tab_hidden'] = 'You switched tab or minimised the window during the lesson.';
$string['violation:window_blur'] = 'You left the lesson window.';
$string['violation:fullscreen_exit'] = 'You left fullscreen during the lesson.';
$string['violation:devtools_suspected'] = 'Developer tools were detected.';
$string['violation:click_confirm_timeout'] = 'You did not confirm in time.';
$string['violation:mouse_idle'] = 'There has been no mouse or keyboard activity for some time.';
$string['violation:face_absent'] = 'Nobody is visible in front of the camera.';
$string['violation:multiple_faces'] = 'More than one person is visible. Continue once only you remain.';
$string['violation:face_review'] = 'Your face could not be confirmed clearly. Centre yourself in the frame and continue.';
$string['violation:fail'] = 'Your face does not match the enrolled learner.';
$string['violation:fail_liveness'] = 'A photograph or video may have been used instead of a live person.';

// Evidence report.
$string['report:title'] = 'Proctoring evidence';
$string['report:enrolledon'] = 'Face enrolled on {$a}';
$string['report:notenrolled'] = 'This learner has not enrolled a face, so no identity check could be made against a reference.';
$string['report:checks'] = 'Identity and presence checks';
$string['report:nochecks'] = 'No checks were recorded in this context.';
$string['report:evidence'] = 'Captured evidence';
$string['report:noevidence'] = 'No photographs or clips were captured in this context.';
$string['report:events'] = 'Attention signals';
$string['report:noevents'] = 'No attention signals were recorded in this context.';
$string['report:time'] = 'Time';
$string['report:kind'] = 'Check';
$string['report:decision'] = 'Decision';
$string['report:similarity'] = 'Similarity';
$string['report:liveness'] = 'Liveness';
$string['report:threshold'] = 'Threshold';
$string['report:model'] = 'Model';
$string['report:signal'] = 'Signal';
$string['report:videotime'] = 'Position';
$string['report:detail'] = 'Detail';
$string['report:thresholdnote'] = 'The threshold shown is the one that was in force when the check ran, not the one configured now.';
