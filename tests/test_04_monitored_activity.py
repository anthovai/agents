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

import json

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
                presenceSeconds: 0,
                verifySeconds: 0,
                clickConfirmSeconds: 0,
                mouseIdleSeconds: 0,
                randomClipsPerHour: 0,
                strictLockdown: false,
                desktopNotification: false
            }, config.options));
            monitor.start();
            // Back to the start first. The activity resumes wherever this
            // learner got to last time, and these tests have watched it often
            // enough to pin it at the end — where play() lands on a video that
            // is already finished, the monitor's tick sees a paused video and
            // skips every check that only applies while a lesson is running.
            // The symptom is a presence test reporting that no check ran.
            video.currentTime = 0;
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


def test_being_sent_out_of_the_lesson_does_not_lose_the_watched_position(
        session, clean_learner):
    """Where the video stops is where they come back to.

    The monitor pauses the video and sends the learner out of the activity.
    Until this was fixed, the server's record of how far they had watched was
    whatever the last fifteen-second tick had written — so a learner cut off at
    28 seconds was returned to 15 and made to sit through it again, as a
    consequence of being interrupted rather than of anything they did.

    Measured rather than argued: the position on the way out is compared with
    what the server kept, and the gap must be under a second.
    """
    clean_learner("learner")
    cmid = monitored_cmid()

    # clean_learner does not touch this activity's progress, and without the
    # reset the check reads a furthest point left by an earlier run — which
    # sails past the assertion no matter what the code does. The first version
    # of this test passed against the unfixed player for exactly that reason.
    moodle("kaivideo-reset", "learner", str(cmid))

    # Answered up front so playback is uninterrupted: a question falling due
    # pauses the video, and what is being measured here is the watched
    # position, not the question rule.
    timeline = json.loads(moodle("kaivideo-timeline", str(cmid)))
    for index, item in enumerate(timeline):
        response = ("" if item["type"] == "info"
                    else item["answerlabel"].split(" / ")[0]
                    if item["type"] == "shorttext"
                    else json.dumps(item["answers"]))
        moodle("kaivideo-answer", "learner", str(cmid), str(index), response)

    session.login("learner")
    open_monitored(session)

    session.note("watch for a while, past the first progress tick")
    session.page.evaluate(f"() => document.querySelector('{VIDEO}').play()")
    session.beat(18)

    left_at = session.page.evaluate(
        f"() => document.querySelector('{VIDEO}').currentTime")
    session.note(f"the learner has watched to {left_at:.1f}s")

    session.note("they switch away, and the monitor takes them out")
    session.page.evaluate("() => window.dispatchEvent(new Event('blur'))")
    session.beat(2)
    # Leaving is what flushes the position, so the navigation is the event
    # under test — not a tidy-up before checking.
    session.goto("/my/")
    session.beat(2)

    state = json.loads(moodle("kaivideo-state", "learner", str(cmid)))
    furthest = state["progress"]["furthest"]
    session.note(f"the server kept {furthest:.1f}s of {left_at:.1f}s watched")

    assert left_at - furthest < 1.0, (
        f"being sent out cost {left_at - furthest:.1f}s of watching — "
        "the learner has to sit through it again")


def test_clicking_through_to_another_page_is_recorded_and_closed_as_such(
        session, clean_learner, eventlog):
    """Leaving the lesson is the commonest way one ends, and it was untracked.

    Clicking back to the course fires neither blur nor visibilitychange — the
    page simply goes. Nothing said the lesson was over, so the sitting was
    eventually closed as abandoned: "we lost contact with them" rather than
    "they left". Because nothing else ever closed a lesson sitting cleanly,
    every ordinary lesson read that way, and the ones we genuinely did lose
    were hidden among them.

    The browser records the leaving; the server decides what it meant. It has
    to be that way round — a reload raises the same event, and only the server
    can see whether anybody came back.
    """
    clean_learner("learner")

    session.login("learner")
    open_monitored(session)
    session.page.evaluate(f"() => document.querySelector('{VIDEO}').play()")
    session.beat(4)

    assert any(s["status"] == "active"
               for s in json.loads(moodle("sessions", "learner"))), \
        "no sitting was open to begin with"

    session.note("the learner clicks back to the course")
    session.goto("/my/")
    session.beat(2.5)

    trail = eventlog("learner")
    assert "page_left" in trail, "leaving the page was not recorded at all"
    session.note("leaving is on the trail, with the video position on it")

    # The sitting is still open at this point, on purpose: the learner might
    # be reloading. The cleanup task is what decides, once nobody has come
    # back — and it must now tell "they left" from "we lost them".
    # Three hours, not two: the staleness cutoff IS two, and backdating by
    # exactly that leaves the row a second on the wrong side of a < test.
    moodle("age-session", "learner", "3")
    moodle("run-stale-task")

    latest = json.loads(moodle("sessions", "learner"))[0]
    session.note(f"the sitting closed as {latest['status']} ({latest['reason']})")
    assert latest["reason"] == "page_left", \
        f"the record does not say how it ended: {latest['reason']}"
    assert latest["status"] == "completed", \
        "a learner who left cleanly is recorded as one we lost contact with"


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
    """Requirement 3, the presence half.

    presenceWarnSec is pinned to 0 here on purpose: this test is about the
    check firing on schedule, not about the grace window that
    test_a_lost_presence_is_given_a_grace_window covers on its own, and a
    fixed interval keeps this one's timing simple to reason about.
    """
    clean_learner("learner")

    session.login("learner")
    session.goto(f"/mod/kaivideo/view.php?id={monitored_cmid()}")
    session.beat(1.5)

    session.note("run the monitor with a two-second presence interval")
    session.page.evaluate(RUN_MONITOR, {"videoSelector": VIDEO,
                                        "options": {"presenceSeconds": 2,
                                                    "presenceWarnSec": 0},
                                        "runFor": 8000})
    session.beat(2)

    log = eventlog("learner")
    assert "face_absent" in log, "no presence check ran"

    # The fake camera shows no face, so the video must not keep playing.
    paused = session.page.evaluate(
        "(selector) => document.querySelector(selector).paused", VIDEO)
    session.note(f"paused after the learner could not be seen: {paused}")
    assert paused is True


def test_a_lost_presence_is_given_a_grace_window(session, clean_learner, eventlog):
    """A single bad frame is not the same as somebody having left.

    With a grace window configured, the first absent frame starts a countdown
    rather than pausing outright: the learner sees how long is left, and a
    face returning inside that window cancels it. Nothing here can make the
    fake camera's face reappear, so what this proves is that the countdown
    element exists and counts down, and that the pause still lands once the
    window runs out.

    Started directly rather than through RUN_MONITOR: that helper stops the
    monitor and resolves in the same step, which would tear everything down
    before there was a chance to look at the countdown mid-flight.
    """
    clean_learner("learner")

    session.login("learner")
    session.goto(f"/mod/kaivideo/view.php?id={monitored_cmid()}")
    session.beat(1.5)

    session.note("start the monitor with an immediate check and a four-second grace window")
    session.page.evaluate(
        """(selector) => new Promise((resolve) => {
            require(['local_kaiproctor/attention_monitor', 'local_kaiproctor/camera'],
            function(AM, Camera) {
                const preview = document.createElement('video');
                preview.setAttribute('playsinline', '');
                preview.muted = true;
                document.body.appendChild(preview);

                const camera = new Camera(preview);
                camera.start().then(function() {
                    const video = document.querySelector(selector);
                    window.__monitor = new AM({
                        video: video,
                        contextid: M.cfg.contextid,
                        getSnapshot: function() { return camera.snapshot(); },
                        getStream: function() { return camera.getStream(); },
                        presenceSeconds: 2,
                        presenceWarnSec: 4,
                        verifySeconds: 0,
                        clickConfirmSeconds: 0,
                        mouseIdleSeconds: 0,
                        randomClipsPerHour: 0,
                        strictLockdown: false,
                        desktopNotification: false
                    });
                    window.__monitor.start();
                    // See RUN_MONITOR: a video left at its end is a paused
                    // video, and a paused video is not checked.
                    video.currentTime = 0;
                    video.play().catch(function() { return null; });
                    resolve(true);
                });
            });
        })""", VIDEO)

    session.note("a countdown appears before the video is paused")
    session.page.wait_for_selector(".kaiproctor-countdown", timeout=10_000)
    countdown_text = session.page.inner_text(".kaiproctor-countdown")
    session.note(f"countdown shown: {countdown_text}")

    still_playing = session.page.evaluate(
        "(selector) => !document.querySelector(selector).paused", VIDEO)
    session.note(f"still playing during the grace window: {still_playing}")
    assert still_playing, "the video was paused before the grace window ran out"

    session.note("wait past the grace window for the pause to land")
    session.page.wait_for_function(
        f"() => document.querySelector('{VIDEO}').paused", timeout=10_000)
    session.beat(1.5)

    log = eventlog("learner")
    assert "presence_lost" in log, "the grace window never started"
    assert "face_absent" in log, "the pause never landed once the window ran out"


def test_the_back_office_intervals_are_what_actually_runs(session, clean_learner,
                                                          eventlog):
    """The settings page drives the real monitor, not just a copy of itself.

    Every other test in this file hands the monitor its intervals directly,
    which measures the monitor and takes the wiring on trust. This one changes
    nothing but the back office, opens the activity the way a learner does, and
    waits: the monitor that starts is the page's own, configured by whatever
    the server said when it opened the sitting.

    Presence is the signal under test and everything else is switched off, so
    that the pause which arrives can only have come from the presence check
    firing on the interval that was set. The camera shows no face, so the
    check has something to find.
    """
    was = {name: moodle("get-setting", name).strip() for name in
           ["presenceseconds", "presencewarnsec", "mouseidleseconds",
            "clickconfirmseconds", "verifyseconds", "randomclipsperhour"]}
    try:
        session.note("in the back office: presence every 3 seconds, nothing else on")
        moodle("set-setting", "presenceseconds", "3")
        moodle("set-setting", "presencewarnsec", "0")
        for off in ["mouseidleseconds", "clickconfirmseconds", "verifyseconds",
                    "randomclipsperhour"]:
            moodle("set-setting", off, "0")

        clean_learner("learner")
        session.login("learner")
        open_monitored(session)

        session.note("play it, and then leave it alone")
        session.page.evaluate(f"() => document.querySelector('{VIDEO}').play()")

        session.note("wait for the monitor to stop it of its own accord")
        session.page.wait_for_function(
            f"() => document.querySelector('{VIDEO}').paused", timeout=30_000)
        session.beat(1.5)

        log = eventlog("learner")
        assert "face_absent" in log, (
            "the video stopped, but not because the presence check said so"
        )
        # The monitor reports what it is running on, so a pause that happened
        # for the right reason on the wrong schedule is still a failure.
        assert '"presence_seconds":3' in log.replace(" ", ""), (
            "the monitor was running on an interval nobody configured"
        )
        session.note("the interval set in the back office is the one enforced")
    finally:
        for name, value in was.items():
            moodle("set-setting", name, value)


def test_the_proctors_own_permission_prompt_is_not_a_violation(
        session, clean_learner, eventlog):
    """Starting the monitor must not be the thing that ends the sitting.

    Asking for notification permission takes the focus off the page, and
    losing focus is what this module punishes. In strict mode with no
    allowance that killed a sitting about two seconds after it opened — the
    learner had done nothing, and the trail recorded them as having walked
    out. The prompt cannot be scripted, so the state it leaves behind is set
    directly and a blur fired against it.
    """
    clean_learner("learner")

    session.login("learner")
    session.goto(f"/mod/kaivideo/view.php?id={monitored_cmid()}")
    session.beat(1.5)

    outcome = session.page.evaluate(
        """(selector) => new Promise((resolve) => {
            require(['local_kaiproctor/attention_monitor'], function(AM) {
                const video = document.querySelector(selector);
                const monitor = new AM({
                    video: video,
                    contextid: M.cfg.contextid,
                    presenceSeconds: 0,
                    verifySeconds: 0,
                    clickConfirmSeconds: 0,
                    mouseIdleSeconds: 0,
                    randomClipsPerHour: 0,
                    strictLockdown: true,
                    blurAllowance: 0,
                    desktopNotification: false
                });
                monitor.start();
                video.currentTime = 0;
                video.play().catch(function() { return null; });

                // As it is while the browser's own prompt is on screen.
                monitor._awaitingPermission = true;
                window.dispatchEvent(new Event('blur'));

                setTimeout(function() {
                    const during = video.paused;
                    // Answered: from here a focus loss is the learner's.
                    monitor._awaitingPermission = false;
                    window.dispatchEvent(new Event('blur'));

                    setTimeout(function() {
                        const after = video.paused;
                        monitor.stop();
                        resolve({during: during, after: after});
                    }, 1500);
                }, 1500);
            });
        })""", VIDEO)

    session.note(f"paused while our prompt was up: {outcome['during']}")
    session.note(f"paused once it was answered: {outcome['after']}")

    assert outcome["during"] is False, (
        "the monitor stopped the lesson over a prompt it raised itself"
    )
    assert outcome["after"] is True, (
        "suppressing our own prompt also suppressed a real focus loss"
    )

    log = eventlog("learner")
    assert "focus_loss_ignored" in log, (
        "the ignored focus loss left no trace, so the gap cannot be explained"
    )
    assert "window_blur" in log, "the real focus loss was not recorded"


def test_the_checks_still_run_where_there_is_no_video(session, clean_learner,
                                                      eventlog):
    """An exam is watched too, and it has nothing to pause.

    The quiz page has no lesson, so the monitor is given no video. It used to
    be given a detached one instead, on the reasoning that pause() would then
    be a harmless no-op — but a video element that has never played reports
    paused === true for ever, and the monitor skips every timed check while
    the lesson is paused. Presence, identity and idle therefore never ran for
    the entire duration of an exam: the one place they matter most.

    Driven here rather than through a real attempt because what is being
    tested is the monitor's own behaviour with no video, and a quiz page adds
    a gate, a timer and a camera prompt to something that needs none of them.
    """
    clean_learner("learner")

    session.login("learner")
    session.goto(f"/mod/kaivideo/view.php?id={monitored_cmid()}")
    session.beat(1.5)

    session.note("run a monitor with no video at all, as a quiz attempt does")
    # The overlay is read before stop(), which clears it — asking afterwards
    # measures the teardown rather than what the learner was shown.
    overlay = session.page.evaluate(
        """() => new Promise((resolve) => {
            require(['local_kaiproctor/attention_monitor', 'local_kaiproctor/camera'],
            function(AM, Camera) {
                const preview = document.createElement('video');
                preview.setAttribute('playsinline', '');
                preview.muted = true;
                document.body.appendChild(preview);

                const camera = new Camera(preview);
                camera.start().then(function() {
                    const monitor = new AM({
                        video: null,
                        contextid: M.cfg.contextid,
                        getSnapshot: function() { return camera.snapshot(); },
                        getStream: function() { return camera.getStream(); },
                        presenceSeconds: 2,
                        presenceWarnSec: 0,
                        mouseIdleSeconds: 3,
                        mouseIdleWarnSec: 0,
                        verifySeconds: 0,
                        clickConfirmSeconds: 0,
                        randomClipsPerHour: 0,
                        strictLockdown: false,
                        desktopNotification: false
                    });
                    monitor.start();
                    setTimeout(function() {
                        const shown = !!document.querySelector('.kaiproctor-overlay');
                        monitor.stop();
                        resolve(shown);
                    }, 10000);
                });
            });
        })"""
    )
    session.beat(2)

    log = eventlog("learner")
    assert "face_absent" in log, "an exam ran with nobody checking the camera"
    assert "mouse_idle" in log, "an exam ran without noticing an idle candidate"

    # The overlay has to be buildable too. With a detached stand-in its host
    # was the element's parent, which was null, and getComputedStyle threw
    # inside a promise — so a learner whose exam was cut short was told by an
    # overlay that could not be created.
    session.note(f"the learner was actually shown an overlay: {overlay}")
    assert overlay, "nothing on screen told the learner why they were stopped"


def test_idle_input_pauses_the_video(session, clean_learner, eventlog):
    """Requirement 2: no mouse or keyboard for the configured time pauses the
    lesson.

    Nothing here touches the page after the monitor starts — the idleness being
    tested is the real thing, not a simulated event.
    """
    clean_learner("learner")

    session.login("learner")
    session.goto(f"/mod/kaivideo/view.php?id={monitored_cmid()}")
    session.beat(1.5)

    session.note("run the monitor with a three-second idle limit, touching nothing")
    session.page.evaluate(RUN_MONITOR, {"videoSelector": VIDEO,
                                        "options": {"mouseIdleSeconds": 3},
                                        "runFor": 8000})
    session.beat(2)

    log = eventlog("learner")
    assert "mouse_idle" in log, "the idle timeout never fired"

    paused = session.page.evaluate(
        "(selector) => document.querySelector(selector).paused", VIDEO)
    session.note(f"paused after sitting idle: {paused}")
    assert paused is True, "the video kept playing with nobody at the controls"


def test_identity_check_runs_on_its_own_interval(session, clean_learner, eventlog):
    """Requirement 4, and only for somebody who has enrolled a face."""
    clean_learner("learner")
    moodle("seed-enrolment", "learner")

    session.login("learner")
    session.goto(f"/mod/kaivideo/view.php?id={monitored_cmid()}")
    session.beat(1.5)

    session.note("run the monitor with a three-second identity interval")
    session.page.evaluate(RUN_MONITOR, {"videoSelector": VIDEO,
                                        "options": {"verifySeconds": 3},
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
                    presenceSeconds: 0,
                    verifySeconds: 0,
                    clickConfirmSeconds: 2,
                    clickConfirmGraceSec: 30,
                    mouseIdleSeconds: 0,
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
