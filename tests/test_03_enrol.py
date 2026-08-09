"""Face enrolment.

Chromium's fake camera produces frames with no face in them. That is enough to
prove the challenge runs, polls the service, and refuses to enrol when it
cannot see a person — which is the behaviour that matters most here. It cannot
prove that a real face enrols successfully; nothing in this file claims it does.
"""
from __future__ import annotations

from conftest import moodle


def test_enrol_page_explains_what_will_happen(session, clean_learner):
    clean_learner("learner")

    session.note("sign in as the learner")
    session.login("learner")

    session.note("open the enrolment page")
    session.goto("/local/kaiproctor/enrol.php")

    text = session.body_text()
    assert "ลงทะเบียนใบหน้า" in text
    assert "มองตรงเข้ากล้อง" in text or "ทำตามคำสั่ง" in text
    assert session.page.locator('[data-region="preview"]').count() == 1
    assert session.page.locator('[data-action="start"]').is_enabled()


def test_challenge_asks_for_a_randomised_sequence(session, clean_learner):
    clean_learner("learner")

    session.note("sign in and open the enrolment page")
    session.login("learner")
    session.goto("/local/kaiproctor/enrol.php")

    session.note("read the sequence the module generated")
    sequences = session.page.evaluate(
        """() => new Promise((resolve) => {
            require(['local_kaiproctor/active_liveness'], function(AL) {
                const runs = [];
                for (let i = 0; i < 12; i++) {
                    runs.push(new AL({getSnapshot: function() {}}).steps.join('>'));
                }
                resolve(runs);
            });
        })"""
    )
    session.note(f"twelve generated sequences: {set(sequences)}")

    # Looking straight ahead is always first — that frame becomes the
    # reference — but the turns must not be predictable.
    assert all(order.startswith("center>") for order in sequences)
    assert len(set(sequences)) > 1, "the pose order never varied over twelve runs"


def test_enrolment_is_refused_when_no_face_is_visible(session, clean_learner, eventlog):
    clean_learner("learner")

    session.note("sign in and open the enrolment page")
    session.login("learner")
    session.goto("/local/kaiproctor/enrol.php")

    session.note("start the challenge in front of a camera showing no face")
    session.page.click('[data-action="start"]')

    # The first pose has its own timeout; the module gives up when it expires.
    session.note("wait for the pose to time out")
    session.page.wait_for_selector(
        '[data-region="status"]:not([hidden])', timeout=40_000
    )
    session.beat(2)

    status = session.page.inner_text('[data-region="status"]')
    session.note(f"status shown to the learner: {status}")
    assert "ไม่สำเร็จ" in status or "ไม่ทัน" in status

    # Nothing may be stored for a challenge that was never passed.
    assert moodle("count", "face", "learner") == "0", (
        "an embedding was stored despite the challenge failing"
    )
    eventlog("learner")


def test_the_page_reports_a_camera_that_will_not_start(session, clean_learner):
    clean_learner("learner")

    session.note("sign in and open the enrolment page")
    session.login("learner")
    session.goto("/local/kaiproctor/enrol.php")

    session.note("simulate the camera being unavailable")
    session.page.evaluate(
        """() => {
            navigator.mediaDevices.getUserMedia = () => Promise.reject(new Error('nocamera'));
        }"""
    )
    session.page.click('[data-action="start"]')
    session.page.wait_for_selector('[data-region="status"]:not([hidden])', timeout=15_000)
    session.beat(1.5)

    status = session.page.inner_text('[data-region="status"]')
    session.note(f"status shown to the learner: {status}")
    assert "กล้อง" in status
    # A dead end is not acceptable: the learner is told what to do about it.
    assert "อนุญาต" in status or "HTTPS" in status
