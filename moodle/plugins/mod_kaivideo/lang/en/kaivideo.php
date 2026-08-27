<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Interactive video';
$string['modulename'] = 'Interactive video';
$string['modulenameplural'] = 'Interactive videos';
$string['pluginadministration'] = 'Interactive video administration';
$string['modulename_help'] = 'Plays a video and interrupts it with questions at times you choose. The video can be set to refuse to continue until a question is answered.';

$string['videourl'] = 'Video address';
$string['videourl_help'] = 'A YouTube or Vimeo link, an HLS stream (.m3u8), or a direct link to a video file the browser can play, such as an MP4 or WebM. Not a page that contains a video: what plays has to be a real video element, which is also what lets proctoring watch it.

Nothing is copied into Moodle, so the lesson stops working whenever that address does, and it does not travel with a course backup.

A stream served from another site has to allow this one to read it (CORS), which is a setting on that server rather than here.';
$string['mustanswer'] = 'Must answer to continue';
$string['mustanswer_help'] = 'The video stops at each question and will not go past it until the learner answers. Seeking forward does not skip a question — it brings it up immediately.';
$string['allowreview'] = 'Allow another attempt at a question';
$string['allowreview_help'] = 'After a wrong answer, offer to try again. Every attempt is recorded; the most recent one counts.';

$string['editquestions'] = 'Questions on the timeline';
$string['addquestion'] = 'Question';
$string['savequestion'] = 'Save question';
$string['questionsaved'] = 'Question saved.';
$string['questiondeleted'] = 'Question deleted.';
$string['attime'] = 'At (seconds)';
$string['attime_help'] = 'How many seconds into the video the question appears. Play the video above and read the time off the controls.';
$string['category'] = 'Topic';
$string['category_help'] = 'What this question is about — "Safety", "Quality", and so on. It goes on the question rather than on the video, because one video usually covers several topics.

The report then breaks the results down by topic, so you can see what a learner or a cohort is actually weak on rather than only their overall percentage.

Leave it empty if you are not separating topics. The field suggests the ones already used on this video, so the same topic does not end up spelled two ways and counted twice.';
$string['error:categorytoolong'] = 'A topic name cannot be longer than {$a} characters.';
$string['questiontext'] = 'Question';
$string['choicen'] = 'Answer {$a}';
$string['correctchoice'] = 'Correct answer';
$string['feedback'] = 'Feedback after answering';
$string['backtovideo'] = 'Back to the video';

$string['noquestions'] = 'This video has no questions on its timeline yet.';
$string['questioncount'] = '{$a} question(s) on this video';
$string['correct'] = 'Correct';
$string['wrong'] = 'Not quite';
$string['continue'] = 'Continue';
$string['tryagain'] = 'Try again';

$string['error:negativetime'] = 'A question cannot be placed before the video starts.';
$string['error:noquestion'] = 'The question needs some text.';
$string['error:toofewchoices'] = 'Give at least two answers to choose between.';
$string['error:toomanychoices'] = 'That is more answers than a question can have.';
$string['error:badcorrectchoice'] = 'The answer marked correct is empty. Pick one that has text in it.';
$string['error:tooclose'] = 'There is already a question at almost the same moment. Move one of them.';
$string['error:badchoice'] = 'That answer does not exist on this question.';

$string['kaivideo:addinstance'] = 'Add a new interactive video';
$string['kaivideo:view'] = 'View an interactive video';
$string['kaivideo:answer'] = 'Answer questions on the timeline';
$string['kaivideo:edititems'] = 'Edit questions on the timeline';
$string['kaivideo:viewreport'] = 'See how learners answered';

$string['privacy:metadata:kaivideo_response'] = 'Which answer a learner chose for each question on the timeline, and whether it was right.';
$string['privacy:metadata:kaivideo_response:userid'] = 'The learner who answered.';
$string['privacy:metadata:kaivideo_response:correct'] = 'Whether it was right.';
$string['privacy:metadata:kaivideo_response:timecreated'] = 'When they answered.';
$string['privacy:metadata:kaivideo_progress'] = 'How far through the video a learner has reached.';
$string['privacy:metadata:kaivideo_progress:userid'] = 'The learner.';
$string['privacy:metadata:kaivideo_progress:furthest'] = 'The furthest point reached, in seconds.';
$string['privacy:metadata:kaivideo_progress:finished'] = 'Whether they reached the end.';
$string['privacy:metadata:kaivideo_progress:timemodified'] = 'When this was last updated.';

// Report.
$string['report'] = 'Results';
$string['report:byquestion'] = 'By question';
$string['report:byquestion_help'] = 'Hardest first. A question most of the class got wrong is usually a fact about the minutes of video before it, not about the class.';
$string['report:bylearner'] = 'By learner';
$string['report:bycategory'] = 'By topic';
$string['report:bycategory_help'] = 'Weakest topic first. One question everybody misses is usually a badly worded question; a whole topic sitting at 40% is a section of the video that did not teach what it was meant to.';
$string['report:learners'] = 'Learners';
$string['report:uncategorised'] = '(no topic)';
$string['report:answered'] = 'Answered';
$string['report:correct'] = 'Correct';
$string['report:correctshare'] = 'Got it right';
$string['report:share'] = 'Score';
$string['report:commonestwrong'] = 'Commonest wrong answer';
$string['report:struggled'] = 'Most got this wrong';
$string['report:furthest'] = 'Watched to';
$string['report:finished'] = 'Reached the end';
$string['report:nobodyyet'] = 'Nobody has opened this yet.';

// Completion.
$string['completionanswerall'] = 'Answer every question';
$string['completionanswerall_label'] = 'Learner must answer every question on the timeline';
$string['completionanswerall_help'] = 'Answering counts, not answering correctly. A learner who works through the whole video has done the activity; whether they got the answers right is what the grade is for.';
$string['completionanswerall_desc'] = 'Answer every question';
$string['completionwatched'] = 'Reach the end';
$string['completionwatched_label'] = 'Learner must reach the end of the video';
$string['completionwatched_help'] = 'Reaching the end is reported by the browser, so it is a record of the playhead getting there rather than proof somebody watched. Pair it with answering the questions if that matters.';
$string['completionwatched_desc'] = 'Reach the end of the video';

// Controls.
$string['play'] = 'Play';
$string['pause'] = 'Pause';
$string['back10'] = 'Back 10s';
$string['error:playerfailed'] = 'The video could not be loaded. Check your connection and reload the page.';
$string['error:notplayable'] = 'That address is not something the browser can play. Use a YouTube or Vimeo link, an HLS stream ending in .m3u8, or a direct link to an MP4 or WebM file.';

// Question types.
$string['type'] = 'Kind of interruption';
$string['type_help'] = 'What happens when the video stops here.

* **One answer** — several options, one of them right.
* **Several answers** — several options, and all the right ones must be ticked. Part of the set earns nothing.
* **Typed answer** — the learner types it. It is matched against the list you give, ignoring spacing and English capitals.
* **Message** — not a question. The video stops, says something, and carries on.';
$string['type:choice'] = 'One answer';
$string['type:multichoice'] = 'Several answers';
$string['type:shorttext'] = 'Typed answer';
$string['type:info'] = 'Message';
$string['questiontext_help'] = 'What the learner reads. For a message, this is the message.';
$string['iscorrect'] = 'Correct';
$string['choices'] = 'Answers to choose between';
$string['choices_help'] = 'Fill in as many as you need and leave the rest empty; blanks are dropped. Tick the ones that are right. For a single-answer question, tick exactly one.';
$string['acceptedanswers'] = 'Accepted answers';
$string['acceptedanswers_help'] = 'One per line. Any of them counts as right.

Matching ignores extra spaces and English capitals, and nothing else. Thai tone marks and vowels are compared as typed, because treating ผู้ and ผู as the same word is accepting a misspelling rather than being lenient. If you want to accept a variant, add it as its own line.';
$string['feedback_help'] = 'Shown after answering. For a message, this is shown underneath it.';
$string['youranswer'] = 'Your answer';
$string['submitanswer'] = 'Submit';
$string['error:badtype'] = 'That is not a kind of question this activity has.';
$string['error:noacceptedanswer'] = 'A typed question needs at least one accepted answer.';
$string['error:answertoolong'] = 'That accepted answer is too long to be something a learner types.';
$string['error:onlyoneanswer'] = 'A single-answer question can only have one answer ticked.';
$string['error:allanswerscorrect'] = 'Every option is ticked, so there is nothing to get wrong. Untick the ones that are not right.';
$string['report:blank'] = '(left blank)';
$string['privacy:metadata:kaivideo_response:response'] = 'What they answered: the options they picked, or the words they typed.';

// Uploading the video.
$string['sourcetype'] = 'Where the video comes from';
$string['sourcetype_help'] = '**Upload a file** puts the video into this Moodle. It is served to enrolled learners only, it goes into course backups, and it keeps working when nothing outside is reachable.

**Use an address** points at a YouTube link, or at a video file already hosted somewhere. Nothing is copied, so whoever controls that address controls whether the lesson plays.

Only one of the two is kept. Switching from one to the other clears what you had.';
$string['source:upload'] = 'Upload a file';
$string['source:url'] = 'Use an address';
$string['videofile'] = 'Video file';
$string['videofile_help'] = 'MP4 or WebM. Whatever the site allows as a maximum file size applies here, so a long recording may need to be uploaded by an administrator or linked instead.';
$string['error:novideo'] = 'Choose a video file, or switch to an address.';
$string['privacy:metadata:filepurpose'] = 'Videos uploaded to an interactive video are course material, not personal data. No learner information is stored in them.';

// Streaming backends.
$string['error:vimeofailed'] = 'The Vimeo player could not be loaded. Check that the video allows playing on this site, and that player.vimeo.com is reachable.';
$string['error:streamfailed'] = 'The stream could not be started. Check the address, and that the server hosting it allows this site to read it.';
