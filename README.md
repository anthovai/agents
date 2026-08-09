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
docker compose up --build
```

เปิด http://localhost:8080 (ครั้งแรกใช้เวลาสักพัก — ติดตั้ง Moodle อัตโนมัติ)

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
