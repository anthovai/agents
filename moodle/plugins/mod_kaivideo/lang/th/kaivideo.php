<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'วิดีโอแบบมีปฏิสัมพันธ์';
$string['modulename'] = 'วิดีโอแบบมีปฏิสัมพันธ์';
$string['modulenameplural'] = 'วิดีโอแบบมีปฏิสัมพันธ์';
$string['pluginadministration'] = 'จัดการวิดีโอแบบมีปฏิสัมพันธ์';
$string['modulename_help'] = 'เล่นวิดีโอแล้วแทรกคำถามตามเวลาที่กำหนด ตั้งให้วิดีโอไม่ยอมเล่นต่อจนกว่าจะตอบคำถามได้';

$string['videourl'] = 'ที่อยู่วิดีโอ';
$string['videourl_help'] = 'ลิงก์ YouTube หรือ Vimeo, สตรีมแบบ HLS (.m3u8) หรือลิงก์ตรงไปยังไฟล์วิดีโอที่เบราว์เซอร์เล่นได้ เช่น MP4 หรือ WebM ไม่ใช่หน้าเว็บที่มีวิดีโออยู่ข้างใน เพราะสิ่งที่เล่นต้องเป็น video element จริง ซึ่งเป็นเหตุผลเดียวกับที่ทำให้ระบบคุมสอบเฝ้าดูมันได้

ไฟล์ไม่ได้ถูกคัดลอกเข้ามาใน Moodle ถ้าที่อยู่นั้นใช้ไม่ได้เมื่อไหร่ บทเรียนก็หยุดทำงานเมื่อนั้น และไม่ติดไปกับการสำรองข้อมูลคอร์สด้วย

สตรีมที่วางไว้บนเว็บอื่นต้องอนุญาตให้เว็บนี้อ่านได้ (CORS) ซึ่งเป็นการตั้งค่าที่เซิร์ฟเวอร์นั้น ไม่ใช่ที่นี่';
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
$string['category'] = 'หมวดหมู่';
$string['category_help'] = 'เรื่องที่คำถามข้อนี้ถาม เช่น "ความปลอดภัย" หรือ "คุณภาพ" — ใส่ที่คำถามแต่ละข้อ ไม่ใช่ที่วิดีโอ เพราะวิดีโอหนึ่งเรื่องมักครอบคลุมหลายหัวข้อ

รายงานจะแยกคะแนนตามหมวดหมู่ให้ ทำให้เห็นว่าผู้เรียนอ่อนเรื่องไหน ไม่ใช่แค่ได้กี่เปอร์เซ็นต์รวม

เว้นว่างได้ถ้ายังไม่ต้องการแยกหมวด ช่องนี้จะแนะนำหมวดที่เคยใช้ในวิดีโอนี้แล้ว เพื่อไม่ให้พิมพ์ชื่อเดียวกันคนละแบบจนกลายเป็นสองหมวดในรายงาน';
$string['error:categorytoolong'] = 'ชื่อหมวดหมู่ยาวเกิน {$a} ตัวอักษร';
$string['questiontext'] = 'คำถาม';
$string['choicen'] = 'ตัวเลือกที่ {$a}';
$string['correctchoice'] = 'คำตอบที่ถูก';
$string['feedback'] = 'ข้อความหลังตอบ';
$string['backtovideo'] = 'กลับไปที่วิดีโอ';

$string['noquestions'] = 'วิดีโอนี้ยังไม่มีคำถามบนไทม์ไลน์';
$string['questioncount'] = 'มีคำถาม {$a} ข้อในวิดีโอนี้';
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
$string['report:bycategory'] = 'แยกตามหมวดหมู่';
$string['report:bycategory_help'] = 'เรียงจากหมวดที่อ่อนที่สุด — คำถามข้อเดียวที่คนตอบผิดหมดมักเป็นเรื่องของคำถามข้อนั้น แต่ทั้งหมวดที่ได้ 40% คือช่วงของวิดีโอที่สอนไม่เข้าใจ';
$string['report:learners'] = 'ผู้เรียน (คน)';
$string['report:uncategorised'] = '(ไม่ระบุหมวดหมู่)';
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
$string['error:notplayable'] = 'ที่อยู่นี้ไม่ใช่สิ่งที่เบราว์เซอร์เล่นได้ กรุณาใช้ลิงก์ YouTube หรือ Vimeo, สตรีม HLS ที่ลงท้ายด้วย .m3u8 หรือลิงก์ตรงไปยังไฟล์ MP4 หรือ WebM';

// Question types.
$string['type'] = 'ชนิดของการหยุด';
$string['type_help'] = 'สิ่งที่เกิดขึ้นเมื่อวิดีโอหยุดตรงนี้

* **ตอบข้อเดียว** — มีหลายตัวเลือก ถูกข้อเดียว
* **ตอบหลายข้อ** — มีหลายตัวเลือก และต้องเลือกข้อที่ถูกให้ครบทุกข้อ เลือกไม่ครบไม่ได้คะแนน
* **พิมพ์คำตอบ** — ผู้เรียนพิมพ์เอง ระบบเทียบกับรายการที่ท่านกำหนด โดยไม่สนใจช่องว่างและตัวพิมพ์ใหญ่เล็กภาษาอังกฤษ
* **ข้อความ** — ไม่ใช่คำถาม วิดีโอหยุด แสดงข้อความ แล้วเล่นต่อ';
$string['type:choice'] = 'ตอบข้อเดียว';
$string['type:multichoice'] = 'ตอบหลายข้อ';
$string['type:shorttext'] = 'พิมพ์คำตอบ';
$string['type:info'] = 'ข้อความ';
$string['questiontext_help'] = 'ข้อความที่ผู้เรียนอ่าน ถ้าเป็นชนิดข้อความ ช่องนี้คือตัวข้อความนั้น';
$string['iscorrect'] = 'ถูก';
$string['choices'] = 'ตัวเลือก';
$string['choices_help'] = 'กรอกเท่าที่ต้องการ ช่องที่เว้นว่างไว้จะถูกตัดทิ้ง ทำเครื่องหมายข้อที่ถูก ถ้าเป็นคำถามแบบตอบข้อเดียวให้ทำเครื่องหมายข้อเดียว';
$string['acceptedanswers'] = 'คำตอบที่ยอมรับ';
$string['acceptedanswers_help'] = 'บรรทัดละหนึ่งคำตอบ ตอบตรงกับข้อใดข้อหนึ่งก็ถือว่าถูก

การเทียบจะไม่สนใจช่องว่างที่เกินและตัวพิมพ์ใหญ่เล็กภาษาอังกฤษเท่านั้น วรรณยุกต์และสระภาษาไทยเทียบตามที่พิมพ์จริง เพราะการถือว่า ผู้ กับ ผู เป็นคำเดียวกันคือการยอมรับคำที่สะกดผิด ไม่ใช่การผ่อนปรน ถ้าต้องการรับคำที่เขียนต่างออกไป ให้เพิ่มเป็นอีกบรรทัด';
$string['feedback_help'] = 'แสดงหลังจากตอบแล้ว ถ้าเป็นชนิดข้อความ จะแสดงต่อท้ายข้อความนั้น';
$string['youranswer'] = 'คำตอบของท่าน';
$string['submitanswer'] = 'ส่งคำตอบ';
$string['error:badtype'] = 'ไม่มีคำถามชนิดนี้ในกิจกรรมนี้';
$string['error:noacceptedanswer'] = 'คำถามแบบพิมพ์คำตอบต้องมีคำตอบที่ยอมรับอย่างน้อยหนึ่งข้อ';
$string['error:answertoolong'] = 'คำตอบที่ยอมรับข้อนี้ยาวเกินกว่าที่ผู้เรียนจะพิมพ์';
$string['error:onlyoneanswer'] = 'คำถามแบบตอบข้อเดียวทำเครื่องหมายว่าถูกได้เพียงข้อเดียว';
$string['error:allanswerscorrect'] = 'ทำเครื่องหมายว่าถูกทุกข้อ จึงไม่มีข้อที่ตอบผิดได้ กรุณาเอาเครื่องหมายออกจากข้อที่ไม่ถูก';
$string['report:blank'] = '(ไม่ได้ตอบ)';
$string['privacy:metadata:kaivideo_response:response'] = 'สิ่งที่ตอบ ได้แก่ ตัวเลือกที่เลือก หรือข้อความที่พิมพ์';

// Uploading the video.
$string['sourcetype'] = 'ที่มาของวิดีโอ';
$string['sourcetype_help'] = '**อัปโหลดไฟล์** เก็บวิดีโอไว้ใน Moodle นี้ ส่งให้เฉพาะผู้เรียนที่ลงทะเบียนแล้ว ติดไปกับการสำรองข้อมูลคอร์ส และยังเล่นได้แม้ออกอินเทอร์เน็ตไม่ได้

**ใส่เป็นที่อยู่** ชี้ไปที่ลิงก์ YouTube หรือไฟล์วิดีโอที่วางไว้ที่อื่นแล้ว ไม่มีการคัดลอกไฟล์ ใครคุมที่อยู่นั้นก็คุมว่าบทเรียนจะเล่นได้หรือไม่

เก็บได้อย่างเดียวเท่านั้น การสลับจากแบบหนึ่งไปอีกแบบจะล้างของเดิมทิ้ง';
$string['source:upload'] = 'อัปโหลดไฟล์';
$string['source:url'] = 'ใส่เป็นที่อยู่';
$string['videofile'] = 'ไฟล์วิดีโอ';
$string['videofile_help'] = 'MP4 หรือ WebM ขนาดไฟล์สูงสุดเป็นไปตามที่ระบบกำหนดไว้ ถ้าเป็นวิดีโอยาวอาจต้องให้ผู้ดูแลระบบอัปโหลดให้ หรือใช้วิธีใส่ที่อยู่แทน';
$string['error:novideo'] = 'กรุณาเลือกไฟล์วิดีโอ หรือเปลี่ยนไปใส่เป็นที่อยู่';
$string['privacy:metadata:filepurpose'] = 'วิดีโอที่อัปโหลดเข้ากิจกรรมนี้เป็นสื่อการสอน ไม่ใช่ข้อมูลส่วนบุคคล และไม่มีข้อมูลของผู้เรียนอยู่ในนั้น';

// Streaming backends.
$string['error:vimeofailed'] = 'โหลดตัวเล่นของ Vimeo ไม่ได้ กรุณาตรวจว่าวิดีโอนั้นอนุญาตให้เล่นบนเว็บนี้ และเข้าถึง player.vimeo.com ได้';
$string['error:streamfailed'] = 'เริ่มสตรีมไม่ได้ กรุณาตรวจที่อยู่ และตรวจว่าเซิร์ฟเวอร์ที่วางไฟล์ไว้อนุญาตให้เว็บนี้อ่านได้';
