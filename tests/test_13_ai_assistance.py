"""AI assistance, and the boundaries around it.

The model summarises a sitting for whoever has to review a hundred of them,
and flags imported questions whose Thai looks damaged. What matters as much as
that working is what it is not allowed to do, so most of these tests are about
the boundary rather than the feature.

The gateway is optional and off by default; tests that need a live model skip
when it is not running, and the boundary tests do not need one at all.
"""
from __future__ import annotations

import json

import pytest

from conftest import moodle


@pytest.fixture
def needs_model(install_support_script):
    """Skip when no model is reachable — checked at run time, not at import.

    This was a module-level skipif, which pytest evaluates during collection:
    before any fixture has run, and therefore before the support script has
    been copied into the container. It passed for months because a previous
    run had left the file there. Rebuilding the Moodle image deleted it, and
    the whole file failed to collect.

    Depending on install_support_script is what makes the ordering a fact
    rather than a hope.
    """
    if moodle("ai-configured").strip() != "yes":
        pytest.skip("no model behind the reviewer service")


def test_ai_is_off_until_somebody_turns_it_on(session):
    """Nothing else in the system may depend on it."""
    state = json.loads(moodle("ai-state"))
    session.note(f"AI settings: {state}")

    # Whether it is on right now depends on the environment; what must hold is
    # that the plugin ships with it off.
    assert state["defaultenabled"] == "0", "AI assistance is enabled by default"


def test_what_gets_sent_contains_no_biometric_data(session, clean_learner):
    """The consent document tells learners their face goes to a stateless
    service that keeps nothing. Sending it to a language model instead would
    make that untrue, so the payload is a whitelist and this checks it."""
    clean_learner("learner")
    moodle("seed-enrolment", "learner")

    session.note("run a monitored lesson so there is a sitting to summarise")
    session.login("learner")
    session.goto("/local/kaiproctor/lesson.php")
    session.page.click('[data-action="start"]')
    session.page.wait_for_selector('[data-region="status"]:not([hidden])', timeout=25_000)
    session.beat(1.5)
    session.page.evaluate("() => window.dispatchEvent(new Event('blur'))")
    session.beat(3)

    payload = json.loads(moodle("ai-payload", "learner"))
    session.note(f"what would be sent: {json.dumps(payload, ensure_ascii=False)[:300]}")

    flat = json.dumps(payload, ensure_ascii=False).lower()

    # Nothing that is, or is derived from, a face.
    for forbidden in ["embedding", "similarity", "livenessscore", "filename",
                      ".jpg", ".webm", "base64"]:
        assert forbidden not in flat, f"the payload carries {forbidden}"

    # And nothing that names the person.
    assert "userid" not in flat and "email" not in flat

    # What it does carry is what the report already shows a reviewer.
    assert "checks" in payload and "events" in payload and "status" in payload
    session.note("payload holds counts and event names only")


def test_the_summary_is_labelled_as_a_draft_not_a_finding(session, clean_learner):
    """A reviewer must never mistake the model's prose for the record."""
    clean_learner("learner")
    moodle("seed-enrolment", "learner")

    session.note("generate a sitting")
    session.login("learner")
    session.goto("/local/kaiproctor/lesson.php")
    session.page.click('[data-action="start"]')
    session.page.wait_for_selector('[data-region="status"]:not([hidden])', timeout=25_000)
    session.beat(2)

    contextid = moodle("user-context-id", "learner")
    userid = moodle("user-id", "learner")
    session.goto(f"/local/kaiproctor/report.php?userid={userid}&contextid={contextid}")
    session.beat(1.5)

    strings = json.loads(moodle("ai-strings"))
    session.note(f"the wording shown around a summary: {strings['note'][:120]}")

    # The disclaimer has to say all three things, or it is not a disclaimer.
    note = strings["note"]
    assert "ไม่ใช่ข้อสรุป" in note, "the wording does not say it is not a finding"
    assert "ไม่ได้เห็นภาพ" in note, "the wording does not say the model saw no images"
    assert "ไม่มีส่วนตัดสิน" in note, "the wording does not say it decides nothing"


def test_a_service_that_is_not_there_fails_visibly(session):
    """An advisory feature may be unavailable; it may not fail silently and
    leave a reviewer thinking there was nothing to say."""
    original = json.loads(moodle("ai-state"))
    try:
        moodle("set-setting", "aienabled", "1")
        moodle("set-setting", "aibaseurl", "http://127.0.0.1:9998/v1")

        result = json.loads(moodle("ai-summarise-latest", "learner"))
        session.note(f"result with no service: {result}")

        assert result["ok"] is False
        assert result["error"]["code"] in ("unreachable", "bad_response")
        assert result["error"]["message"], "failed without saying why"
    finally:
        moodle("set-setting", "aienabled", original["enabled"] or "0")
        moodle("set-setting", "aibaseurl", original["baseurl"])


def test_the_model_is_told_not_to_accuse_anybody(session):
    """The instructions are part of the product, not a detail: a summary that
    says a learner cheated would be read as a finding by whoever opens it."""
    # Whitespace-normalised: the guardrails are wrapped across lines in the
    # source, and a test that breaks when somebody reflows a comment is noise.
    prompt = " ".join(moodle("ai-prompt").split())
    session.note(f"guardrails found in: {prompt[:120]}")

    for guardrail in [
        "Do not state or imply that the learner cheated",
        "Do not recommend passing, failing, or disciplining anybody",
        "rather than inventing concerns",
        "rather than filling the gap",
    ]:
        assert guardrail in prompt, f"the instruction '{guardrail}' is gone"


def test_a_summary_comes_back_when_a_model_is_behind_the_service(needs_model, session, clean_learner):
    """The whole chain: platform -> reviewer service -> model.

    Left switched off outside this test, because the shipped default being
    'off' is itself one of the guarantees.
    """
    clean_learner("learner")
    moodle("seed-enrolment", "learner")

    session.note("generate a sitting")
    session.login("learner")
    session.goto("/local/kaiproctor/lesson.php")
    session.page.click('[data-action="start"]')
    session.page.wait_for_selector('[data-region="status"]:not([hidden])', timeout=25_000)
    session.beat(2)

    original = json.loads(moodle("ai-state"))
    try:
        moodle("set-setting", "aienabled", "1")
        health = json.loads(moodle("ai-health"))
        session.note(f"model behind the service: {health.get('model')} at {health.get('backend')}")

        result = json.loads(moodle("ai-summarise-latest", "learner"))
        session.note(f"summary: {str(result)[:400]}")

        assert result["ok"] is True, f"the chain failed: {result}"
        assert len(result["summary"]) > 20

        # The service refuses to hand back a summary that reached a verdict,
        # so a summary arriving at all is itself the assertion that it did not.
        assert result["model"], "the service did not say which model wrote it"
    finally:
        moodle("set-setting", "aienabled", original["enabled"] or "0")
