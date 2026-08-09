"""Requirements 1, 3, 4 and 5 on the monitored lesson page.

Leaving the lesson window pauses the video and is recorded; presence and
identity run on their own schedules; evidence is captured and kept.
"""
from __future__ import annotations

from conftest import moodle


def test_lesson_page_offers_the_video_and_a_camera_preview(session, clean_learner):
    clean_learner("learner")

    session.note("sign in as the learner")
    session.login("learner")

    session.note("open the monitored lesson")
    session.goto("/local/kaiproctor/lesson.php")

    assert session.page.locator('[data-region="lesson-video"]').count() == 1
    assert session.page.locator('[data-region="preview"]').count() == 1
    assert session.page.locator('[data-action="start"]').is_enabled()

    # A learner with no enrolled face is told identity checks are off rather
    # than being failed by them every ten minutes.
    text = session.body_text()
    assert "ยังไม่ได้ลงทะเบียนใบหน้า" in text


def test_leaving_the_window_pauses_the_video_and_is_recorded(session, clean_learner, eventlog):
    """Requirement 1."""
    clean_learner("learner")

    session.note("sign in and open the monitored lesson")
    session.login("learner")
    session.goto("/local/kaiproctor/lesson.php")

    session.note("start the lesson under monitoring")
    session.page.click('[data-action="start"]')
    session.page.wait_for_selector('[data-region="status"]:not([hidden])', timeout=20_000)
    session.beat(2)

    playing = session.page.evaluate(
        """() => !document.querySelector('[data-region="lesson-video"]').paused"""
    )
    session.note(f"video playing after start: {playing}")

    session.note("the learner switches away from the lesson window")
    session.page.evaluate("() => window.dispatchEvent(new Event('blur'))")
    session.beat(2.5)

    state = session.page.evaluate(
        """() => {
            const overlay = document.querySelector('.kaiproctor-overlay');
            return {
                paused: document.querySelector('[data-region="lesson-video"]').paused,
                overlay: !!overlay,
                blocking: overlay ? overlay.dataset.blocking : null,
                title: overlay ? overlay.querySelector('.kaiproctor-overlay-title').textContent : null,
                message: overlay ? overlay.querySelector('.kaiproctor-overlay-message').textContent : null
            };
        }"""
    )
    session.note(f"after leaving the window: {state}")

    assert state["paused"] is True, "the video kept playing after the learner left"
    assert state["overlay"] is True
    assert state["blocking"] == "true", "the learner could see the lesson through the overlay"
    assert "ออกจากหน้าต่างเรียน" in state["message"]

    log = eventlog("learner")
    session.note("audit trail written to reports/eventlog")
    assert "monitor_started" in log
    assert "window_blur" in log


def test_a_violation_captures_evidence(session, clean_learner, eventlog):
    """Requirement 5."""
    clean_learner("learner")

    session.note("sign in and start the monitored lesson")
    session.login("learner")
    session.goto("/local/kaiproctor/lesson.php")
    session.page.click('[data-action="start"]')
    session.page.wait_for_selector('[data-region="status"]:not([hidden])', timeout=20_000)
    session.beat(1.5)

    session.note("the learner leaves the window, which should be photographed")
    session.page.evaluate("() => window.dispatchEvent(new Event('blur'))")
    session.beat(3.5)

    stored = moodle("evidence", "learner")
    session.note(f"evidence rows: {stored}")
    assert "violation_window_blur" in stored, "no photograph was kept for the violation"
    eventlog("learner")


# Runs one kind of check on a short interval and stops. The two checks are
# exercised separately on purpose: the first failed presence check pauses the
# video, and a paused lesson stops every other check by design, so running
# both at once would only ever prove whichever fired first.
RUN_MONITOR = """
(config) => new Promise((resolve) => {
    require(['local_kaiproctor/attention_monitor', 'local_kaiproctor/camera'],
    function(AM, Camera) {
        const camera = new Camera(document.querySelector('[data-region="preview"]'));
        camera.start().then(function() {
            const video = document.querySelector('[data-region="lesson-video"]');
            const monitor = new AM(Object.assign({
                video: video,
                contextid: M.cfg.contextid,
                getSnapshot: function() { return camera.snapshot(); },
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


def test_presence_check_runs_on_its_interval_and_pauses_the_lesson(session, clean_learner, eventlog):
    """Requirement 3, the presence half."""
    clean_learner("learner")

    session.note("sign in and open the lesson")
    session.login("learner")
    session.goto("/local/kaiproctor/lesson.php")

    session.note("run the monitor with a two-second presence interval")
    session.page.evaluate(RUN_MONITOR, {"options": {"presenceMinutes": 1 / 30}, "runFor": 8000})
    session.beat(2)

    log = eventlog("learner")
    assert "face_absent" in log, "no presence check ran"

    # The fake camera shows no face, so the lesson must not keep playing.
    paused = session.page.evaluate(
        """() => document.querySelector('[data-region="lesson-video"]').paused"""
    )
    session.note(f"lesson paused after the learner could not be seen: {paused}")
    assert paused is True


def test_identity_check_runs_on_its_own_interval(session, clean_learner, eventlog):
    """Requirement 3, the identity half."""
    clean_learner("learner")
    moodle("seed-enrolment", "learner")

    session.note("sign in and open the lesson")
    session.login("learner")
    session.goto("/local/kaiproctor/lesson.php")

    session.note("run the monitor with a three-second identity interval and presence off")
    session.page.evaluate(RUN_MONITOR, {"options": {"verifyMinutes": 1 / 20}, "runFor": 10000})
    session.beat(2)

    eventlog("learner")
    checks = moodle("checks", "learner")
    session.note(f"check rows written server-side: {checks}")

    # With no face in frame the check must be recorded as absent — a missing
    # learner is a presence problem, not evidence of impersonation.
    assert "identity" in checks, "no identity check reached the server"
    assert "absent" in checks, "a frame with no face was not recorded as absent"


def test_a_random_clip_is_recorded_and_stored(session, clean_learner, eventlog):
    """Requirement 5, the sampling half."""
    clean_learner("learner")

    session.note("sign in and open the lesson")
    session.login("learner")
    session.goto("/local/kaiproctor/lesson.php")

    session.note("run the monitor with clips scheduled frequently")
    session.page.evaluate(
        """() => new Promise((resolve) => {
            require(['local_kaiproctor/attention_monitor', 'local_kaiproctor/camera'],
            function(AM, Camera) {
                const camera = new Camera(document.querySelector('[data-region="preview"]'));
                camera.start().then(function() {
                    const monitor = new AM({
                        video: document.querySelector('[data-region="lesson-video"]'),
                        contextid: M.cfg.contextid,
                        getSnapshot: function() { return camera.snapshot(); },
                        getStream: function() { return camera.getStream(); },
                        presenceMinutes: 0,
                        verifyMinutes: 0,
                        clickConfirmMinutes: 0,
                        mouseIdleMinutes: 0,
                        randomClipsPerHour: 3600,   // roughly one a second
                        clipSeconds: 2,
                        desktopNotification: false
                    });
                    monitor.start();
                    setTimeout(function() { monitor.stop(); resolve(true); }, 10000);
                });
            });
        })"""
    )
    session.beat(3)

    log = eventlog("learner")
    assert "clip_started" in log, "no clip was ever started"
    assert "clip_uploaded" in log, "a clip was recorded but never stored"

    stored = moodle("evidence", "learner")
    session.note(f"evidence rows: {stored}")
    assert "random_sample" in stored
    assert ".webm" in stored


def test_the_learner_is_asked_to_confirm_they_are_still_there(session, clean_learner, eventlog):
    """Requirement 4, the in-page half. OS notifications need a real desktop."""
    clean_learner("learner")

    session.note("sign in and open the lesson")
    session.login("learner")
    session.goto("/local/kaiproctor/lesson.php")

    session.note("run the monitor with a very short confirmation interval")
    session.page.evaluate(
        """() => new Promise((resolve) => {
            require(['local_kaiproctor/attention_monitor'], function(AM) {
                const video = document.querySelector('[data-region="lesson-video"]');
                window.__monitor = new AM({
                    video: video,
                    contextid: M.cfg.contextid,
                    presenceMinutes: 0,
                    verifyMinutes: 0,
                    clickConfirmMinutes: 1 / 30,      // every two seconds
                    clickConfirmGraceSec: 30,
                    mouseIdleMinutes: 0,
                    randomClipsPerHour: 0,
                    desktopNotification: false
                });
                window.__monitor.start();
                video.play().catch(function() { return null; });
                resolve(true);
            });
        })"""
    )

    session.note("wait for the confirmation prompt")
    session.page.wait_for_selector(".kaiproctor-overlay", timeout=20_000)
    session.beat(2)

    overlay = session.page.evaluate(
        """() => {
            const o = document.querySelector('.kaiproctor-overlay');
            return {
                title: o.querySelector('.kaiproctor-overlay-title').textContent,
                button: o.querySelector('button').textContent,
                blocking: o.dataset.blocking
            };
        }"""
    )
    session.note(f"prompt shown: {overlay}")
    assert "ยืนยัน" in overlay["title"]
    # Non-blocking: the lesson keeps playing while they have time to respond.
    assert overlay["blocking"] == "false"

    session.note("the learner confirms")
    session.page.click(".kaiproctor-overlay button")
    session.beat(2)
    session.page.evaluate("() => window.__monitor.stop()")

    log = eventlog("learner")
    assert "click_confirm_ok" in log
