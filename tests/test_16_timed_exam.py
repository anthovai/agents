"""Timing: the clock, the window, and the attempt limit.

The old system's exam module carried a deadline on every paper — created_at +
duration — and when it passed, the paper was graded on whatever was answered,
with timed_out on the record. In Moodle the same three controls are quiz
settings: timelimit, timeopen/timeclose, attempts. What these tests check is
not that Moodle has a clock; it is that the clock a teacher sets in the back
office is the one the learner actually sits under — visible, enforced, and
graded the same way the old system graded it.

None of this needs a camera: the identity gate is satisfied with a
server-written pass, exactly as test_06 does it, so the timing behaviour is
what the test isolates.
"""
from __future__ import annotations

import json
import time

from conftest import QUIZ_CMID, moodle


def open_attempt(session):
    """Sign in, pass the identity gate, and land inside the attempt."""
    moodle("seed-enrolment", "learner")
    moodle("seed-pass", "learner", str(QUIZ_CMID))

    session.login("learner")
    session.goto(f"/mod/quiz/view.php?id={QUIZ_CMID}")
    session.page.locator('form[action*="startattempt"] button').first.click()
    session.page.wait_for_load_state("domcontentloaded")
    session.beat(1)

    # A timed quiz interposes a confirmation ("the clock starts now") that an
    # untimed one does not have.
    confirm = session.page.locator(
        '.modal [data-action="save"], .modal-footer .btn-primary, #id_submitbutton')
    if confirm.count():
        confirm.first.click()
        session.page.wait_for_load_state("domcontentloaded")
        session.beat(1)

    if session.page.locator('[name="kaiproctorattempted"]').count():
        session.page.evaluate(
            """() => document.querySelector('[name="kaiproctorattempted"]')
                     .closest('form').submit()""")
        session.page.wait_for_load_state("domcontentloaded")
        session.beat(1.5)

    assert "/mod/quiz/attempt.php" in session.page.url, \
        "the attempt did not open"


def restore_timing(original: dict):
    moodle("quiz-timing", str(QUIZ_CMID), str(original["timelimit"]),
           str(original["timeopen"]), str(original["timeclose"]),
           str(original["attempts"]), original["overduehandling"])


def test_the_time_limit_set_in_the_back_office_reaches_the_learner(
        session, clean_learner):
    """The countdown on the learner's screen is the number the teacher typed.

    Announced before starting AND ticking during the attempt: a limit that is
    only discovered after the exam has begun is a limit sprung on somebody.
    """
    clean_learner("learner")
    original = json.loads(moodle("quiz-timing", str(QUIZ_CMID)))

    try:
        session.note("give the quiz a 30-minute limit from the back office")
        moodle("quiz-timing", str(QUIZ_CMID), "1800", "0", "0", "0", "autosubmit")

        moodle("seed-enrolment", "learner")
        session.login("learner")
        session.goto(f"/mod/quiz/view.php?id={QUIZ_CMID}")
        session.beat(1)

        body = session.page.inner_text("body")
        assert "30" in body and ("นาที" in body or "min" in body.lower()), \
            "the learner is not told about the limit before starting"
        session.note("the view page announces the limit before the attempt")

        moodle("seed-pass", "learner", str(QUIZ_CMID))
        session.page.locator('form[action*="startattempt"] button').first.click()
        session.page.wait_for_load_state("domcontentloaded")
        session.beat(1)

        # The pre-attempt confirmation names the limit too; go through it.
        confirm = session.page.locator(
            '.modal button:has-text("เริ่ม"), .modal [data-action="save"], '
            '#id_submitbutton')
        if confirm.count():
            confirm.first.click()
            session.page.wait_for_load_state("domcontentloaded")
            session.beat(1)

        if session.page.locator('[name="kaiproctorattempted"]').count():
            session.page.evaluate(
                """() => document.querySelector('[name="kaiproctorattempted"]')
                         .closest('form').submit()""")
            session.page.wait_for_load_state("domcontentloaded")
            session.beat(1.5)

        session.note("inside the attempt, the timer must be ticking")
        timer = session.page.locator("#quiz-timer, #quiz-time-left")
        assert timer.count(), "no countdown is shown during a timed attempt"

        first = session.page.inner_text("#quiz-time-left")
        session.beat(3)
        second = session.page.inner_text("#quiz-time-left")
        session.note(f"timer read {first!r}, then {second!r}")
        assert first != second, "the countdown is displayed but not counting"
    finally:
        restore_timing(original)


def test_when_time_runs_out_the_paper_is_graded_on_what_was_answered(
        session, clean_learner):
    """The old system's rule, kept: a timed-out paper is not thrown away.

    One question answered correctly out of three, clock expires, and the
    record must show a finished attempt worth exactly that one answer —
    because "you ran out of time so you get nothing" turns a timing rule
    into a grading rule.

    Expiry is simulated by moving the attempt's start into the past and
    running Moodle's own overdue sweep — the same code path a real expiry
    takes through cron, without the test sitting through a real limit.
    """
    clean_learner("learner")
    original = json.loads(moodle("quiz-timing", str(QUIZ_CMID)))

    try:
        moodle("quiz-timing", str(QUIZ_CMID), "300", "0", "0", "0", "autosubmit")

        open_attempt(session)

        session.note("answer only the first question, correctly")
        correct = json.loads(moodle("correct-answers", str(QUIZ_CMID)))
        question = session.page.locator(".que").first
        option = question.locator("div.r0, div.r1").filter(
            has_text=correct[0]).first
        option.locator("input[type=radio]").check()
        session.beat(1)

        # Saved without finishing: the point is that the learner did NOT
        # submit — the clock did.
        session.page.locator('input[name="next"], button[name="next"]').first.click()
        session.page.wait_for_load_state("domcontentloaded")
        session.beat(1.5)

        session.note("push the attempt's start 10 minutes into the past")
        moodle("expire-attempt", "learner", str(QUIZ_CMID), "600")
        moodle("run-overdue-task")
        session.beat(1)

        state = json.loads(moodle("attempt-grade", "learner", str(QUIZ_CMID)))
        session.note(f"after the overdue sweep: {state}")

        assert state["state"] == "finished", \
            f"the expired attempt is {state['state']}, not auto-submitted"
        assert state["sumgrades"] == 1.0, \
            "the timed-out paper was not graded on what was answered"
    finally:
        restore_timing(original)


def test_a_closed_window_refuses_the_attempt(session, clean_learner):
    """timeclose in the past = no way in, and the page says why."""
    clean_learner("learner")
    original = json.loads(moodle("quiz-timing", str(QUIZ_CMID)))

    try:
        session.note("close the quiz an hour ago, from the back office")
        past = int(time.time()) - 3600
        moodle("quiz-timing", str(QUIZ_CMID), "0", "0", str(past), "0",
               "autosubmit")

        moodle("seed-enrolment", "learner")
        moodle("seed-pass", "learner", str(QUIZ_CMID))
        session.login("learner")
        session.goto(f"/mod/quiz/view.php?id={QUIZ_CMID}")
        session.beat(1.5)

        assert not session.page.locator(
            'form[action*="startattempt"] button').count(), \
            "a closed quiz still offers a start button"

        body = session.page.inner_text("body")
        session.note("the page explains the window rather than just refusing")
        assert "ปิด" in body or "closed" in body.lower(), \
            "no explanation of why the quiz cannot be started"
    finally:
        restore_timing(original)


def test_the_attempt_limit_is_enforced(session, clean_learner):
    """attempts=1 means the second sitting does not exist to be started."""
    clean_learner("learner")
    original = json.loads(moodle("quiz-timing", str(QUIZ_CMID)))

    try:
        session.note("allow exactly one attempt, from the back office")
        moodle("quiz-timing", str(QUIZ_CMID), "0", "0", "0", "1", "autosubmit")

        open_attempt(session)

        session.note("finish the attempt without answering")
        session.page.locator('input[name="next"], button[name="next"]').first.click()
        session.page.wait_for_load_state("domcontentloaded")
        session.beat(1)
        session.page.locator("#frm-finishattempt button").first.click()
        session.beat(1.5)
        confirm = session.page.locator(
            '.modal [data-action="save"], .modal-footer .btn-primary')
        if confirm.count():
            confirm.first.click()
        session.page.wait_for_url("**/review.php*", timeout=30_000)
        session.beat(1)

        session.note("back on the quiz page, there must be no second attempt")
        session.goto(f"/mod/quiz/view.php?id={QUIZ_CMID}")
        session.beat(1.5)

        assert not session.page.locator(
            'form[action*="startattempt"] button').count(), \
            "a second attempt is on offer past the limit"
        session.note("the used attempt is the only one")
    finally:
        restore_timing(original)


def test_monitoring_policy_edits_reach_the_lesson_page(session):
    """The interval a proctor sets is the interval the lesson runs under.

    Checked end to end — admin setting to the JS configuration on a monitored
    page — because this is the wiring the whole monitoring feature hangs off:
    a value that saves in the back office but never reaches the page would
    fail silently, with every check still running on the old schedule.
    """
    cmid = moodle("kaivideo-cmid-monitored").strip()
    original = moodle("get-setting", "presenceminutes").strip()

    try:
        session.note("set the presence interval to a distinctive 7 minutes")
        moodle("set-setting", "presenceminutes", "7")

        session.login("learner")
        session.goto(f"/mod/kaivideo/view.php?id={cmid}")
        session.beat(2)

        # The page's AMD init call carries the policy; read it back from what
        # was actually delivered to the browser, not from the database.
        content = session.page.content()
        assert '"presenceminutes":7' in content.replace(" ", ""), \
            "the new interval never reached the page configuration"
        session.note("the monitored page is configured with the new interval")
    finally:
        moodle("set-setting", "presenceminutes", original)
