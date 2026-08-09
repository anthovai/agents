# KAISER Proctor on Moodle — แผนสถาปัตยกรรม

โปรเจคใหม่ เริ่มบน Moodle ทั้งหมด แทนการเขียน LMS เอง
โปรเจคเดิม `D:\face-re` **ไม่ถูกแตะ** ใช้เป็น reference spec + ชุดเทสต์อ้างอิง

---

## หลักการตัดสินใจ

> เขียนเองเฉพาะสิ่งที่เป็น IP และ Moodle ไม่มี — ที่เหลือใช้ของ Moodle

| ต้องการ | ใครทำ |
|---|---|
| ผู้ใช้ / role / สิทธิ์ | **Moodle core** |
| หลักสูตร / เนื้อหา / วิดีโอ | **Moodle core** (mod_page, mod_resource) |
| ข้อสอบ + คลังข้อสอบ + ตัดเกรด | **Moodle core** (mod_quiz + question bank) |
| ความยินยอม PDPA + versioning | **Moodle core** (`tool_policy`) |
| คำขอลบข้อมูล / export | **Moodle core** (`tool_dataprivacy` + Privacy API) |
| รายงาน / log / audit | **Moodle core** (logstore) + ของเรา |
| ล็อกเครื่องระดับ OS | **Safe Exam Browser** + `quizaccess_seb` (core ตั้งแต่ 3.9) |
| Face embedding + match | **เขียนเอง** (face-service) |
| Passive liveness | **เขียนเอง** (MiniFASNet, Apache-2.0) |
| **Active liveness challenge** | **เขียนเอง** — ไม่มีใน OSS ตัวไหน |
| **Attention enforcement 6 สัญญาณ** | **เขียนเอง** |
| **หลักฐานภาพ/คลิป + retention** | **เขียนเอง** (แต่ hook เข้า Privacy API) |

---

## Stack

```
┌─────────────────────────────────────────────────────────┐
│ Browser                                                  │
│  Moodle UI + AMD modules ของเรา                          │
│   ├── kaiproctor/active_liveness   (challenge หันหน้า)   │
│   ├── kaiproctor/attention_monitor (6 สัญญาณ)            │
│   └── kaiproctor/lockdown          (ชั้นเสริมนอก SEB)    │
└────────────────┬────────────────────────────────────────┘
                 │ Moodle Web Service (AJAX, sesskey)
┌────────────────▼────────────────────────────────────────┐
│ Moodle 5.1 LTS   (php:8.3-apache + PostgreSQL 16)       │
│  core: tool_policy · tool_dataprivacy · mod_quiz         │
│        quizaccess_seb                                    │
│  ของเรา:                                                 │
│   local_kaiproctor      บริการกลาง + evidence + privacy   │
│   quizaccess_kaiproctor กติกาเข้าสอบ (ยืนยันหน้า/consent)│
└────────────────┬────────────────────────────────────────┘
                 │ HTTP (internal network, shared secret)
┌────────────────▼────────────────────────────────────────┐
│ face-service   FastAPI (stateless, ไม่เก็บข้อมูล)        │
│  POST /analyze  → bbox, pose(yaw/pitch/roll), liveness   │
│  POST /embed    → embedding 128-d base64                 │
│  POST /verify   → similarity + decision                  │
│                                                          │
│  detect  : YuNet         (OpenCV Zoo, Apache-2.0)        │
│  embed   : SFace         (OpenCV Zoo, Apache-2.0)        │
│  liveness: MiniFASNet    (MiniVision, Apache-2.0)        │
└─────────────────────────────────────────────────────────┘
```

**ทุกโมเดลใช้เชิงพาณิชย์ได้** — ไม่มี InsightFace, ไม่มี AGPL

---

## Plugin ที่ต้องเขียน

### `local_kaiproctor` — บริการกลาง

| ส่วน | หน้าที่ |
|---|---|
| `classes/face_client.php` | คุยกับ face-service (embed / verify / analyze) |
| `classes/evidence.php` | เก็บ snapshot / clip ลง Moodle File API |
| `classes/enrolment.php` | ลงทะเบียนใบหน้าผู้เรียน (1 คน N embedding) |
| `classes/privacy/provider.php` | **บังคับ** — บอก Moodle ว่าเก็บอะไร + ลบยังไง |
| `classes/task/purge_evidence.php` | scheduled task ล้างหลักฐานเกิน retention |
| `externallib` / `classes/external/` | AJAX endpoint ให้ JS ฝั่งหน้าเรียน |
| `amd/src/*.js` | active_liveness, attention_monitor, lockdown |
| `db/access.php` | capability: enrol face, view evidence, manage |
| `settings.php` | face-service URL, threshold, นโยบาย attention |

ตาราง: `local_kaiproctor_face` (embedding ผู้เรียน), `local_kaiproctor_evidence`,
`local_kaiproctor_check` (ผลตรวจแต่ละครั้ง), `local_kaiproctor_event`

### `quizaccess_kaiproctor` — กติกาเข้าสอบ

- ก่อนเริ่ม: ต้องยอมรับ policy (tool_policy) + ผ่าน active liveness challenge
- ระหว่างสอบ: inject attention monitor, verify ซ้ำตาม interval
- เมื่อละเมิด: บันทึก event + (โหมดเข้ม) ปิด attempt
- หน้ารายงานให้อาจารย์: timeline หลักฐานต่อ attempt

### ทางเลือก: `mod_kaivideo` หรือใช้ `local_kaiproctor` แปะกับ mod_page
สำหรับ "หน้าเรียนวิดีโอที่ต้องเฝ้าดู" — ตัดสินใจภายหลังหลังทำ quiz path เสร็จ

---

## ลำดับงาน

| # | งาน | ผลลัพธ์ที่วัดได้ |
|---|---|---|
| 1 | docker stack ขึ้น | เปิด Moodle ติดตั้งเสร็จที่ localhost:8080 |
| 2 | face-service + SFace | `POST /verify` คืน similarity ถูกต้องกับรูปทดสอบใน `face-re/tests/images/` |
| 3 | `local_kaiproctor` ติดตั้งได้ + Privacy API ผ่าน | Moodle admin ไม่ฟ้อง missing privacy provider |
| 4 | ลงทะเบียนใบหน้า + active liveness | ผู้เรียน enrol หน้าได้ผ่าน challenge |
| 5 | `quizaccess_kaiproctor` gate ข้อสอบ | เข้าสอบไม่ได้ถ้าหน้าไม่ตรง |
| 6 | attention monitor ระหว่างสอบ | event ถูกบันทึกครบ 6 สัญญาณ |
| 7 | SEB integration | `.seb` config ถูกต้อง + Config Key ตรวจผ่าน |
| 8 | ย้าย E2E test จาก face-re | เทสต์ 7 ข้อกำหนดเดิมผ่านบน Moodle |

ข้อ 8 สำคัญ — [face-re/reports/REPORT.md](../../face-re/reports/REPORT.md) มีตารางแม็พ
ข้อกำหนดลูกค้า 7 ข้อ → เทสต์ ต้องได้ตารางเทียบเท่าบน stack ใหม่ ไม่งั้นถือว่าถอยหลัง

---

## ที่ยังไม่ตัดสินใจ

1. **หน้าเรียนวิดีโอ** — mod ของเราเอง หรือใช้ mod_page + filter
2. **1:N face search** — ตอนนี้ 1:1 พอ ถ้าต้องกันสวมสิทธิ์ข้ามคน ค่อยเพิ่ม pgvector
3. **gaze tracking** — MediaPipe ใน browser (Apache-2.0) เฟส 2
4. **ตรวจมือถือ/คนที่สอง** — ต้องใช้ object detector ที่ไม่ใช่ AGPL (MediaPipe EfficientDet-Lite)
