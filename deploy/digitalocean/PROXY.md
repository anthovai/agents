# ทางเข้าจากภายนอก

compose ในโฟลเดอร์นี้**ไม่มี** reverse proxy ทั้งสองบริการผูกกับ loopback
(`127.0.0.1:18081` และ `127.0.0.1:18082`) และไม่มีทางเข้าจากอินเทอร์เน็ตจนกว่า
จะมีตัวหน้าให้

เดิมมี Caddy อยู่ในไฟล์นี้ ซึ่งถูกต้องบนเครื่องที่ทำงานนี้อย่างเดียว แต่เครื่องจริง
ที่ขึ้นไปมีคอนเทนเนอร์อยู่ก่อนราวสี่สิบตัวหลัง Caddy ของมันเอง — bind port 80
จึงล้มเหลว **ซึ่งเป็นผลลัพธ์ที่ดี** เพราะถ้าแย่ง port สำเร็จ เว็บอื่นทั้งเครื่องจะดับ

เลือกทางใดทางหนึ่งข้างล่าง

---

## ทาง A — เครื่องนี้ยังไม่มี proxy

ใช้ [Caddyfile](Caddyfile) ในโฟลเดอร์นี้ เพิ่ม service กลับเข้า compose

```yaml
  caddy:
    image: caddy:2-alpine
    restart: unless-stopped
    environment:
      # Caddy อ่าน {$SITE_DOMAIN} จาก environment ของตัวเอง ไม่ใช่ของ compose
      # ถ้าไม่มีบรรทัดนี้ placeholder จะกลายเป็นค่าว่าง ไฟล์เปิดด้วย `{` เปล่า
      # แล้ว Caddy จะอ่านทั้งไฟล์เป็น global options — error ที่ได้ไม่บอกสาเหตุจริง
      SITE_DOMAIN: ${SITE_DOMAIN:?set SITE_DOMAIN in .env}
    ports: ["80:80", "443:443"]
    volumes:
      - ./Caddyfile:/etc/caddy/Caddyfile:ro
      # ใบรับรองและ account key — หายแล้วต้องขอใหม่ทุกครั้งที่ restart
      # ซึ่ง Let's Encrypt จำกัดไว้ 5 ครั้งต่อโดเมนต่อสัปดาห์
      - caddy-data:/data
      - caddy-config:/config
    depends_on: [face, agent]
```

และเพิ่ม `caddy-data:` กับ `caddy-config:` ใต้ `volumes:`

**ต้องมี A record ชี้มาที่เครื่องนี้ก่อนสตาร์ต** Caddy ขอใบรับรองทันทีที่ขึ้น
DNS ที่ยังไม่ตามคือการขอที่ล้มเหลว และล้มเหลว 5 ครั้งต่อสัปดาห์ต่อโดเมนคือเพดาน

```bash
dig +short <domain>
```

---

## ทาง B — เครื่องนี้มี proxy อยู่แล้ว (กรณีที่ใช้จริงตอนนี้)

```bash
sh /opt/kai/deploy/digitalocean/attach-to-proxy.sh
```

สคริปต์นี้แก้ Caddy ที่กำลังให้บริการเว็บอื่นอยู่ จึงทำตามลำดับนี้เสมอ

1. สำรอง Caddyfile เป็นไฟล์ติด timestamp ก่อนแตะอะไร
2. **ต่อท้าย** block ใหม่ ไม่แก้บรรทัดเดิมสักบรรทัด
3. `caddy validate` ก่อนโหลด — ไม่ผ่านคือคืนไฟล์เดิมแล้วหยุด ไม่เคยโหลดของเสีย
4. `caddy reload` ไม่ใช่ restart เว็บอื่นไม่หล่นสักรีเควสต์
5. รันซ้ำได้ ครั้งที่สองไม่ทำอะไร

ปรับได้ด้วย environment variable ถ้าชื่อไม่ตรง

```bash
PROXY=proxy DOMAIN=example.com \
AGENT=kai-services-agent-1 FACE=kai-services-face-1 \
  sh attach-to-proxy.sh
```

**ถอนคืน** ลบตั้งแต่บรรทัด `# --- kai-services` ถึงปีกกาปิดของ block นั้น
แล้ว `docker exec <proxy> caddy reload --config /etc/caddy/Caddyfile`
ไฟล์สำรองที่วางไว้ข้างๆ คือของเดิมทั้งไฟล์

---

## ยังไม่มีโดเมน

`sslip.io` แปลง IP ที่อยู่ในชื่อโฮสต์กลับเป็น IP นั้น — `152.42.177.130.sslip.io`
resolve ไปที่ `152.42.177.130` โดยไม่ต้องตั้ง DNS อะไรเลย และเพราะมันเป็นชื่อจริง
Let's Encrypt จึงออกใบรับรองให้ได้ ต่างจาก IP เปล่าที่ออกให้ไม่ได้

**ใช้ตอนให้ลูกค้าทดลองเท่านั้น** ก่อนขึ้นใช้งานจริงต้องเปลี่ยนเป็นโดเมนของตัวเอง
ชื่อนี้พึ่งบริการของคนอื่นในการ resolve และประกาศ IP ของเครื่องไว้ในชื่อตัวเอง
