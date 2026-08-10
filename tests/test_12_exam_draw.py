"""The paper a learner is given: reproducible, and provably not chosen.

The original system drew each paper from a seed derived from who was sitting
and which attempt it was. Moodle draws its random slots with shuffle(), which
uses PHP's global Mersenne Twister, so seeding that engine immediately before
the attempt is created gets the same property without touching core.
"""
from __future__ import annotations

import json
import os

from conftest import moodle

# The blueprint quiz created by docker/seed-demo.php: three random slots, one
# per difficulty tag.
BLUEPRINT_CMID = int(os.environ.get("KP_BLUEPRINT_CMID", "13"))


def draw(username: str, attemptnumber: int) -> dict:
    return json.loads(moodle("draw-probe", str(BLUEPRINT_CMID), username, str(attemptnumber)))


def test_the_same_learner_and_attempt_always_get_the_same_paper(session):
    """The property the whole thing exists for."""
    session.note("draw attempt 1 for the learner")
    first = draw("learner", 1)
    session.note(f"seed {first['seed']} -> questions {first['questionids']}")

    session.note("throw it away and draw attempt 1 again")
    second = draw("learner", 1)
    session.note(f"seed {second['seed']} -> questions {second['questionids']}")

    assert first["seed"] == second["seed"]
    assert first["questionids"] == second["questionids"], (
        "the same learner and attempt number produced a different paper"
    )
    assert len(first["questionids"]) == 3, "the blueprint did not fill every slot"


def test_a_second_attempt_gets_a_different_paper(session):
    """Retaking must not hand back the paper they have already seen."""
    first = draw("learner", 1)
    second = draw("learner", 2)
    session.note(f"attempt 1: {first['questionids']}  attempt 2: {second['questionids']}")

    assert first["seed"] != second["seed"]
    assert first["questionids"] != second["questionids"]


def test_two_learners_sitting_the_same_exam_get_different_papers(session):
    first = draw("learner", 1)
    other = draw("learner2", 1)
    session.note(f"learner: {first['questionids']}  learner2: {other['questionids']}")

    assert first["seed"] != other["seed"]
    assert first["questionids"] != other["questionids"]


def test_the_seed_is_only_a_function_of_the_identifiers(session):
    """It carries no timestamp and no site secret, so anybody holding the
    learner, the quiz and the attempt number can recompute it — which is what
    makes it evidence rather than an assertion."""
    session.note("draw the same attempt twice, minutes apart in database terms")
    first = draw("learner", 3)
    second = draw("learner", 3)

    assert first["seed"] == second["seed"], "the seed changed between two draws"
    assert first["seed"] > 0


def test_the_recorded_draw_is_checked_against_the_identifiers(session, clean_learner):
    """A stored seed that does not recalculate means somebody edited the
    record, and the report has to say so rather than display it as fact."""
    clean_learner("learner")
    moodle("seed-enrolment", "learner")
    moodle("seed-pass", "learner", str(BLUEPRINT_CMID))

    session.note("sit the blueprint exam")
    session.login("learner")
    session.goto(f"/mod/quiz/view.php?id={BLUEPRINT_CMID}")
    session.page.locator('form[action*="startattempt"] button').first.click()
    session.page.wait_for_load_state("domcontentloaded")
    session.beat(1)

    if session.page.locator('[name="kaiproctorattempted"]').count():
        session.page.evaluate(
            """() => document.querySelector('[name="kaiproctorattempted"]').closest('form').submit()"""
        )
        session.page.wait_for_load_state("domcontentloaded")
        session.beat(2)

    recorded = json.loads(moodle("draw-record", "learner"))
    session.note(f"recorded draw: {recorded}")

    assert recorded is not None, "sitting the exam recorded no draw"
    assert recorded["seedverified"] is True
    assert recorded["seed"] == recorded["expectedseed"]
    assert len(recorded["questionids"]) == 3

    # The rule each slot drew under is recorded too, so the paper can be judged
    # against what it was supposed to be.
    tags = [slot.get("tags", []) for slot in recorded["blueprint"]]
    session.note(f"blueprint: {tags}")
    assert sorted(sum(tags, [])) == ["easy", "hard", "medium"]


def test_a_tampered_seed_is_reported_as_tampered(session):
    session.note("rewrite the stored seed to a value nobody's identifiers give")
    moodle("tamper-seed", "learner")

    recorded = json.loads(moodle("draw-record", "learner"))
    session.note(f"after tampering: seed={recorded['seed']} expected={recorded['expectedseed']}")
    assert recorded["seedverified"] is False

    session.note("open the evidence report as the instructor")
    session.login("instructor")
    contextid = moodle("context-id", str(BLUEPRINT_CMID))
    userid = moodle("user-id", "learner")
    session.goto(f"/local/kaiproctor/report.php?userid={userid}&contextid={contextid}")
    session.beat(1.5)

    session.note("open the draw details")
    details = session.page.locator('[data-region="draw"] summary')
    assert details.count() >= 1, "the report does not show how the paper was drawn"
    details.first.click()
    session.beat(1.5)

    assert session.page.locator('[data-region="seed-mismatch"]').count() >= 1, (
        "the report shows a tampered seed as though it were sound"
    )
    assert session.page.locator('[data-region="seed-verified"]').count() == 0
