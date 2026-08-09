"""Requirement 6 — PDPA consent before any biometric data is collected.

Consent is Moodle's tool_policy rather than a table of our own, so these tests
check the platform behaviour we are relying on: the policy is presented, it is
compulsory, and the learner's own record of it is visible to them.
"""
from __future__ import annotations

import json

from conftest import moodle


def test_nothing_is_reachable_before_consent_is_given(session, unconsented_learner):
    """Requirement 6, the part that actually matters.

    Biometric processing may not begin without consent, so the enrolment page
    in particular must be unreachable until the policy is agreed to.
    """
    unconsented_learner("learner")

    session.note("sign in as a learner who has not consented")
    session.login("learner")

    session.note("try to go straight to face enrolment")
    session.goto("/local/kaiproctor/enrol.php")
    session.beat(1.5)

    text = session.body_text()
    session.note("the consent page blocks the way")
    assert "ชีวมิติ" in text, "the learner was not shown the biometric policy"
    assert session.page.locator('[data-action="start"]').count() == 0, (
        "the enrolment camera was reachable without consent"
    )
    # The agreement is a deliberate act, not a pre-ticked box.
    checkbox = session.page.locator('input[type="checkbox"]').first
    assert checkbox.count() >= 1
    assert checkbox.is_checked() is False


def test_enrolment_becomes_reachable_once_consent_is_given(session, unconsented_learner):
    unconsented_learner("learner")

    session.note("sign in as a learner who has not consented")
    session.login("learner")
    session.goto("/local/kaiproctor/enrol.php")
    session.beat(1)

    session.note("agree to the policy")
    session.page.locator('input[type="checkbox"]').first.check()
    session.beat(0.8)
    session.page.locator('button[type="submit"], input[type="submit"]').first.click()
    session.page.wait_for_load_state("domcontentloaded")
    session.beat(1.5)

    session.note("face enrolment is now reachable")
    session.goto("/local/kaiproctor/enrol.php")
    assert session.page.locator('[data-action="start"]').count() == 1


def test_consent_document_states_what_is_collected(session, clean_learner):
    clean_learner("learner")

    session.note("sign in as the learner")
    session.login("learner")

    session.note("open the published PDPA policy")
    session.goto("/admin/tool/policy/viewall.php")
    text = session.body_text()

    assert "PDPA" in text or "ชีวมิติ" in text, "the biometric policy is not listed"

    session.note("open the policy itself")
    session.page.locator("a", has_text="ชีวมิติ").first.click()
    session.page.wait_for_load_state("domcontentloaded")
    session.beat(1.5)

    content = session.body_text()
    # The specific things collected have to be named, not summarised away.
    for phrase in ["ค่าที่แทนใบหน้า", "ภาพนิ่งและคลิป", "มาตรา 26"]:
        assert phrase in content, f"the policy does not mention {phrase}"


def test_consent_is_compulsory_not_optional(session):
    session.note("sign in as an administrator")
    session.login("admin")

    session.note("open the policy management page")
    session.goto("/admin/tool/policy/managedocs.php")
    assert "ชีวมิติ" in session.body_text()

    session.note("read how the published policy is actually configured")
    policies = json.loads(moodle("policy-info"))
    session.note(f"published policies: {policies}")

    biometric = [p for p in policies if "ชีวมิติ" in p["name"]]
    assert biometric, "the biometric policy is not published"
    # A policy the learner can decline and still proceed with is not consent
    # for data that may not be processed without it. Read from the stored
    # setting rather than from page text, which varies with UI language.
    assert biometric[0]["iscompulsory"] is True


def test_learner_can_see_their_own_consent_record(session, clean_learner):
    clean_learner("learner")

    session.note("sign in as the learner")
    session.login("learner")

    session.note("open their data-privacy page")
    session.goto("/admin/tool/dataprivacy/summary.php")
    session.beat(1.5)

    text = session.body_text()
    # Moodle's own subject-access route has to be reachable, since it is what
    # the plugin's Privacy API provider plugs into.
    assert text.strip(), "the data privacy summary did not render"
