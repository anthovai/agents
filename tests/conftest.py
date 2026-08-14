"""Playwright fixtures and artefact capture.

Every test gets its own browser context so it gets its own video. Artefacts
land in reports/ in the same shape face-re produced, so the two runs can be
compared side by side:

    reports/video/<test>.webm       the run, slowed down to be watchable
    reports/screenshots/<test>.png  full page at the end of the test
    reports/eventlog/<test>.txt     the audit trail the run produced
    reports/junit.xml               machine-readable results

The browser is deliberately slowed (SLOW_MO) and pauses are added at the
points that matter. A correct-but-unwatchable video is not evidence anybody
can check, which is the whole reason these are recorded.
"""
from __future__ import annotations

import json
import os
import shutil
import subprocess
import time
from pathlib import Path

import pytest
from playwright.sync_api import sync_playwright

PROJECT_ROOT = Path(__file__).resolve().parent.parent
REPORTS = PROJECT_ROOT / "reports"
VIDEO_DIR = REPORTS / "video"
SHOT_DIR = REPORTS / "screenshots"
EVENT_DIR = REPORTS / "eventlog"
RAW_VIDEO_DIR = REPORTS / ".rawvideo"

BASE_URL = os.environ.get("KP_BASE_URL", "http://localhost:8080")

# Milliseconds between Playwright actions. High on purpose: the recording is
# meant to be watched by a person checking that the system behaves.
SLOW_MO = int(os.environ.get("KP_SLOW_MO", "700"))
VIEWPORT = {"width": 1280, "height": 800}

ACCOUNTS = {
    "learner": "Learn!2345",
    "learner2": "Learn!2345",
    "instructor": "Teach!2345",
    "admin": "DevAdmin!2345",
}

# Activities created by docker/seed-demo.php.
QUIZ_CMID = int(os.environ.get("KP_QUIZ_CMID", "8"))
SEB_QUIZ_CMID = int(os.environ.get("KP_SEB_QUIZ_CMID", "10"))


# --------------------------------------------------------------------------
# Talking to Moodle
# --------------------------------------------------------------------------

_prepared = False


def _prepare_container() -> None:
    """Put the support files in place, once per process.

    This used to happen only in a fixture, so anything running at collection
    time — a module-level constant, a skipif — called into a container nobody
    had prepared. It passed for months because a previous run left the files
    behind, and broke the first time an image was rebuilt. Doing it from
    moodle() makes the ordering a property of the call instead of a property of
    which fixtures a test happened to ask for.
    """
    global _prepared
    if _prepared:
        return
    _prepared = True

    env = {**os.environ, "MSYS_NO_PATHCONV": "1"}
    support = Path("tests") / "support"

    for name in ["kp-query.php", "ask-questions.json"]:
        subprocess.run(
            ["docker", "compose", "cp", str(support / name),
             f"moodle:/var/www/html/{name}"],
            cwd=PROJECT_ROOT, capture_output=True, text=True, env=env, check=True)

    for command in [
        # purge_caches does not touch the component map, and it is stale
        # whenever a class file has been added to a plugin without the
        # plugin's version changing — which then fails as "class not found".
        ["rm", "-f", "/var/moodledata/cache/core_component.php"],
        # AMD modules are served from a bundle keyed on the JS revision, so an
        # edited module keeps being served in its old form until this runs. The
        # symptom is "No define call for <module>", which says nothing about
        # the edit that caused it.
        ["php", "/var/www/html/admin/cli/purge_caches.php"],
    ]:
        subprocess.run(["docker", "compose", "exec", "-T", "moodle", *command],
                       cwd=PROJECT_ROOT, capture_output=True, text=True,
                       env=env, check=False)


def moodle(*args: str) -> str:
    """Run tests/support/kp-query.php inside the Moodle container."""
    _prepare_container()
    result = subprocess.run(
        ["docker", "compose", "exec", "-T", "moodle",
         "php", "/var/www/html/kp-query.php", *args],
        cwd=PROJECT_ROOT, capture_output=True, text=True, encoding="utf-8",
        env={**os.environ, "MSYS_NO_PATHCONV": "1"},
    )
    if result.returncode != 0:
        raise RuntimeError(
            f"kp-query.php {' '.join(args)} failed:\n{result.stdout}\n{result.stderr}"
        )
    return result.stdout.strip()


@pytest.fixture(scope="session", autouse=True)
def install_support_script():
    """Put the support CLI, and the sample exam pack, in the containers.

    The copying lives in _prepare_container so that anything calling moodle()
    gets it whether or not it asked for this fixture. What remains here is the
    fixture other fixtures depend on to express ordering, and the exam pack.
    """
    _prepare_container()

    # The labelled question set the retrieval assertions score against; it
    # lives beside the support script because both are read inside the
    # container, not from here.
    subprocess.run(
        ["docker", "compose", "cp",
         str(Path("tests") / "support" / "ask-questions.json"),
         "moodle:/var/www/html/ask-questions.json"],
        cwd=PROJECT_ROOT, capture_output=True, text=True,
        env={**os.environ, "MSYS_NO_PATHCONV": "1"}, check=True,
    )

    sample = PROJECT_ROOT / "face-service" / "tests" / "sample-exam.pdf"
    if sample.is_file():
        subprocess.run(
            ["docker", "compose", "cp", str(sample.relative_to(PROJECT_ROOT)),
             "moodle:/tmp/sample.pdf"],
            cwd=PROJECT_ROOT, capture_output=True, text=True,
            env={**os.environ, "MSYS_NO_PATHCONV": "1"},
        )
    yield


@pytest.fixture(scope="session")
def stack_health(install_support_script) -> dict:
    return json.loads(moodle("health"))


@pytest.fixture(scope="session", autouse=True)
def staff_have_consented(install_support_script):
    """Accept the site policy for the accounts that are not the subject of the
    consent tests. The policy is compulsory and blocks every page until it is
    agreed to — correct behaviour, proved in test_02, but staff walking through
    it in every other test would obscure what those tests are checking."""
    for username in ("admin", "instructor"):
        moodle("accept-policy", username)
    yield


# --------------------------------------------------------------------------
# Artefact directories
# --------------------------------------------------------------------------

@pytest.fixture(scope="session", autouse=True)
def artefact_dirs():
    # Best-effort clean. A run that was interrupted can leave a video file
    # still held by a browser process, and failing every test because last
    # time's artefact will not delete would be absurd.
    for directory in (VIDEO_DIR, SHOT_DIR, EVENT_DIR, RAW_VIDEO_DIR):
        if directory.exists():
            for item in directory.iterdir():
                try:
                    item.unlink()
                except OSError as error:
                    print(f"could not remove stale artefact {item}: {error}")
        directory.mkdir(parents=True, exist_ok=True)
    yield
    # Playwright names videos by an internal id; they were renamed per test as
    # each context closed, so anything still here is from a crashed context.
    shutil.rmtree(RAW_VIDEO_DIR, ignore_errors=True)


# --------------------------------------------------------------------------
# Browser
# --------------------------------------------------------------------------

@pytest.fixture(scope="session")
def playwright_instance():
    with sync_playwright() as playwright:
        yield playwright


@pytest.fixture(scope="session")
def browser(playwright_instance):
    browser = playwright_instance.chromium.launch(
        slow_mo=SLOW_MO,
        args=[
            # A fake camera that always succeeds but shows no face. It proves
            # the plumbing and the no-face paths; it cannot prove matching
            # accuracy, and no test here claims otherwise.
            "--use-fake-device-for-media-stream",
            "--use-fake-ui-for-media-stream",
            "--autoplay-policy=no-user-gesture-required",
        ],
    )
    yield browser
    browser.close()


class Session:
    """A page plus the small vocabulary the tests need."""

    def __init__(self, page, name: str):
        self.page = page
        self.name = name
        self.notes: list[str] = []

    def note(self, message: str) -> None:
        """Record a human-readable step, shown in the report."""
        self.notes.append(message)

    def beat(self, seconds: float = 1.2) -> None:
        """Hold still so the recording is followable."""
        self.page.wait_for_timeout(int(seconds * 1000))

    def login(self, username: str) -> None:
        page = self.page
        page.goto(f"{BASE_URL}/login/index.php")
        page.fill("#username", username)
        page.fill("#password", ACCOUNTS[username])
        self.beat(0.6)
        page.click("#loginbtn")
        page.wait_for_load_state("domcontentloaded")
        self.beat()

    def logout(self) -> None:
        # Moodle needs the sesskey, otherwise logout.php only renders a
        # confirmation page and the session survives — which then makes the
        # next login silently a no-op.
        sesskey = self.page.evaluate("() => (window.M && M.cfg && M.cfg.sesskey) || ''")
        self.page.goto(f"{BASE_URL}/login/logout.php?sesskey={sesskey}")
        self.page.wait_for_load_state("domcontentloaded")
        self.beat(0.8)

    def goto(self, path: str) -> None:
        self.page.goto(f"{BASE_URL}{path}")
        self.page.wait_for_load_state("domcontentloaded")
        self.beat(0.8)

    def body_text(self) -> str:
        return self.page.inner_text("body")


_monitored_cmid = None


def monitored_cmid() -> int:
    """The interactive video that has monitoring switched on.

    Its own activity rather than flipping the setting on the one the
    interactive-video tests use: a camera prompt and an overlay appearing
    mid-question would break those for reasons that have nothing to do with
    what they check.
    """
    global _monitored_cmid
    if _monitored_cmid is None:
        _monitored_cmid = int(moodle("kaivideo-cmid-monitored").strip())
    return _monitored_cmid


def monitored_url() -> str:
    """The monitored activity's address, without starting anything.

    For tests that need a Moodle page able to load this plugin's AMD modules
    and nothing more. Starting the monitor there would add a camera and
    overlays that have nothing to do with what they check.
    """
    return f"/mod/kaivideo/view.php?id={monitored_cmid()}"


def open_monitored(session, timeout: int = 30_000) -> int:
    """Open the monitored activity and get the monitor actually running.

    Replaces what used to be a standalone /local/kaiproctor/lesson.php page.
    That page had an explicit start button; a monitored activity starts on the
    learner's first interaction instead, because getUserMedia needs a user
    gesture and opening an activity is not one.

    Waits for the banner to turn green, which is the point at which the sitting
    is open and the monitor is running — before that, assertions race the
    camera.
    """
    cmid = monitored_cmid()
    session.goto(f"/mod/kaivideo/view.php?id={cmid}")
    session.beat(1)

    # The gesture. Any click will do; the module listens once on the document.
    session.page.click("body", position={"x": 5, "y": 5})

    session.page.wait_for_selector(
        ".kaiproctor-monitor-banner.alert-success", timeout=timeout)
    session.beat(1.5)
    return cmid


@pytest.fixture
def session(browser, request, install_support_script):
    """One browser context per test, recorded to its own video."""
    test_name = request.node.name
    context = browser.new_context(
        viewport=VIEWPORT,
        record_video_dir=str(RAW_VIDEO_DIR),
        record_video_size=VIEWPORT,
        permissions=["camera"],
        locale="th-TH",
    )
    context.grant_permissions(["camera"], origin=BASE_URL)
    page = context.new_page()
    active = Session(page, test_name)

    yield active

    # Screenshot first: the context has to stay open for it.
    try:
        page.screenshot(path=str(SHOT_DIR / f"{test_name}.png"), full_page=True)
    except Exception as error:  # noqa: BLE001 - artefacts must never fail a test
        print(f"screenshot failed for {test_name}: {error}")

    # A test that never opened a page records a few kilobytes of blank white,
    # which is not evidence of anything and makes the useful recordings harder
    # to find. Measurements that assert against the database rather than the
    # screen are legitimate; keeping a video of them is not.
    opened_a_page = page.url not in ("", "about:blank")

    video = page.video
    context.close()  # finalises the video file

    if video and not opened_a_page:
        try:
            Path(video.path()).unlink(missing_ok=True)
        except Exception as error:  # noqa: BLE001
            print(f"could not discard the blank video for {test_name}: {error}")
    elif video:
        try:
            source = Path(video.path())
            target = VIDEO_DIR / f"{test_name}.webm"
            for _ in range(20):
                if source.exists():
                    shutil.move(str(source), str(target))
                    break
                time.sleep(0.25)
        except Exception as error:  # noqa: BLE001
            print(f"video capture failed for {test_name}: {error}")

    if active.notes:
        (EVENT_DIR / f"{test_name}.steps.txt").write_text(
            "\n".join(active.notes), encoding="utf-8"
        )


@pytest.fixture
def eventlog(request):
    """Dump a learner's audit trail to reports/eventlog/<test>.txt."""
    def dump(username: str) -> str:
        text = moodle("events", username)
        (EVENT_DIR / f"{request.node.name}.txt").write_text(
            f"# audit trail for {username}\n{text}\n", encoding="utf-8"
        )
        return text
    return dump


@pytest.fixture
def clean_learner():
    """Give a test a learner with no proctoring history, already consented.

    Consent is accepted here because the PDPA policy is compulsory and blocks
    every other page until it is agreed to — which is the behaviour test_02
    proves on purpose. Tests about anything else should not all have to walk
    through it first.
    """
    def reset(username: str) -> None:
        moodle("reset", username)
        moodle("purge-attempts", username)
        moodle("accept-policy", username)
    return reset


@pytest.fixture
def unconsented_learner():
    """A learner who has not agreed to the PDPA policy."""
    def prepare(username: str) -> None:
        moodle("reset", username)
        moodle("purge-attempts", username)
        moodle("revoke-policy", username)
    return prepare
