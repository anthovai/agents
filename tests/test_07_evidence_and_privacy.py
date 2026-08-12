"""Evidence handling and the PDPA obligations around it.

Evidence has to be readable by the people entitled to see it, unreadable by
everybody else, and gone when its retention expires.
"""
from __future__ import annotations

from conftest import moodle, open_monitored


def _capture_some_evidence(session) -> int:
    """Leave a violation photograph behind, and say where it was left.

    The report is per-context. A sitting on a monitored activity belongs to
    that activity's context, not to the learner's user context — which is
    where the old standalone lesson page put it.
    """
    cmid = open_monitored(session)
    session.beat(1.5)
    session.page.evaluate("() => window.dispatchEvent(new Event('blur'))")
    session.beat(3)
    return cmid


def test_the_report_shows_checks_evidence_and_signals(session, clean_learner):
    clean_learner("learner")
    moodle("seed-enrolment", "learner")

    session.note("sign in as the learner and generate some evidence")
    session.login("learner")
    cmid = _capture_some_evidence(session)

    context_id = moodle("cm-context-id", str(cmid)).strip()
    user_id = moodle("user-id", "learner")

    session.note("the learner opens their own evidence report")
    session.goto(f"/local/kaiproctor/report.php?userid={user_id}&contextid={context_id}")
    session.beat(2)

    text = session.body_text()
    # The report's own content, not the page heading. In a module context the
    # theme puts the course name in the heading, so asserting on that was
    # testing Boost rather than this page.
    assert "ผลตรวจตัวตนและการมีตัวตน" in text, "the checks table is missing"
    assert "สัญญาณการเฝ้าดู" in text
    assert "window_blur" in text
    # The image itself has to render, not just a row saying one exists.
    assert session.page.locator(".kaiproctor-evidence-item img").count() >= 1


def test_the_report_records_the_threshold_that_was_in_force(session, clean_learner):
    clean_learner("learner")
    moodle("seed-enrolment", "learner")
    moodle("seed-pass", "learner", "8")

    session.note("sign in as an instructor")
    session.login("instructor")

    context_id = moodle("context-id", "8")
    user_id = moodle("user-id", "learner")

    session.note("open the learner's evidence for that quiz")
    session.goto(f"/local/kaiproctor/report.php?userid={user_id}&contextid={context_id}")
    session.beat(2)

    text = session.body_text()
    assert "0.7123" in text, "the similarity score is not shown"
    assert "0.3630" in text or "0.363" in text, "the threshold in force is not shown"
    # An admin raising the threshold next month must not rewrite what last
    # month's decisions meant.
    assert "เกณฑ์ที่แสดงคือเกณฑ์ที่ใช้ตอนตรวจครั้งนั้น" in text


def test_one_learner_cannot_read_another_learners_evidence(session, clean_learner):
    clean_learner("learner")
    moodle("seed-enrolment", "learner")

    session.note("generate evidence as the first learner")
    session.login("learner")
    victim_context = moodle("cm-context-id",
                            str(_capture_some_evidence(session))).strip()
    victim_id = moodle("user-id", "learner")
    session.logout()

    session.note("sign in as a different learner and try to open it")
    clean_learner("learner2")
    session.login("learner2")
    session.goto(f"/local/kaiproctor/report.php?userid={victim_id}&contextid={victim_context}")
    session.beat(2)

    text = session.body_text()
    session.note("access is refused")
    assert "window_blur" not in text
    assert ("ไม่มีสิทธิ" in text or "Sorry" in text
            or "ข้อผิดพลาด" in text or "error" in text.lower())


def test_expired_evidence_is_purged(session, clean_learner):
    clean_learner("learner")

    session.note("generate evidence as the learner")
    session.login("learner")
    _capture_some_evidence(session)

    before = moodle("count", "evidence", "learner")
    session.note(f"evidence rows before the purge: {before}")
    assert int(before) >= 1

    session.note("backdate it past the retention window and run the purge task")
    moodle("age-evidence", "learner", "400")
    purged = moodle("run-purge-task")
    session.note(f"purge task result: {purged}")

    after = moodle("count", "evidence", "learner")
    session.note(f"evidence rows after the purge: {after}")
    assert after == "0", "evidence outlived its retention period"


def test_privacy_api_deletes_the_face_on_erasure(session, clean_learner):
    """The reason this project sits on Moodle rather than a bespoke store."""
    clean_learner("learner")
    moodle("seed-enrolment", "learner")

    session.note("generate checks and evidence for the learner")
    session.login("learner")
    _capture_some_evidence(session)

    assert moodle("count", "face", "learner") == "1"
    assert int(moodle("count", "evidence", "learner")) >= 1

    session.note("run the plugin's own Privacy API provider, as an approved erasure would")
    session.note(moodle("privacy-delete", "learner"))

    # The embedding is not context-bound: an erasure that left it behind would
    # leave the learner identifiable.
    assert moodle("count", "face", "learner") == "0"
    assert moodle("count", "evidence", "learner") == "0"
    assert moodle("count", "check", "learner") == "0"
