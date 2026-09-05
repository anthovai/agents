# Deploy บน Digital Ocean

Face Recognition กับ Agent บน droplet เดียว หลัง reverse proxy ตัวเดียว
เปิดสู่อินเทอร์เน็ตแค่ 443 (และ 22 สำหรับ SSH)

**reverse proxy ไม่ได้อยู่ใน compose ชุดนี้** ทั้งสองบริการผูกกับ loopback
เท่านั้น จะมีทางเข้าจากภายนอกได้ต้องอ่าน [PROXY.md](PROXY.md) ก่อน — มีสองทาง
คือยก Caddy ของโฟลเดอร์นี้ขึ้นเอง หรือเกาะ proxy ที่เครื่องมีอยู่แล้ว

---

## แผนผัง port

| บริการ | port เดิม | port บน droplet | เปิดสู่เน็ต |
|---|---|---|---|
| reverse proxy (ดู PROXY.md) | — | **80, 443** | ✅ ทางเข้าเดียว |
| Face Recognition | 9000 | `127.0.0.1:18081` | ❌ loopback |
| Agent | 9200 | `127.0.0.1:18082` | ❌ loopback |

**ทำไมเปลี่ยนเลข** ทั้งสองค่าเดิมชนของที่คนใช้กันเยอะ

- `9000` เป็นของ MinIO, Portainer และ PHP-FPM
- `9200` เป็นของ Elasticsearch — ตัวนี้อันตรายที่สุด เพราะถ้าวันหนึ่งลง
  Elasticsearch บน droplet เดียวกัน มันจะยึด port ไป แล้วอาการที่เห็นคือ
  "Agent ล่ม" ซึ่งชี้ไปผิดทางทั้งหมด
- `18081` / `18082` ไม่มีอะไรมาตรฐานจองไว้

ถึงจะชนก็ไม่กระทบภายนอกอยู่ดี เพราะทั้งคู่ผูกกับ loopback แต่การเลือกเลขที่ไม่ชน
ตั้งแต่แรก แปลว่าไม่ต้องมาไล่หาเหตุตอนตีสอง

**เข้าใช้งานจากภายนอก** ผ่านพาธ ไม่ใช่ port

```
https://<domain>/face/health
https://<domain>/face/verify
https://<domain>/agent/health
https://<domain>/agent/chat
```

---

## ขนาด droplet และค่าใช้จ่าย

Agent ใช้ RAM จริง **41 MB** เพราะไม่ได้รันโมเดลเอง — ส่งไป gpt-5-mini
Face Recognition กิน CPU ตอนประมวลผลภาพ แต่โมเดลรวมกันไม่ถึง 45 MB

| รายการ | ต่อเดือน |
|---|---|
| Droplet 2 vCPU / 2 GB (Basic Regular) | ~$12 |
| Backup อัตโนมัติ (แนะนำ) | ~$2.40 |
| gpt-5-mini | ตามใช้จริง — ดูด้านล่าง |
| **รวมค่าเครื่อง** | **~$14.40** |

**1 GB ($6) พอไหม** พอสำหรับทดสอบ แต่ถ้ามี `/analyze` ยิงพร้อมกันหลายคน
OpenCV จะกิน RAM เร็วกว่าที่คิด — 2 GB คือจุดที่ไม่ต้องมาลุ้น

**ค่า gpt-5-mini** หนึ่งเทิร์นของ agent คือการเรียกโมเดลหลายครั้ง
วัดจากการทดสอบจริงได้ราว 3,000–8,000 token ต่อเทิร์น รวม reasoning token
คิดคร่าวๆ **หลักสตางค์ต่อคำถาม** ถ้าใช้วันละ 200 คำถามก็ยังไม่ถึง $10/เดือน
แต่ควร**ตั้ง usage limit ที่ฝั่ง OpenAI** ไว้ด้วย เพราะ key ที่หลุด
หรือ loop ที่ยิงรัว จะไม่มีอะไรหยุดมันจากฝั่งนี้

> เทียบให้เห็นภาพ: ถ้ารันโมเดล 7B เองบน droplet ต้องใช้เครื่อง 16 GB
> ราว **$84/เดือน** — การเปลี่ยนมาใช้ gpt-5-mini ประหยัดค่าเครื่องราว 6 เท่า
> และไม่ต้องเปิดเครื่องที่ออฟฟิศทิ้งไว้

---

## ทางลัด: สคริปต์เดียวจบ

บน droplet ที่เพิ่งสร้าง

```bash
REPO_URL=<git-url> sh -c "$(curl -fsSL <raw-url>/deploy/digitalocean/bootstrap.sh)"
```

หรือถ้า clone ไว้แล้ว

```bash
sh /opt/kai/deploy/digitalocean/bootstrap.sh
```

สคริปต์ทำครบตั้งแต่ลง Docker, ตั้ง firewall, สร้าง `.env` พร้อมสุ่มกุญแจให้,
ดึงไฟล์โมเดล, จนถึงสตาร์ตและตรวจ health

**รันซ้ำได้เสมอ** ถ้าค้างกลางทางให้รันใหม่ ไม่ต้องไล่ดูว่าทำถึงไหนแล้ว
รอบแรกจะหยุดพร้อมบอกให้ไปเติม `SITE_DOMAIN` กับ `RAG_LLM_API_KEY` ก่อน
แล้วรันอีกครั้ง

สคริปต์**ตรวจ DNS ให้ก่อนสตาร์ต** ถ้าโดเมนยังไม่ชี้มาที่ droplet จะหยุดทันที
เพราะ Let's Encrypt จำกัดการขอที่ล้มเหลวไว้ 5 ครั้งต่อโดเมนต่อสัปดาห์ —
พลาดสี่ครั้งแล้วต้องรอทั้งสัปดาห์

ขั้นตอนแบบละเอียดข้างล่างนี้คือสิ่งที่สคริปต์ทำ เผื่อต้องทำมือหรือแก้ปัญหา

---

## ขั้นตอน deploy

### 1. สร้าง droplet

- Ubuntu 24.04 LTS, Basic Regular 2 vCPU / 2 GB
- เลือก region **Singapore (SGP1)** — ใกล้ไทยที่สุด latency ต่ำสุด
- เปิด backup ตอนสร้าง (ถูกกว่าเปิดทีหลัง)
- ใส่ SSH key ตอนสร้าง **อย่าใช้รหัสผ่าน**

### 2. ชี้ DNS ก่อนเริ่ม

จำเป็นเฉพาะเมื่อจะยก Caddy ของโฟลเดอร์นี้ขึ้นเอง (ทาง A ใน
[PROXY.md](PROXY.md)) — สร้าง A record ชี้ `<domain>` มาที่ IP ของ droplet
**แล้วรอให้ resolve จริงก่อน** Caddy ขอใบรับรองทันทีที่สตาร์ต ถ้า DNS ยังไม่ตาม
คือขอไม่ผ่าน และ Let's Encrypt จำกัดไว้ 5 ครั้งต่อสัปดาห์ต่อโดเมน

```bash
dig +short <domain>
```

ต้องได้ IP ของ droplet ออกมาก่อนจึงไปขั้นถัดไป

ยังไม่มีโดเมน ใช้ `<ip>.sslip.io` ไปก่อนได้สำหรับให้ลูกค้าทดลอง — ดู PROXY.md

### 3. ติดตั้ง Docker และ firewall

```bash
ssh root@<droplet-ip>
```

```bash
curl -fsSL https://get.docker.com | sh
```

firewall **เฉพาะเมื่อเครื่องนี้เป็นของงานนี้อย่างเดียว** การเปิด ufw
คือการเปลี่ยนสิ่งที่ทั้งเครื่องยอมให้ผ่าน ไม่ใช่ผลข้างเคียงของการ deploy —
บนเครื่องที่มีบริการอื่นอยู่ ให้ข้ามขั้นนี้ไป (`bootstrap.sh` ก็ข้ามให้เอง
ถ้าพบว่า ufw ปิดอยู่)

```bash
ufw allow OpenSSH && ufw allow 80/tcp && ufw allow 443/tcp
ufw allow from 172.16.0.0/12   # คอนเทนเนอร์ที่ต้องเรียก service บน host
ufw enable
```

บรรทัดที่สองสำคัญ ถ้าไม่มี proxy ที่อยู่ในคอนเทนเนอร์จะเรียกโปรเซสที่อยู่นอก
คอนเทนเนอร์ไม่ได้ ซึ่งเคยทำให้เว็บที่ไม่เกี่ยวกับ deploy นี้ดับไปด้วย

### 4. เอาโค้ดขึ้น

```bash
git clone <repo-url> /opt/kai && cd /opt/kai
```

### 5. โหลดไฟล์โมเดลของ Face Recognition

สองไฟล์ใหญ่ไม่ได้อยู่ใน repository ต้องดึงบน droplet

```bash
sh /opt/kai/deliverables/face-recognition/models/fetch.sh
```

### 6. ส่ง index ของ Agent ขึ้นไป

Index ไม่ได้อยู่ใน repository เพราะเป็น build artefact ของ export ชุดหนึ่ง
คัดลอกจากเครื่องที่ build ไว้ (รันคำสั่งนี้**บนเครื่องตัวเอง** ไม่ใช่บน droplet)

```bash
scp indorama-rag/index.sqlite root@<droplet-ip>:/opt/kai/deploy/digitalocean/index/index.sqlite
```

ถ้าจะเปิดผู้ช่วยฝั่ง**ผู้เรียน** (`/agent/learner/*`) ส่งอีกไฟล์ขึ้นไปด้วย
เป็นคนละ index กันโดยตั้งใจ เพราะผู้เรียนต้องไม่เห็นชื่อตารางและ controller
ที่ index ตัวแรกทำมาจากมันทั้งอัน

```bash
scp indorama-rag/learner-index.sqlite root@<droplet-ip>:/opt/kai/deploy/digitalocean/index/learner-index.sqlite
```

ไม่ส่งไฟล์นี้ก็ได้ — `/agent/learner/health` จะตอบว่า `not_configured`
ส่วน endpoint ฝั่ง developer ทำงานตามปกติ

### 7. ตั้งค่า

```bash
cp /opt/kai/deploy/digitalocean/.env.example /opt/kai/deploy/digitalocean/.env
```

แก้ไฟล์ `.env` ใส่ `SITE_DOMAIN`, `RAG_LLM_API_KEY` และสร้างกุญแจสองตัว

```bash
openssl rand -hex 32
```

### 8. สตาร์ต

```bash
cd /opt/kai && docker compose -f deploy/digitalocean/docker-compose.yml --env-file deploy/digitalocean/.env up -d --build
```

### 9. ตรวจว่าขึ้นจริง

```bash
curl -s https://<domain>/face/health
```

```bash
curl -s https://<domain>/agent/health
```

```bash
curl -s https://<domain>/agent/learner/health
```

ทั้งคู่ต้องได้ `"ok": true` — `/face/health` ต้องมี `models_present` 4 ไฟล์
และ `liveness_available: true`

ตรวจว่ากุญแจบังคับใช้จริง (ต้องได้ `401`)

```bash
curl -s -o /dev/null -w "%{http_code}\n" -X POST https://<domain>/agent/chat -H "content-type: application/json" -d '{"user_id":"x","message":"hi"}'
```

---

## ส่งมอบให้ลูกค้า

ให้ลูกค้า 3 อย่าง

1. URL ฐาน — `https://<domain>/face` หรือ `https://<domain>/agent`
2. กุญแจของเขา — `X-Face-Key` หรือ `X-Agent-Key` (คนละใบ)
3. เอกสาร — [face-recognition/README.md](../../deliverables/face-recognition/README.md)
   หรือ [indorama-rag/API.md](../../indorama-rag/API.md)

**ส่งกุญแจคนละช่องทางกับ URL** และอย่าส่งทางอีเมลถ้าเลี่ยงได้

**ลูกค้าต้องเรียกจาก backend ของเขา ไม่ใช่จาก browser** กุญแจที่อยู่ในหน้าเว็บ
คือกุญแจสาธารณะ — และสำหรับ Agent มันหมายถึงใครก็อ่านบทสนทนาของคนอื่นได้
(ดูเหตุผลใน `indorama-rag/app/auth.py`)

---

## ดูแลหลัง deploy

**ล็อก**

```bash
docker compose -f deploy/digitalocean/docker-compose.yml logs -f --tail 50
```

**สำรองบทสนทนา**

```bash
docker run --rm -v kai-services_agent-chats:/data -v /root:/backup alpine tar czf /backup/chats-$(date +%F).tar.gz -C /data .
```

**อัปเดตโค้ด**

```bash
cd /opt/kai && git pull && docker compose -f deploy/digitalocean/docker-compose.yml --env-file deploy/digitalocean/.env up -d --build
```

---

## ข้อควรระวัง

**ตั้ง usage limit ที่ฝั่ง OpenAI ด้วย** Agent จำกัดจำนวนครั้งต่อกุญแจแล้ว
(ตั้งต้น 20 ครั้ง/นาที burst 10 ปรับที่ `RAG_RATE_PER_MINUTE`) แต่นั่นจำกัด
**จำนวนครั้ง ไม่ใช่จำนวนเงิน** คำถามยาวคือ token เยอะตาม เพดานที่นับเป็นเงินได้
มีอยู่ที่เดียวคือฝั่งผู้ให้บริการโมเดล

**กุญแจ Agent แยกต่อลูกค้าได้** เขียนเป็น `ลูกค้า:กุญแจ` คั่นด้วยจุลภาค
รายที่รั่วเพิกถอนได้โดยไม่กระทบรายอื่น และโควตานับแยกกัน

**ข้อมูลอยู่กับบุคคลที่สามสองราย** DigitalOcean เก็บ index (โครงสร้างฐานข้อมูล
ลูกค้า) และ OpenAI เห็นทุกคำถามพร้อม chunk ที่ค้นเจอ ถ้าสัญญากับลูกค้าระบุว่า
ข้อมูลห้ามออกนอกองค์กร **ต้องติดตั้งบนเซิร์ฟเวอร์ลูกค้าแทน** — compose ชุดเดียวกัน
ใช้ได้ ต่างแค่ที่ตั้ง และเปลี่ยน `RAG_LLM_BASE_URL` กลับไปหา Ollama ในเครือข่ายเขา

**Face Recognition ไม่เก็บอะไรเลย** ไม่มีรูปหรือ embedding ค้างบน droplet
ระบบฝั่งลูกค้าเป็นผู้เก็บ ซึ่งแปลว่าลูกค้าเป็นผู้ควบคุมข้อมูลตาม PDPA ไม่ใช่เรา

**threshold ที่ตั้งไว้ยังไม่ได้ calibrate** ค่า 0.363 เป็นค่าอ้างอิงของ OpenCV
ทดสอบจริงพบว่าคนละคนได้คะแนน 0.3051 ซึ่งตกในช่วง `review` ไม่ใช่ `fail`
ต้องรัน `calibrate.py` กับรูปจริงก่อนใช้ตัดสินตัวตนใครจริงๆ
