"""Sittings: one monitored session, with the rules that governed it.

The point of recording a sitting is that months later somebody can ask "what
was actually enforced when this decision was made" and get an answer that is
not "whatever is configured today".
"""
from __future__ import annotations

import json

import pytest

from conftest import QUIZ_CMID, monitored_cmid, moodle, open_monitored


@pytest.fixture
def strict_lessons():
    """End a lesson on the first breach, which is not what a site does.

    Two settings, because ending a sitting needs both: the strict reading
    (which lessons do not get by default, since ending one protects nothing —
    see session::current_policy) and no tolerance left (a site that allows two
    focus losses does not end anything on the first).

    Pinned here rather than assumed, and restored to whatever the site had.
    The tests below are about what a terminated sitting looks like; they should
    not also depend on how forgiving this particular deployment is configured
    to be, which is a number an administrator is free to change.
    """
    before = json.loads(moodle("current-policy"))
    moodle("set-setting", "lessonstrictlockdown", "1")
    moodle("set-setting", "blurallowance", "0")
    yield
    moodle("set-setting", "lessonstrictlockdown",
           "1" if before["strictlockdown"] else "0")
    moodle("set-setting", "blurallowance", str(int(before["blurallowance"])))


def test_starting_a_lesson_opens_one_sitting(session, clean_learner):
    clean_learner("learner")

    session.note("sign in and open the monitored activity")
    session.login("learner")
    session.note("open the monitored activity and let the monitor start")
    open_monitored(session)
    session.beat(2)

    sittings = json.loads(moodle("sessions", "learner"))
    session.note(f"sittings: {sittings}")

    assert len(sittings) == 1, "starting the lesson did not open exactly one sitting"
    assert sittings[0]["status"] == "active"


def test_reloading_does_not_start_a_second_sitting(session, clean_learner):
    """A reload or a second tab must not split one sitting's evidence in two."""
    clean_learner("learner")

    session.note("sign in and start the lesson")
    session.login("learner")
    open_monitored(session)
    session.beat(1.5)

    first = json.loads(moodle("sessions", "learner"))

    session.note("reload and start again")
    open_monitored(session)
    session.beat(2)

    second = json.loads(moodle("sessions", "learner"))
    session.note(f"sittings after reload: {second}")

    assert len(second) == 1, "the reload opened a second sitting"
    assert second[0]["id"] == first[0]["id"]


def test_the_rules_in_force_are_recorded_on_the_sitting(session, clean_learner):
    clean_learner("learner")

    session.note("sign in and start the lesson")
    session.login("learner")
    open_monitored(session)
    session.beat(2)

    sittings = json.loads(moodle("sessions", "learner"))
    policy = sittings[0]["policy"]
    session.note(f"recorded policy: {policy}")

    # Everything the monitor's behaviour depends on has to be in the snapshot,
    # including how strict the face comparison was.
    for key in ["presenceseconds", "verifyseconds", "clickconfirmseconds",
                "mouseidleseconds", "randomclipsperhour", "blurallowance",
                "strictlockdown", "matchthreshold", "reviewmin"]:
        assert key in policy, f"the snapshot does not record {key}"


def test_changing_the_settings_does_not_rewrite_a_finished_sitting(session, clean_learner):
    """The whole reason the snapshot exists."""
    clean_learner("learner")

    session.note("sign in and run a lesson under the current rules")
    session.login("learner")
    open_monitored(session)
    session.beat(2)

    before = json.loads(moodle("sessions", "learner"))[0]
    original = before["policy"]["verifyseconds"]
    session.note(f"identity check interval at the time: {original}")

    try:
        session.note("an administrator later changes the interval")
        moodle("set-setting", "verifyseconds", "99")

        after = json.loads(moodle("sessions", "learner"))[0]
        session.note(f"the sitting still records: {after['policy']['verifyseconds']}")

        assert after["policy"]["verifyseconds"] == original, (
            "changing the settings rewrote what a past sitting was governed by"
        )
        # And a new sitting picks the new value up, or the snapshot would be
        # recording something nobody is enforcing.
        assert json.loads(moodle("current-policy"))["verifyseconds"] == 99.0
    finally:
        moodle("set-setting", "verifyseconds", str(original))


def test_a_terminated_sitting_records_why(session, clean_learner, strict_lessons):
    clean_learner("learner")

    session.note("sign in and start the lesson")
    session.login("learner")
    open_monitored(session)
    session.beat(1.5)

    session.note("the learner leaves the window, which ends it in strict mode")
    session.page.evaluate("() => window.dispatchEvent(new Event('blur'))")
    session.beat(4)

    sittings = json.loads(moodle("sessions", "learner"))
    session.note(f"sitting after the breach: {sittings}")

    assert sittings[0]["status"] == "terminated"
    assert sittings[0]["reason"] == "window_blur"
    assert sittings[0]["timeend"], "a closed sitting has no end time"


def test_a_late_completion_cannot_launder_a_terminated_sitting(session, clean_learner,
                                                               strict_lessons):
    """A client that gets cut off must not be able to report the sitting as a
    clean finish afterwards."""
    clean_learner("learner")

    session.note("sign in and start the lesson")
    session.login("learner")
    open_monitored(session)
    session.beat(1.5)

    sittings = json.loads(moodle("sessions", "learner"))
    sessionid = sittings[0]["id"]

    session.note("the sitting is terminated")
    session.page.evaluate("() => window.dispatchEvent(new Event('blur'))")
    session.beat(3.5)

    session.note("the client then claims it completed normally")
    result = session.page.evaluate(
        """(sessionid) => new Promise((resolve) => {
            require(['local_kaiproctor/api'], function(Api) {
                Api.endSession(sessionid, 'completed', 'faked')
                   .then(r => resolve(r)).catch(e => resolve({error: String(e)}));
            });
        })""",
        sessionid,
    )
    session.note(f"server response: {result}")
    session.beat(1)

    after = json.loads(moodle("sessions", "learner"))
    assert after[0]["status"] == "terminated", "a terminated sitting was laundered into a clean one"


def test_a_client_cannot_mark_a_sitting_abandoned(session, clean_learner):
    """'abandoned' belongs to the cleanup task: it means nobody was there to
    end it, which a client that is plainly there must not be able to claim."""
    clean_learner("learner")

    session.note("sign in and start the lesson")
    session.login("learner")
    open_monitored(session)
    session.beat(1.5)

    sessionid = json.loads(moodle("sessions", "learner"))[0]["id"]

    result = session.page.evaluate(
        """(sessionid) => new Promise((resolve) => {
            require(['local_kaiproctor/api'], function(Api) {
                Api.endSession(sessionid, 'abandoned', 'x')
                   .then(r => resolve(r)).catch(e => resolve({error: String(e)}));
            });
        })""",
        sessionid,
    )
    session.note(f"server response: {result}")

    assert result.get("ok") is False
    assert result.get("errorcode") == "invalid_status"


def test_a_sitting_nobody_closed_is_marked_abandoned(session, clean_learner):
    clean_learner("learner")

    session.note("sign in and start the lesson")
    session.login("learner")
    open_monitored(session)
    session.beat(1.5)

    session.note("the learner's machine goes away without closing anything")
    moodle("age-session", "learner", "3")

    session.note("the cleanup task runs")
    session.note(moodle("run-stale-task"))

    sittings = json.loads(moodle("sessions", "learner"))
    session.note(f"sitting afterwards: {sittings}")

    assert sittings[0]["status"] == "abandoned"
    assert sittings[0]["reason"] == "no_activity"


def test_checks_and_evidence_are_filed_under_the_sitting(session, clean_learner):
    clean_learner("learner")

    session.note("sign in and start the lesson")
    session.login("learner")
    open_monitored(session)
    session.beat(1.5)

    sessionid = json.loads(moodle("sessions", "learner"))[0]["id"]

    session.note("a breach captures evidence")
    session.page.evaluate("() => window.dispatchEvent(new Event('blur'))")
    session.beat(4)

    unfiled = moodle("unfiled", "learner")
    session.note(f"records not attached to any sitting: {unfiled}")
    assert unfiled == "0", "evidence was recorded without a sitting to file it under"

    filed = moodle("filed-under", "learner", str(sessionid))
    session.note(f"records under sitting {sessionid}: {filed}")
    assert int(filed) >= 1


def test_the_report_groups_everything_by_sitting(session, clean_learner, strict_lessons):
    clean_learner("learner")

    session.note("sign in and run a lesson that gets terminated")
    session.login("learner")
    open_monitored(session)
    session.beat(1.5)
    session.page.evaluate("() => window.dispatchEvent(new Event('blur'))")
    session.beat(4)

    contextid = moodle("cm-context-id", str(monitored_cmid())).strip()
    userid = moodle("user-id", "learner")

    session.note("open the evidence report")
    session.goto(f"/local/kaiproctor/report.php?userid={userid}&contextid={contextid}")
    session.beat(2)

    assert session.page.locator('[data-region="session"]').count() == 1

    text = session.body_text()
    session.note("the sitting shows its outcome")
    assert "ถูกระบบยุติ" in text
    assert "window_blur" in text
    assert "กฎที่บังคับใช้ระหว่างการเรียนครั้งนี้" in text

    session.note("open the recorded rules, the way an auditor would")
    session.page.locator("details summary").first.click()
    session.beat(1.5)

    opened = session.body_text()
    # The snapshot is what an auditor reads, so the page has to say plainly
    # that these are historic figures, not the current settings.
    assert "การแก้การตั้งค่าตอนนี้ไม่เปลี่ยนค่านี้" in opened
    assert "เกณฑ์ผ่าน" in opened, "the threshold in force is not part of the snapshot shown"


def test_an_exam_attempt_is_its_own_sitting(session, clean_learner):
    clean_learner("learner")
    moodle("seed-enrolment", "learner")
    moodle("seed-pass", "learner", str(QUIZ_CMID))

    session.note("sign in and open the proctored quiz")
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

    session.note("the learner interacts, starting the camera and the sitting")
    session.page.locator(".que input[type=radio]").first.click()
    session.beat(4)

    sittings = json.loads(moodle("sessions", "learner"))
    session.note(f"sittings: {sittings}")

    exam = [s for s in sittings if s["attemptid"]]
    assert exam, "the attempt did not open a sitting tied to it"
    assert exam[0]["status"] == "active"
