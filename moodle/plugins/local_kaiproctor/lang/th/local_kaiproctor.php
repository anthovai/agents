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
