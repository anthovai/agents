# โมเดลที่ใช้ และไลเซนส์

**ทุกตัวในโฟลเดอร์นี้ต้องใช้เชิงพาณิชย์ได้** ถ้าจะเพิ่มโมเดลใหม่ ต้องบันทึกไลเซนส์ที่นี่ก่อน

| ไฟล์ | โมเดล | ที่มา | ไลเซนส์ | ใช้ทำอะไร |
|---|---|---|---|---|
| `face_detection_yunet_2023mar.onnx` | YuNet | [opencv/opencv_zoo](https://github.com/opencv/opencv_zoo/tree/main/models/face_detection_yunet) | MIT | ตรวจจับใบหน้า + 5 landmark |
| `face_recognition_sface_2021dec.onnx` | SFace | [opencv/opencv_zoo](https://github.com/opencv/opencv_zoo/tree/main/models/face_recognition_sface) | **Apache-2.0** (ยืนยันจากไฟล์ LICENSE ในโฟลเดอร์โมเดล) | embedding 128-d |
| `2.7_80x80_MiniFASNetV2.onnx` | MiniFASNetV2 | [MiniVision Silent-Face-Anti-Spoofing](https://github.com/minivision-ai/Silent-Face-Anti-Spoofing) | Apache-2.0 | passive liveness |
| `4_0_0_80x80_MiniFASNetV1SE.onnx` | MiniFASNetV1SE | เดียวกัน | Apache-2.0 | passive liveness |

## ที่ตั้งใจไม่ใช้

| โมเดล | เหตุผล |
|---|---|
| InsightFace `buffalo_l` / `antelopev2` | โค้ด MIT แต่ **น้ำหนักโมเดลอนุญาตเฉพาะงานวิจัยที่ไม่ใช่เชิงพาณิชย์** — ต้องซื้อไลเซนส์แยกถึงจะขายได้ |
| YOLOv8 / YOLO11 (Ultralytics) | AGPL-3.0 — ลามถึงโค้ดฝั่ง server ที่ให้บริการผ่านเน็ต |

ถ้าจะกลับไปใช้ InsightFace เพราะความแม่นยำ ต้องติดต่อ `recognition-oss-pack@insightface.ai`
และเก็บสัญญาไว้ก่อน ห้ามใส่กลับเข้ามาเงียบๆ

## ดาวน์โหลด

MiniFASNet ทั้งสองไฟล์ถูกก็อปมาจากโปรเจคเดิม `D:\face-re\models\` แล้ว
YuNet + SFace ยังต้องดึง — รัน `fetch.sh` (หรือ `fetch.ps1` บน Windows)
