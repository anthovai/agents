"""The proctored quiz gate.

The important property is that the gate is satisfied only by a check the
server itself wrote. Everything the browser sends is treated as a claim.
"""
from __future__ import annotations

import json

from conftest import QUIZ_CMID, moodle


def test_quiz_announces_that_it_is_proctored(session, clean_learner):
    clean_learner("learner")
    moodle("seed-enrolment", "learner")

    session.note("sign in as the learner")
    session.login("learner")

    session.note("open the proctored quiz")
    session.goto(f"/mod/quiz/view.php?id={QUIZ_CMID}")

    text = session.body_text()
    # The learner is told before they start, not after.
    assert "ยืนยันตัวตนผ่านกล้อง" in text


def test_a_learner_with_no_enrolled_face_cannot_start(session, clean_learner, eventlog):
    clean_learner("learner2")

    session.note("sign in as a learner who has never enrolled a face")
    session.login("learner2")

    session.note("open the proctored quiz")
    session.goto(f"/mod/quiz/view.php?id={QUIZ_CMID}")
    session.beat(1.5)

    text = session.body_text()
    assert "ยังไม่ได้ลงทะเบียนใบหน้า" in text
    # Refused with a way forward rather than a dead end.
    assert session.page.locator('a[href*="kaiproctor/enrol"]').count() >= 1
    assert session.page.locator('form[action*="startattempt"]').count() == 0


def test_the_preflight_check_asks_for_the_camera(session, clean_learner):
    clean_learner("learner")
    moodle("seed-enrolment", "learner")

    session.note("sign in as an enrolled learner")
    session.login("learner")
    session.goto(f"/mod/quiz/view.php?id={QUIZ_CMID}")

    session.note("start the attempt")
    session.page.locator('form[action*="startattempt"] button').first.click()
    session.page.wait_for_load_state("domcontentloaded")
    session.beat(2)

    assert session.page.locator('[data-region="kaiproctor-preflight"]').count() == 1
    assert session.page.locator('[data-action="verify"]').count() == 1
    text = session.body_text()
    assert "ยืนยันตัวตน" in text
    assert "จะเริ่มทำข้อสอบไม่ได้จนกว่า" in text


def test_a_failed_check_says_what_was_actually_wrong(session, clean_learner):
    """The message has to name the cause, and one cause in particular.

    Chromium's fake camera shows no face, so what is being proved here is the
    no-face branch. The branch that matters most in production is the one
    beside it: a face that does not match the enrolled one. Both used to
    produce the same sentence, and that sentence told the learner to check the
    room lighting — which is wrong for every cause here, and sends somebody to
    adjust a lamp when the real answer is that they need to re-enrol.
    """
    clean_learner("learner")
    moodle("seed-enrolment", "learner")

    session.note("sign in as an enrolled learner and reach the identity check")
    session.login("learner")
    session.goto(f"/mod/quiz/view.php?id={QUIZ_CMID}")
    session.page.locator('form[action*="startattempt"] button').first.click()
    session.page.wait_for_load_state("domcontentloaded")
    session.beat(1.5)

    session.note("the panel is idle until the learner starts it")
    region = session.page.locator('[data-region="kaiproctor-preflight"]')
    assert region.get_attribute("data-state") == "idle"

    session.note("run the check in front of a camera showing no face")
    session.page.click('[data-action="verify"]')

    # Each pose has its own 15-second timeout and every poll is a round trip
    # to the face service, so the wait allows for a slow one.
    session.page.wait_for_selector(
        '[data-region="status"]:not([hidden])', timeout=90_000)
    session.beat(2)

    status = session.page.inner_text('[data-region="status"]')
    session.note(f"status shown to the learner: {status}")

    assert region.get_attribute("data-state") == "failed", (
        "the panel did not show the check as failed"
    )
    assert "ใบหน้า" in status, "the message does not say what was wrong"
    assert "แสง" not in status, (
        "the message is blaming the lighting again, which nothing here measures"
    )


def test_a_forged_client_marker_does_not_open_the_attempt(session, clean_learner, eventlog):
    """The heart of the gate."""
    clean_learner("learner")
    moodle("seed-enrolment", "learner")

    session.note("sign in as an enrolled learner")
    session.login("learner")
    session.goto(f"/mod/quiz/view.php?id={QUIZ_CMID}")

    session.note("start the attempt to reach the identity check")
    session.page.locator('form[action*="startattempt"] button').first.click()
    session.page.wait_for_load_state("domcontentloaded")
    session.beat(1.5)

    session.note("forge the client-side 'verified' marker and submit anyway")
    session.page.evaluate(
        """() => {
            const marker = document.querySelector('[name="kaiproctorattempted"]');
            marker.value = 1;
            marker.closest('form').submit();
        }"""
    )
    session.page.wait_for_load_state("domcontentloaded")
    session.beat(2.5)

    assert "/mod/quiz/attempt.php" not in session.page.url, (
        "a forged client marker was enough to open the attempt"
    )
    text = session.body_text()
    session.note("the learner is told why they were refused")
    assert "ต้องยืนยันตัวตนก่อนเริ่มทำข้อสอบ" in text


def test_a_server_written_pass_opens_the_attempt(session, clean_learner, eventlog):
    clean_learner("learner")
    moodle("seed-enrolment", "learner")

    session.note("sign in as an enrolled learner")
    session.login("learner")
    session.goto(f"/mod/quiz/view.php?id={QUIZ_CMID}")

    session.note("start the attempt to reach the identity check")
    session.page.locator('form[action*="startattempt"] button').first.click()
    session.page.wait_for_load_state("domcontentloaded")
    session.beat(1.5)

    session.note("record the passing check the camera flow would have written")
    moodle("seed-pass", "learner", str(QUIZ_CMID))

    session.note("submit the identity check")
    session.page.evaluate(
        """() => document.querySelector('[name="kaiproctorattempted"]').closest('form').submit()"""
    )
    session.page.wait_for_load_state("domcontentloaded")
    session.beat(2.5)

    assert "/mod/quiz/attempt.php" in session.page.url, "a valid pass did not open the attempt"
    assert session.page.locator(".que").count() == 3
    session.note("the learner is in the exam with all three questions")


def test_monitoring_runs_during_the_attempt(session, clean_learner, eventlog):
    clean_learner("learner")
    moodle("seed-enrolment", "learner")
    moodle("seed-pass", "learner", str(QUIZ_CMID))

    session.note("sign in and open the attempt")
    session.login("learner")
    session.goto(f"/mod/quiz/view.php?id={QUIZ_CMID}")
    session.page.locator('form[action*="startattempt"] button').first.click()
    session.page.wait_for_load_state("domcontentloaded")
    session.beat(1)

    marker = session.page.locator('[name="kaiproctorattempted"]')
    if marker.count():
        session.page.evaluate(
            """() => document.querySelector('[name="kaiproctorattempted"]').closest('form').submit()"""
        )
        session.page.wait_for_load_state("domcontentloaded")
        session.beat(1.5)

    session.note("the learner interacts, which starts the camera and the monitor")
    session.page.locator(".que input[type=radio]").first.click()
    session.beat(3)

    injected = session.page.evaluate(
        """() => !!document.querySelector('.kaiproctor-preview-attempt')"""
    )
    session.note(f"camera preview injected into the exam page: {injected}")
    assert injected, "the attempt page was not being monitored"

    session.note("the learner leaves the exam window")
    session.page.evaluate("() => window.dispatchEvent(new Event('blur'))")
    session.beat(3)

    log = eventlog("learner")
    assert "monitor_started" in log
    assert "window_blur" in log


def test_answers_can_be_submitted_and_graded(session, clean_learner):
    clean_learner("learner")
    moodle("seed-enrolment", "learner")
    moodle("seed-pass", "learner", str(QUIZ_CMID))

    session.note("sign in and open the attempt")
    session.login("learner")
    session.goto(f"/mod/quiz/view.php?id={QUIZ_CMID}")
    session.page.locator('form[action*="startattempt"] button').first.click()
    session.page.wait_for_load_state("domcontentloaded")
    session.beat(1)

    if session.page.locator('[name="kaiproctorattempted"]').count():
        session.page.evaluate(
            """() => document.querySelector('[name="kaiproctorattempted"]').closest('form').submit()"""
        )
        session.page.wait_for_load_state("domcontentloaded")
        session.beat(1.5)

    session.note("answer all three questions correctly")
    # Moodle shuffles the options per learner, so the right answer is found by
    # its text, not its position. Answering correctly and then getting full
    # marks is what shows the server mapped the shuffled choices back.
    correct = json.loads(moodle("correct-answers", str(QUIZ_CMID)))
    session.note(f"expected answers: {correct}")

    for index, answer in enumerate(correct):
        question = session.page.locator(".que").nth(index)
        option = question.locator("div.r0, div.r1").filter(has_text=answer).first
        option.locator("input[type=radio]").check()
        session.beat(0.5)

    session.note("go to the attempt summary")
    session.page.locator('input[name="next"], button[name="next"]').first.click()
    session.page.wait_for_load_state("domcontentloaded")
    session.beat(1.5)

    session.note("submit all and finish")
    # The button lives inside form#frm-finishattempt. Note that
    # .btn-finishattempt is the wrapping div, not the control — clicking that
    # does nothing and the attempt silently stays open.
    session.page.locator("#frm-finishattempt button").first.click()
    session.beat(1.5)

    confirm = session.page.locator('.modal [data-action="save"], .modal-footer .btn-primary')
    if confirm.count():
        session.note("confirm the submission")
        confirm.first.click()

    session.note("wait for the graded review page")
    session.page.wait_for_url("**/review.php*", timeout=30_000)
    session.beat(2.5)

    session.note("the attempt was graded server-side")
    grade = json.loads(moodle("attempt-grade", "learner", str(QUIZ_CMID)))
    session.note(f"stored grade: {grade}")

    assert grade["state"] == "finished", "the attempt was never submitted"
    assert grade["sumgrades"] == grade["maxgrades"] == 3.0, (
        "three correct answers did not score full marks — the shuffled choices "
        "were not mapped back correctly"
    )
