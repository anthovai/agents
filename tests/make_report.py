"""Turn a pytest run into reports/REPORT.md.

Reads reports/junit.xml and the artefacts beside it, and writes the same kind
of report face-re produced: a pass/fail summary, the customer's seven
requirements mapped to the tests that prove each one, and an explicit list of
what automation could not check.

Run automatically by run-tests.sh; safe to run again on an existing junit.xml.
"""
from __future__ import annotations

import json
import subprocess
import sys
from datetime import datetime, timezone
from pathlib import Path

# defusedxml rather than the stdlib parser: junit.xml is our own output, but a
# report generator is exactly the kind of tool that later gets pointed at a
# file from somewhere else.
from defusedxml import ElementTree as ET

PROJECT_ROOT = Path(__file__).resolve().parent.parent
REPORTS = PROJECT_ROOT / "reports"

# The seven requirements the customer originally specified, each mapped to the
# tests that demonstrate it. A requirement with no passing test is reported as
# unproven rather than quietly omitted.
REQUIREMENTS = [
    (
        "1",
        "ออกจาก Active Window → หยุดวิดีโอ + ปิดหน้าเรียน",
        [
            "test_leaving_the_window_pauses_the_video_and_is_recorded",
            "test_monitoring_runs_during_the_attempt",
        ],
    ),
    (
        "2",
        "ล็อกไม่ให้ออกไปทำอย่างอื่น",
        [
            "test_lockdown_blocks_and_reports_every_browser_exit",
            "test_text_selection_and_dragging_are_suppressed",
            "test_an_ordinary_browser_cannot_start_the_seb_quiz",
            "test_seb_is_configured_with_a_real_config_key",
        ],
    ),
    (
        "3",
        "กล้องตรวจใบหน้าตามเวลาที่กำหนด",
        [
            "test_presence_check_runs_on_its_interval_and_pauses_the_lesson",
            "test_identity_check_runs_on_its_own_interval",
            "test_challenge_asks_for_a_randomised_sequence",
        ],
    ),
    (
        "4",
        "แจ้งเตือนเมื่อออกจากหน้าเรียน",
        [
            "test_the_learner_is_asked_to_confirm_they_are_still_there",
            "test_leaving_the_window_pauses_the_video_and_is_recorded",
        ],
    ),
    (
        "5",
        "เก็บข้อมูลทุกอย่าง + สุ่มถ่ายวิดีโอ",
        [
            "test_a_violation_captures_evidence",
            "test_a_random_clip_is_recorded_and_stored",
            "test_the_report_shows_checks_evidence_and_signals",
        ],
    ),
    (
        "6",
        "ความยินยอม PDPA ก่อนเก็บข้อมูลใบหน้า",
        [
            "test_nothing_is_reachable_before_consent_is_given",
            "test_enrolment_becomes_reachable_once_consent_is_given",
            "test_consent_document_states_what_is_collected",
            "test_consent_is_compulsory_not_optional",
            "test_privacy_api_deletes_the_face_on_erasure",
            "test_expired_evidence_is_purged",
        ],
    ),
    (
        "7",
        "ข้อสอบมีการคุมสอบและตัดเกรดฝั่ง server",
        [
            "test_a_forged_client_marker_does_not_open_the_attempt",
            "test_a_server_written_pass_opens_the_attempt",
            "test_answers_can_be_submitted_and_graded",
        ],
    ),
]

# Capabilities beyond the original seven, added since.
EXTRAS = [
    (
        "บันทึกการเรียนเป็นครั้งๆ พร้อมกฎที่บังคับตอนนั้น (audit)",
        [
            "test_starting_a_lesson_opens_one_sitting",
            "test_the_rules_in_force_are_recorded_on_the_sitting",
            "test_changing_the_settings_does_not_rewrite_a_finished_sitting",
            "test_the_report_groups_everything_by_sitting",
        ],
    ),
    (
        "สถานะการจบและการกันไม่ให้ปลอมสถานะ",
        [
            "test_a_terminated_sitting_records_why",
            "test_a_late_completion_cannot_launder_a_terminated_sitting",
            "test_a_client_cannot_mark_a_sitting_abandoned",
            "test_a_sitting_nobody_closed_is_marked_abandoned",
        ],
    ),
    (
        "บทเรียนวิดีโอแบบมีปฏิสัมพันธ์ ภายใต้การเฝ้าดู",
        [
            "test_the_activity_says_it_is_proctored_before_anything_starts",
            "test_monitoring_starts_when_the_learner_begins",
            "test_the_video_player_is_found_through_its_published_interface",
            "test_leaving_the_activity_window_is_recorded",
        ],
    ),
    (
        "ผู้สอนไม่ถูกเฝ้าดู และเปิด/ปิดการเฝ้าดูรายกิจกรรมได้",
        [
            "test_staff_viewing_the_activity_are_not_monitored",
            "test_staff_can_turn_proctoring_off_and_on",
            "test_an_unmonitored_activity_is_left_alone",
        ],
    ),
    (
        "Safe Exam Browser คู่กับการยืนยันตัวตนด้วยใบหน้า",
        [
            "test_the_seb_config_file_is_downloadable_by_the_learner",
            "test_both_rules_describe_themselves_to_the_learner",
        ],
    ),
]

MANUAL_ONLY = [
    (
        "ความแม่นยำของการเทียบใบหน้า",
        "กล้องปลอมของ Chromium ไม่มีใบหน้าอยู่ในภาพ",
        "เปิด /local/kaiproctor/enrol.php บนเครื่องที่มีกล้องจริง แล้วลงทะเบียนและยืนยันตัวตน "
        "จากนั้นรัน face-service/tests/test_calibration.py เพื่อหาเกณฑ์ที่เหมาะสม",
    ),
    (
        "Pop-up Notification ระดับระบบปฏิบัติการ",
        "เบราว์เซอร์ที่รันแบบอัตโนมัติไม่มี notification center ของ OS",
        "เปิดหน้าเรียนด้วยมือบน localhost กดอนุญาตการแจ้งเตือน แล้วสลับหน้าต่าง",
    ),
    (
        "การบังคับเต็มจอ",
        "requestFullscreen ต้องมาจากการกดของผู้ใช้จริง เทสต์อัตโนมัติจึงเรียกไม่ได้",
        "กดเริ่มเรียนเอง แล้วกด Esc ออกจากเต็มจอ ต้องถูกบันทึกเป็น fullscreen_exit",
    ),
    (
        "การตรวจจับ devtools",
        "อาศัยสัดส่วนขนาดหน้าต่าง ซึ่งไม่แน่นอนในเบราว์เซอร์ที่ถูกควบคุมด้วยสคริปต์",
        "เปิด devtools แบบ docked ระหว่างเรียน ต้องถูกบันทึกเป็น devtools_suspected",
    ),
    (
        "การล็อกระดับเครื่อง",
        "หน้าเว็บไม่มีสิทธิ์ระดับระบบปฏิบัติการ — Alt+Tab จอที่สอง และมือถือข้างๆ ห้ามไม่ได้จริง",
        "ใช้ Safe Exam Browser คู่กับ quizaccess_seb ซึ่งตั้งค่าไว้แล้วในข้อสอบ 'ความเสี่ยงสูง (SEB)'",
    ),
    (
        "การทำงานของ Safe Exam Browser ตัวจริง",
        "SEB เป็นโปรแกรมติดตั้งบนเครื่อง เบราว์เซอร์อัตโนมัติปลอมเป็นมันไม่ได้",
        "ติดตั้ง SEB แล้วเปิดลิงก์ seb:// จากหน้าข้อสอบ ต้องเข้าสอบได้และ Config Key ต้องตรง",
    ),
]


def load_results() -> tuple[list[dict], dict]:
    junit = REPORTS / "junit.xml"
    if not junit.is_file():
        raise SystemExit(f"{junit} not found — run the tests first")

    root = ET.parse(junit).getroot()
    suite = root.find("testsuite") if root.tag == "testsuites" else root

    cases = []
    for case in suite.iter("testcase"):
        failure = case.find("failure")
        error = case.find("error")
        skipped = case.find("skipped")
        if failure is not None or error is not None:
            status = "failed"
            message = (failure if failure is not None else error).get("message", "")
        elif skipped is not None:
            status = "skipped"
            message = skipped.get("message", "")
        else:
            status = "passed"
            message = ""
        cases.append({
            "name": case.get("name"),
            "classname": case.get("classname", ""),
            "time": float(case.get("time", 0)),
            "status": status,
            "message": message,
        })

    totals = {
        "total": len(cases),
        "passed": sum(1 for c in cases if c["status"] == "passed"),
        "failed": sum(1 for c in cases if c["status"] == "failed"),
        "skipped": sum(1 for c in cases if c["status"] == "skipped"),
        "time": float(suite.get("time", 0)),
    }
    return cases, totals


def stack_health() -> dict:
    try:
        out = subprocess.run(
            ["docker", "compose", "exec", "-T", "moodle",
             "php", "/var/www/html/kp-query.php", "health"],
            cwd=PROJECT_ROOT, capture_output=True, text=True, encoding="utf-8", check=True,
        ).stdout
        return json.loads(out)
    except Exception:  # noqa: BLE001 - the report is still worth writing
        return {}


def artefact_list(directory: Path, suffix: str) -> list[str]:
    if not directory.is_dir():
        return []
    return sorted(p.name for p in directory.glob(f"*{suffix}"))


def main() -> int:
    cases, totals = load_results()
    by_name = {case["name"]: case for case in cases}
    health = stack_health()

    videos = artefact_list(REPORTS / "video", ".webm")
    shots = artefact_list(REPORTS / "screenshots", ".png")
    logs = artefact_list(REPORTS / "eventlog", ".txt")

    now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M UTC")
    lines: list[str] = []
    add = lines.append

    add("# ผลการทดสอบระบบคุมสอบบน Moodle")
    add("")
    add(f"รันเมื่อ **{now}**")
    if health:
        add(f"· Moodle **{health.get('moodle_release', '?')}**")
        add(f"· face-service **{health.get('faceservice_models', []) and 'yunet+sface'}**"
            f" (โมเดล {len(health.get('faceservice_models', []))} ตัว,"
            f" liveness {'พร้อม' if health.get('liveness_available') else 'ไม่พร้อม'})")
        add(f"· เกณฑ์ผ่านที่ใช้ตอนทดสอบ **{health.get('match_threshold')}**")
    add("· Chromium (Playwright) พร้อมกล้องปลอม `--use-fake-device-for-media-stream`")
    add("")

    add("## สรุป")
    add("")
    add("| | จำนวน |")
    add("|---|---|")
    add(f"| ผ่าน | **{totals['passed']}** |")
    add(f"| ไม่ผ่าน | {totals['failed']} |")
    if totals["skipped"]:
        add(f"| ข้าม | {totals['skipped']} |")
    add(f"| เวลาที่ใช้ | {totals['time']:.1f} วินาที |")
    add("")

    add("## ไฟล์หลักฐานในโฟลเดอร์นี้")
    add("")
    add("| ไฟล์ | คืออะไร |")
    add("|---|---|")
    add("| `junit.xml` | ผลรายเทสต์แบบมาตรฐาน เปิดใน CI หรือ IDE ได้ |")
    add("| `pytest-output.txt` | log การรันเต็ม |")
    add(f"| `video/<ชื่อเทสต์>.webm` | วิดีโอการรันแต่ละเทสต์ ({len(videos)} ไฟล์) "
        "หน่วงจังหวะไว้ให้ดูทัน |")
    add(f"| `screenshots/<ชื่อเทสต์>.png` | ภาพหน้าจอเต็มหน้าตอนจบแต่ละเทสต์ ({len(shots)} ไฟล์) |")
    add(f"| `eventlog/<ชื่อเทสต์>.txt` | audit log ที่ระบบบันทึกไว้ในเทสต์นั้น ({len(logs)} ไฟล์) |")
    add("| `eventlog/<ชื่อเทสต์>.steps.txt` | ลำดับขั้นที่เทสต์เดิน อ่านคู่กับวิดีโอ |")
    add("")

    add("## ข้อกำหนด 7 ข้อ → เทสต์ที่พิสูจน์")
    add("")
    add("| # | ข้อกำหนด | สถานะ | เทสต์ |")
    add("|---|---|---|---|")
    for number, title, tests in REQUIREMENTS:
        statuses = [by_name.get(name, {}).get("status", "missing") for name in tests]
        if all(status == "passed" for status in statuses):
            mark = "ผ่าน"
        elif any(status == "failed" for status in statuses):
            mark = "**ไม่ผ่าน**"
        else:
            mark = "**ยังไม่ได้พิสูจน์**"
        listed = "<br>".join(
            f"`{name}` {'' if by_name.get(name, {}).get('status') == 'passed' else '(' + by_name.get(name, {}).get('status', 'missing') + ')'}"
            for name in tests
        )
        add(f"| {number} | {title} | {mark} | {listed} |")
    add("")

    add("## ความสามารถที่เพิ่มมาภายหลัง")
    add("")
    add("| ความสามารถ | สถานะ | เทสต์ |")
    add("|---|---|---|")
    for title, tests in EXTRAS:
        statuses = [by_name.get(name, {}).get("status", "missing") for name in tests]
        if all(status == "passed" for status in statuses):
            mark = "ผ่าน"
        elif any(status == "failed" for status in statuses):
            mark = "**ไม่ผ่าน**"
        else:
            mark = "**ยังไม่ได้พิสูจน์**"
        listed = "<br>".join(f"`{name}`" for name in tests)
        add(f"| {title} | {mark} | {listed} |")
    add("")

    add("## รายการเทสต์ทั้งหมด")
    add("")
    add("| เทสต์ | ผล | วินาที |")
    add("|---|---|---|")
    for case in cases:
        icon = {"passed": "ผ่าน", "failed": "**ไม่ผ่าน**", "skipped": "ข้าม"}[case["status"]]
        add(f"| `{case['name']}` | {icon} | {case['time']:.1f} |")
    add("")

    failures = [case for case in cases if case["status"] == "failed"]
    if failures:
        add("## รายละเอียดที่ไม่ผ่าน")
        add("")
        for case in failures:
            add(f"### `{case['name']}`")
            add("")
            add("```")
            add(case["message"].strip()[:1500])
            add("```")
            add("")

    add("## ที่เทสต์อัตโนมัติทำแทนไม่ได้ ต้องตรวจด้วยมือ")
    add("")
    add("| เรื่อง | เหตุผล | วิธีตรวจ |")
    add("|---|---|---|")
    for topic, reason, how in MANUAL_ONLY:
        add(f"| {topic} | {reason} | {how} |")
    add("")

    add("> ชุดเทสต์นี้พิสูจน์ว่า flow, การบังคับนโยบาย และการเก็บหลักฐานทำงานถูกต้อง")
    add("> **ไม่ได้พิสูจน์ความแม่นยำของการเทียบใบหน้า** เพราะกล้องปลอมไม่มีใบหน้าอยู่ในภาพ")
    add("> เกณฑ์ที่ใช้อยู่ยังเป็นค่าอ้างอิงของผู้พัฒนาโมเดล ไม่ใช่ค่าที่ปรับเทียบกับข้อมูลจริง")
    add("")

    add("## วิธีรันซ้ำ")
    add("")
    add("```bash")
    add("docker compose up -d")
    add("sh run-tests.sh")
    add("```")
    add("")

    (REPORTS / "REPORT.md").write_text("\n".join(lines), encoding="utf-8")
    print(f"wrote {REPORTS / 'REPORT.md'}")
    print(f"  {totals['passed']} passed, {totals['failed']} failed, "
          f"{totals['skipped']} skipped in {totals['time']:.1f}s")
    return 1 if totals["failed"] else 0


if __name__ == "__main__":
    sys.exit(main())
