"""PDF question import and the administrator's statistics page.

The importer is the last piece the original system had that Moodle does not:
Thai licence-exam packs are distributed as PDFs, and there is no other way to
get them into a question bank without retyping them.
"""
from __future__ import annotations

import json
import subprocess
from pathlib import Path

import pytest

from conftest import PROJECT_ROOT, moodle

SAMPLE_PDF = PROJECT_ROOT / "face-service" / "tests" / "sample-exam.pdf"


@pytest.fixture(scope="module", autouse=True)
def sample_pdf():
    """Generate the fixture pack if it is not already there."""
    if SAMPLE_PDF.is_file():
        return SAMPLE_PDF

    result = subprocess.run(
        [str(PROJECT_ROOT / ".venv" / "Scripts" / "python.exe"),
         "tests/make_sample_pdf.py", "tests/sample-exam.pdf"],
        cwd=PROJECT_ROOT / "face-service", capture_output=True, text=True,
    )
    if not SAMPLE_PDF.is_file():
        pytest.skip(f"could not build the sample pack: {result.stdout}{result.stderr}")
    return SAMPLE_PDF


def test_the_parser_reads_a_thai_exam_pack(session):
    """Questions, choices and the answer key all come back."""
    session.note("send the sample pack to the parser")
    parsed = json.loads(moodle("parse-pdf"))
    session.note(f"parsed: {parsed['count']} questions — {parsed['note']}")

    assert parsed["ok"] is True
    assert parsed["count"] == 6, "not every question in the pack was found"

    for question in parsed["questions"]:
        assert len(question["choices"]) == 4, "a question lost one of its choices"
        assert 0 <= question["answer"] < 4, "a question has no usable answer key"


def test_difficulty_is_spread_across_the_pack(session):
    """These packs carry no difficulty, so it is assigned by thirds — which is
    what makes a difficulty blueprint possible at all."""
    counts = json.loads(moodle("parse-pdf-counts"))
    session.note(f"difficulty spread: {counts}")

    assert sum(counts.values()) == 6
    assert all(counts[level] > 0 for level in ("easy", "medium", "hard"))


def test_a_file_that_is_not_an_exam_pack_is_refused(session):
    """And refused with a reason the person holding the file can act on."""
    session.note("send something that is not a question pack")
    result = json.loads(moodle("parse-pdf-garbage"))
    session.note(f"response: {result}")

    assert result["ok"] is False
    assert result["error"]["code"] in ("bad_pdf", "invalid_image")
    assert result["error"]["message"], "refused with no explanation"


def test_importing_puts_the_questions_in_the_bank_with_difficulty_tags(session):
    before = json.loads(moodle("bank-state"))
    session.note(f"question bank before: {before}")

    session.note("import the pack")
    result = json.loads(moodle("import-pdf"))
    session.note(f"import result: {result}")

    assert result["ok"] is True
    assert result["imported"] == 6
    assert result["skipped"] == 0

    after = json.loads(moodle("bank-state"))
    session.note(f"question bank after: {after}")
    assert after["entries"] == before["entries"] + 6

    # Difficulty lives as a tag because a Moodle question has no field for it —
    # and because a quiz can then draw random questions by tag, which is the
    # blueprint the original system had.
    for level in ("easy", "medium", "hard"):
        assert after["tags"].get(level, 0) >= before["tags"].get(level, 0) + 2


def test_a_teacher_builds_a_random_paper_from_the_course_bank(session):
    """The other half of importing: drawing a paper out again.

    Moodle can already hold "a random question from this category" in a slot,
    and picks a different one per attempt. What it cannot do is put thirty of
    them in without thirty trips through a modal — which is the ordinary case
    for a bank of imported questions, not an exotic one.

    Built against a quiz created for this test. The demo course's blueprint
    quiz is what test_12 measures reproducible draws against, and filling it
    with untagged questions quietly breaks that.
    """
    courseid = int(moodle("course-id").strip())
    quizcmid = int(moodle("make-quiz", "เทสต์ชุดข้อสอบสุ่ม").strip())
    try:
        session.note("sign in as the teacher and open the builder")
        session.login("instructor")
        session.goto(f"/local/kaiproctor/randompaper.php?courseid={courseid}")
        session.beat(1.5)

        available = int(moodle("bank-available").strip())
        session.note(f"the course bank offers {available} questions")
        assert available > 0, "nothing in the bank to draw from"

        session.note("ask for more questions than exist")
        session.page.select_option('select[name="quizid"]', str(
            moodle("quiz-instance", str(quizcmid)).strip()))
        session.page.fill('input[name="count"]', str(available + 1))
        session.page.click("#id_submitbutton")
        session.beat(1.5)

        assert "randompaper.php" in session.page.url, (
            "a paper larger than the bank was accepted"
        )
        body = session.body_text()
        assert str(available) in body, "the refusal does not say what is available"
        session.note("refused, naming the size of the bank")

        session.note("ask for five, which the bank can supply")
        session.page.fill('input[name="count"]', "5")
        session.page.click("#id_submitbutton")
        session.page.wait_for_url("**/mod/quiz/edit.php*", timeout=30_000)
        session.beat(1.5)

        slots = json.loads(moodle("quiz-slots", str(quizcmid)))
        session.note(f"slots now: {slots}")

        assert slots["count"] == 5, "the paper does not hold what was asked for"
        assert slots["random"] == 5, "the slots hold fixed questions, not draws"
        # A paper worth whatever the quiz was worth before is wrong everywhere
        # it is shown, starting with the gradebook.
        assert slots["sumgrades"] == 5.0, "the total was not recomputed"
    finally:
        moodle("delete-quiz", str(quizcmid))


def test_the_stats_page_reports_the_service_and_the_evidence(session):
    session.note("sign in as an administrator")
    session.login("admin")

    session.note("open the statistics page")
    session.goto("/local/kaiproctor/stats.php")
    session.beat(2)

    status = session.page.locator('[data-region="service-status"]')
    assert status.count() == 1
    assert "alert-success" in (status.get_attribute("class") or ""), (
        "the face service is up but the page does not say so"
    )

    for stat in ["enrolled", "sessions", "checks", "monitored",
                 "proctoredquizzes", "evidencecount"]:
        assert session.page.locator(f'[data-stat="{stat}"]').count() == 1, (
            f"the page does not report {stat}"
        )

    session.note("the retention period in force is shown")
    # Checked against the configured value rather than against page prose: the
    # page renders in whatever language the reader has set, and pinning one
    # language breaks the test for the other.
    retention = moodle("get-setting", "retentiondays")
    session.note(f"configured retention: {retention} days")
    assert retention in session.body_text()


def test_the_stats_page_says_when_the_face_service_is_unreachable(session):
    """Silence here would be the worst outcome: proctoring that quietly stopped
    checking anybody looks exactly like proctoring that is working."""
    session.note("sign in as an administrator")
    session.login("admin")

    original = moodle("get-setting", "faceserviceurl")
    try:
        session.note("point the plugin at a service that is not there")
        moodle("set-setting", "faceserviceurl", "http://127.0.0.1:9999")

        session.goto("/local/kaiproctor/stats.php")
        session.beat(2)

        status = session.page.locator('[data-region="service-status"]')
        shown = status.inner_text()
        session.note(f"status shown: {shown[:90]}")
        assert "alert-danger" in (status.get_attribute("class") or "")
        # The reason has to be on screen, not just a red box.
        assert len(shown.strip()) > 20, "the page says something is wrong but not what"
    finally:
        moodle("set-setting", "faceserviceurl", original)


def test_the_stats_page_warns_when_retention_is_not_being_enforced(session, clean_learner):
    """Evidence outliving its retention period means the purge task is not
    running — invisible until somebody asks why there is a year of faces on
    disk."""
    clean_learner("learner")

    session.note("create a piece of evidence and backdate it past retention")
    moodle("seed-evidence", "learner")
    moodle("age-evidence", "learner", "400")

    session.note("sign in as an administrator")
    session.login("admin")
    session.goto("/local/kaiproctor/stats.php")
    session.beat(2)

    warning = session.page.locator('[data-region="retention-warning"]')
    session.note("the page raises it")
    assert warning.count() == 1
    assert warning.inner_text().strip(), "the warning box is empty"

    session.note("running the purge clears both the evidence and the warning")
    moodle("run-purge-task")
    session.goto("/local/kaiproctor/stats.php")
    session.beat(1.5)
    assert session.page.locator('[data-region="retention-warning"]').count() == 0
