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

// Monitoring an activity.
$string['activity:settings'] = 'Proctoring';
$string['activity:explain'] = 'When proctoring is on, learners opening this activity are watched: presence, identity, focus, and random evidence clips, exactly as during a proctored exam. Staff viewing the activity are not monitored.';
$string['activity:on'] = 'This activity is proctored.';
$string['activity:off'] = 'This activity is not proctored.';
$string['activity:turnon'] = 'Turn proctoring on';
$string['activity:turnoff'] = 'Turn proctoring off';
$string['activity:saved'] = 'Saved.';
$string['activity:unsupported'] = 'Proctoring cannot be attached to a {$a} activity.';
$string['activity:willmonitor'] = 'This activity is proctored. Click or press a key to start — your camera will be used to confirm you are present.';
$string['activity:monitoring'] = 'Monitoring is active. Stay in front of the camera and do not leave this window.';

// Sittings.
$string['task:closestalesessions'] = 'Close abandoned proctoring sessions';
$string['session:active'] = 'In progress';
$string['session:completed'] = 'Completed';
$string['session:terminated'] = 'Ended by the system';
$string['session:abandoned'] = 'Ended without confirmation';
$string['report:nosessions'] = 'No monitored sittings were recorded in this context.';
$string['report:ended'] = 'ended';
$string['report:policy'] = 'Rules enforced during this sitting';
$string['report:policynote'] = 'This is what was in force when the sitting began, recorded at the time. Changing the settings now does not change it.';
$string['report:everyminutes'] = 'every {$a} minutes';
$string['report:checkoff'] = 'off';
$string['report:orphans'] = 'Recorded outside any sitting';
$string['report:orphansnote'] = 'From before sittings were recorded. Kept rather than hidden, but there is no policy snapshot for them.';

// PDF question import.
$string['import:title'] = 'Import questions from a PDF';
$string['import:intro'] = 'For Thai licence-exam packs: questions numbered 1., choices ก./ข./ค./ง., and an answer key headed "คำตอบ : วิชา". The PDF must contain real text — a scan of paper will not work. Nothing is imported until you have seen what was found.';
$string['import:file'] = 'PDF file';
$string['import:parse'] = 'Read the PDF';
$string['import:parsefailed'] = 'The PDF could not be read ({$a}).';
$string['import:preview'] = 'First few questions';
$string['import:previewnote'] = 'The correct answer is shown in bold. If these look wrong, the pack is not in a layout this importer understands — do not import it.';
$string['import:count'] = 'Questions found';
$string['import:easy'] = 'Easy';
$string['import:medium'] = 'Medium';
$string['import:hard'] = 'Hard';
$string['import:confirm'] = 'Import {$a} questions';
$string['import:done'] = 'Imported {$a->imported} questions, skipped {$a->skipped}.';
$string['import:failed'] = 'The import failed.';
$string['import:nousable'] = 'No question had a usable answer key.';
$string['import:expired'] = 'That import is no longer pending. Upload the PDF again.';
$string['import:openbank'] = 'Open the question bank';
$string['import:difficultynote'] = 'Difficulty is stored as a question tag, so a quiz can draw random questions by difficulty.';

// Statistics.
$string['stats:title'] = 'Proctoring statistics';
$string['stats:service'] = 'Face service';
$string['stats:serviceup'] = 'Reachable';
$string['stats:servicedown'] = 'Not reachable';
$string['stats:models'] = '{$a} models loaded';
$string['stats:threshold'] = 'match threshold {$a}';
$string['stats:nolivenessmodel'] = 'No anti-spoofing model is loaded. Every check will report liveness as not evaluated, and a photograph held up to the camera would not be caught.';
$string['stats:noserviceurl'] = 'No face service URL is configured.';
$string['stats:badresponse'] = 'The service answered, but not with a health report.';
$string['stats:usage'] = 'Usage';
$string['stats:enrolled'] = 'Learners with an enrolled face';
$string['stats:sessions'] = 'Monitored sittings';
$string['stats:checks'] = 'Checks recorded';
$string['stats:monitored'] = 'Activities being proctored';
$string['stats:proctoredquizzes'] = 'Quizzes being proctored';
$string['stats:sessionoutcomes'] = 'How sittings ended';
$string['stats:decisions'] = 'Identity check outcomes';
$string['stats:retention'] = 'Evidence and retention';
$string['stats:evidencecount'] = 'Photographs and clips stored';
$string['stats:evidencesize'] = 'Space used';
$string['stats:oldestevidence'] = 'Oldest evidence';
$string['stats:purgelastrun'] = 'Purge task last ran';
$string['stats:neverrun'] = 'never';
$string['stats:overdue'] = 'There is evidence older than the retention period. The purge task is not running — check that Moodle cron is working.';
$string['stats:nodata'] = 'Nothing recorded yet.';

// How the paper was drawn.
$string['draw:title'] = 'How this paper was drawn';
$string['draw:note'] = 'The seed is calculated from the learner, the quiz and the attempt number alone. It could not have been chosen to produce a particular paper, and anybody with those three values can recalculate it. The question list is the paper actually given, and stays true even if the question bank is edited later.';
$string['draw:attemptnumber'] = 'Attempt';
$string['draw:seed'] = 'Seed';
$string['draw:seedverified'] = 'recalculates correctly';
$string['draw:seedmismatch'] = 'DOES NOT MATCH — investigate';
$string['draw:questions'] = 'Questions given';
$string['draw:slot'] = 'Question {$a}';
$string['draw:randomfrom'] = 'drawn at random from: {$a}';
$string['draw:fixed'] = 'the same for every learner';
$string['draw:papertitle'] = 'Exam paper';
$string['draw:papernote'] = 'An attempt was drawn but monitoring never started for it — a camera that would not open, or a learner who left before beginning. The paper is recorded either way.';

// AI assistance.
$string['settings:ai'] = 'AI assistance';
$string['settings:ai_desc'] = 'Optional. A language model can summarise a sitting for whoever reviews it, and flag imported questions whose Thai text looks damaged. It never sees a photograph, a clip or a face measurement, and it never decides anything: every answer is advice a person then judges.<br><br><strong>Before enabling this against a hosted model, check that sending learner activity outside your organisation is covered by your consent document and your data processing agreements.</strong> Point the gateway at a locally-run model instead if it is not.';
$string['settings:aienabled'] = 'Enable AI assistance';
$string['settings:aienabled_desc'] = 'Off by default. Nothing else in the system depends on it.';
$string['settings:aibaseurl'] = 'Reviewer service URL';
$string['settings:aibaseurl_desc'] = 'Where the KAISER Proctor AI reviewer runs, e.g. http://ai-service:9100. The prompts and the rules about what may be sent live in that service, not here.';
$string['settings:aiapikey'] = 'Service key';
$string['settings:aiapikey_desc'] = 'Sent as X-Proctor-Key. Which model answers, and whether it runs on hardware you control, is a setting on the service rather than here.';

$string['ai:notconfigured'] = 'AI assistance is not switched on.';
$string['ai:badresponse'] = 'The reviewer service answered, but not with a summary.';
$string['ai:emptyresponse'] = 'The model returned nothing.';
$string['ai:summarytitle'] = 'Draft summary';
$string['ai:summarynote'] = 'Written by a language model from the counts on this page. It is a reading aid, not a finding: it did not see any image or score, and nothing here decides anything. Check it against the record below.';
$string['ai:summarise'] = 'Draft a summary';
$string['ai:failed'] = 'The summary could not be drafted: {$a}';
$string['ai:questiontitle'] = 'Questions that may have come through damaged';
$string['ai:questionnote'] = 'Thai vowels and tone marks can end up in the wrong order when text is extracted from a PDF. These are suggestions to look at, not corrections.';
$string['ai:nofindings'] = 'Nothing looked damaged.';

// The navigation assistant.
$string['ask:title'] = 'Ask about this site';
$string['ask:intro'] = 'Ask where something is and you will get the link. It only knows the pages you can already open, and it will say so when it cannot find one.';
$string['ask:placeholder'] = 'e.g. where is the safety lesson?';
$string['ask:send'] = 'Ask';
$string['ask:thinking'] = 'Looking...';
$string['ask:nomatch'] = 'Nothing on this site matches that. Try naming the course or the activity.';
$string['ask:notavailable'] = 'The assistant is switched off.';
$string['ask:sources'] = 'Pages this came from';
$string['ask:note'] = 'Answers are built only from pages you can open. It cannot see your grades or your results, and it does not decide anything.';
$string['ask:page:enrol'] = 'Enrol your face';
$string['ask:page:enrol_desc'] = 'Register your face before a monitored lesson or exam.';
$string['ask:page:lesson'] = 'Monitored lesson';
$string['ask:page:lesson_desc'] = 'The lesson page that checks you are present while you study.';

// The AI console.
$string['ai:console'] = 'AI assistance';
$string['ai:on'] = 'On';
$string['ai:off'] = 'Off';
$string['ai:turnon'] = 'Turn on';
$string['ai:turnoff'] = 'Turn off';
$string['ai:turnedon'] = 'AI assistance is on.';
$string['ai:turnedoff'] = 'AI assistance is off.';
$string['ai:service'] = 'Reviewer service';
$string['ai:backend'] = 'Model endpoint';
$string['ai:contract'] = 'Payload contract';
$string['ai:task:summarise'] = 'Model for sitting summaries';
$string['ai:task:ask'] = 'Model for the navigation assistant';
$string['ai:task:questions'] = 'Model for proof-reading imported questions';
$string['ai:onpremises'] = 'The model runs on infrastructure you control. No learner activity leaves your network.';
$string['ai:offpremises'] = 'The model endpoint is outside your network. Learner activity — event names and counts, never images or face measurements — will be sent to it. Check this is covered by your consent document and your data processing agreements before switching it on.';
$string['ai:brokenwhileon'] = 'AI assistance is on, but no model is answering. Every attempt will fail until this is fixed.';
$string['ai:note'] = 'The service never receives an image, a clip, a face measurement or a name; it refuses payloads that carry them. Nothing it returns decides anything.';
$string['ai:settingslink'] = 'Plugin settings';
