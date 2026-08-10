"""The stack itself: services, plugins, and the site policy handler.

If these fail nothing below them means anything, so they run first.
"""
from __future__ import annotations

from conftest import BASE_URL


def test_face_service_is_up_with_every_model_loaded(stack_health):
    assert stack_health["faceservice_ok"] is True
    # Two anti-spoofing models plus the detector and the recogniser.
    assert len(stack_health["faceservice_models"]) == 4
    assert stack_health["liveness_available"] is True, (
        "liveness silently unavailable means every check would report "
        "'not evaluated' and a photograph would pass"
    )


def test_both_plugins_are_installed(stack_health):
    assert stack_health["local_version"], "local_kaiproctor is not installed"
    assert stack_health["quizaccess_version"], "quizaccess_kaiproctor is not installed"


def test_all_web_services_are_registered(stack_health):
    """By name, not by count.

    A number here has been wrong twice now — bumped after adding a service,
    which tests nothing except that somebody remembered to bump it. Names say
    which one is missing, and adding one is then a deliberate edit rather than
    an increment.
    """
    expected = {
        "local_kaiproctor_enrol_face",
        "local_kaiproctor_verify_frame",
        "local_kaiproctor_log_event",
        "local_kaiproctor_store_evidence",
        "local_kaiproctor_analyze_frame",
        "local_kaiproctor_start_session",
        "local_kaiproctor_end_session",
        "local_kaiproctor_summarise_session",
        "local_kaiproctor_ask",
    }
    registered = set(stack_health["webservices"])

    assert not expected - registered, f"not registered: {sorted(expected - registered)}"
    assert not registered - expected, f"registered but unexpected: {sorted(registered - expected)}"


def test_pdpa_policy_is_the_site_policy_handler(stack_health):
    assert stack_health["sitepolicyhandler"] == "tool_policy"
    assert stack_health["policies"] >= 1


def test_site_loads(session):
    session.note("open the site front page")
    session.goto("/")
    assert "KAIPROCTOR" in session.page.title()


def test_face_service_is_not_reachable_from_the_browser(session):
    """It is on an internal network on purpose; only Moodle may call it."""
    session.note("confirm the face service is not published to the host")
    result = session.page.evaluate(
        """() => fetch('http://localhost:9000/health', {mode: 'no-cors'})
                 .then(() => 'reachable').catch(() => 'blocked')"""
    )
    assert result == "blocked"
