"""Requirements 1, 3, 4 and 5, on a monitored activity.

Leaving the window pauses the video and is recorded; presence and identity run
on their own schedules; evidence is captured and kept.

These used to run against /local/kaiproctor/lesson.php, a standalone page that
existed because there was nothing else to watch. There is now: an interactive
video is an ordinary course activity, gradeable and completable, and flagging
it monitored is the thing a customer would actually do. The page has gone and
these moved here, which also means they now exercise the path a learner takes
rather than one built for the tests.

The behaviours are unchanged — the same attention_monitor drives both — but the
way in differs: a monitored activity has no start button. It starts on the
learner's first interaction, because getUserMedia needs a user gesture and
opening an activity is not one.
"""
from __future__ import annotations

from conftest import monitored_cmid, moodle, open_monitored

VIDEO = '[data-region="video"]'

# Drives the monitor directly, with the intervals under test passed in.
#
# Kept from the version that ran against the old lesson page, and kept for the
# same reason: what these three tests are about is the monitor's schedule, and
# reaching it through the server's policy would mean waiting real minutes and
# testing the settings form at the same time. The page underneath changed; the
# thing being measured did not.
#
# It makes its own camera and preview because the activity's own monitor may not
# have started — that is a separate test, above.
RUN_MONITOR = """
(config) => new Promise((resolve) => {
    require(['local_kaiproctor/attention_monitor', 'local_kaiproctor/camera'],
    function(AM, Camera) {
        const preview = document.createElement('video');
        preview.setAttribute('playsinline', '');
        preview.muted = true;
        document.body.appendChild(preview);

        const camera = new Camera(preview);
        camera.start().then(function() {
            const video = document.querySelector(config.videoSelector);
            const monitor = new AM(Object.assign({
                video: video,
                contextid: M.cfg.contextid,
                getSnapshot: function() { return camera.snapshot(); },
                getStream: function() { return camera.getStream(); },
                presenceMinutes: 0,
                verifyMinutes: 0,
                clickConfirmMinutes: 0,
                mouseIdleMinutes: 0,
                randomClipsPerHour: 0,
                strictLockdown: false,
                desktopNotification: false
            }, config.options));
            monitor.start();
            video.play().catch(function() { return null; });
            setTimeout(function() { monitor.stop(); resolve(true); }, config.runFor);
        });
    });
})
"""



def test_the_activity_says_it_is_monitored_and_offers_the_camera(session, clean_learner):
    clean_learner("learner")

    session.note("sign in as the learner")
    session.login("learner")

    session.note("open the monitored interactive video")
    session.goto(f"/mod/kaivideo/view.php?id={monitored_cmid()}")
    session.beat(1.5)

    # Told before anything starts, not after the camera is already on.
    assert session.page.locator(".kaiproctor-monitor-banner").count() == 1
    assert session.page.locator(VIDEO).count() == 1

    session.note("start it, and the banner confirms monitoring is running")
    session.page.click("body", position={"x": 5, "y": 5})
    session.page.wait_for_selector(".kaiproctor-monitor-banner.alert-success",
                                   timeout=30_000)
    session.beat(1.5)

    assert session.page.locator(".kaiproctor-preview-attempt").count() == 1


def test_leaving_the_window_pauses_the_video_and_is_recorded(session, clean_learner,
                                                             eventlog):
    """Requirement 1."""
    clean_learner("learner")

    session.note("sign in and open the monitored activity")
    session.login("learner")
    open_monitored(session)

    session.note("play the video so there is something to interrupt")
    session.page.evaluate(f"() => document.querySelector('{VIDEO}').play()")
    session.beat(2)

    session.note("the learner switches away from the window")
    session.page.evaluate("() => window.dispatchEvent(new Event('blur'))")
    session.beat(2.5)

    state = session.page.evaluate(
        """(selector) => {
            const overlay = document.querySelector('.kaiproctor-overlay');
            const video = document.querySelector(selector);
            return {
                paused: video ? video.paused : null,
                overlay: !!overlay,
                blocking: overlay ? overlay.dataset.blocking : null
            };
        }""", VIDEO)
    session.note(f"after leaving the window: {state}")

    assert state["paused"] is True, "the video kept playing while they were away"
    assert state["overlay"] is True, "nothing told them why it stopped"

    trail = eventlog("learner")
    assert "window_blur" in trail, "leaving the window was not recorded"


def test_a_violation_captures_evidence(session, clean_learner, eventlog):
    """Requirement 5: the event is not the evidence.

    A line in a log saying somebody left the window is a claim. The snapshot
    taken at that moment is what makes it answerable months later.
    """
    clean_learner("learner")

    session.login("learner")
    open_monitored(session)

    session.note("leave the window, which is a policy breach")
    session.page.evaluate("() => window.dispatchEvent(new Event('blur'))")
    session.beat(4)

    eventlog("learner")
    stored = moodle("evidence", "learner")
    session.note(f"evidence rows: {stored.strip()[:200]}")

    assert "no evidence" not in stored, "a breach was recorded with nothing kept"


def test_presence_check_runs_on_its_interval(session, clean_learner, eventlog):
    """Requirement 3, the presence half."""
    clean_learner("learner")

    session.login("learner")
    session.goto(f"/mod/kaivideo/view.php?id={monitored_cmid()}")
    session.beat(1.5)

    session.note("run the monitor with a two-second presence interval")
    session.page.evaluate(RUN_MONITOR, {"videoSelector": VIDEO,
                                        "options": {"presenceMinutes": 1 / 30},
                                        "runFor": 8000})
    session.beat(2)

    log = eventlog("learner")
    assert "face_absent" in log, "no presence check ran"

    # The fake camera shows no face, so the video must not keep playing.
    paused = session.page.evaluate(
        "(selector) => document.querySelector(selector).paused", VIDEO)
    session.note(f"paused after the learner could not be seen: {paused}")
    assert paused is True


def test_identity_check_runs_on_its_own_interval(session, clean_learner, eventlog):
    """Requirement 4, and only for somebody who has enrolled a face."""
    clean_learner("learner")
    moodle("seed-enrolment", "learner")

    session.login("learner")
    session.goto(f"/mod/kaivideo/view.php?id={monitored_cmid()}")
    session.beat(1.5)

    session.note("run the monitor with a three-second identity interval")
    session.page.evaluate(RUN_MONITOR, {"videoSelector": VIDEO,
                                        "options": {"verifyMinutes": 1 / 20},
                                        "runFor": 10000})
    session.beat(2)

    eventlog("learner")
    checks = moodle("checks", "learner")
    session.note(f"check rows written server-side: {checks.strip()[:300]}")

    # With no face in frame the check must be recorded as absent — a missing
    # learner is a presence problem, not evidence of impersonation.
    assert "identity" in checks, "no identity check reached the server"
    assert "absent" in checks, "a frame with no face was not recorded as absent"


def test_a_random_clip_is_recorded_and_stored(session, clean_learner, eventlog):
    """Requirement 5, the unannounced half.

    Timing is randomised so it cannot be anticipated, which is what makes it
    awkward to test: the rate is turned up rather than the randomness turned
    off, so what runs is the real mechanism.
    """
    clean_learner("learner")

    session.login("learner")
    session.goto(f"/mod/kaivideo/view.php?id={monitored_cmid()}")
    session.beat(1.5)

    session.note("run the monitor with clips scheduled about once a second")
    session.page.evaluate(RUN_MONITOR, {"videoSelector": VIDEO,
                                        "options": {"randomClipsPerHour": 3600,
                                                    "clipSeconds": 2},
                                        "runFor": 10000})
    session.beat(3)

    log = eventlog("learner")
    assert "clip_started" in log, "no clip was ever started"
    assert "clip_uploaded" in log, "a clip was recorded but never stored"

    stored = moodle("evidence", "learner")
    session.note(f"evidence rows: {stored.strip()[:300]}")
    assert "random_sample" in stored
    assert ".webm" in stored


def test_the_learner_is_asked_to_confirm_they_are_still_there(session, clean_learner,
                                                              eventlog):
    """A camera sees somebody in front of it. It does not see somebody paying
    attention, which is why the learner is asked to press a button.

    The monitor is left running rather than stopped after a fixed time: what is
    being waited for is the prompt appearing, and stopping the monitor first
    would be waiting for something that has been switched off.
    """
    clean_learner("learner")

    session.login("learner")
    session.goto(f"/mod/kaivideo/view.php?id={monitored_cmid()}")
    session.beat(1.5)

    session.note("run the monitor with a confirmation due every two seconds")
    session.page.evaluate(
        """(selector) => new Promise((resolve) => {
            require(['local_kaiproctor/attention_monitor'], function(AM) {
                const video = document.querySelector(selector);
                window.__monitor = new AM({
                    video: video,
                    contextid: M.cfg.contextid,
                    presenceMinutes: 0,
                    verifyMinutes: 0,
                    clickConfirmMinutes: 1 / 30,
                    clickConfirmGraceSec: 30,
                    mouseIdleMinutes: 0,
                    randomClipsPerHour: 0,
                    desktopNotification: false
                });
                window.__monitor.start();
                video.play().catch(function() { return null; });
                resolve(true);
            });
        })""", VIDEO)

    session.note("wait for the confirmation prompt")
    session.page.wait_for_selector(".kaiproctor-overlay", timeout=25_000)
    session.beat(2)

    overlay = session.page.evaluate(
        """() => {
            const o = document.querySelector('.kaiproctor-overlay');
            return {
                title: o.querySelector('.kaiproctor-overlay-title').textContent,
                blocking: o.dataset.blocking
            };
        }""")
    session.note(f"the learner is asked: {overlay}")

    assert "ยืนยัน" in overlay["title"]
    # Not blocking: they are being asked to confirm, not shut out. The video
    # pausing is the consequence of ignoring it, not of being asked.
    assert overlay["blocking"] == "false"

    session.note("the learner presses the button")
    session.page.click(".kaiproctor-overlay button")
    session.beat(2)

    log = eventlog("learner")
    assert "click_confirm_ok" in log, "confirming was not recorded"
