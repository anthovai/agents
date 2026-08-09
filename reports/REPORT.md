# ผลการทดสอบระบบคุมสอบบน Moodle

รันเมื่อ **2026-08-09 16:40 UTC**
· Moodle **5.1.5+ (Build: 20260807)**
· face-service **yunet+sface** (โมเดล 4 ตัว, liveness พร้อม)
· เกณฑ์ผ่านที่ใช้ตอนทดสอบ **0.363**
· Chromium (Playwright) พร้อมกล้องปลอม `--use-fake-device-for-media-stream`

## สรุป

| | จำนวน |
|---|---|
| ผ่าน | **37** |
| ไม่ผ่าน | 0 |
| เวลาที่ใช้ | 704.8 วินาที |

## ไฟล์หลักฐานในโฟลเดอร์นี้

| ไฟล์ | คืออะไร |
|---|---|
| `junit.xml` | ผลรายเทสต์แบบมาตรฐาน เปิดใน CI หรือ IDE ได้ |
| `pytest-output.txt` | log การรันเต็ม |
| `video/<ชื่อเทสต์>.webm` | วิดีโอการรันแต่ละเทสต์ (33 ไฟล์) หน่วงจังหวะไว้ให้ดูทัน |
| `screenshots/<ชื่อเทสต์>.png` | ภาพหน้าจอเต็มหน้าตอนจบแต่ละเทสต์ (33 ไฟล์) |
| `eventlog/<ชื่อเทสต์>.txt` | audit log ที่ระบบบันทึกไว้ในเทสต์นั้น (42 ไฟล์) |
| `eventlog/<ชื่อเทสต์>.steps.txt` | ลำดับขั้นที่เทสต์เดิน อ่านคู่กับวิดีโอ |

## ข้อกำหนด 7 ข้อ → เทสต์ที่พิสูจน์

| # | ข้อกำหนด | สถานะ | เทสต์ |
|---|---|---|---|
| 1 | ออกจาก Active Window → หยุดวิดีโอ + ปิดหน้าเรียน | ผ่าน | `test_leaving_the_window_pauses_the_video_and_is_recorded` <br>`test_monitoring_runs_during_the_attempt`  |
| 2 | ล็อกไม่ให้ออกไปทำอย่างอื่น | ผ่าน | `test_lockdown_blocks_and_reports_every_browser_exit` <br>`test_text_selection_and_dragging_are_suppressed`  |
| 3 | กล้องตรวจใบหน้าตามเวลาที่กำหนด | ผ่าน | `test_presence_check_runs_on_its_interval_and_pauses_the_lesson` <br>`test_identity_check_runs_on_its_own_interval` <br>`test_challenge_asks_for_a_randomised_sequence`  |
| 4 | แจ้งเตือนเมื่อออกจากหน้าเรียน | ผ่าน | `test_the_learner_is_asked_to_confirm_they_are_still_there` <br>`test_leaving_the_window_pauses_the_video_and_is_recorded`  |
| 5 | เก็บข้อมูลทุกอย่าง + สุ่มถ่ายวิดีโอ | ผ่าน | `test_a_violation_captures_evidence` <br>`test_a_random_clip_is_recorded_and_stored` <br>`test_the_report_shows_checks_evidence_and_signals`  |
| 6 | ความยินยอม PDPA ก่อนเก็บข้อมูลใบหน้า | ผ่าน | `test_nothing_is_reachable_before_consent_is_given` <br>`test_enrolment_becomes_reachable_once_consent_is_given` <br>`test_consent_document_states_what_is_collected` <br>`test_consent_is_compulsory_not_optional` <br>`test_privacy_api_deletes_the_face_on_erasure` <br>`test_expired_evidence_is_purged`  |
| 7 | ข้อสอบมีการคุมสอบและตัดเกรดฝั่ง server | ผ่าน | `test_a_forged_client_marker_does_not_open_the_attempt` <br>`test_a_server_written_pass_opens_the_attempt` <br>`test_answers_can_be_submitted_and_graded`  |

## รายการเทสต์ทั้งหมด

| เทสต์ | ผล | วินาที |
|---|---|---|
| `test_face_service_is_up_with_every_model_loaded` | ผ่าน | 4.7 |
| `test_both_plugins_are_installed` | ผ่าน | 0.0 |
| `test_all_web_services_are_registered` | ผ่าน | 0.0 |
| `test_pdpa_policy_is_the_site_policy_handler` | ผ่าน | 0.0 |
| `test_site_loads` | ผ่าน | 6.3 |
| `test_face_service_is_not_reachable_from_the_browser` | ผ่าน | 3.2 |
| `test_nothing_is_reachable_before_consent_is_given` | ผ่าน | 17.2 |
| `test_enrolment_becomes_reachable_once_consent_is_given` | ผ่าน | 20.8 |
| `test_consent_document_states_what_is_collected` | ผ่าน | 15.4 |
| `test_consent_is_compulsory_not_optional` | ผ่าน | 11.5 |
| `test_learner_can_see_their_own_consent_record` | ผ่าน | 15.5 |
| `test_enrol_page_explains_what_will_happen` | ผ่าน | 13.2 |
| `test_challenge_asks_for_a_randomised_sequence` | ผ่าน | 13.5 |
| `test_enrolment_is_refused_when_no_face_is_visible` | ผ่าน | 35.7 |
| `test_the_page_reports_a_camera_that_will_not_start` | ผ่าน | 15.9 |
| `test_lesson_page_offers_the_video_and_a_camera_preview` | ผ่าน | 14.5 |
| `test_leaving_the_window_pauses_the_video_and_is_recorded` | ผ่าน | 20.7 |
| `test_a_violation_captures_evidence` | ผ่าน | 23.2 |
| `test_presence_check_runs_on_its_interval_and_pauses_the_lesson` | ผ่าน | 25.8 |
| `test_identity_check_runs_on_its_own_interval` | ผ่าน | 32.0 |
| `test_a_random_clip_is_recorded_and_stored` | ผ่าน | 32.9 |
| `test_the_learner_is_asked_to_confirm_they_are_still_there` | ผ่าน | 24.6 |
| `test_lockdown_blocks_and_reports_every_browser_exit` | ผ่าน | 22.3 |
| `test_text_selection_and_dragging_are_suppressed` | ผ่าน | 14.7 |
| `test_an_unknown_signal_is_refused_by_the_server` | ผ่าน | 16.5 |
| `test_quiz_announces_that_it_is_proctored` | ผ่าน | 15.0 |
| `test_a_learner_with_no_enrolled_face_cannot_start` | ผ่าน | 17.3 |
| `test_the_preflight_check_asks_for_the_camera` | ผ่าน | 18.1 |
| `test_a_forged_client_marker_does_not_open_the_attempt` | ผ่าน | 20.8 |
| `test_a_server_written_pass_opens_the_attempt` | ผ่าน | 22.1 |
| `test_monitoring_runs_during_the_attempt` | ผ่าน | 28.1 |
| `test_answers_can_be_submitted_and_graded` | ผ่าน | 36.0 |
| `test_the_report_shows_checks_evidence_and_signals` | ผ่าน | 28.8 |
| `test_the_report_records_the_threshold_that_was_in_force` | ผ่าน | 21.8 |
| `test_one_learner_cannot_read_another_learners_evidence` | ผ่าน | 40.4 |
| `test_expired_evidence_is_purged` | ผ่าน | 24.8 |
| `test_privacy_api_deletes_the_face_on_erasure` | ผ่าน | 31.3 |

## ที่เทสต์อัตโนมัติทำแทนไม่ได้ ต้องตรวจด้วยมือ

| เรื่อง | เหตุผล | วิธีตรวจ |
|---|---|---|
| ความแม่นยำของการเทียบใบหน้า | กล้องปลอมของ Chromium ไม่มีใบหน้าอยู่ในภาพ | เปิด /local/kaiproctor/enrol.php บนเครื่องที่มีกล้องจริง แล้วลงทะเบียนและยืนยันตัวตน จากนั้นรัน face-service/tests/test_calibration.py เพื่อหาเกณฑ์ที่เหมาะสม |
| Pop-up Notification ระดับระบบปฏิบัติการ | เบราว์เซอร์ที่รันแบบอัตโนมัติไม่มี notification center ของ OS | เปิดหน้าเรียนด้วยมือบน localhost กดอนุญาตการแจ้งเตือน แล้วสลับหน้าต่าง |
| การบังคับเต็มจอ | requestFullscreen ต้องมาจากการกดของผู้ใช้จริง เทสต์อัตโนมัติจึงเรียกไม่ได้ | กดเริ่มเรียนเอง แล้วกด Esc ออกจากเต็มจอ ต้องถูกบันทึกเป็น fullscreen_exit |
| การตรวจจับ devtools | อาศัยสัดส่วนขนาดหน้าต่าง ซึ่งไม่แน่นอนในเบราว์เซอร์ที่ถูกควบคุมด้วยสคริปต์ | เปิด devtools แบบ docked ระหว่างเรียน ต้องถูกบันทึกเป็น devtools_suspected |
| การล็อกระดับเครื่อง | หน้าเว็บไม่มีสิทธิ์ระดับระบบปฏิบัติการ — Alt+Tab จอที่สอง และมือถือข้างๆ ห้ามไม่ได้จริง | ใช้ Safe Exam Browser คู่กับ quizaccess_seb สำหรับการสอบที่มีความเสี่ยงสูง |

> ชุดเทสต์นี้พิสูจน์ว่า flow, การบังคับนโยบาย และการเก็บหลักฐานทำงานถูกต้อง
> **ไม่ได้พิสูจน์ความแม่นยำของการเทียบใบหน้า** เพราะกล้องปลอมไม่มีใบหน้าอยู่ในภาพ
> เกณฑ์ที่ใช้อยู่ยังเป็นค่าอ้างอิงของผู้พัฒนาโมเดล ไม่ใช่ค่าที่ปรับเทียบกับข้อมูลจริง

## วิธีรันซ้ำ

```bash
docker compose up -d
sh run-tests.sh
```
