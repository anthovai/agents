# ผลการทดสอบระบบคุมสอบบน Moodle

รันเมื่อ **2026-08-11 04:43 UTC**
· Moodle **5.1.5+ (Build: 20260807)**
· face-service **yunet+sface** (โมเดล 4 ตัว, liveness พร้อม)
· เกณฑ์ผ่านที่ใช้ตอนทดสอบ **0.363**
· Chromium (Playwright) พร้อมกล้องปลอม `--use-fake-device-for-media-stream`

## สรุป

| | จำนวน |
|---|---|
| ผ่าน | **91** |
| ไม่ผ่าน | 0 |
| เวลาที่ใช้ | 1196.9 วินาที |

## ไฟล์หลักฐานในโฟลเดอร์นี้

| ไฟล์ | คืออะไร |
|---|---|
| `junit.xml` | ผลรายเทสต์แบบมาตรฐาน เปิดใน CI หรือ IDE ได้ |
| `pytest-output.txt` | log การรันเต็ม |
| `video/<ชื่อเทสต์>.webm` | วิดีโอการรันแต่ละเทสต์ (71 ไฟล์) หน่วงจังหวะไว้ให้ดูทัน |
| `screenshots/<ชื่อเทสต์>.png` | ภาพหน้าจอเต็มหน้าตอนจบแต่ละเทสต์ (84 ไฟล์) |
| `eventlog/<ชื่อเทสต์>.txt` | audit log ที่ระบบบันทึกไว้ในเทสต์นั้น (95 ไฟล์) |
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
| สุ่มข้อสอบซ้ำได้จาก seed และพิสูจน์ว่าไม่ได้เลือกไว้ก่อน | ผ่าน | `test_the_same_learner_and_attempt_always_get_the_same_paper`<br>`test_a_second_attempt_gets_a_different_paper`<br>`test_two_learners_sitting_the_same_exam_get_different_papers`<br>`test_the_recorded_draw_is_checked_against_the_identifiers`<br>`test_a_tampered_seed_is_reported_as_tampered` |
| ผู้ช่วย AI และขอบเขตที่ห้ามข้าม | **ยังไม่ได้พิสูจน์** | `test_ai_is_off_until_somebody_turns_it_on`<br>`test_what_gets_sent_contains_no_biometric_data`<br>`test_the_summary_is_labelled_as_a_draft_not_a_finding`<br>`test_a_gateway_that_is_not_there_fails_visibly`<br>`test_the_model_is_told_not_to_accuse_anybody` |
| นำเข้าข้อสอบจาก PDF แนวข้อสอบไทย | ผ่าน | `test_the_parser_reads_a_thai_exam_pack`<br>`test_difficulty_is_spread_across_the_pack`<br>`test_a_file_that_is_not_an_exam_pack_is_refused`<br>`test_importing_puts_the_questions_in_the_bank_with_difficulty_tags` |
| หน้าสถิติสำหรับผู้ดูแล | ผ่าน | `test_the_stats_page_reports_the_service_and_the_evidence`<br>`test_the_stats_page_says_when_the_face_service_is_unreachable`<br>`test_the_stats_page_warns_when_retention_is_not_being_enforced` |
| บันทึกการเรียนเป็นครั้งๆ พร้อมกฎที่บังคับตอนนั้น (audit) | ผ่าน | `test_starting_a_lesson_opens_one_sitting`<br>`test_the_rules_in_force_are_recorded_on_the_sitting`<br>`test_changing_the_settings_does_not_rewrite_a_finished_sitting`<br>`test_the_report_groups_everything_by_sitting` |
| สถานะการจบและการกันไม่ให้ปลอมสถานะ | ผ่าน | `test_a_terminated_sitting_records_why`<br>`test_a_late_completion_cannot_launder_a_terminated_sitting`<br>`test_a_client_cannot_mark_a_sitting_abandoned`<br>`test_a_sitting_nobody_closed_is_marked_abandoned` |
| บทเรียนวิดีโอแบบมีปฏิสัมพันธ์ ภายใต้การเฝ้าดู | ผ่าน | `test_the_activity_says_it_is_proctored_before_anything_starts`<br>`test_monitoring_starts_when_the_learner_begins`<br>`test_the_video_player_is_found_through_its_published_interface`<br>`test_leaving_the_activity_window_is_recorded` |
| ผู้สอนไม่ถูกเฝ้าดู และเปิด/ปิดการเฝ้าดูรายกิจกรรมได้ | ผ่าน | `test_staff_viewing_the_activity_are_not_monitored`<br>`test_staff_can_turn_proctoring_off_and_on`<br>`test_an_unmonitored_activity_is_left_alone` |
| Safe Exam Browser คู่กับการยืนยันตัวตนด้วยใบหน้า | ผ่าน | `test_the_seb_config_file_is_downloadable_by_the_learner`<br>`test_both_rules_describe_themselves_to_the_learner` |

## รายการเทสต์ทั้งหมด

| เทสต์ | ผล | วินาที |
|---|---|---|
| `test_face_service_is_up_with_every_model_loaded` | ผ่าน | 3.5 |
| `test_both_plugins_are_installed` | ผ่าน | 0.0 |
| `test_all_web_services_are_registered` | ผ่าน | 0.0 |
| `test_pdpa_policy_is_the_site_policy_handler` | ผ่าน | 0.0 |
| `test_site_loads` | ผ่าน | 3.5 |
| `test_face_service_is_not_reachable_from_the_browser` | ผ่าน | 3.0 |
| `test_nothing_is_reachable_before_consent_is_given` | ผ่าน | 11.7 |
| `test_enrolment_becomes_reachable_once_consent_is_given` | ผ่าน | 16.3 |
| `test_consent_document_states_what_is_collected` | ผ่าน | 12.1 |
| `test_consent_is_compulsory_not_optional` | ผ่าน | 8.8 |
| `test_learner_can_see_their_own_consent_record` | ผ่าน | 11.5 |
| `test_enrol_page_explains_what_will_happen` | ผ่าน | 9.6 |
| `test_challenge_asks_for_a_randomised_sequence` | ผ่าน | 10.0 |
| `test_enrolment_is_refused_when_no_face_is_visible` | ผ่าน | 28.9 |
| `test_the_page_reports_a_camera_that_will_not_start` | ผ่าน | 12.3 |
| `test_lesson_page_offers_the_video_and_a_camera_preview` | ผ่าน | 9.7 |
| `test_leaving_the_window_pauses_the_video_and_is_recorded` | ผ่าน | 15.2 |
| `test_a_violation_captures_evidence` | ผ่าน | 16.3 |
| `test_presence_check_runs_on_its_interval_and_pauses_the_lesson` | ผ่าน | 19.9 |
| `test_identity_check_runs_on_its_own_interval` | ผ่าน | 23.1 |
| `test_a_random_clip_is_recorded_and_stored` | ผ่าน | 23.5 |
| `test_the_learner_is_asked_to_confirm_they_are_still_there` | ผ่าน | 17.1 |
| `test_lockdown_blocks_and_reports_every_browser_exit` | ผ่าน | 14.6 |
| `test_text_selection_and_dragging_are_suppressed` | ผ่าน | 9.6 |
| `test_an_unknown_signal_is_refused_by_the_server` | ผ่าน | 10.1 |
| `test_quiz_announces_that_it_is_proctored` | ผ่าน | 11.7 |
| `test_a_learner_with_no_enrolled_face_cannot_start` | ผ่าน | 11.3 |
| `test_the_preflight_check_asks_for_the_camera` | ผ่าน | 12.9 |
| `test_a_forged_client_marker_does_not_open_the_attempt` | ผ่าน | 15.2 |
| `test_a_server_written_pass_opens_the_attempt` | ผ่าน | 15.6 |
| `test_monitoring_runs_during_the_attempt` | ผ่าน | 22.5 |
| `test_answers_can_be_submitted_and_graded` | ผ่าน | 27.6 |
| `test_the_report_shows_checks_evidence_and_signals` | ผ่าน | 20.8 |
| `test_the_report_records_the_threshold_that_was_in_force` | ผ่าน | 14.6 |
| `test_one_learner_cannot_read_another_learners_evidence` | ผ่าน | 29.0 |
| `test_expired_evidence_is_purged` | ผ่าน | 16.8 |
| `test_privacy_api_deletes_the_face_on_erasure` | ผ่าน | 18.6 |
| `test_seb_is_configured_with_a_real_config_key` | ผ่าน | 0.9 |
| `test_an_ordinary_browser_cannot_start_the_seb_quiz` | ผ่าน | 12.8 |
| `test_the_seb_config_file_is_downloadable_by_the_learner` | ผ่าน | 12.1 |
| `test_both_rules_describe_themselves_to_the_learner` | ผ่าน | 13.2 |
| `test_the_activity_says_it_is_proctored_before_anything_starts` | ผ่าน | 11.4 |
| `test_monitoring_starts_when_the_learner_begins` | ผ่าน | 15.7 |
| `test_the_video_player_is_found_through_its_published_interface` | ผ่าน | 16.1 |
| `test_leaving_the_activity_window_is_recorded` | ผ่าน | 17.8 |
| `test_staff_viewing_the_activity_are_not_monitored` | ผ่าน | 10.2 |
| `test_staff_can_turn_proctoring_off_and_on` | ผ่าน | 15.6 |
| `test_an_unmonitored_activity_is_left_alone` | ผ่าน | 17.3 |
| `test_starting_a_lesson_opens_one_sitting` | ผ่าน | 12.7 |
| `test_reloading_does_not_start_a_second_sitting` | ผ่าน | 17.4 |
| `test_the_rules_in_force_are_recorded_on_the_sitting` | ผ่าน | 12.7 |
| `test_changing_the_settings_does_not_rewrite_a_finished_sitting` | ผ่าน | 14.9 |
| `test_a_terminated_sitting_records_why` | ผ่าน | 16.1 |
| `test_a_late_completion_cannot_launder_a_terminated_sitting` | ผ่าน | 17.3 |
| `test_a_client_cannot_mark_a_sitting_abandoned` | ผ่าน | 12.2 |
| `test_a_sitting_nobody_closed_is_marked_abandoned` | ผ่าน | 13.2 |
| `test_checks_and_evidence_are_filed_under_the_sitting` | ผ่าน | 17.2 |
| `test_the_report_groups_everything_by_sitting` | ผ่าน | 23.2 |
| `test_an_exam_attempt_is_its_own_sitting` | ผ่าน | 19.8 |
| `test_the_parser_reads_a_thai_exam_pack` | ผ่าน | 1.0 |
| `test_difficulty_is_spread_across_the_pack` | ผ่าน | 0.9 |
| `test_a_file_that_is_not_an_exam_pack_is_refused` | ผ่าน | 0.9 |
| `test_importing_puts_the_questions_in_the_bank_with_difficulty_tags` | ผ่าน | 2.2 |
| `test_the_stats_page_reports_the_service_and_the_evidence` | ผ่าน | 11.0 |
| `test_the_stats_page_says_when_the_face_service_is_unreachable` | ผ่าน | 12.2 |
| `test_the_stats_page_warns_when_retention_is_not_being_enforced` | ผ่าน | 16.8 |
| `test_the_same_learner_and_attempt_always_get_the_same_paper` | ผ่าน | 2.0 |
| `test_a_second_attempt_gets_a_different_paper` | ผ่าน | 1.7 |
| `test_two_learners_sitting_the_same_exam_get_different_papers` | ผ่าน | 1.7 |
| `test_the_seed_is_only_a_function_of_the_identifiers` | ผ่าน | 1.7 |
| `test_the_recorded_draw_is_checked_against_the_identifiers` | ผ่าน | 15.0 |
| `test_a_tampered_seed_is_reported_as_tampered` | ผ่าน | 14.2 |
| `test_ai_is_off_until_somebody_turns_it_on` | ผ่าน | 0.9 |
| `test_what_gets_sent_contains_no_biometric_data` | ผ่าน | 15.7 |
| `test_the_summary_is_labelled_as_a_draft_not_a_finding` | ผ่าน | 18.3 |
| `test_a_service_that_is_not_there_fails_visibly` | ผ่าน | 3.9 |
| `test_the_model_is_told_not_to_accuse_anybody` | ผ่าน | 0.8 |
| `test_a_summary_comes_back_when_a_model_is_behind_the_service` | ผ่าน | 31.4 |
| `test_the_assistant_is_off_until_somebody_turns_it_on` | ผ่าน | 13.4 |
| `test_an_empty_question_does_nothing` | ผ่าน | 14.6 |
| `test_a_question_the_site_cannot_answer_is_refused_on_the_page` | ผ่าน | 15.6 |
| `test_another_learners_course_cannot_be_found_by_name` | ผ่าน | 17.6 |
| `test_the_page_says_so_when_the_service_is_down` | ผ่าน | 17.5 |
| `test_a_learner_asks_where_something_is_and_the_link_works` | ผ่าน | 49.8 |
| `test_every_link_offered_is_one_this_learner_may_open` | ผ่าน | 41.8 |
| `test_an_administrator_can_switch_it_on_and_off_from_the_console` | ผ่าน | 20.4 |
| `test_the_console_shows_which_model_answers_and_where_it_runs` | ผ่าน | 11.4 |
| `test_a_learner_cannot_reach_the_console` | ผ่าน | 9.8 |
| `test_the_shipped_threshold_still_earns_its_number` | ผ่าน | 1.2 |
| `test_retrieval_finds_the_right_page_without_a_model` | ผ่าน | 1.2 |
| `test_a_vendor_endpoint_is_not_mistaken_for_your_own_hardware` | ผ่าน | 1.8 |

## ที่เทสต์อัตโนมัติทำแทนไม่ได้ ต้องตรวจด้วยมือ

| เรื่อง | เหตุผล | วิธีตรวจ |
|---|---|---|
| ความแม่นยำของการเทียบใบหน้า | กล้องปลอมของ Chromium ไม่มีใบหน้าอยู่ในภาพ | เปิด /local/kaiproctor/enrol.php บนเครื่องที่มีกล้องจริง แล้วลงทะเบียนและยืนยันตัวตน จากนั้นรัน face-service/tests/test_calibration.py เพื่อหาเกณฑ์ที่เหมาะสม |
| Pop-up Notification ระดับระบบปฏิบัติการ | เบราว์เซอร์ที่รันแบบอัตโนมัติไม่มี notification center ของ OS | เปิดหน้าเรียนด้วยมือบน localhost กดอนุญาตการแจ้งเตือน แล้วสลับหน้าต่าง |
| การบังคับเต็มจอ | requestFullscreen ต้องมาจากการกดของผู้ใช้จริง เทสต์อัตโนมัติจึงเรียกไม่ได้ | กดเริ่มเรียนเอง แล้วกด Esc ออกจากเต็มจอ ต้องถูกบันทึกเป็น fullscreen_exit |
| การตรวจจับ devtools | อาศัยสัดส่วนขนาดหน้าต่าง ซึ่งไม่แน่นอนในเบราว์เซอร์ที่ถูกควบคุมด้วยสคริปต์ | เปิด devtools แบบ docked ระหว่างเรียน ต้องถูกบันทึกเป็น devtools_suspected |
| คุณภาพของสรุปที่ AI เขียน | เทสต์ที่ต้องใช้โมเดลจริงจะข้ามไปเมื่อ gateway ไม่ได้รัน ขอบเขตทั้งหมดถูกตรวจแล้วโดยไม่ต้องมีโมเดล | ใส่ API key แล้ว docker compose --profile ai up -d จากนั้นอ่านสรุปสัก 10-20 ครั้งด้วยตา ก่อนเปิดให้ผู้ตรวจจริงใช้ |
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
