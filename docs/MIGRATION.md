# face-re → Moodle : แม็พทีละชิ้น

ตารางนี้ตอบคำถามเดียว: **โค้ด 8,200 บรรทัดใน `D:\face-re` แต่ละชิ้นไปไหน**

## ยกมาแทบไม่แก้ (logic เป็น IP)

| ของเดิม | บรรทัด | ปลายทาง | หมายเหตุ |
|---|---|---|---|
| `app/liveness.py` | 98 | `face-service/app/liveness.py` | MiniFASNet Apache-2.0 ใช้ต่อได้เลย ไฟล์ `.onnx` ก็อปจาก `face-re/models/` |
| `app/static/active-liveness.js` | 165 | `local_kaiproctor/amd/src/active_liveness.js` | แปลงเป็น AMD module + เรียกผ่าน Moodle AJAX แทน fetch ตรง |
| `app/static/attention-monitor.js` | 449 | `local_kaiproctor/amd/src/attention_monitor.js` | เหมือนกัน — logic 6 สัญญาณคงเดิม |
| `app/static/lockdown.js` | 159 | `local_kaiproctor/amd/src/lockdown.js` | เป็นชั้นเสริม ของจริงคือ SEB |

## เขียนใหม่ (แนวคิดเดิม ภาษาใหม่)

| ของเดิม | บรรทัด | ปลายทาง |
|---|---|---|
| `app/face_engine.py` | 118 | `face-service/app/face_engine.py` — เปลี่ยน InsightFace → YuNet + SFace |
| `app/store.py` (media, checks, events) | ~400/655 | `local_kaiproctor/classes/evidence.php` + Moodle File API |
| `app/main.py` (session/event/report) | ~300/741 | `local_kaiproctor/classes/external/` + Moodle logstore |
| `kai-proctor/backend/app/seb.py` | 82 | ใช้ `quizaccess_seb` ของ core แทน — ของ core ทำ Config Key ถูกสเปคจริง |

## ทิ้ง — Moodle ทำให้แล้ว

| ของเดิม | บรรทัด | Moodle ใช้อะไรแทน |
|---|---|---|
| `kai-proctor/backend/app/auth.py` | 256 | core auth + role + capability |
| `app/store.py` (consent tables) | ~150 | `tool_policy` — versioning + re-consent + audit trail |
| `app/store.py` (purge/erase) | ~100 | `tool_dataprivacy` + Privacy API |
| `app/exam.py` | 437 | `mod_quiz` + question bank (random question, shuffle, ตัดเกรด server-side) |
| `app/config.py` (policy dict) | 118 | `settings.php` + admin UI |
| `kai-proctor/.../static/pages/*` | ~1,100 | Moodle UI |

## ⚠️ ทิ้งแล้วเสียของ — ต้องหาทางชดเชย

| ของเดิม | ปัญหา | ทางออก |
|---|---|---|
| `app/exam.py` **deterministic seed draw** | `mod_quiz` random question **reproduce ไม่ได้** ผู้ตรวจสอบย้อนหลังไม่รู้ว่าคนนี้ได้ชุดไหนจากกติกาอะไร | Moodle เก็บ `question_attempts` ต่อ attempt อยู่แล้ว → ชุดข้อสอบจริงตรวจย้อนได้ **แต่** ต้องเขียน report ที่ export ออกมาเป็นหลักฐานเหมือนของเดิม |
| `app/exam_pdf.py` **import ข้อสอบจาก PDF** | Moodle ไม่มี | เก็บไว้เป็น CLI tool แปลง PDF → **Moodle GIFT/XML** แล้ว import เข้า question bank |
| ข้อความ/UX ภาษาไทยทั้งหมด | Moodle UI คนละแบบ | lang string ไทยใน plugin + ทดสอบกับผู้ใช้จริง |

## ชุดเทสต์

`face-re/tests/` มี 36 เทสต์ผ่าน + `reports/REPORT.md` แม็พข้อกำหนด 7 ข้อ
**เก็บไว้เป็นสัญญา (contract)** — stack ใหม่ต้องผ่านข้อกำหนดเดิมทั้ง 7 ข้อ
เขียน E2E ใหม่ด้วย Playwright ยิงใส่ Moodle แล้วทำ REPORT.md แบบเดียวกัน

`face-re/tests/images/` (ref.jpg / same.jpg / other.jpg) ใช้ทดสอบ face-service ใหม่ได้เลย
— ต้องได้ผล pass/fail เหมือนเดิมหลังเปลี่ยนจาก buffalo_l เป็น SFace (threshold จะต่างกัน ต้อง calibrate ใหม่)

## เหตุผลที่ต้อง calibrate threshold ใหม่

| | buffalo_l (เดิม) | SFace (ใหม่) |
|---|---|---|
| มิติ embedding | 512 | 128 |
| ระยะทาง | cosine | cosine (OpenCV แนะนำ) |
| threshold แนะนำ | 0.42 / 0.35 | ~0.363 (cosine) — **ต้องวัดกับข้อมูลจริงก่อนใช้** |

ห้ามก็อป 0.42 มาใช้ตรงๆ — คนละโมเดล คนละ distribution
