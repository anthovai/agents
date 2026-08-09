"""Safe Exam Browser on the high-stakes quiz.

SEB is the only open-source thing that locks the machine itself. Our
browser-side lockdown detects and reports; it cannot stop Alt+Tab, a second
monitor, or a phone on the desk. For an exam where that matters both run
together, so what is checked here is that they do — and that an ordinary
browser is refused.

Driving SEB itself is out of scope: it is a native application, and no
automated browser can pretend to be one convincingly. What can be checked is
everything Moodle does around it.
"""
from __future__ import annotations

import json

from conftest import SEB_QUIZ_CMID, moodle


def test_seb_is_configured_with_a_real_config_key(session):
    """The Config Key is cryptographic and Moodle generates it.

    The earlier prototype approximated it with sha256(url + key), which SEB
    would never have accepted.
    """
    info = json.loads(moodle("seb-info", str(SEB_QUIZ_CMID)))
    session.note(f"SEB settings: {info}")

    assert info["requiresafeexambrowser"] == 1, "SEB is not required on this quiz"
    assert len(info["configkey"]) == 64, "the Config Key is not a sha256 digest"
    assert info["configbytes"] > 500, "the generated .seb config looks empty"
    # Both layers on the same exam: SEB owns the machine, we own the identity.
    assert info["kaiproctorenabled"] is True


def test_an_ordinary_browser_cannot_start_the_seb_quiz(session, clean_learner):
    clean_learner("learner")
    moodle("seed-enrolment", "learner")

    session.note("sign in as an enrolled learner")
    session.login("learner")

    session.note("open the high-stakes quiz in an ordinary browser")
    session.goto(f"/mod/quiz/view.php?id={SEB_QUIZ_CMID}")
    session.beat(2)

    text = session.body_text()
    session.note("the quiz refuses to start")
    assert "Safe Exam Browser" in text or "SEB" in text
    assert session.page.locator('form[action*="startattempt"]').count() == 0, (
        "the attempt could be started without Safe Exam Browser"
    )


def test_the_seb_config_file_is_downloadable_by_the_learner(session, clean_learner):
    """SEB is launched by opening this file, so the learner must be able to get
    it — refusing them the config would make the exam unenterable."""
    clean_learner("learner")
    moodle("seed-enrolment", "learner")

    session.note("sign in as an enrolled learner")
    session.login("learner")
    session.goto(f"/mod/quiz/view.php?id={SEB_QUIZ_CMID}")
    session.beat(1.5)

    session.note("check the link Moodle offers")
    hrefs = session.page.eval_on_selector_all(
        "a", "els => els.map(e => e.getAttribute('href') || '')"
    )
    launch = [h for h in hrefs if h.startswith("seb://")]
    session.note(f"launch links: {launch}")

    # Moodle hands off with a seb:// URL, which the installed Safe Exam
    # Browser registers as a protocol handler. That is the mechanism; a plain
    # download link is not what starts the exam.
    assert launch, "there is no seb:// link to launch Safe Exam Browser"

    # The same URL over http is the configuration itself. Fetching it proves
    # Moodle really generates a file rather than just rendering a link.
    config_url = "http://" + launch[0][len("seb://"):]
    session.note(f"fetching the configuration at {config_url}")
    response = session.page.request.get(config_url)
    session.note(f"download status: {response.status}, {len(response.body())} bytes")
    assert response.status == 200
    assert len(response.body()) > 100, "the generated configuration is empty"


def test_both_rules_describe_themselves_to_the_learner(session, clean_learner):
    clean_learner("learner")
    moodle("seed-enrolment", "learner")

    session.note("sign in and open the high-stakes quiz")
    session.login("learner")
    session.goto(f"/mod/quiz/view.php?id={SEB_QUIZ_CMID}")
    session.beat(1.5)

    text = session.body_text()
    session.note("the learner is told about both layers before starting")
    assert "ยืนยันตัวตนผ่านกล้อง" in text, "the face proctoring rule is not announced"
    assert "Safe Exam Browser" in text or "SEB" in text, "the SEB requirement is not announced"
