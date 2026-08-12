"""Requirement 2 — closing the browser-level exits.

The stated limit holds: a web page cannot stop Alt+Tab reaching the operating
system, a second monitor, or a phone on the desk. What is tested here is that
what the browser *can* intercept is intercepted and recorded.
"""
from __future__ import annotations

from conftest import monitored_url, moodle

DRIVE_LOCKDOWN = """
() => new Promise((resolve) => {
    require(['local_kaiproctor/lockdown', 'local_kaiproctor/api'], function(Lockdown, Api) {
        const seen = [];
        const lockdown = new Lockdown({
            requireFullscreen: false,   // needs a real user gesture
            warnOnUnload: false,        // would block the test navigating away
            detectDevtools: false,      // window-size heuristic, not deterministic
            onViolation: function(type, detail) {
                seen.push(type);
                Api.logEvent(M.cfg.contextid, type, detail, null).catch(function() { return null; });
            }
        });
        lockdown.start().then(function() {
            const fire = (name, init) => document.dispatchEvent(
                new (name === 'contextmenu' ? MouseEvent :
                     name.startsWith('key') ? KeyboardEvent : ClipboardEvent)(
                    name, Object.assign({bubbles: true, cancelable: true}, init || {})));

            fire('contextmenu');
            fire('copy');
            fire('cut');
            fire('paste');
            [{key: 'F12'},
             {key: 'i', ctrlKey: true, shiftKey: true},
             {key: 't', ctrlKey: true},
             {key: 'p', ctrlKey: true},
             {key: 'Tab', ctrlKey: true},
             {key: 'Tab', altKey: true},
             {key: 'PrintScreen'}].forEach(init => fire('keydown', init));

            setTimeout(function() {
                lockdown.stop();
                resolve(seen);
            }, 2000);
        });
    });
})
"""


def test_lockdown_blocks_and_reports_every_browser_exit(session, clean_learner, eventlog):
    clean_learner("learner")

    session.note("sign in and open a monitored activity")
    session.login("learner")
    session.goto(monitored_url())

    session.note("attempt every shortcut and menu lockdown is meant to catch")
    caught = session.page.evaluate(DRIVE_LOCKDOWN)
    session.note(f"violations caught: {caught}")

    for expected in ["context_menu", "copy_attempt", "paste_attempt",
                     "devtools_suspected", "browser_shortcut",
                     "tab_switch", "app_switch", "print_screen"]:
        assert expected in caught, f"{expected} was not intercepted"

    session.beat(2)
    log = eventlog("learner")
    session.note("violations written to the audit trail")
    # Catching them in the browser is worthless if they never reach the server.
    for expected in ["context_menu", "copy_attempt", "browser_shortcut", "app_switch"]:
        assert expected in log, f"{expected} was caught but never recorded"


def test_text_selection_and_dragging_are_suppressed(session, clean_learner):
    clean_learner("learner")

    session.note("sign in and open a monitored activity")
    session.login("learner")
    session.goto(monitored_url())

    session.note("switch lockdown on and try to select text")
    result = session.page.evaluate(
        """() => new Promise((resolve) => {
            require(['local_kaiproctor/lockdown'], function(Lockdown) {
                const lockdown = new Lockdown({
                    requireFullscreen: false, warnOnUnload: false, detectDevtools: false
                });
                lockdown.start().then(function() {
                    const selection = document.dispatchEvent(
                        new Event('selectstart', {bubbles: true, cancelable: true}));
                    const drag = document.dispatchEvent(
                        new Event('dragstart', {bubbles: true, cancelable: true}));
                    const userSelect = getComputedStyle(document.documentElement).userSelect;
                    lockdown.stop();
                    resolve({selectionAllowed: selection, dragAllowed: drag, userSelect: userSelect});
                });
            });
        })"""
    )
    session.note(f"result: {result}")
    assert result["selectionAllowed"] is False
    assert result["dragAllowed"] is False
    assert result["userSelect"] == "none"


def test_an_unknown_signal_is_refused_by_the_server(session, clean_learner):
    """A tampered client must not be able to write arbitrary rows into the log."""
    clean_learner("learner")

    session.note("sign in")
    session.login("learner")
    session.goto(monitored_url())

    session.note("try to log an invented signal type")
    result = session.page.evaluate(
        """() => new Promise((resolve) => {
            require(['local_kaiproctor/api'], function(Api) {
                Api.logEvent(M.cfg.contextid, 'totally_invented_signal', {}, null)
                   .then(r => resolve(r)).catch(e => resolve({error: String(e)}));
            });
        })"""
    )
    session.note(f"server response: {result}")
    assert result.get("ok") is False
    assert result.get("errorcode") == "unknown_type"

    assert "totally_invented_signal" not in moodle("events", "learner")
