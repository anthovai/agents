<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'วิดีโอแบบมีปฏิสัมพันธ์';
$string['modulename'] = 'วิดีโอแบบมีปฏิสัมพันธ์';
$string['modulenameplural'] = 'วิดีโอแบบมีปฏิสัมพันธ์';
$string['pluginadministration'] = 'จัดการวิดีโอแบบมีปฏิสัมพันธ์';
$string['modulename_help'] = 'เล่นวิดีโอแล้วแทรกคำถามตามเวลาที่กำหนด ตั้งให้วิดีโอไม่ยอมเล่นต่อจนกว่าจะตอบคำถามได้';

$string['videourl'] = 'ที่อยู่วิดีโอ';
$string['videourl_help'] = 'ไฟล์วิดีโอที่เบราว์เซอร์เล่นได้ตรงๆ เช่น MP4 หรือ WebM ไม่ใช่โค้ดฝัง — สิ่งที่เล่นต้องเป็น video element จริง ซึ่งเป็นเหตุผลเดียวกับที่ทำให้ระบบคุมสอบเฝ้าดูมันได้';
$string['mustanswer'] = 'ต้องตอบก่อนจึงจะเล่นต่อ';
$string['mustanswer_help'] = 'วิดีโอจะหยุดที่คำถามแต่ละข้อและไม่เล่นผ่านไปจนกว่าจะตอบ การลากแถบเวลาข้ามไปไม่ช่วยให้ข้ามคำถาม — คำถามจะขึ้นมาทันที';
$string['allowreview'] = 'ให้ตอบคำถามใหม่ได้';
$string['allowreview_help'] = 'ถ้าตอบผิด ให้โอกาสตอบใหม่ ทุกครั้งที่ตอบถูกบันทึกไว้ และครั้งล่าสุดคือครั้งที่นับ';

$string['editquestions'] = 'คำถามบนไทม์ไลน์';
$string['addquestion'] = 'คำถาม';
$string['savequestion'] = 'บันทึกคำถาม';
$string['questionsaved'] = 'บันทึกคำถามแล้ว';
$string['questiondeleted'] = 'ลบคำถามแล้ว';
$string['attime'] = 'ที่วินาทีที่';
$string['attime_help'] = 'คำถามจะขึ้นเมื่อวิดีโอเล่นไปถึงวินาทีนี้ เล่นวิดีโอด้านบนแล้วอ่านเวลาจากแถบควบคุมได้เลย';
$string['questiontext'] = 'คำถาม';
$string['choicen'] = 'ตัวเลือกที่ {$a}';
$string['correctchoice'] = 'คำตอบที่ถูก';
$string['correctchoice_help'] = 'ข้อไหนถูก — ค่านี้จะไม่ถูกส่งไปที่เบราว์เซอร์จนกว่าผู้เรียนจะเลือกคำตอบแล้ว';
$string['feedback'] = 'ข้อความหลังตอบ';
$string['backtovideo'] = 'กลับไปที่วิดีโอ';

$string['noquestions'] = 'วิดีโอนี้ยังไม่มีคำถามบนไทม์ไลน์';
$string['questioncount'] = 'มีคำถาม {$a} ข้อในวิดีโอนี้';
$string['mustanswerhint'] = 'ต้องตอบทุกข้อก่อนวิดีโอจึงจะเล่นต่อ';
$string['correct'] = 'ถูกต้อง';
$string['wrong'] = 'ยังไม่ถูก';
$string['continue'] = 'เล่นต่อ';
$string['tryagain'] = 'ลองใหม่';

$string['error:negativetime'] = 'วางคำถามก่อนวิดีโอเริ่มไม่ได้';
$string['error:noquestion'] = 'คำถามต้องมีข้อความ';
$string['error:toofewchoices'] = 'ต้องมีตัวเลือกอย่างน้อยสองข้อ';
$string['error:toomanychoices'] = 'ตัวเลือกมากเกินกว่าที่คำถามหนึ่งข้อจะมีได้';
$string['error:badcorrectchoice'] = 'ข้อที่ทำเครื่องหมายว่าถูกไม่มีข้อความอยู่ เลือกข้อที่มีข้อความ';
$string['error:tooclose'] = 'มีคำถามอยู่ที่เวลาใกล้กันมากแล้ว ย้ายข้อใดข้อหนึ่ง';
$string['error:badchoice'] = 'ไม่มีตัวเลือกนี้ในคำถามข้อนี้';

$string['kaivideo:addinstance'] = 'เพิ่มวิดีโอแบบมีปฏิสัมพันธ์';
$string['kaivideo:view'] = 'ดูวิดีโอแบบมีปฏิสัมพันธ์';
$string['kaivideo:answer'] = 'ตอบคำถามบนไทม์ไลน์';
$string['kaivideo:edititems'] = 'แก้ไขคำถามบนไทม์ไลน์';
$string['kaivideo:viewreport'] = 'ดูว่าผู้เรียนตอบอะไร';

$string['privacy:metadata:kaivideo_response'] = 'คำตอบที่ผู้เรียนเลือกในแต่ละคำถามบนไทม์ไลน์ และผลว่าถูกหรือไม่';
$string['privacy:metadata:kaivideo_response:userid'] = 'ผู้เรียนที่ตอบ';
$string['privacy:metadata:kaivideo_response:choice'] = 'ตัวเลือกที่เลือก';
$string['privacy:metadata:kaivideo_response:correct'] = 'ถูกหรือไม่';
$string['privacy:metadata:kaivideo_response:timecreated'] = 'เวลาที่ตอบ';
$string['privacy:metadata:kaivideo_progress'] = 'ผู้เรียนดูวิดีโอไปถึงจุดใดแล้ว';
$string['privacy:metadata:kaivideo_progress:userid'] = 'ผู้เรียน';
$string['privacy:metadata:kaivideo_progress:furthest'] = 'จุดที่ไกลที่สุดที่ดูถึง (วินาที)';
$string['privacy:metadata:kaivideo_progress:finished'] = 'ดูจบหรือยัง';
$string['privacy:metadata:kaivideo_progress:timemodified'] = 'เวลาที่อัปเดตล่าสุด';

// Report.
$string['report'] = 'ผลการเรียน';
$string['report:byquestion'] = 'แยกตามคำถาม';
$string['report:byquestion_help'] = 'เรียงจากข้อที่ยากที่สุด — ข้อที่คนส่วนใหญ่ตอบผิด มักบอกเรื่องของวิดีโอช่วงก่อนหน้านั้น ไม่ใช่เรื่องของผู้เรียน';
$string['report:bylearner'] = 'แยกตามผู้เรียน';
$string['report:answered'] = 'ตอบแล้ว';
$string['report:correct'] = 'ตอบถูก';
$string['report:correctshare'] = 'สัดส่วนที่ตอบถูก';
$string['report:share'] = 'คะแนน';
$string['report:commonestwrong'] = 'คำตอบผิดที่เลือกมากที่สุด';
$string['report:struggled'] = 'ส่วนใหญ่ตอบผิด';
$string['report:furthest'] = 'ดูถึง';
$string['report:finished'] = 'ดูจบแล้ว';
$string['report:nobodyyet'] = 'ยังไม่มีใครเปิดกิจกรรมนี้';

// Completion.
$string['completionanswerall'] = 'ตอบคำถามครบทุกข้อ';
$string['completionanswerall_label'] = 'ผู้เรียนต้องตอบคำถามบนไทม์ไลน์ครบทุกข้อ';
$string['completionanswerall_help'] = 'นับที่การตอบ ไม่ใช่การตอบถูก — คนที่ดูจนจบทั้งวิดีโอถือว่าทำกิจกรรมแล้ว ส่วนตอบถูกกี่ข้อเป็นเรื่องของคะแนน';
$string['completionanswerall_desc'] = 'ตอบคำถามครบทุกข้อ';
$string['completionwatched'] = 'ดูจนจบ';
$string['completionwatched_label'] = 'ผู้เรียนต้องดูวิดีโอจนจบ';
$string['completionwatched_help'] = 'การดูจนจบรายงานมาจากเบราว์เซอร์ จึงเป็นบันทึกว่าหัวเล่นไปถึงปลายทาง ไม่ใช่หลักฐานว่ามีคนนั่งดู ถ้าเรื่องนี้สำคัญ ให้ใช้คู่กับเงื่อนไขตอบคำถามครบ';
$string['completionwatched_desc'] = 'ดูวิดีโอจนจบ';

// Controls.
$string['play'] = 'เล่น';
$string['pause'] = 'หยุด';
$string['back10'] = 'ถอย 10 วิ';
$string['error:playerfailed'] = 'โหลดวิดีโอไม่สำเร็จ ตรวจการเชื่อมต่อแล้วรีโหลดหน้านี้';
$string['error:notplayable'] = 'ที่อยู่นี้เบราว์เซอร์เล่นไม่ได้ ให้ใช้ลิงก์ YouTube หรือลิงก์ตรงไปยังไฟล์ MP4 หรือ WebM';
