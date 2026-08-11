<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Interactive video';
$string['modulename'] = 'Interactive video';
$string['modulenameplural'] = 'Interactive videos';
$string['pluginadministration'] = 'Interactive video administration';
$string['modulename_help'] = 'Plays a video and interrupts it with questions at times you choose. The video can be set to refuse to continue until a question is answered.';

$string['videourl'] = 'Video address';
$string['videourl_help'] = 'A video file the browser can play directly, such as an MP4 or WebM. Not an embed code: what plays has to be a real video element, which is also what lets proctoring watch it.';
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
$string['questiontext'] = 'Question';
$string['choicen'] = 'Answer {$a}';
$string['correctchoice'] = 'Correct answer';
$string['correctchoice_help'] = 'Which answer is right. It is never sent to the browser until the learner has chosen.';
$string['feedback'] = 'Feedback after answering';
$string['backtovideo'] = 'Back to the video';

$string['noquestions'] = 'This video has no questions on its timeline yet.';
$string['questioncount'] = '{$a} question(s) on this video';
$string['mustanswerhint'] = 'each must be answered before the video continues';
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
$string['privacy:metadata:kaivideo_response:choice'] = 'The answer they chose.';
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
