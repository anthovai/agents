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
    # start_session and end_session joined the original five.
    assert stack_health["webservices"] == 7


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
