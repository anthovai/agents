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

$string['settings:policy'] = 'What is watched during a lesson';
$string['settings:policy_desc'] = 'How often each check runs while somebody is learning. <strong>Every box is in seconds.</strong> Put 0 in a box to switch that check off.';
$string['settings:clickconfirmgracesec'] = 'Seconds allowed to press confirm';
$string['settings:clickconfirmgracesec_desc'] = 'How long they have to press it. The video pauses if they do not.';
$string['settings:mouseidlewarnsec'] = 'Warn N seconds before pausing for stillness';
$string['settings:mouseidlewarnsec_desc'] = 'A countdown appears this long before the pause. <strong>It cannot be longer than the box above.</strong> Example: 30 above and 10 here = twenty seconds of stillness, then a countdown 10, 9, 8 … then the pause. Put 0 to pause with no warning.';
$string['settings:warntoolong'] = 'A {$a->warn} second warning does not fit: the video pauses after {$a->tolerance} seconds of inactivity, and the countdown has to run inside that. Lower this, or raise "Idle tolerance" first.';
$string['settings:warnnegative'] = 'This cannot be negative.';
$string['settings:presencewarnsec'] = 'Warn N seconds before pausing for a missing face';
$string['settings:presencewarnsec_desc'] = 'When the camera cannot see a face, it re-checks and counts down for this long before pausing. Put 0 to pause on the first frame with no face.';
$string['settings:randomclipsperhour'] = 'Evidence clips recorded per hour';
$string['settings:randomclipsperhour_desc'] = 'How many short clips are kept as evidence in an average hour. The times are chosen at random so they cannot be predicted. Put 0 to record none.';
$string['settings:clipseconds'] = 'Length of an evidence clip, in seconds';
$string['settings:clipseconds_desc'] = 'How long each recorded clip is.';
$string['settings:blurallowance'] = 'Times allowed to leave the lesson window';
$string['settings:blurallowance_desc'] = 'How often they may switch to another window before the sitting is ended. Put 0 to end it the first time.';
$string['settings:strictlockdown'] = 'Strict mode (exams)';
$string['settings:strictlockdown_desc'] = 'End the attempt on a policy breach instead of pausing and letting the learner continue. Applies to exam attempts only.';
$string['settings:lessonstrictlockdown'] = 'Strict mode for lessons too';
$string['settings:lessonstrictlockdown_desc'] = 'By default a lesson is never ended: a breach pauses the video, keeps the evidence, and the learner can resume — ending it protects nothing and only makes them start again. Turn this on to have lessons ended the way exams are.';
$string['settings:desktopnotification'] = 'Desktop notifications';
$string['settings:desktopnotification_desc'] = 'Raise an operating-system notification when the learner leaves the lesson.';

// Enrolment page.
// The notice shown right before the camera opens. Separate from the site
// policy, which is agreed to once and covers everything — this one is about
// what is happening in the next few seconds. {$a} is the retention period,
// read from the setting so the notice cannot promise something the purge task
// does not do.
$string['notice:title'] = 'The camera is about to open';
$string['notice:enrol'] = '<p>The next step opens your camera to enrol your face.</p>
<ul>
<li>What is stored is a <strong>set of numbers representing your face</strong>, not a photograph of you.</li>
<li>It is used only to confirm that the person studying and sitting exams is you.</li>
<li>You can ask for it to be deleted at any time, from your account\'s privacy page.</li>
</ul>
<p>Press "Agree and open camera" to continue.</p>';
$string['notice:verify'] = '<p>The next step opens your camera to confirm it is you, before the exam starts.</p>
<ul>
<li>Your live face is compared against the one you enrolled.</li>
<li>Checks continue during the exam, and <strong>images are kept as evidence when something looks wrong</strong>.</li>
<li>Evidence is deleted automatically after {$a} days.</li>
</ul>
<p>Press "Agree and open camera" to continue.</p>';
$string['notice:agree'] = 'Agree and open camera';
$string['notice:decline'] = 'Do not agree';
$string['notice:declined'] = 'The camera cannot open without your agreement. Press again when you are ready.';

$string['enrol:title'] = 'Enrol your face';
$string['enrol:intro'] = 'Your identity is confirmed by camera during monitored lessons. Follow the on-screen instructions: look straight at the camera, then turn your head as asked.';
$string['enrol:start'] = 'Start';
$string['enrol:success'] = 'Your face has been enrolled.';
$string['enrol:failed'] = 'Your face could not be enrolled. Try again, and if it keeps failing tell whoever runs the course.';
$string['enrol:timeout'] = 'You did not complete the movement in time. Try again, turning your head slowly.';
$string['enrol:replacing'] = 'You have already enrolled a face. Completing this will replace it.';
$string['enrol:existing'] = 'Face enrolled on {$a}.';
// Shown on the learner's own profile, where the question is "have I done this
// yet" rather than "how do I do it".
$string['profile:enrolledon'] = 'Enrolled on {$a}';
$string['profile:notenrolled'] = 'Not enrolled yet — required before a monitored lesson or exam.';


// Camera hints.
$string['hint:noface'] = 'Move so your face is clearly visible.';
$string['hint:multiplefaces'] = 'Only one face should be in frame.';
$string['hint:spoof'] = 'Possible spoofing detected.';
$string['error:nocamera'] = 'No camera was found on this device. Plug one in, or use a device that has one.';
$string['error:cameradenied'] = 'Camera access was blocked. Click the camera icon in the address bar, allow it, and reload the page — retrying without that changes nothing.';
$string['error:camerabusy'] = 'The camera is being used by another program. Close video calls or camera apps, then try again.';
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
$string['countdown:idle'] = 'No movement detected. Pausing the video in {$a}s.';
$string['countdown:presence'] = 'You are not visible to the camera. Pausing the video in {$a}s.';
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

// Building a paper that draws at random from the course's own bank.
$string['paper:title'] = 'Build a random paper';
$string['paper:intro'] = 'Draw questions at random from this course\'s question bank. Every candidate gets a different paper, and none of them sees a question\'s difficulty. This course\'s bank currently holds <strong>{$a}</strong> questions to draw from.';
$string['paper:quiz'] = 'Put them in';
$string['paper:count'] = 'Number of questions';
$string['paper:count_help'] = 'How many questions each candidate sits. They are drawn afresh from this course\'s bank every time somebody starts an attempt, so the number cannot exceed what the bank holds.';
$string['paper:replace'] = 'Clear the existing questions first';
$string['paper:replace_help'] = 'Leave this off and the random draws are appended to whatever is already there, which is rarely what building a new paper means.';
$string['paper:build'] = 'Build the paper';
$string['paper:done'] = 'Added {$a->added} random questions (removed {$a->removed}).';
$string['paper:atleastone'] = 'A paper needs at least one question.';
$string['paper:toomany'] = 'This course\'s bank holds only {$a} questions, so a larger paper cannot be drawn.';
$string['paper:noquizzes'] = 'This course has no quiz to put questions in. Create one, then come back.';
$string['paper:nobank'] = 'This course\'s question bank is empty. Import some questions before drawing a paper.';
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
$string['ask:intro'] = 'Ask where something is and you will get the link. It can also tell you your own marks and attempts. It only knows the pages you can already open, and it will say so when it cannot find one.';
$string['ask:placeholder'] = 'e.g. where is the safety lesson? / did I pass?';
$string['ask:send'] = 'Ask';
$string['ask:thinking'] = 'Looking...';
$string['ask:nomatch'] = 'Nothing on this site matches that. Try naming the course or the activity.';
$string['ask:notavailable'] = 'The assistant is switched off.';
$string['ask:sources'] = 'Pages this came from';
$string['ask:note'] = 'Answers are built only from pages you can open, and from your own record on them. It cannot see anybody else\'s results, it never works a figure out for itself, and it does not decide anything.';
$string['ask:page:enrol'] = 'Enrol your face';
$string['ask:page:enrol_desc'] = 'Register your face before a monitored lesson or exam.';

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
$string['ai:offpremises'] = 'The model endpoint is outside your network. Learner activity — event names and counts, and a learner\'s own marks and attempts when they ask about them, but never images or face measurements — will be sent to it. Check this is covered by your consent document and your data processing agreements before switching it on.';
$string['ai:brokenwhileon'] = 'AI assistance is on, but no model is answering. Every attempt will fail until this is fixed.';
$string['ai:note'] = 'The service never receives an image, a clip, a face measurement or a name; it refuses payloads that carry them. A learner\'s own marks are sent only when that learner asks about them, and never anybody else\'s. Nothing it returns decides anything.';
$string['ai:settingslink'] = 'Plugin settings';


// Why an enrolment attempt did not work, specifically.
$string['enrol:noface'] = 'The camera could not find your face. Sit facing it, with nothing covering your face, and try again.';
$string['enrol:toosmall'] = 'Your face is too small in the picture. Move closer to the camera and try again.';
$string['enrol:multiplefaces'] = 'More than one face is in the picture. Make sure only you are in frame — a photograph or screen behind you counts too.';
$string['enrol:spoof'] = 'The camera could not confirm a live person. Enrol using your own camera, looking straight at it, rather than holding up a photograph or another screen.';
$string['hint:toosmall'] = 'Move closer to the camera.';
// The assistant's launcher, on every page. Its icon is drawn in the template
// rather than named here: a glyph is not something a translator should have to
// choose, and the one this used to carry was a "?" that collided with Moodle's
// own help button sitting directly under it.
$string['ask:openfull'] = 'Open the full page';

$string['settings:asksource'] = 'Which assistant answers the ask widget';
$string['settings:asksource_desc'] = 'The Moodle assistant answers "where is the page I need", from the courses this learner can already open. The Indorama assistant answers about the structure of the legacy LMS — its tables, routes and source files — and knows nothing about Moodle. They are separate services; this chooses which one the widget asks. The Indorama option requires local/kaiproctor:manage, because a database schema is not something to put in front of a learner.';
$string['settings:asksource:moodle'] = 'Moodle navigation (this site)';
$string['settings:asksource:indorama'] = 'Indorama LMS structure (separate service)';
$string['settings:ragbaseurl'] = 'Indorama assistant base URL';
$string['settings:ragbaseurl_desc'] = 'Where the indorama-rag service is listening, for example http://host.docker.internal:8110 when it runs on the host and Moodle runs in a container. Only used when the source above is set to Indorama.';
$string['settings:ragapikey'] = 'Indorama assistant key';
$string['settings:ragapikey_desc'] = 'The shared key that service is configured with (RAG_API_KEY), sent as X-Agent-Key. Leave empty if it runs without one — which is only safe on a machine nothing else can reach.';
$string['ask:rag:notconfigured'] = 'The Indorama assistant is selected but no address is configured for it.';
$string['ask:rag:unreachable'] = 'Could not reach the Indorama assistant. Check that the service is running and that the address in the settings is right.';
$string['ask:rag:malformed'] = 'The Indorama assistant replied with something this plugin could not read.';

$string['chat:role:user'] = 'You';
$string['chat:role:assistant'] = 'Assistant';
$string['chat:sources'] = 'Referred to';
$string['chat:overquota'] = 'Your saved conversations have reached the size limit for one account. Delete a conversation to make room.';
$string['chat:history'] = 'My conversations';
$string['chat:history_desc'] = 'Everything you have asked the assistant, kept as Markdown you can read, download or delete. Nobody else can open these.';
$string['chat:none'] = 'You have not asked the assistant anything yet.';
$string['chat:turns'] = '{$a} messages';
$string['chat:usage'] = 'Using {$a->used} of {$a->quota}';
$string['chat:open'] = 'Open';
$string['chat:download'] = 'Download .md';
$string['chat:rename'] = 'Rename';
$string['chat:delete'] = 'Delete';
$string['chat:deleteall'] = 'Delete every conversation';
$string['chat:confirmdelete'] = 'Delete this conversation? There is no undo.';
$string['chat:confirmdeleteall'] = 'Delete every conversation you have had with the assistant? There is no undo.';
$string['chat:deleted'] = 'Deleted.';
$string['settings:chatquota'] = 'Conversation storage per user';
$string['settings:chatquota_desc'] = 'Bytes of transcript one account may keep. A turn of conversation runs two to five kilobytes, so the default of one gigabyte is a backstop against a runaway script rather than a budget anybody has to manage. Over the limit, saving refuses rather than trimming — a conversation that quietly stopped recording looks the same as one nobody continued.';
$string['privacy:metadata:convo'] = 'Conversations with the assistant, kept so the person who had them can read them again.';
$string['privacy:metadata:convo:userid'] = 'Who had the conversation.';
$string['privacy:metadata:convo:title'] = 'A name for the conversation, taken from its first question.';
$string['privacy:metadata:convo:timecreated'] = 'When it started.';
$string['privacy:metadata:convo:timemodified'] = 'When it was last added to.';

$string['settings:ragstaffonly'] = 'Restrict the Indorama assistant to staff';
$string['settings:ragstaffonly_desc'] = 'On by default, and worth leaving on unless you have decided otherwise: that assistant answers about a database schema, which is useful to whoever maintains the system and odd to put in front of somebody taking a course. Turn it off to let every logged-in user ask it. This does not widen what the assistant knows — it never sees a row of data either way — only who may ask.';

$string['ask:rag:off_topic'] = 'I can only answer about the Indorama learning system — its courses, content and how it is put together. Try asking about those.';
$string['ask:rag:no_material'] = 'I could not find anything about that. Try naming a course, a topic, or what you are trying to do.';
$string['ask:rag:ungrounded_answer'] = 'I could not give an answer I was able to check, so I have not given one. Please try asking a different way.';
$string['ask:rag:llm_timeout'] = 'That took too long to answer. The first question after a quiet period is slow while the model loads — please try again.';
$string['ask:rag:llm_empty'] = 'I did not manage to put an answer together. Please try again.';
$string['ask:rag:llm_unreachable'] = 'The assistant is not responding right now. Please try again in a moment.';
$string['ask:rag:tool_limit'] = 'I looked in several places and still could not settle on an answer. Try asking something more specific.';
$string['ask:rag:refused'] = 'I could not answer that one. Please try asking a different way.';
$string['settings:presenceseconds'] = 'Check somebody is at the camera, every N seconds';
$string['settings:presenceseconds_desc'] = 'Takes a frame this often to see that a person is there. It does not check who. Example: 120 = every two minutes.';
$string['settings:verifyseconds'] = 'Check it is the same person, every N seconds';
$string['settings:verifyseconds_desc'] = 'Compares the face against the one enrolled, this often. Only applies to learners who have enrolled a face. Example: 600 = every ten minutes.';
$string['settings:clickconfirmseconds'] = 'Ask the learner to confirm, every N seconds';
$string['settings:clickconfirmseconds_desc'] = 'Shows a button to confirm they are still there. Counted only while the video is playing, so a interval longer than the video means the button never appears. Example: 300 = every five minutes.';
$string['settings:mouseidleseconds'] = 'Pause after N seconds of no movement';
$string['settings:mouseidleseconds_desc'] = 'If the mouse does not move and nothing is typed for this long, the video pauses. Example: 30 = pause after half a minute of stillness.';
$string['report:everyseconds'] = 'every {$a} seconds';
