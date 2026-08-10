# KAISER Proctor on Moodle

ระบบคุมสอบ/คุมการอบรมออนไลน์ บน Moodle 5.1 LTS
พัฒนาโดย KAISER KLOWNS CO., LTD.

โปรเจคก่อนหน้า `D:\face-re` **ยังอยู่ครบ ไม่ถูกแตะ** ใช้เป็น reference spec
และเป็นสัญญาว่าระบบใหม่ต้องทำข้อกำหนดลูกค้า 7 ข้อเดิมได้ไม่ต่ำกว่าเดิม

- [docs/PLAN.md](docs/PLAN.md) — สถาปัตยกรรม + ลำดับงาน
- [docs/MIGRATION.md](docs/MIGRATION.md) — โค้ดเดิมแต่ละชิ้นไปไหน

## เริ่มต้น

```bash
cp .env.example .env
```

แก้รหัสผ่านใน `.env` ให้เรียบร้อยก่อน แล้ว:

```bash
sh face-service/models/fetch.sh
```

```bash
sh moodle/plugins/fetch-third-party.sh
```

```bash
docker compose up --build
```

เปิด http://localhost:8080 (ครั้งแรกใช้เวลาสักพัก — ติดตั้ง Moodle อัตโนมัติ)

## สิ่งที่ไม่ได้เขียนเอง

เขียนเองเฉพาะที่เป็น IP ที่เหลือใช้ของที่มีอยู่แล้ว

| ความสามารถ | ใครทำ | ไลเซนส์ |
|---|---|---|
| ผู้ใช้ สิทธิ์ คอร์ส ข้อสอบ ตัดเกรด รายงาน | Moodle core | GPL-3 |
| ความยินยอม PDPA + คำขอลบข้อมูล | `tool_policy` + Privacy API (core) | GPL-3 |
| ล็อกเครื่องระดับ OS | Safe Exam Browser + `quizaccess_seb` (core) | MPL / GPL |
| **วิดีโอแบบมีปฏิสัมพันธ์** | [`mod_interactivevideo`](https://github.com/sokunthearithmakara/moodle-mod_interactivevideo) | GPL-3 |
| ตรวจจับใบหน้า / embedding | YuNet + SFace (OpenCV Zoo) | MIT / Apache-2.0 |
| Liveness | MiniFASNet (Silent-Face-Anti-Spoofing) | Apache-2.0 |
| **Active liveness challenge** | เขียนเอง | — |
| **Attention enforcement 6 สัญญาณ** | เขียนเอง | — |
| **หลักฐาน + retention + กติกาเข้าสอบ** | เขียนเอง | — |

`mod_interactivevideo` ถูกดึงมาแบบ pin commit โดย `fetch-third-party.sh` ไม่ได้ก็อปเข้ามาในโปรเจค

## บันทึกการเรียนเป็นครั้งๆ (sitting)

ทุกครั้งที่มีการเฝ้าดู ระบบเปิด **sitting** หนึ่งรายการ แล้วผูกผลตรวจ หลักฐาน
และสัญญาณทั้งหมดของครั้งนั้นไว้ด้วยกัน — บอกได้ว่าอันไหนคือการนั่งเรียนครั้งไหน

แต่ละ sitting เก็บ **นโยบายที่บังคับใช้ ณ ตอนเริ่ม** ไว้เป็น snapshot รวมถึงเกณฑ์
เทียบใบหน้า ผู้ดูแลแก้การตั้งค่าทีหลัง **ไม่เปลี่ยนความหมายของครั้งที่ผ่านมาแล้ว**
— เป็นคำตอบให้กับคำถามที่ผู้เรียนอาจถามหลายเดือนต่อมาว่า "ตอนนั้นระบบตั้งกฎอะไรไว้"

นโยบายถูกอ่านจาก server ตอนเปิด sitting แล้วส่งกลับไปให้เบราว์เซอร์ใช้ ไม่ใช่ให้
เบราว์เซอร์บอกว่าใช้กฎอะไร — ไม่งั้น snapshot จะพิสูจน์อะไรไม่ได้เลย

| สถานะ | หมายถึง |
|---|---|
| `active` | กำลังเรียนอยู่ |
| `completed` | จบจริง (วิดีโอจบ หรือส่งข้อสอบแล้ว) |
| `terminated` | ระบบตัดจบ พร้อมเหตุผล |
| `abandoned` | หยุดเฝ้าดูโดยไม่มีอะไรยืนยันว่าจบ (ปิดเครื่อง เน็ตหลุด) |

การออกจากหน้าเว็บ **ไม่** ถูกนับว่า `completed` เพราะเบราว์เซอร์แยกไม่ออกระหว่าง
รีโหลด เน็ตหลุด และเลิกกลางคัน — scheduled task จะปิดให้เป็น `abandoned` แทน
ซึ่งบันทึกเฉพาะสิ่งที่รู้จริง และ `completed` ที่มาทีหลังไม่สามารถทับ `terminated` ได้

## Safe Exam Browser

ข้อสอบ **"ความเสี่ยงสูง (SEB)"** เปิดทั้งสองชั้นพร้อมกัน:

- **SEB** ล็อกเครื่อง — Moodle สร้างไฟล์ `.seb` และ **Config Key** ให้เอง (ของจริง ไม่ใช่ hash ที่ทำเอง)
- **ระบบเรา** ยืนยันตัวตนและเก็บหลักฐาน

เปิดจากเบราว์เซอร์ธรรมดาไม่ได้ ต้องกดลิงก์ `seb://` ซึ่ง SEB ที่ติดตั้งบนเครื่องจะรับช่วงต่อ

> ชั้น lockdown ในเบราว์เซอร์ของเรา **ตรวจจับและบันทึก** เท่านั้น ห้าม Alt+Tab จอที่สอง
> หรือมือถือข้างๆ ไม่ได้จริง ถ้าต้องการล็อกจริงต้องใช้ SEB

## บทเรียนวิดีโอแบบมีปฏิสัมพันธ์

`mod_interactivevideo` ให้ annotation คำถามระหว่างวิดีโอ และ player 22 แบบ (YouTube, Vimeo,
PeerTube, HLS, HTML5, ...) ส่วนของเราคือเฝ้าดูผู้เรียนขณะใช้งาน

เปิด/ปิดรายกิจกรรมได้ที่เมนู **"การคุมสอบ"** ในกิจกรรมนั้น (`/local/kaiproctor/monitor.php?cmid=`)
รองรับ `interactivevideo`, `h5pactivity`, `page`, `resource`, `url`

การเชื่อมต่อใช้เฉพาะ `window.IVPLAYER` ที่ปลั๊กอินเปิดให้ใช้อย่างเป็นทางการ ไม่แตะภายใน
จึงไม่พังเมื่อเขาออกเวอร์ชันใหม่ — ดู [video_adapter.js](moodle/plugins/local_kaiproctor/amd/src/video_adapter.js)

**ผู้สอนที่เปิดดูกิจกรรมจะไม่ถูกเฝ้าดู** — คนตรวจงานไม่ใช่คนสอบ

## โครงสร้าง

```
docker-compose.yml          Moodle + PostgreSQL + face-service + cron
docker/                     Dockerfile และ entrypoint ของ Moodle
face-service/               FastAPI — detection, embedding, pose, liveness
  app/                      โค้ดบริการ (stateless ไม่เก็บข้อมูล)
  models/                   น้ำหนักโมเดล + LICENSES.md
  tests/                    smoke test + ตัวปรับเทียบ threshold
moodle/plugins/
  local_kaiproctor/         บริการกลาง + Privacy API + evidence
  quizaccess_kaiproctor/    กติกาเข้าสอบ
docs/                       แผนและตารางย้ายโค้ด
```

## ไลเซนส์ของโมเดล

ทุกโมเดลที่ใช้ต้องใช้เชิงพาณิชย์ได้ ดู [face-service/models/LICENSES.md](face-service/models/LICENSES.md)
InsightFace `buffalo_l` **ถูกถอดออกโดยตั้งใจ** — น้ำหนักโมเดลอนุญาตเฉพาะงานวิจัยที่ไม่ใช่เชิงพาณิชย์

## ⚠️ ยังไม่ได้ปรับเทียบเกณฑ์ใบหน้า

`FACE_MATCH_THRESHOLD=0.363` เป็นค่าอ้างอิงของผู้พัฒนา SFace **ไม่ใช่ค่าที่ปรับเทียบกับข้อมูลจริง**
โปรเจคเดิมก็ไม่เคยปรับเทียบเช่นกัน (`face-re/tests/images/` ที่ smoke test อ้างถึงไม่เคยมีอยู่จริง —
เทสต์ 36 ตัวที่ผ่านใช้กล้องปลอมที่ไม่มีใบหน้า จึงพิสูจน์ flow ได้ แต่ไม่พิสูจน์ความแม่นยำ)

ก่อนใช้งานจริงต้อง:

1. ใส่ภาพลงทะเบียนจริงใน `face-service/tests/faces/` ชื่อ `<คน>_<ลำดับ>.jpg`
2. `pytest tests/test_calibration.py -s -m calibration`
3. เอาค่าที่ได้ไปใส่ `FACE_MATCH_THRESHOLD`

## สถานะ

| ส่วน | สถานะ |
|---|---|
| Docker stack | โครงพร้อม ยังไม่ได้รันจริง |
| face-service | โค้ดครบ 3 endpoint · smoke test ผ่าน 6/6 · **ยังไม่ทดสอบกับใบหน้าจริง** |
| `local_kaiproctor` | version/settings/capability/schema/Privacy API พร้อม · ยังไม่มี logic |
| `quizaccess_kaiproctor` | โครงกติกาพร้อม · ยังไม่ gate จริง |
| ย้าย attention-monitor / active-liveness | ยังไม่เริ่ม |
| SEB | ยังไม่เริ่ม (ใช้ `quizaccess_seb` ของ core) |
