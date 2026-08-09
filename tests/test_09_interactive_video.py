"""Proctoring attached to an interactive video activity.

The interactive video itself is mod_interactivevideo (GPL-3): annotations,
in-video questions, and 22 player backends we did not write. What is tested
here is the seam — that flagging an activity as proctored actually watches the
learner, that the player is driven through its published interface rather than
its internals, and that staff are not watched.
"""
from __future__ import annotations

from conftest import INTERACTIVE_VIDEO_CMID as IV_CMID
from conftest import moodle


def test_the_activity_says_it_is_proctored_before_anything_starts(session, clean_learner):
    clean_learner("learner")

    session.note("sign in as the learner")
    session.login("learner")

    session.note("open the interactive video")
    session.goto(f"/mod/interactivevideo/view.php?id={IV_CMID}")
    session.beat(2)

    banner = session.page.locator(".kaiproctor-monitor-banner")
    assert banner.count() == 1, "the learner was not told the activity is proctored"
    text = banner.inner_text()
    session.note(f"banner: {text}")
    assert "คุมสอบ" in text
    # The camera must not have started yet — consent to being watched is the
    # act of beginning, and getUserMedia needs a gesture anyway.
    assert session.page.locator(".kaiproctor-preview-attempt").count() == 0


def test_monitoring_starts_when_the_learner_begins(session, clean_learner, eventlog):
    clean_learner("learner")

    session.note("sign in and open the interactive video")
    session.login("learner")
    session.goto(f"/mod/interactivevideo/view.php?id={IV_CMID}")
    session.beat(2)

    session.note("the learner starts interacting")
    session.page.mouse.click(640, 400)
    session.page.wait_for_selector(".kaiproctor-preview-attempt", timeout=25_000)
    session.beat(3)

    banner = session.page.locator(".kaiproctor-monitor-banner")
    session.note(f"banner now: {banner.inner_text()}")
    assert "alert-success" in (banner.get_attribute("class") or "")

    log = eventlog("learner")
    assert "monitor_started" in log, "the monitor never started"


def test_the_video_player_is_found_through_its_published_interface(session, clean_learner):
    """mod_interactivevideo exposes window.IVPLAYER on purpose.

    Reaching past that into its internals would break on its next release, so
    the adapter uses only the play/pause/getCurrentTime/isPaused contract that
    every one of its backends implements.
    """
    clean_learner("learner")

    session.note("sign in and open the interactive video")
    session.login("learner")
    session.goto(f"/mod/interactivevideo/view.php?id={IV_CMID}")
    session.beat(2)
    session.page.mouse.click(640, 400)
    session.beat(4)

    surface = session.page.evaluate(
        """() => new Promise((resolve) => {
            require(['local_kaiproctor/video_adapter'], function(VideoAdapter) {
                VideoAdapter.forPage(5000).then(function(adapter) {
                    if (!adapter) { resolve({found: false}); return; }
                    resolve({
                        found: true,
                        hasPause: typeof adapter.pause === 'function',
                        hasPlay: typeof adapter.play === 'function',
                        pausedIsBoolean: typeof adapter.paused === 'boolean',
                        timeIsNumber: typeof adapter.currentTime === 'number',
                        ivplayer: !!window.IVPLAYER
                    });
                    return null;
                });
            });
        })"""
    )
    session.note(f"adapter surface: {surface}")

    assert surface["found"] is True, "nothing playable was found on the page"
    assert surface["hasPause"] and surface["hasPlay"]
    assert surface["pausedIsBoolean"], "paused must be readable synchronously by the monitor"
    assert surface["timeIsNumber"]


def test_leaving_the_activity_window_is_recorded(session, clean_learner, eventlog):
    clean_learner("learner")

    session.note("sign in and start the interactive video")
    session.login("learner")
    session.goto(f"/mod/interactivevideo/view.php?id={IV_CMID}")
    session.beat(2)
    session.page.mouse.click(640, 400)
    session.page.wait_for_selector(".kaiproctor-preview-attempt", timeout=25_000)
    session.beat(2)

    session.note("the learner switches away")
    session.page.evaluate("() => window.dispatchEvent(new Event('blur'))")
    session.beat(3)

    log = eventlog("learner")
    session.note("the departure reached the audit trail")
    assert "window_blur" in log
    # Strict mode is on by default, so leaving ends the session outright.
    assert "session_terminated" in log


def test_staff_viewing_the_activity_are_not_monitored(session):
    """A teacher checking their own activity is not sitting an assessment."""
    session.note("sign in as the instructor")
    session.login("instructor")

    session.note("open the same interactive video")
    session.goto(f"/mod/interactivevideo/view.php?id={IV_CMID}")
    session.beat(2.5)

    assert session.page.locator(".kaiproctor-monitor-banner").count() == 0, (
        "staff were told they are being proctored"
    )
    assert session.page.locator(".kaiproctor-preview-attempt").count() == 0


def test_staff_can_turn_proctoring_off_and_on(session, clean_learner):
    session.note("sign in as the instructor")
    session.login("instructor")

    session.note("open the proctoring setting for the activity")
    session.goto(f"/local/kaiproctor/monitor.php?cmid={IV_CMID}")
    session.beat(1.5)

    assert "เปิดการคุมสอบอยู่" in session.body_text()

    session.note("turn it off")
    session.page.locator('button[type="submit"], input[type="submit"]').first.click()
    session.page.wait_for_load_state("domcontentloaded")
    session.beat(1.5)
    assert moodle("monitored", str(IV_CMID)) == "no"
    assert "ยังไม่ได้เปิด" in session.body_text()

    session.note("turn it back on")
    session.page.locator('button[type="submit"], input[type="submit"]').first.click()
    session.page.wait_for_load_state("domcontentloaded")
    session.beat(1.5)
    assert moodle("monitored", str(IV_CMID)) == "yes"


def test_an_unmonitored_activity_is_left_alone(session, clean_learner):
    clean_learner("learner")
    moodle("set-monitored", str(IV_CMID), "0")

    try:
        session.note("sign in as the learner with proctoring switched off")
        session.login("learner")
        session.goto(f"/mod/interactivevideo/view.php?id={IV_CMID}")
        session.beat(2.5)

        assert session.page.locator(".kaiproctor-monitor-banner").count() == 0
        session.page.mouse.click(640, 400)
        session.beat(3)
        assert session.page.locator(".kaiproctor-preview-attempt").count() == 0
        assert moodle("events", "learner").startswith("(no proctoring events")
    finally:
        moodle("set-monitored", str(IV_CMID), "1")
