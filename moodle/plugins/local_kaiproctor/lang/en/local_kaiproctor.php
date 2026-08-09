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
