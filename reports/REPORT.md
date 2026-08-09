# ผลการทดสอบระบบคุมสอบบน Moodle

รันเมื่อ **2026-08-09 18:07 UTC**
· Moodle **5.1.5+ (Build: 20260807)**
· face-service **yunet+sface** (โมเดล 4 ตัว, liveness พร้อม)
· เกณฑ์ผ่านที่ใช้ตอนทดสอบ **0.363**
· Chromium (Playwright) พร้อมกล้องปลอม `--use-fake-device-for-media-stream`

## สรุป

| | จำนวน |
|---|---|
| ผ่าน | **48** |
| ไม่ผ่าน | 0 |
| เวลาที่ใช้ | 1151.9 วินาที |

## ไฟล์หลักฐานในโฟลเดอร์นี้

| ไฟล์ | คืออะไร |
|---|---|
| `junit.xml` | ผลรายเทสต์แบบมาตรฐาน เปิดใน CI หรือ IDE ได้ |
| `pytest-output.txt` | log การรันเต็ม |
| `video/<ชื่อเทสต์>.webm` | วิดีโอการรันแต่ละเทสต์ (44 ไฟล์) หน่วงจังหวะไว้ให้ดูทัน |
| `screenshots/<ชื่อเทสต์>.png` | ภาพหน้าจอเต็มหน้าตอนจบแต่ละเทสต์ (44 ไฟล์) |
| `eventlog/<ชื่อเทสต์>.txt` | audit log ที่ระบบบันทึกไว้ในเทสต์นั้น (55 ไฟล์) |
| `eventlog/<ชื่อเทสต์>.steps.txt` | ลำดับขั้นที่เทสต์เดิน อ่านคู่กับวิดีโอ |

## ข้อกำหนด 7 ข้อ → เทสต์ที่พิสูจน์

| # | ข้อกำหนด | สถานะ | เทสต์ |
|---|---|---|---|
| 1 | ออกจาก Active Window → หยุดวิดีโอ + ปิดหน้าเรียน | ผ่าน | `test_leaving_the_window_pauses_the_video_and_is_recorded` <br>`test_monitoring_runs_during_the_attempt`  |
| 2 | ล็อกไม่ให้ออกไปทำอย่างอื่น | ผ่าน | `test_lockdown_blocks_and_reports_every_browser_exit` <br>`test_text_selection_and_dragging_are_suppressed` <br>`test_an_ordinary_browser_cannot_start_the_seb_quiz` <br>`test_seb_is_configured_with_a_real_config_key`  |
| 3 | กล้องตรวจใบหน้าตามเวลาที่กำหนด | ผ่าน | `test_presence_check_runs_on_its_interval_and_pauses_the_lesson` <br>`test_identity_check_runs_on_its_own_interval` <br>`test_challenge_asks_for_a_randomised_sequence`  |
| 4 | แจ้งเตือนเมื่อออกจากหน้าเรียน | ผ่าน | `test_the_learner_is_asked_to_confirm_they_are_still_there` <br>`test_leaving_the_window_pauses_the_video_and_is_recorded`  |
| 5 | เก็บข้อมูลทุกอย่าง + สุ่มถ่ายวิดีโอ | ผ่าน | `test_a_violation_captures_evidence` <br>`test_a_random_clip_is_recorded_and_stored` <br>`test_the_report_shows_checks_evidence_and_signals`  |
| 6 | ความยินยอม PDPA ก่อนเก็บข้อมูลใบหน้า | ผ่าน | `test_nothing_is_reachable_before_consent_is_given` <br>`test_enrolment_becomes_reachable_once_consent_is_given` <br>`test_consent_document_states_what_is_collected` <br>`test_consent_is_compulsory_not_optional` <br>`test_privacy_api_deletes_the_face_on_erasure` <br>`test_expired_evidence_is_purged`  |
| 7 | ข้อสอบมีการคุมสอบและตัดเกรดฝั่ง server | ผ่าน | `test_a_forged_client_marker_does_not_open_the_attempt` <br>`test_a_server_written_pass_opens_the_attempt` <br>`test_answers_can_be_submitted_and_graded`  |

## ความสามารถที่เพิ่มมาภายหลัง

| ความสามารถ | สถานะ | เทสต์ |
|---|---|---|
| บทเรียนวิดีโอแบบมีปฏิสัมพันธ์ ภายใต้การเฝ้าดู | ผ่าน | `test_the_activity_says_it_is_proctored_before_anything_starts`<br>`test_monitoring_starts_when_the_learner_begins`<br>`test_the_video_player_is_found_through_its_published_interface`<br>`test_leaving_the_activity_window_is_recorded` |
| ผู้สอนไม่ถูกเฝ้าดู และเปิด/ปิดการเฝ้าดูรายกิจกรรมได้ | ผ่าน | `test_staff_viewing_the_activity_are_not_monitored`<br>`test_staff_can_turn_proctoring_off_and_on`<br>`test_an_unmonitored_activity_is_left_alone` |
| Safe Exam Browser คู่กับการยืนยันตัวตนด้วยใบหน้า | ผ่าน | `test_the_seb_config_file_is_downloadable_by_the_learner`<br>`test_both_rules_describe_themselves_to_the_learner` |

## รายการเทสต์ทั้งหมด

| เทสต์ | ผล | วินาที |
|---|---|---|
| `test_face_service_is_up_with_every_model_loaded` | ผ่าน | 15.6 |
| `test_both_plugins_are_installed` | ผ่าน | 0.1 |
| `test_all_web_services_are_registered` | ผ่าน | 0.0 |
| `test_pdpa_policy_is_the_site_policy_handler` | ผ่าน | 0.0 |
| `test_site_loads` | ผ่าน | 16.4 |
| `test_face_service_is_not_reachable_from_the_browser` | ผ่าน | 6.1 |
| `test_nothing_is_reachable_before_consent_is_given` | ผ่าน | 27.5 |
| `test_enrolment_becomes_reachable_once_consent_is_given` | ผ่าน | 29.2 |
| `test_consent_document_states_what_is_collected` | ผ่าน | 33.9 |
| `test_consent_is_compulsory_not_optional` | ผ่าน | 30.1 |
| `test_learner_can_see_their_own_consent_record` | ผ่าน | 22.8 |
| `test_enrol_page_explains_what_will_happen` | ผ่าน | 14.9 |
| `test_challenge_asks_for_a_randomised_sequence` | ผ่าน | 14.7 |
| `test_enrolment_is_refused_when_no_face_is_visible` | ผ่าน | 35.4 |
| `test_the_page_reports_a_camera_that_will_not_start` | ผ่าน | 17.8 |
| `test_lesson_page_offers_the_video_and_a_camera_preview` | ผ่าน | 15.0 |
| `test_leaving_the_window_pauses_the_video_and_is_recorded` | ผ่าน | 23.8 |
| `test_a_violation_captures_evidence` | ผ่าน | 28.7 |
| `test_presence_check_runs_on_its_interval_and_pauses_the_lesson` | ผ่าน | 30.9 |
| `test_identity_check_runs_on_its_own_interval` | ผ่าน | 37.8 |
| `test_a_random_clip_is_recorded_and_stored` | ผ่าน | 33.5 |
| `test_the_learner_is_asked_to_confirm_they_are_still_there` | ผ่าน | 29.1 |
| `test_lockdown_blocks_and_reports_every_browser_exit` | ผ่าน | 27.4 |
| `test_text_selection_and_dragging_are_suppressed` | ผ่าน | 20.3 |
| `test_an_unknown_signal_is_refused_by_the_server` | ผ่าน | 25.9 |
| `test_quiz_announces_that_it_is_proctored` | ผ่าน | 22.0 |
| `test_a_learner_with_no_enrolled_face_cannot_start` | ผ่าน | 41.4 |
| `test_the_preflight_check_asks_for_the_camera` | ผ่าน | 22.2 |
| `test_a_forged_client_marker_does_not_open_the_attempt` | ผ่าน | 28.8 |
| `test_a_server_written_pass_opens_the_attempt` | ผ่าน | 23.2 |
| `test_monitoring_runs_during_the_attempt` | ผ่าน | 31.5 |
| `test_answers_can_be_submitted_and_graded` | ผ่าน | 38.6 |
| `test_the_report_shows_checks_evidence_and_signals` | ผ่าน | 31.4 |
| `test_the_report_records_the_threshold_that_was_in_force` | ผ่าน | 23.2 |
| `test_one_learner_cannot_read_another_learners_evidence` | ผ่าน | 43.3 |
| `test_expired_evidence_is_purged` | ผ่าน | 29.1 |
| `test_privacy_api_deletes_the_face_on_erasure` | ผ่าน | 34.8 |
| `test_seb_is_configured_with_a_real_config_key` | ผ่าน | 2.1 |
| `test_an_ordinary_browser_cannot_start_the_seb_quiz` | ผ่าน | 19.2 |
| `test_the_seb_config_file_is_downloadable_by_the_learner` | ผ่าน | 17.3 |
| `test_both_rules_describe_themselves_to_the_learner` | ผ่าน | 18.1 |
| `test_the_activity_says_it_is_proctored_before_anything_starts` | ผ่าน | 20.0 |
| `test_monitoring_starts_when_the_learner_begins` | ผ่าน | 25.3 |
| `test_the_video_player_is_found_through_its_published_interface` | ผ่าน | 23.6 |
| `test_leaving_the_activity_window_is_recorded` | ผ่าน | 32.4 |
| `test_staff_viewing_the_activity_are_not_monitored` | ผ่าน | 15.1 |
| `test_staff_can_turn_proctoring_off_and_on` | ผ่าน | 25.0 |
| `test_an_unmonitored_activity_is_left_alone` | ผ่าน | 46.5 |

## ที่เทสต์อัตโนมัติทำแทนไม่ได้ ต้องตรวจด้วยมือ

| เรื่อง | เหตุผล | วิธีตรวจ |
|---|---|---|
| ความแม่นยำของการเทียบใบหน้า | กล้องปลอมของ Chromium ไม่มีใบหน้าอยู่ในภาพ | เปิด /local/kaiproctor/enrol.php บนเครื่องที่มีกล้องจริง แล้วลงทะเบียนและยืนยันตัวตน จากนั้นรัน face-service/tests/test_calibration.py เพื่อหาเกณฑ์ที่เหมาะสม |
| Pop-up Notification ระดับระบบปฏิบัติการ | เบราว์เซอร์ที่รันแบบอัตโนมัติไม่มี notification center ของ OS | เปิดหน้าเรียนด้วยมือบน localhost กดอนุญาตการแจ้งเตือน แล้วสลับหน้าต่าง |
| การบังคับเต็มจอ | requestFullscreen ต้องมาจากการกดของผู้ใช้จริง เทสต์อัตโนมัติจึงเรียกไม่ได้ | กดเริ่มเรียนเอง แล้วกด Esc ออกจากเต็มจอ ต้องถูกบันทึกเป็น fullscreen_exit |
| การตรวจจับ devtools | อาศัยสัดส่วนขนาดหน้าต่าง ซึ่งไม่แน่นอนในเบราว์เซอร์ที่ถูกควบคุมด้วยสคริปต์ | เปิด devtools แบบ docked ระหว่างเรียน ต้องถูกบันทึกเป็น devtools_suspected |
| การล็อกระดับเครื่อง | หน้าเว็บไม่มีสิทธิ์ระดับระบบปฏิบัติการ — Alt+Tab จอที่สอง และมือถือข้างๆ ห้ามไม่ได้จริง | ใช้ Safe Exam Browser คู่กับ quizaccess_seb ซึ่งตั้งค่าไว้แล้วในข้อสอบ 'ความเสี่ยงสูง (SEB)' |
| การทำงานของ Safe Exam Browser ตัวจริง | SEB เป็นโปรแกรมติดตั้งบนเครื่อง เบราว์เซอร์อัตโนมัติปลอมเป็นมันไม่ได้ | ติดตั้ง SEB แล้วเปิดลิงก์ seb:// จากหน้าข้อสอบ ต้องเข้าสอบได้และ Config Key ต้องตรง |

> ชุดเทสต์นี้พิสูจน์ว่า flow, การบังคับนโยบาย และการเก็บหลักฐานทำงานถูกต้อง
> **ไม่ได้พิสูจน์ความแม่นยำของการเทียบใบหน้า** เพราะกล้องปลอมไม่มีใบหน้าอยู่ในภาพ
> เกณฑ์ที่ใช้อยู่ยังเป็นค่าอ้างอิงของผู้พัฒนาโมเดล ไม่ใช่ค่าที่ปรับเทียบกับข้อมูลจริง

## วิธีรันซ้ำ

```bash
docker compose up -d
sh run-tests.sh
```
