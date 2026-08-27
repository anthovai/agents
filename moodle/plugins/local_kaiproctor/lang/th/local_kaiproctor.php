<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'KAISER Proctor';
$string['privacy:metadata:face'] = 'ค่าทางคณิตศาสตร์ที่แทนใบหน้าของผู้เรียน ใช้ยืนยันว่าคนที่เรียนคือคนที่ลงทะเบียนไว้ ระบบไม่เก็บภาพถ่ายต้นฉบับ';
$string['privacy:metadata:face:userid'] = 'ผู้เรียนที่เป็นเจ้าของค่าใบหน้านี้';
$string['privacy:metadata:face:embedding'] = 'ค่าที่แทนใบหน้า';
$string['privacy:metadata:face:timecreated'] = 'เวลาที่ลงทะเบียนใบหน้า';
$string['privacy:metadata:evidence'] = 'ภาพนิ่งและคลิปสั้นที่บันทึกระหว่างการเรียนที่มีระบบเฝ้าดู เพื่อเป็นหลักฐานว่าใครอยู่หน้าจอ';
$string['privacy:metadata:evidence:userid'] = 'ผู้เรียนที่ถูกบันทึกหลักฐาน';
$string['privacy:metadata:evidence:reason'] = 'เหตุผลที่บันทึก — ตรวจตามรอบ สุ่มตรวจ หรือพบการละเมิดนโยบาย';
$string['privacy:metadata:evidence:timecreated'] = 'เวลาที่บันทึกหลักฐาน';
$string['privacy:metadata:check'] = 'ผลการตรวจตัวตนและการมีตัวตนแต่ละครั้ง: คะแนนความคล้าย คะแนน liveness และผลการตัดสิน';
$string['privacy:metadata:check:userid'] = 'ผู้เรียนที่ถูกตรวจ';
$string['privacy:metadata:check:similarity'] = 'ความคล้ายระหว่างภาพสดกับใบหน้าที่ลงทะเบียน';
$string['privacy:metadata:check:decision'] = 'ผลการตัดสินของระบบ';
$string['privacy:metadata:check:timecreated'] = 'เวลาที่ตรวจ';
$string['privacy:metadata:faceservice'] = 'ภาพถูกส่งไปวิเคราะห์ที่ face service ซึ่งไม่เก็บข้อมูลใดๆ ไว้';
$string['privacy:metadata:faceservice:image'] = 'ภาพที่ถูกวิเคราะห์';

$string['settings:faceservice'] = 'Face service';
$string['settings:faceserviceurl'] = 'URL ของ face service';
$string['settings:faceserviceurl_desc'] = 'เช่น http://face-service:9000 ต้องอยู่ในเครือข่ายภายใน ห้ามเปิดออกอินเทอร์เน็ต';
$string['settings:apikey'] = 'กุญแจร่วม';
$string['settings:apikey_desc'] = 'ส่งไปกับ header X-Proctor-Key ต้องตรงกับ PROCTOR_API_KEY ฝั่ง service';
$string['settings:matching'] = 'การเทียบใบหน้า';
$string['settings:matchthreshold'] = 'เกณฑ์ผ่าน';
$string['settings:matchthreshold_desc'] = 'ค่า cosine similarity ที่ถือว่าใบหน้าตรงกัน ต้องปรับเทียบกับภาพลงทะเบียนจริงก่อนใช้งานจริง — ค่าตั้งต้นเป็นค่าอ้างอิงของผู้พัฒนาโมเดล ไม่ใช่ค่าที่ปรับเทียบแล้ว';
$string['settings:reviewmin'] = 'เกณฑ์ก้ำกึ่ง';
$string['settings:reviewmin_desc'] = 'คะแนนระหว่างค่านี้กับเกณฑ์ผ่านถือว่าไม่ชัดเจน ระบบจะให้ผู้เรียนจัดหน้าใหม่แทนการตัดสินว่าไม่ผ่าน';
$string['settings:retentiondays'] = 'ระยะเก็บหลักฐาน (วัน)';
$string['settings:retentiondays_desc'] = 'ภาพและคลิปที่เก่ากว่านี้จะถูกลบโดย scheduled task';

$string['settings:policy'] = 'นโยบายการเฝ้าดู';
$string['settings:policy_desc'] = 'ตั้งว่าระบบจะตรวจอะไรบ้างระหว่างเรียน และตรวจถี่แค่ไหน <strong>ทุกช่องเป็นวินาที</strong> ใส่ 0 ในช่องไหนคือปิดการตรวจนั้น';
$string['settings:clickconfirmgracesec'] = 'ให้เวลากดยืนยันกี่วินาที';
$string['settings:clickconfirmgracesec_desc'] = 'นับถอยหลังให้กดปุ่ม ถ้าไม่กดภายในเวลานี้ วิดีโอจะหยุด';
$string['settings:mouseidlewarnsec'] = 'เตือนล่วงหน้ากี่วินาที ก่อนหยุดเพราะไม่ขยับ';
$string['settings:mouseidlewarnsec_desc'] = 'ตัวนับถอยหลังจะขึ้นก่อนวิดีโอหยุดตามเวลานี้ <strong>ต้องไม่เกินค่าในช่องด้านบน</strong> ตัวอย่าง: ช่องบน 30 ช่องนี้ 10 = นิ่งครบ 20 วินาทีเริ่มนับถอยหลัง 10 9 8 … แล้วหยุด ใส่ 0 = หยุดเลยโดยไม่เตือน';
$string['settings:warntoolong'] = 'เตือนล่วงหน้า {$a->warn} วินาทีไม่ได้ เพราะระบบทนความนิ่งได้เพียง {$a->tolerance} วินาที ตัวนับถอยหลังต้องอยู่ในช่วงนั้น — ลดค่านี้ลง หรือเพิ่ม "ทนการนิ่งได้" ก่อน';
$string['settings:warnnegative'] = 'ใส่ค่าติดลบไม่ได้';
$string['settings:presencewarnsec'] = 'เตือนล่วงหน้ากี่วินาที ก่อนหยุดเพราะไม่เห็นหน้า';
$string['settings:presencewarnsec_desc'] = 'เมื่อกล้องไม่เห็นหน้า ระบบจะตรวจซ้ำและนับถอยหลังตามเวลานี้ก่อนหยุดจริง ใส่ 0 = หยุดทันทีที่ไม่เห็นหน้า';
$string['settings:randomclipsperhour'] = 'อัดคลิปเก็บหลักฐาน กี่คลิปต่อชั่วโมง';
$string['settings:randomclipsperhour_desc'] = 'จำนวนคลิปสั้นที่เก็บไว้เป็นหลักฐานโดยเฉลี่ยใน 1 ชั่วโมง ระบบสุ่มเวลาอัดเองเพื่อไม่ให้คาดเดาได้ ใส่ 0 = ไม่อัด';
$string['settings:clipseconds'] = 'คลิปหลักฐานยาวกี่วินาที';
$string['settings:clipseconds_desc'] = 'ความยาวของคลิปแต่ละอันที่อัดเก็บไว้';
$string['settings:blurallowance'] = 'ออกจากหน้าเรียนได้กี่ครั้ง';
$string['settings:blurallowance_desc'] = 'สลับไปหน้าต่างอื่นได้กี่ครั้งก่อนถูกยุติการเรียน ใส่ 0 = ยุติตั้งแต่ครั้งแรก';
$string['settings:strictlockdown'] = 'โหมดเข้มงวด (ข้อสอบ)';
$string['settings:strictlockdown_desc'] = 'ยุติการสอบเมื่อละเมิดนโยบาย แทนการหยุดแล้วให้ทำต่อ ใช้กับการทำข้อสอบเท่านั้น';
$string['settings:lessonstrictlockdown'] = 'โหมดเข้มงวดกับบทเรียนด้วย';
$string['settings:lessonstrictlockdown_desc'] = 'ปกติบทเรียนจะไม่ถูกยุติ เมื่อละเมิดนโยบายระบบจะหยุดวิดีโอ บันทึกหลักฐาน แล้วให้กดเรียนต่อได้ เพราะการยุติบทเรียนไม่ได้ปกป้องอะไร แค่ทำให้ต้องเริ่มใหม่ เปิดข้อนี้ถ้าต้องการให้บทเรียนถูกยุติเหมือนข้อสอบ';
$string['settings:desktopnotification'] = 'แจ้งเตือนระดับระบบ';
$string['settings:desktopnotification_desc'] = 'เด้งการแจ้งเตือนของระบบปฏิบัติการเมื่อผู้เรียนออกจากหน้าเรียน';

// The notice shown right before the camera opens. Separate from the site
// policy, which is agreed to once and covers everything — this one is about
// what is happening in the next few seconds. {$a} is the retention period,
// read from the setting so the notice cannot promise something the purge task
// does not do.
$string['notice:title'] = 'กำลังจะเปิดกล้อง';
$string['notice:enrol'] = '<p>ขั้นตอนต่อไปจะเปิดกล้องเพื่อลงทะเบียนใบหน้าของคุณ</p>
<ul>
<li>ระบบเก็บเป็น<strong>ค่าตัวเลขที่แทนใบหน้า</strong> ไม่ได้เก็บรูปถ่ายของคุณไว้</li>
<li>ใช้เพื่อยืนยันว่าคนที่เรียนและสอบคือคุณเท่านั้น</li>
<li>คุณขอลบข้อมูลนี้ได้ทุกเมื่อผ่านเมนูข้อมูลส่วนบุคคลของบัญชีคุณ</li>
</ul>
<p>กด "ยินยอมและเปิดกล้อง" เพื่อดำเนินการต่อ</p>';
$string['notice:verify'] = '<p>ขั้นตอนต่อไปจะเปิดกล้องเพื่อยืนยันว่าเป็นคุณ ก่อนเริ่มทำข้อสอบ</p>
<ul>
<li>ระบบเทียบใบหน้าสดกับค่าที่คุณลงทะเบียนไว้</li>
<li>ระหว่างสอบจะมีการตรวจเป็นระยะ และ<strong>เก็บภาพไว้เป็นหลักฐานเมื่อพบสิ่งผิดปกติ</strong></li>
<li>หลักฐานถูกลบอัตโนมัติเมื่อครบ {$a} วัน</li>
</ul>
<p>กด "ยินยอมและเปิดกล้อง" เพื่อดำเนินการต่อ</p>';
$string['notice:agree'] = 'ยินยอมและเปิดกล้อง';
$string['notice:decline'] = 'ไม่ยินยอม';
$string['notice:declined'] = 'คุณยังไม่ได้ให้ความยินยอม จึงยังเปิดกล้องไม่ได้ กดอีกครั้งเมื่อพร้อม';

$string['enrol:title'] = 'ลงทะเบียนใบหน้า';
$string['enrol:intro'] = 'ระบบจะยืนยันตัวตนผ่านกล้องระหว่างเรียน ทำตามคำสั่งบนหน้าจอ: มองตรงเข้ากล้อง แล้วหันหน้าตามที่ระบบบอก';
$string['enrol:start'] = 'เริ่ม';
$string['enrol:success'] = 'ลงทะเบียนใบหน้าเรียบร้อยแล้ว';
$string['enrol:failed'] = 'ลงทะเบียนใบหน้าไม่สำเร็จ ลองใหม่อีกครั้ง ถ้ายังไม่ได้ให้แจ้งผู้ดูแลหลักสูตร';
$string['enrol:timeout'] = 'หันหน้าไม่ทันเวลา ลองใหม่และหันช้าๆ';
$string['enrol:replacing'] = 'คุณลงทะเบียนใบหน้าไว้แล้ว การทำรายการนี้จะแทนที่ของเดิม';
$string['enrol:existing'] = 'ลงทะเบียนใบหน้าเมื่อ {$a}';
// Shown on the learner's own profile, where the question is "have I done this
// yet" rather than "how do I do it".
$string['profile:enrolledon'] = 'ลงทะเบียนไว้แล้วเมื่อ {$a}';
$string['profile:notenrolled'] = 'ยังไม่ได้ลงทะเบียนใบหน้า — ต้องลงทะเบียนก่อนเข้าบทเรียนหรือสอบที่มีการเฝ้าดู';


$string['hint:noface'] = 'ขยับให้เห็นใบหน้าชัดๆ';
$string['hint:multiplefaces'] = 'ให้มีใบหน้าเดียวในกรอบ';
$string['hint:spoof'] = 'ตรวจพบสัญญาณการปลอมแปลง';
$string['error:nocamera'] = 'ไม่พบกล้องบนเครื่องนี้ กรุณาต่อกล้อง หรือใช้เครื่องที่มีกล้อง';
$string['error:cameradenied'] = 'การใช้กล้องถูกปฏิเสธ กดไอคอนกล้องที่แถบที่อยู่ของเบราว์เซอร์ เลือกอนุญาต แล้วรีโหลดหน้านี้ — กดลองใหม่เฉยๆ จะไม่ช่วยเพราะเบราว์เซอร์จำคำตอบเดิมไว้';
$string['error:camerabusy'] = 'กล้องถูกใช้งานโดยโปรแกรมอื่นอยู่ ปิดโปรแกรมประชุมหรือแอปกล้อง แล้วลองใหม่';
$string['error:generic'] = 'เกิดข้อผิดพลาด กรุณาลองใหม่';

$string['kaiproctor:enrolface'] = 'ลงทะเบียนใบหน้าของตนเอง';
$string['kaiproctor:viewevidence'] = 'ดูหลักฐานการคุมสอบ';
$string['kaiproctor:manage'] = 'จัดการการตั้งค่าการคุมสอบ';

$string['task:purgeevidence'] = 'ล้างหลักฐานการคุมสอบที่หมดอายุ';
$string['event:attention'] = 'สัญญาณจากระบบคุมสอบ';

$string['invalidevidencekind'] = 'ชนิดหลักฐานไม่ถูกต้อง: {$a}';
$string['evidencetoolarge'] = 'ไฟล์หลักฐานมีขนาดเกินที่กำหนด';

$string['liveness:center'] = 'มองตรงเข้ากล้อง';
$string['liveness:left'] = 'ค่อยๆ หันหน้าไปทางซ้าย';
$string['liveness:right'] = 'ค่อยๆ หันหน้าไปทางขวา';

$string['notification:title'] = 'ระบบคุมสอบ';
$string['paused:title'] = 'วิดีโอถูกหยุด';
$string['paused:resume'] = 'เล่นต่อ';
$string['countdown:idle'] = 'ไม่พบการเคลื่อนไหว จะหยุดวิดีโอในอีก {$a} วินาที';
$string['countdown:presence'] = 'ไม่พบคุณหน้ากล้อง จะหยุดวิดีโอในอีก {$a} วินาที';
$string['confirm:title'] = 'ยืนยันว่ายังเรียนอยู่';
$string['confirm:body'] = 'กดปุ่มภายในเวลาที่กำหนด';
$string['confirm:button'] = 'ยืนยัน';
$string['terminated:title'] = 'การอบรมถูกยุติ';
$string['terminated:close'] = 'ปิดหน้าเรียน';

$string['violation:tab_hidden'] = 'สลับแท็บหรือย่อหน้าต่างระหว่างเรียน';
$string['violation:window_blur'] = 'ออกจากหน้าต่างเรียน';
$string['violation:fullscreen_exit'] = 'ออกจากโหมดเต็มจอระหว่างเรียน';
$string['violation:devtools_suspected'] = 'ตรวจพบการเปิดเครื่องมือนักพัฒนา';
$string['violation:click_confirm_timeout'] = 'ไม่ได้กดยืนยันในเวลาที่กำหนด';
$string['violation:mouse_idle'] = 'ไม่มีการขยับเมาส์หรือใช้คีย์บอร์ดเป็นเวลานาน';
$string['violation:face_absent'] = 'ไม่พบผู้เรียนหน้าจอ';
$string['violation:multiple_faces'] = 'พบมากกว่าหนึ่งคนหน้ากล้อง กดเล่นต่อเมื่อเหลือผู้เรียนคนเดียว';
$string['violation:face_review'] = 'ยืนยันใบหน้าไม่ชัดเจน จัดหน้าให้อยู่กลางกล้องแล้วกดเล่นต่อ';
$string['violation:fail'] = 'ใบหน้าไม่ตรงกับผู้ลงทะเบียน';
$string['violation:fail_liveness'] = 'สงสัยการใช้ภาพถ่ายหรือวิดีโอแทนคนจริง';

$string['report:title'] = 'หลักฐานการคุมสอบ';
$string['report:enrolledon'] = 'ลงทะเบียนใบหน้าเมื่อ {$a}';
$string['report:notenrolled'] = 'ผู้เรียนรายนี้ยังไม่ได้ลงทะเบียนใบหน้า จึงไม่มีภาพอ้างอิงให้เทียบ';
$string['report:checks'] = 'ผลตรวจตัวตนและการมีตัวตน';
$string['report:nochecks'] = 'ไม่มีผลตรวจในบริบทนี้';
$string['report:evidence'] = 'หลักฐานที่บันทึกไว้';
$string['report:noevidence'] = 'ไม่มีภาพหรือคลิปที่บันทึกในบริบทนี้';
$string['report:events'] = 'สัญญาณการเฝ้าดู';
$string['report:noevents'] = 'ไม่มีสัญญาณที่บันทึกในบริบทนี้';
$string['report:time'] = 'เวลา';
$string['report:kind'] = 'ประเภทการตรวจ';
$string['report:decision'] = 'ผลตัดสิน';
$string['report:similarity'] = 'ความคล้าย';
$string['report:liveness'] = 'Liveness';
$string['report:threshold'] = 'เกณฑ์';
$string['report:model'] = 'โมเดล';
$string['report:signal'] = 'สัญญาณ';
$string['report:videotime'] = 'ตำแหน่ง';
$string['report:detail'] = 'รายละเอียด';
$string['report:thresholdnote'] = 'เกณฑ์ที่แสดงคือเกณฑ์ที่ใช้ตอนตรวจครั้งนั้น ไม่ใช่เกณฑ์ที่ตั้งไว้ปัจจุบัน';

$string['activity:settings'] = 'การคุมสอบ';
$string['activity:explain'] = 'เมื่อเปิดการคุมสอบ ผู้เรียนที่เข้ากิจกรรมนี้จะถูกเฝ้าดู ทั้งการมีตัวตน การยืนยันตัวตน การออกจากหน้าต่าง และการสุ่มเก็บคลิปหลักฐาน เหมือนตอนสอบทุกอย่าง ผู้สอนที่เปิดดูกิจกรรมจะไม่ถูกเฝ้าดู';
$string['activity:on'] = 'กิจกรรมนี้เปิดการคุมสอบอยู่';
$string['activity:off'] = 'กิจกรรมนี้ยังไม่ได้เปิดการคุมสอบ';
$string['activity:turnon'] = 'เปิดการคุมสอบ';
$string['activity:turnoff'] = 'ปิดการคุมสอบ';
$string['activity:saved'] = 'บันทึกแล้ว';
$string['activity:unsupported'] = 'ยังไม่รองรับการคุมสอบสำหรับกิจกรรมชนิด {$a}';
$string['activity:willmonitor'] = 'กิจกรรมนี้มีระบบคุมสอบ คลิกหรือกดปุ่มใดก็ได้เพื่อเริ่ม ระบบจะใช้กล้องยืนยันว่าคุณอยู่หน้าจอ';
$string['activity:monitoring'] = 'ระบบกำลังเฝ้าดู กรุณาอยู่หน้ากล้องและอย่าออกจากหน้าต่างนี้';

$string['task:closestalesessions'] = 'ปิดการเรียนที่ค้างอยู่';
$string['session:active'] = 'กำลังดำเนินอยู่';
$string['session:completed'] = 'เสร็จสิ้น';
$string['session:terminated'] = 'ถูกระบบยุติ';
$string['session:abandoned'] = 'จบโดยไม่มีการยืนยัน';
$string['report:nosessions'] = 'ไม่มีการเรียนที่ถูกเฝ้าดูในบริบทนี้';
$string['report:ended'] = 'สิ้นสุด';
$string['report:policy'] = 'กฎที่บังคับใช้ระหว่างการเรียนครั้งนี้';
$string['report:policynote'] = 'นี่คือกฎที่บังคับอยู่ตอนเริ่ม บันทึกไว้ ณ เวลานั้น การแก้การตั้งค่าตอนนี้ไม่เปลี่ยนค่านี้';
$string['report:checkoff'] = 'ปิด';
$string['report:orphans'] = 'บันทึกที่ไม่ได้อยู่ในการเรียนครั้งใด';
$string['report:orphansnote'] = 'มาจากช่วงก่อนที่ระบบจะบันทึกเป็นครั้งๆ เก็บไว้แทนที่จะซ่อน แต่ไม่มีบันทึกกฎที่บังคับตอนนั้น';

$string['import:title'] = 'นำเข้าข้อสอบจาก PDF';
$string['import:intro'] = 'สำหรับแนวข้อสอบใบอนุญาตแบบไทย: ข้อสอบขึ้นต้นด้วย 1. ตัวเลือก ก./ข./ค./ง. และมีเฉลยขึ้นต้นว่า "คำตอบ : วิชา" ไฟล์ PDF ต้องมีข้อความจริง ไฟล์ที่สแกนจากกระดาษใช้ไม่ได้ ระบบจะยังไม่นำเข้าจนกว่าคุณจะเห็นผลการอ่านก่อน';
$string['import:file'] = 'ไฟล์ PDF';
$string['import:parse'] = 'อ่านไฟล์';
$string['import:parsefailed'] = 'อ่านไฟล์ PDF ไม่ได้ ({$a})';
$string['import:preview'] = 'ตัวอย่างข้อสอบที่อ่านได้';
$string['import:previewnote'] = 'ตัวหนาคือคำตอบที่ถูก ถ้าดูแล้วผิด แปลว่ารูปแบบไฟล์ไม่ตรงกับที่ตัวอ่านรองรับ อย่านำเข้า';
$string['import:count'] = 'จำนวนข้อที่อ่านได้';
$string['import:easy'] = 'ง่าย';
$string['import:medium'] = 'ปานกลาง';
$string['import:hard'] = 'ยาก';
$string['import:confirm'] = 'นำเข้า {$a} ข้อ';
$string['import:done'] = 'นำเข้าแล้ว {$a->imported} ข้อ ข้ามไป {$a->skipped} ข้อ';
$string['import:failed'] = 'นำเข้าไม่สำเร็จ';
$string['import:nousable'] = 'ไม่มีข้อไหนที่จับคู่เฉลยได้';
$string['import:expired'] = 'รายการนำเข้านี้หมดอายุแล้ว กรุณาอัปโหลดไฟล์ใหม่';
$string['import:openbank'] = 'เปิดคลังข้อสอบ';

// Building a paper that draws at random from the course's own bank.
$string['paper:title'] = 'สร้างชุดข้อสอบสุ่ม';
$string['paper:intro'] = 'สุ่มข้อสอบจากคลังของรายวิชานี้มาเป็นชุดข้อสอบ ผู้เข้าสอบแต่ละคนได้คนละชุด และไม่เห็นระดับความยากของข้อสอบ ตอนนี้คลังของรายวิชานี้มี <strong>{$a}</strong> ข้อให้สุ่ม';
$string['paper:quiz'] = 'ใส่ลงในข้อสอบ';
$string['paper:count'] = 'จำนวนข้อ';
$string['paper:count_help'] = 'จำนวนข้อที่ผู้เข้าสอบแต่ละคนจะได้ ระบบจะสุ่มจากคลังของรายวิชานี้ใหม่ทุกครั้งที่มีคนเริ่มทำข้อสอบ จึงต้องไม่เกินจำนวนข้อที่มีในคลัง';
$string['paper:replace'] = 'ล้างข้อสอบเดิมในชุดนี้ก่อน';
$string['paper:replace_help'] = 'ถ้าไม่เลือก ข้อสอบสุ่มจะถูกเพิ่มต่อท้ายของเดิม ซึ่งมักไม่ใช่สิ่งที่ต้องการเมื่อกำลังสร้างชุดใหม่';
$string['paper:build'] = 'สร้างชุดข้อสอบ';
$string['paper:done'] = 'เพิ่มข้อสอบสุ่ม {$a->added} ข้อ (ลบของเดิม {$a->removed} ข้อ)';
$string['paper:atleastone'] = 'ต้องมีอย่างน้อย 1 ข้อ';
$string['paper:toomany'] = 'คลังของรายวิชานี้มีเพียง {$a} ข้อ จะสุ่มเกินจำนวนที่มีไม่ได้';
$string['paper:noquizzes'] = 'รายวิชานี้ยังไม่มีข้อสอบให้ใส่ สร้างกิจกรรมข้อสอบก่อน แล้วกลับมาที่หน้านี้';
$string['paper:nobank'] = 'คลังข้อสอบของรายวิชานี้ยังว่างอยู่ นำเข้าข้อสอบก่อนจึงจะสุ่มได้';
$string['import:difficultynote'] = 'ระดับความยากถูกเก็บเป็น tag ของข้อสอบ ข้อสอบจึงสุ่มตามระดับความยากได้';

$string['stats:title'] = 'สถิติระบบคุมสอบ';
$string['stats:service'] = 'Face service';
$string['stats:serviceup'] = 'เชื่อมต่อได้';
$string['stats:servicedown'] = 'เชื่อมต่อไม่ได้';
$string['stats:models'] = 'โหลดโมเดล {$a} ตัว';
$string['stats:threshold'] = 'เกณฑ์ผ่าน {$a}';
$string['stats:nolivenessmodel'] = 'ไม่มีโมเดลตรวจการปลอมแปลงถูกโหลด ผลตรวจทุกครั้งจะรายงานว่าไม่ได้ประเมิน และการถือภาพถ่ายมาส่องกล้องจะจับไม่ได้';
$string['stats:noserviceurl'] = 'ยังไม่ได้ตั้งค่า URL ของ face service';
$string['stats:badresponse'] = 'บริการตอบกลับมา แต่ไม่ใช่รายงานสถานะ';
$string['stats:usage'] = 'การใช้งาน';
$string['stats:enrolled'] = 'ผู้เรียนที่ลงทะเบียนใบหน้าแล้ว';
$string['stats:sessions'] = 'จำนวนการเรียนที่ถูกเฝ้าดู';
$string['stats:checks'] = 'จำนวนผลตรวจที่บันทึก';
$string['stats:monitored'] = 'กิจกรรมที่เปิดการคุมสอบ';
$string['stats:proctoredquizzes'] = 'ข้อสอบที่เปิดการคุมสอบ';
$string['stats:sessionoutcomes'] = 'ผลการจบของแต่ละครั้ง';
$string['stats:decisions'] = 'ผลการตรวจตัวตน';
$string['stats:retention'] = 'หลักฐานและการเก็บรักษา';
$string['stats:evidencecount'] = 'ภาพและคลิปที่เก็บไว้';
$string['stats:evidencesize'] = 'พื้นที่ที่ใช้';
$string['stats:oldestevidence'] = 'หลักฐานที่เก่าที่สุด';
$string['stats:purgelastrun'] = 'task ล้างข้อมูลทำงานล่าสุด';
$string['stats:neverrun'] = 'ยังไม่เคยทำงาน';
$string['stats:overdue'] = 'มีหลักฐานที่เก่ากว่าระยะเก็บรักษาที่กำหนด แปลว่า task ล้างข้อมูลไม่ทำงาน กรุณาตรวจสอบ Moodle cron';
$string['stats:nodata'] = 'ยังไม่มีข้อมูล';

$string['draw:title'] = 'ชุดข้อสอบนี้ถูกสุ่มมาอย่างไร';
$string['draw:note'] = 'ค่า seed คำนวณจากผู้เรียน ข้อสอบ และลำดับครั้งที่สอบเท่านั้น จึงเลือกให้ได้ชุดที่ต้องการไม่ได้ และใครก็ตามที่มีสามค่านี้คำนวณซ้ำเพื่อตรวจสอบได้ ส่วนรายการข้อสอบคือชุดที่ได้รับจริง ซึ่งยังคงเป็นจริงแม้คลังข้อสอบจะถูกแก้ภายหลัง';
$string['draw:attemptnumber'] = 'ครั้งที่';
$string['draw:seed'] = 'ค่า seed';
$string['draw:seedverified'] = 'คำนวณซ้ำแล้วตรงกัน';
$string['draw:seedmismatch'] = 'ไม่ตรงกัน — ต้องตรวจสอบ';
$string['draw:questions'] = 'ข้อสอบที่ได้รับ';
$string['draw:slot'] = 'ข้อที่ {$a}';
$string['draw:randomfrom'] = 'สุ่มจาก: {$a}';
$string['draw:fixed'] = 'เหมือนกันทุกคน';
$string['draw:papertitle'] = 'ชุดข้อสอบ';
$string['draw:papernote'] = 'มีการสุ่มชุดข้อสอบแล้ว แต่ระบบเฝ้าดูไม่ได้เริ่มทำงาน เช่น เปิดกล้องไม่ได้ หรือผู้เรียนออกไปก่อน ชุดข้อสอบยังถูกบันทึกไว้ทุกกรณี';

$string['settings:ai'] = 'ผู้ช่วย AI';
$string['settings:ai_desc'] = 'ใช้หรือไม่ก็ได้ โมเดลภาษาช่วยสรุปการเรียนแต่ละครั้งให้ผู้ตรวจ และช่วยชี้ข้อสอบที่ข้อความไทยดูผิดเพี้ยนจากการนำเข้า PDF โมเดล<strong>ไม่เห็นภาพ คลิป หรือค่าที่แทนใบหน้า</strong> และ<strong>ไม่ตัดสินอะไรทั้งสิ้น</strong> ทุกคำตอบเป็นเพียงคำแนะนำที่คนต้องพิจารณาเอง<br><br><strong>ก่อนเปิดใช้กับโมเดลบนคลาวด์ ต้องตรวจว่าการส่งข้อมูลกิจกรรมของผู้เรียนออกนอกองค์กรอยู่ในขอบเขตของเอกสารความยินยอมและสัญญาประมวลผลข้อมูลแล้ว</strong> ถ้ายังไม่ครอบคลุม ให้ชี้ gateway ไปที่โมเดลที่รันเองแทน';
$string['settings:aienabled'] = 'เปิดใช้ผู้ช่วย AI';
$string['settings:aienabled_desc'] = 'ปิดไว้เป็นค่าเริ่มต้น ไม่มีส่วนอื่นของระบบที่ต้องพึ่งพา';
$string['settings:aibaseurl'] = 'ที่อยู่บริการผู้ช่วย AI';
$string['settings:aibaseurl_desc'] = 'ตำแหน่งที่บริการ AI reviewer ทำงาน เช่น http://ai-service:9100 — คำสั่งที่ให้โมเดลและกฎว่าส่งอะไรได้บ้าง อยู่ที่บริการนั้น ไม่ได้อยู่ที่นี่';
$string['settings:aiapikey'] = 'กุญแจของบริการ';
$string['settings:aiapikey_desc'] = 'ส่งเป็น X-Proctor-Key — ส่วนที่ว่าโมเดลไหนตอบ และรันบนเครื่องเราหรือของผู้ให้บริการ เป็นการตั้งค่าที่ตัวบริการ';

$string['ai:notconfigured'] = 'ยังไม่ได้เปิดใช้ผู้ช่วย AI';
$string['ai:badresponse'] = 'gateway ตอบกลับมา แต่ไม่ใช่ผลลัพธ์ของโมเดล';
$string['ai:emptyresponse'] = 'โมเดลไม่ได้ตอบอะไรกลับมา';
$string['ai:summarytitle'] = 'ร่างสรุป';
$string['ai:summarynote'] = 'เขียนโดยโมเดลภาษาจากตัวเลขที่แสดงในหน้านี้ เป็นเพียงตัวช่วยอ่าน ไม่ใช่ข้อสรุป โมเดลไม่ได้เห็นภาพหรือคะแนนใดๆ และไม่มีส่วนตัดสินอะไร กรุณาตรวจสอบกับบันทึกจริงด้านล่าง';
$string['ai:summarise'] = 'ให้ AI ร่างสรุป';
$string['ai:failed'] = 'ร่างสรุปไม่สำเร็จ: {$a}';
$string['ai:questiontitle'] = 'ข้อสอบที่อาจนำเข้ามาผิดเพี้ยน';
$string['ai:questionnote'] = 'สระและวรรณยุกต์ไทยอาจสลับลำดับตอนดึงข้อความจาก PDF รายการนี้คือจุดที่ควรไปดู ไม่ใช่การแก้ไข';
$string['ai:nofindings'] = 'ไม่พบข้อความที่ดูผิดเพี้ยน';

// The navigation assistant.
$string['ask:title'] = 'ถามเกี่ยวกับเว็บนี้';
$string['ask:intro'] = 'ถามว่าอะไรอยู่ตรงไหน แล้วจะได้ลิงก์กลับไป และถามคะแนนหรือจำนวนครั้งที่สอบของตัวเองได้ด้วย — รู้เฉพาะหน้าที่คุณเปิดได้อยู่แล้ว และจะบอกตรงๆ ถ้าหาไม่เจอ';
$string['ask:placeholder'] = 'เช่น บทเรียนความปลอดภัยอยู่ตรงไหน / ผมสอบผ่านไหม';
$string['ask:send'] = 'ถาม';
$string['ask:thinking'] = 'กำลังค้น...';
$string['ask:nomatch'] = 'ไม่พบหน้าที่ตรงกับคำถามนี้ ลองระบุชื่อคอร์สหรือชื่อกิจกรรมดู';
$string['ask:notavailable'] = 'ผู้ช่วยถูกปิดอยู่';
$string['ask:sources'] = 'หน้าที่ใช้ตอบ';
$string['ask:note'] = 'ตอบจากหน้าที่คุณเปิดได้ และจากผลของคุณเองบนหน้าเหล่านั้นเท่านั้น มองไม่เห็นผลของคนอื่น ไม่คิดเลขเอง และไม่ตัดสินอะไรทั้งสิ้น';
$string['ask:page:enrol'] = 'ลงทะเบียนใบหน้า';
$string['ask:page:enrol_desc'] = 'ลงทะเบียนใบหน้าก่อนเข้าบทเรียนหรือสอบที่มีการเฝ้าดู';

// The AI console.
$string['ai:console'] = 'ผู้ช่วย AI';
$string['ai:on'] = 'เปิดอยู่';
$string['ai:off'] = 'ปิดอยู่';
$string['ai:turnon'] = 'เปิดใช้งาน';
$string['ai:turnoff'] = 'ปิดการใช้งาน';
$string['ai:turnedon'] = 'เปิดผู้ช่วย AI แล้ว';
$string['ai:turnedoff'] = 'ปิดผู้ช่วย AI แล้ว';
$string['ai:service'] = 'บริการผู้ช่วย';
$string['ai:backend'] = 'ปลายทางของโมเดล';
$string['ai:contract'] = 'เวอร์ชันสัญญา';
$string['ai:task:summarise'] = 'โมเดลสำหรับสรุปการเรียนแต่ละครั้ง';
$string['ai:task:ask'] = 'โมเดลสำหรับผู้ช่วยนำทาง';
$string['ai:task:questions'] = 'โมเดลสำหรับตรวจข้อสอบที่นำเข้า';
$string['ai:onpremises'] = 'โมเดลรันบนเครื่องที่คุณควบคุมเอง ข้อมูลกิจกรรมผู้เรียนไม่ออกนอกเครือข่าย';
$string['ai:offpremises'] = 'ปลายทางของโมเดลอยู่นอกเครือข่ายคุณ ข้อมูลกิจกรรมผู้เรียน — ชื่อเหตุการณ์ จำนวนครั้ง และคะแนนของผู้เรียนเองเมื่อเขาถามถึง แต่ไม่ใช่ภาพหรือค่าวัดใบหน้า — จะถูกส่งออกไป ตรวจให้แน่ใจก่อนเปิดว่าอยู่ในขอบเขตของเอกสารความยินยอมและสัญญาประมวลผลข้อมูลแล้ว';
$string['ai:brokenwhileon'] = 'ผู้ช่วย AI เปิดอยู่ แต่ไม่มีโมเดลตอบ ทุกครั้งที่เรียกใช้จะล้มเหลวจนกว่าจะแก้';
$string['ai:note'] = 'บริการไม่เคยได้รับภาพ คลิป ค่าวัดใบหน้า หรือชื่อคน และจะปฏิเสธ payload ที่มีสิ่งเหล่านั้น ส่วนคะแนนจะถูกส่งเฉพาะของผู้เรียนที่ถามเท่านั้น ไม่มีของคนอื่น และสิ่งที่ตอบกลับไม่ได้ตัดสินอะไรทั้งสิ้น';
$string['ai:settingslink'] = 'ตั้งค่าปลั๊กอิน';


// Why an enrolment attempt did not work, specifically.
$string['enrol:noface'] = 'กล้องหาใบหน้าไม่พบ นั่งหันหน้าเข้ากล้อง อย่าให้มีอะไรปิดหน้า แล้วลองใหม่';
$string['enrol:toosmall'] = 'ใบหน้าเล็กเกินไปในภาพ ขยับเข้าใกล้กล้องแล้วลองใหม่';
$string['enrol:multiplefaces'] = 'มีใบหน้ามากกว่าหนึ่งในภาพ ให้มีคุณอยู่คนเดียวในกรอบ — ภาพถ่ายหรือจอที่อยู่ด้านหลังก็นับด้วย';
$string['enrol:spoof'] = 'ยืนยันไม่ได้ว่าเป็นคนจริง ให้ลงทะเบียนด้วยกล้องของคุณเองและมองตรงเข้ากล้อง ไม่ใช่ถือภาพถ่ายหรือจออื่นมาส่อง';
$string['hint:toosmall'] = 'ขยับเข้าใกล้กล้อง';
// The assistant's launcher, on every page. Its icon is drawn in the template
// rather than named here: a glyph is not something a translator should have to
// choose, and the one this used to carry was a "?" that collided with Moodle's
// own help button sitting directly under it.
$string['ask:openfull'] = 'เปิดหน้าเต็ม';

$string['settings:asksource'] = 'ให้ผู้ช่วยตัวไหนตอบในกล่องถาม';
$string['settings:asksource_desc'] = 'ผู้ช่วยของ Moodle ตอบว่า "หน้าที่ต้องการอยู่ตรงไหน" จากคอร์สที่ผู้เรียนคนนั้นเปิดได้ ส่วนผู้ช่วย Indorama ตอบเรื่องโครงสร้างของ LMS เดิม — ตาราง route และไฟล์ซอร์ส — และไม่รู้อะไรเกี่ยวกับ Moodle เลย ทั้งสองเป็นคนละบริการ ตัวเลือกนี้เลือกว่ากล่องถามจะส่งคำถามไปที่ไหน ตัวเลือก Indorama ต้องมีสิทธิ์ local/kaiproctor:manage เพราะโครงสร้างฐานข้อมูลไม่ใช่สิ่งที่ควรวางไว้ตรงหน้าผู้เรียน';
$string['settings:asksource:moodle'] = 'นำทางใน Moodle (เว็บนี้)';
$string['settings:asksource:indorama'] = 'โครงสร้าง LMS ของ Indorama (บริการแยก)';
$string['settings:ragbaseurl'] = 'ที่อยู่ของผู้ช่วย Indorama';
$string['settings:ragbaseurl_desc'] = 'ที่อยู่ที่บริการ indorama-rag รออยู่ เช่น http://host.docker.internal:8110 เมื่อบริการรันบนเครื่อง host และ Moodle รันในคอนเทนเนอร์ ใช้เฉพาะเมื่อเลือกแหล่งเป็น Indorama';
$string['settings:ragapikey'] = 'กุญแจของผู้ช่วย Indorama';
$string['settings:ragapikey_desc'] = 'กุญแจลับที่ตั้งไว้ฝั่งบริการนั้น (RAG_API_KEY) ส่งไปในหัวข้อ X-Agent-Key เว้นว่างได้ถ้าบริการไม่ได้ตั้งกุญแจ ซึ่งปลอดภัยเฉพาะบนเครื่องที่ไม่มีอะไรอื่นเข้าถึงได้';
$string['ask:rag:notconfigured'] = 'เลือกผู้ช่วย Indorama ไว้ แต่ยังไม่ได้ตั้งที่อยู่ของบริการ';
$string['ask:rag:unreachable'] = 'ติดต่อผู้ช่วย Indorama ไม่ได้ ตรวจว่าบริการรันอยู่และที่อยู่ในการตั้งค่าถูกต้อง';
$string['ask:rag:malformed'] = 'ผู้ช่วย Indorama ตอบกลับมาเป็นรูปแบบที่ปลั๊กอินอ่านไม่ได้';

$string['chat:role:user'] = 'คุณ';
$string['chat:role:assistant'] = 'ผู้ช่วย';
$string['chat:sources'] = 'อ้างอิงจาก';
$string['chat:overquota'] = 'ประวัติแชทของคุณเต็มโควตาแล้ว ลบบทสนทนาสักรายการเพื่อให้มีที่ว่าง';
$string['chat:history'] = 'ประวัติแชทของฉัน';
$string['chat:history_desc'] = 'ทุกอย่างที่คุณเคยถามผู้ช่วย เก็บเป็นไฟล์ Markdown ที่คุณอ่าน ดาวน์โหลด และลบเองได้ คนอื่นเปิดดูไม่ได้';
$string['chat:none'] = 'คุณยังไม่เคยถามผู้ช่วย';
$string['chat:turns'] = '{$a} ข้อความ';
$string['chat:usage'] = 'ใช้ไป {$a->used} จาก {$a->quota}';
$string['chat:open'] = 'เปิด';
$string['chat:download'] = 'ดาวน์โหลด .md';
$string['chat:rename'] = 'เปลี่ยนชื่อ';
$string['chat:delete'] = 'ลบ';
$string['chat:deleteall'] = 'ลบบทสนทนาทั้งหมด';
$string['chat:confirmdelete'] = 'ลบบทสนทนานี้? ไม่มีการกู้คืน';
$string['chat:confirmdeleteall'] = 'ลบบทสนทนาทั้งหมดที่เคยคุยกับผู้ช่วย? ไม่มีการกู้คืน';
$string['chat:deleted'] = 'ลบแล้ว';
$string['settings:chatquota'] = 'พื้นที่เก็บบทสนทนาต่อผู้ใช้';
$string['settings:chatquota_desc'] = 'จำนวนไบต์ของบทสนทนาที่หนึ่งบัญชีเก็บได้ หนึ่งเทิร์นกินราวสองถึงห้ากิโลไบต์ ค่าตั้งต้นหนึ่งกิกะไบต์จึงเป็นกันชนกันสคริปต์วนลูป ไม่ใช่งบที่ต้องคอยบริหาร เมื่อเกินโควตาระบบจะปฏิเสธการบันทึก ไม่ตัดข้อความทิ้ง เพราะบทสนทนาที่หยุดบันทึกเงียบๆ หน้าตาเหมือนบทสนทนาที่ไม่มีใครคุยต่อ';
$string['privacy:metadata:convo'] = 'บทสนทนากับผู้ช่วย เก็บไว้ให้เจ้าของกลับมาอ่านได้';
$string['privacy:metadata:convo:userid'] = 'ใครเป็นคนคุย';
$string['privacy:metadata:convo:title'] = 'ชื่อบทสนทนา ตั้งจากคำถามแรก';
$string['privacy:metadata:convo:timecreated'] = 'เริ่มเมื่อไร';
$string['privacy:metadata:convo:timemodified'] = 'คุยต่อครั้งล่าสุดเมื่อไร';

$string['settings:ragstaffonly'] = 'จำกัดผู้ช่วย Indorama ไว้เฉพาะเจ้าหน้าที่';
$string['settings:ragstaffonly_desc'] = 'เปิดไว้เป็นค่าตั้งต้น และควรเปิดไว้เว้นแต่ตัดสินใจเป็นอย่างอื่น เพราะผู้ช่วยตัวนั้นตอบเรื่องโครงสร้างฐานข้อมูล ซึ่งมีประโยชน์กับคนที่ดูแลระบบและแปลกถ้าเอาไปวางตรงหน้าคนที่กำลังเรียน ปิดตัวเลือกนี้เพื่อให้ผู้ใช้ที่ล็อกอินทุกคนถามได้ การปิดไม่ได้ทำให้ผู้ช่วยรู้อะไรมากขึ้น (มันไม่เคยเห็นข้อมูลในแถวอยู่แล้ว) แต่เปลี่ยนแค่ว่าใครถามได้';

$string['ask:rag:off_topic'] = 'ฉันตอบได้เฉพาะเรื่องระบบการเรียนของ Indorama — หลักสูตร เนื้อหา และโครงสร้างของระบบ ลองถามเรื่องพวกนี้ดูนะคะ';
$string['ask:rag:no_material'] = 'ไม่พบข้อมูลเกี่ยวกับเรื่องนั้นค่ะ ลองระบุชื่อหลักสูตร หัวข้อ หรือสิ่งที่กำลังจะทำดู';
$string['ask:rag:ungrounded_answer'] = 'ฉันตอบไม่ได้แบบที่ตรวจสอบที่มาได้ จึงขอไม่ตอบดีกว่าค่ะ ลองถามด้วยคำอื่นดูนะคะ';
$string['ask:rag:llm_timeout'] = 'ใช้เวลานานเกินไปค่ะ คำถามแรกหลังจากเว้นช่วงจะช้าเพราะระบบกำลังโหลดโมเดล ลองถามอีกครั้งนะคะ';
$string['ask:rag:llm_empty'] = 'ฉันเรียบเรียงคำตอบไม่สำเร็จค่ะ ลองถามอีกครั้งนะคะ';
$string['ask:rag:llm_unreachable'] = 'ตอนนี้ติดต่อผู้ช่วยไม่ได้ค่ะ รออีกสักครู่แล้วลองใหม่';
$string['ask:rag:tool_limit'] = 'ฉันค้นหลายที่แล้วแต่ยังสรุปคำตอบไม่ได้ค่ะ ลองถามให้เจาะจงขึ้นดูนะคะ';
$string['ask:rag:refused'] = 'ข้อนี้ฉันตอบไม่ได้ค่ะ ลองถามด้วยคำอื่นดูนะคะ';
$string['settings:presenceseconds'] = 'ตรวจว่ามีคนอยู่หน้ากล้อง ทุกกี่วินาที';
$string['settings:presenceseconds_desc'] = 'ระบบถ่ายภาพจากกล้องตามช่วงเวลานี้เพื่อดูว่ายังมีคนนั่งอยู่ ไม่ได้ดูว่าเป็นใคร ตัวอย่าง: 120 = ตรวจทุก 2 นาที';
$string['settings:verifyseconds'] = 'ตรวจว่าเป็นคนเดิม ทุกกี่วินาที';
$string['settings:verifyseconds_desc'] = 'เทียบใบหน้ากับที่ลงทะเบียนไว้ตามช่วงเวลานี้ ทำงานเฉพาะกับผู้เรียนที่ลงทะเบียนใบหน้าแล้ว ตัวอย่าง: 600 = ตรวจทุก 10 นาที';
$string['settings:clickconfirmseconds'] = 'ให้ผู้เรียนกดยืนยัน ทุกกี่วินาที';
$string['settings:clickconfirmseconds_desc'] = 'ขึ้นปุ่มให้กดยืนยันว่ายังเรียนอยู่ นับเฉพาะตอนวิดีโอกำลังเล่น ถ้าตั้งไว้นานกว่าความยาววิดีโอ ปุ่มจะไม่ขึ้นเลย ตัวอย่าง: 300 = ทุก 5 นาที';
$string['settings:mouseidleseconds'] = 'หยุดวิดีโอเมื่อไม่ขยับนานกี่วินาที';
$string['settings:mouseidleseconds_desc'] = 'ถ้าไม่ขยับเมาส์และไม่พิมพ์อะไรเลยนานเท่านี้ วิดีโอจะหยุด ตัวอย่าง: 30 = นิ่งเกินครึ่งนาทีแล้วหยุด';
$string['report:everyseconds'] = 'ทุก {$a} วินาที';
