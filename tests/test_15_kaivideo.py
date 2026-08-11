"""Our own interactive video, driven the way a learner drives it.

Built rather than borrowed because the customer has to be able to audit all
three pieces they are buying, and a third-party plugin can be read but not
answered for.

What is worth testing is not that a video plays. It is the three claims the
activity makes and that a browser is the only place to check:

  - a question the author placed at 00:03 actually stops the video at 00:03;
  - dragging the seek bar past a question does not get past the question;
  - the correct answer is not sitting in the page waiting to be read.
"""
from __future__ import annotations

import json

import pytest

from conftest import moodle

def cmid_for(provider: str) -> int:
    """Look the activity up instead of hard-coding an id.

    A course-module id changes whenever the demo course is reseeded in a
    different order, and a test pinned to 15 fails for that reason alone.
    """
    return int(moodle("kaivideo-cmid", provider).strip())


KAIVIDEO_CMID = cmid_for("file")


@pytest.fixture
def fresh_video():
    """Clear this learner's answers so each test starts from the first
    question, and hand back what the timeline actually contains."""
    moodle("kaivideo-reset", "learner", str(KAIVIDEO_CMID))
    return json.loads(moodle("kaivideo-timeline", str(KAIVIDEO_CMID)))


def open_video(session, username: str = "learner"):
    session.login(username)
    session.goto(f"/mod/kaivideo/view.php?id={KAIVIDEO_CMID}")
    session.beat(1.5)


def play(session):
    """Start playback without needing a real click on the controls."""
    session.page.evaluate(
        "() => document.querySelector('[data-region=video]').play()")


def wait_for_question(session, timeout: int = 30_000) -> str:
    session.page.wait_for_selector('[data-region="question"]:not([hidden])',
                                   timeout=timeout)
    session.beat(1.5)
    return session.page.inner_text('[data-region="questiontext"]')


# --------------------------------------------------------------------------
# The learner's path through it
# --------------------------------------------------------------------------

def test_no_question_is_on_screen_before_one_is_due(session, fresh_video):
    """The panel must be invisible, not merely marked hidden.

    It carried Bootstrap's d-flex, whose display rule beats the [hidden]
    attribute, so an empty white card sat in the middle of the video from the
    moment the page loaded. Every test passed throughout: they matched
    :not([hidden]), which was true of the attribute while the thing was plainly
    on screen. Asserting what the browser paints is the only version of this
    check that was ever worth having.
    """
    open_video(session)

    assert not session.page.is_visible('[data-region="question"]'),         "the question panel is on screen before any question is due"
    session.note("nothing is covering the video on arrival")


def test_the_video_stops_where_the_author_put_a_question(session, fresh_video):
    first = fresh_video[0]
    session.note(f"a question is placed at {first['attime']}s: {first['questiontext'][:50]}")

    open_video(session)
    play(session)

    asked = wait_for_question(session)
    session.note(f"the video stopped and asked: {asked[:60]}")

    assert asked.strip() == first["questiontext"].strip()
    assert session.page.evaluate(
        "() => document.querySelector('[data-region=video]').paused"), \
        "the question is on screen but the video is still playing behind it"


def test_a_wrong_answer_does_not_reveal_the_right_one(session, fresh_video):
    """The activity offers another attempt, so the answer stays hidden.

    Showing the explanation and then offering "try again" makes the second
    attempt free — it is not another attempt at anything, it is a button that
    awards a mark. This is checked on what reaches the page, because that is
    where a learner would read it.
    """
    first = fresh_video[0]
    wrong = 1 if first["correctchoice"] != 1 else 0

    open_video(session)
    play(session)
    wait_for_question(session)

    session.note(f"answer wrongly on purpose (choice {wrong})")
    session.page.query_selector_all('[data-action="choose"]')[wrong].click()
    session.page.wait_for_selector('[data-region="outcome"]:not([hidden])',
                                   timeout=20_000)
    session.beat(1.5)

    assert session.page.get_attribute('[data-region="kaivideo"]', "data-state") == "wrong"
    feedback = session.page.inner_text('[data-region="feedback"]').strip()
    session.note(f"feedback shown after a wrong answer: {feedback or '(none)'}")
    assert feedback == "", "the explanation was handed over before the retry"

    # And the whole page: the correct answer must not be anywhere in it.
    correct_text = first["choices"][first["correctchoice"]]
    outcome = session.page.inner_text('[data-region="outcome"]')
    assert correct_text not in outcome, "the right answer was printed with the verdict"

    assert session.page.query_selector('[data-action="retry"]').is_visible()


def test_answering_correctly_lets_the_video_continue(session, fresh_video):
    first = fresh_video[0]

    open_video(session)
    play(session)
    wait_for_question(session)

    session.note(f"answer correctly (choice {first['correctchoice']})")
    session.page.query_selector_all('[data-action="choose"]')[first["correctchoice"]].click()
    session.page.wait_for_selector('[data-region="outcome"]:not([hidden])',
                                   timeout=20_000)
    session.beat(1.5)

    assert session.page.get_attribute('[data-region="kaivideo"]', "data-state") == "correct"
    # Now the explanation is the point of having asked.
    session.note(f"feedback: {session.page.inner_text('[data-region=feedback]')[:80]}")

    session.page.click('[data-action="continue"]')
    session.beat(2)

    assert session.page.query_selector('[data-region="question"][hidden]'), \
        "the question is still on screen after continuing"


def test_seeking_past_a_question_does_not_get_past_it(session, fresh_video):
    """The rule that makes 'must answer' mean anything.

    Watching for the playhead to cross a timestamp is the obvious
    implementation and the wrong one: the seek bar goes straight over it. A
    question is due whenever the playhead is at or beyond it and unanswered,
    which makes seeking and playing the same case.
    """
    assert len(fresh_video) >= 2, "this test needs a second question to skip to"
    second = fresh_video[1]

    open_video(session)
    play(session)

    first = fresh_video[0]
    wait_for_question(session)
    session.page.query_selector_all('[data-action="choose"]')[first["correctchoice"]].click()
    session.page.wait_for_selector('[data-region="outcome"]:not([hidden])', timeout=20_000)
    session.page.click('[data-action="continue"]')
    session.beat(1.5)

    session.note(f"drag the playhead well past the question at {second['attime']}s")
    session.page.evaluate(
        "() => { document.querySelector('[data-region=video]').currentTime = 30; }")

    asked = wait_for_question(session)
    session.note(f"it came up anyway: {asked[:60]}")
    assert asked.strip() == second["questiontext"].strip()
    assert session.page.evaluate(
        "() => document.querySelector('[data-region=video]').paused")


def test_the_correct_answer_is_not_in_the_page(session, fresh_video):
    """A player that knows the answer is a player a learner can read.

    The timeline reaches the browser without correct answers in it — checked
    against the page source rather than the API, because that is what somebody
    would actually open the developer tools on.
    """
    open_video(session)

    content = session.page.content()
    for item in fresh_video:
        marker = f'"correctchoice":{item["correctchoice"]}'
        assert marker not in content.replace(" ", ""), \
            "the correct answer was shipped to the browser"

    session.note(f"none of the {len(fresh_video)} answers are in the page")


# --------------------------------------------------------------------------
# What it records
# --------------------------------------------------------------------------

def test_the_grade_is_the_fraction_answered_correctly(session, fresh_video):
    """One right out of two is half the marks. Unanswered counts as wrong: a
    learner who skipped half the video has not earned the same mark as one who
    answered everything."""
    first = fresh_video[0]

    open_video(session)
    play(session)
    wait_for_question(session)
    session.page.query_selector_all('[data-action="choose"]')[first["correctchoice"]].click()
    session.page.wait_for_selector('[data-region="outcome"]:not([hidden])', timeout=20_000)
    session.beat(2)

    state = json.loads(moodle("kaivideo-state", "learner", str(KAIVIDEO_CMID)))
    session.note(f"after one correct answer of {len(fresh_video)}: {state}")

    assert state["correct"] == 1
    assert abs(state["fraction"] - 1 / len(fresh_video)) < 0.001
    assert abs(state["grade"] - (100 / len(fresh_video))) < 0.01, \
        f"the gradebook says {state['grade']}"


def test_every_attempt_is_kept_not_just_the_last(session, fresh_video):
    """A teacher asking "did they guess twice and then get it" has to be able
    to find out, and a table that overwrites cannot answer that."""
    first = fresh_video[0]
    wrong = 1 if first["correctchoice"] != 1 else 0

    open_video(session)
    play(session)
    wait_for_question(session)

    session.page.query_selector_all('[data-action="choose"]')[wrong].click()
    session.page.wait_for_selector('[data-region="outcome"]:not([hidden])', timeout=20_000)
    session.page.click('[data-action="retry"]')
    session.beat(1)
    session.page.query_selector_all('[data-action="choose"]')[first["correctchoice"]].click()
    session.page.wait_for_selector('[data-region="outcome"]:not([hidden])', timeout=20_000)
    session.beat(2)

    state = json.loads(moodle("kaivideo-state", "learner", str(KAIVIDEO_CMID)))
    session.note(f"attempts recorded: {state['attempts']}, counted correct: {state['correct']}")

    assert state["attempts"] == 2, "the first attempt was overwritten"
    assert state["correct"] == 1, "the latest answer is not the one that counts"


# --------------------------------------------------------------------------
# Alongside the rest of the system
# --------------------------------------------------------------------------

def test_it_can_be_proctored_like_any_other_activity(session):
    """The player is a plain <video> element, so the attention monitor drives
    it with no adapter at all — which is why this needed no new monitoring
    code, only permission to be listed."""
    supported = moodle("monitored-kinds").strip().split(",")
    session.note(f"activities that can be monitored: {supported}")
    assert "kaivideo" in [kind.strip() for kind in supported]

    original = moodle("monitored", str(KAIVIDEO_CMID)).strip()
    try:
        moodle("set-monitored", str(KAIVIDEO_CMID), "1")

        open_video(session)
        body = session.page.inner_text("body")
        session.note("the learner is told before anything starts")
        assert "คุมสอบ" in body or "เฝ้าดู" in body or "proctor" in body.lower()
    finally:
        moodle("set-monitored", str(KAIVIDEO_CMID), original or "0")


def test_the_assistant_can_point_a_learner_at_it(session):
    """It is an ordinary activity, so it appears in the navigation index like
    any other — no special case anywhere."""
    moodle("ask-purge-index")
    ranked = json.loads(moodle("ask-rank", "learner", "วิดีโอแบบมีปฏิสัมพันธ์"))

    urls = [item["url"] for item in ranked]
    session.note(f"top matches: {[item['title'] for item in ranked[:3]]}")
    assert any("/mod/kaivideo/view.php" in url for url in urls), \
        "our interactive video is not findable by the assistant"


def test_a_course_holding_one_can_still_be_backed_up_and_restored():
    """No page, and the most valuable test in this file.

    The first version declared FEATURE_BACKUP_MOODLE2 and shipped none of the
    classes behind it, so backing up any course containing the activity died
    with "class not found" — a core feature broken by an activity that
    otherwise worked perfectly. Nothing in the module's own behaviour showed
    it; only running a backup did.
    """
    result = json.loads(moodle("kaivideo-backup-restore"))

    assert result["activities"] >= 1, "the activity did not survive the round trip"
    assert result["questions"] >= 2, "the timeline did not come back"
    assert result["answers"] >= 1, "learner answers were lost in the round trip"


# --------------------------------------------------------------------------
# What the teacher sees, and when it counts as done
# --------------------------------------------------------------------------

def test_the_report_names_the_question_the_class_got_wrong(session, fresh_video):
    """The reason this page exists.

    "Most of the class picked answer 3 at 04:12" is not a fact about those
    learners; it is a fact about the four minutes of video before it, and it
    is the only thing on the page that tells somebody what to go and change.
    """
    first = fresh_video[0]
    wrong = 1 if first["correctchoice"] != 1 else 0

    session.note("arrange a class that mostly got the first question wrong")
    for who in ("learner", "learner2"):
        moodle("kaivideo-reset", who, str(KAIVIDEO_CMID))
        moodle("kaivideo-answer", who, str(KAIVIDEO_CMID), "0", str(wrong))

    session.login("instructor")
    session.goto(f"/mod/kaivideo/report.php?cmid={KAIVIDEO_CMID}")
    session.beat(2)

    table = session.page.inner_text('[data-region="by-question"]')
    session.note(f"the report says:\n{table[:400]}")

    row = session.page.query_selector(
        f'[data-question="{first["attime"]:02.0f}"], tr[data-struggled="1"]')
    assert row is not None, "nothing was flagged despite everybody getting it wrong"
    assert session.page.query_selector('[data-region="struggled"]'), \
        "the question the class failed is not marked"

    # And the commonest wrong answer is named, because that is usually the
    # misconception rather than the question being unclear.
    assert first["choices"][wrong] in table


def test_a_learner_cannot_open_the_report(session):
    session.login("learner")
    session.goto(f"/mod/kaivideo/report.php?cmid={KAIVIDEO_CMID}")
    session.beat(1.5)

    assert not session.page.query_selector('[data-region="by-learner"]'), \
        "a learner was shown everybody's results"
    session.note("the report refuses a learner")


def test_completion_counts_answering_every_question():
    """Answering counts, not answering correctly: a learner who worked through
    the whole video has done the activity, and whether they got the answers
    right is what the grade is for."""
    timeline = json.loads(moodle("kaivideo-timeline", str(KAIVIDEO_CMID)))
    moodle("kaivideo-reset", "learner", str(KAIVIDEO_CMID))
    moodle("kaivideo-set-completion", str(KAIVIDEO_CMID), "1", "0")

    try:
        state = json.loads(moodle("kaivideo-completion", "learner", str(KAIVIDEO_CMID)))
        assert state["completionanswerall"] is False, "complete before answering anything"

        # One of two is not all of them.
        moodle("kaivideo-answer", "learner", str(KAIVIDEO_CMID), "0",
               str(timeline[0]["correctchoice"]))
        state = json.loads(moodle("kaivideo-completion", "learner", str(KAIVIDEO_CMID)))
        assert state["completionanswerall"] is False, "half the questions counted as all"

        # Answered wrongly, and it still counts: the rule is about working
        # through the video, not about being right.
        wrong = 1 if timeline[1]["correctchoice"] != 1 else 0
        moodle("kaivideo-answer", "learner", str(KAIVIDEO_CMID), "1", str(wrong))
        state = json.loads(moodle("kaivideo-completion", "learner", str(KAIVIDEO_CMID)))
        assert state["completionanswerall"] is True, \
            "answering every question did not complete the activity"
    finally:
        moodle("kaivideo-set-completion", str(KAIVIDEO_CMID), "0", "0")


# --------------------------------------------------------------------------
# The other backend
# --------------------------------------------------------------------------

def test_a_youtube_video_plays_and_still_stops_for_a_question(session):
    """The same guarantee across a postMessage boundary.

    YouTube's own controls are switched off, because with them on a learner can
    seek and resume inside the iframe where nothing in the module can intervene
    — and "the video will not continue past an unanswered question" would stop
    being something we can say. Ours are the only controls, and the due-question
    rule is unchanged.

    Skipped when YouTube is unreachable: on a sealed network this cannot work,
    and pretending otherwise would be a test that lies about the environment.
    """
    cmid = cmid_for("youtube")
    timeline = json.loads(moodle("kaivideo-timeline", str(cmid)))
    moodle("kaivideo-reset", "learner", str(cmid))

    session.login("learner")
    session.goto(f"/mod/kaivideo/view.php?id={cmid}")
    session.beat(4)

    root = session.page.query_selector('[data-region="kaivideo"]')
    assert root.get_attribute("data-provider") == "youtube"

    if root.get_attribute("data-state") != "ready":
        pytest.skip("the YouTube player did not load — no route to youtube.com")

    session.note("the iframe loaded and published the shared player interface")
    assert session.page.query_selector("iframe"), "no iframe was created"
    assert session.page.evaluate("() => !!window.KAIVIDEO"), \
        "the proctoring monitor would have nothing to watch"

    # No native controls to press: ours are the only ones.
    session.note("press our own Play")
    session.page.click('[data-action="play"]')

    session.page.wait_for_selector('[data-region="question"]:not([hidden])',
                                   timeout=60_000)
    session.beat(2)

    asked = session.page.inner_text('[data-region="questiontext"]')
    session.note(f"stopped at {timeline[0]['attime']}s and asked: {asked[:50]}")
    assert asked.strip() == timeline[0]["questiontext"].strip()
    assert session.page.evaluate("() => window.KAIVIDEO.isPaused()"), \
        "the question is up but YouTube is still playing behind it"

    session.page.query_selector_all('[data-action="choose"]')[
        timeline[0]["correctchoice"]].click()
    session.page.wait_for_selector('[data-region="outcome"]:not([hidden])',
                                   timeout=20_000)
    session.beat(1.5)
    assert session.page.get_attribute('[data-region="kaivideo"]', "data-state") == "correct"


def test_an_address_nothing_can_play_is_refused_when_it_is_typed():
    """The commonest authoring mistake is pasting a page that contains a video
    rather than the video. Caught at the form, because otherwise it reaches the
    learner as an empty player with nothing to explain it."""
    for good in ["https://www.youtube.com/watch?v=aqz-KE-bpKQ",
                 "https://youtu.be/aqz-KE-bpKQ",
                 "https://example.test/lesson.mp4"]:
        assert moodle("kaivideo-playable", good).strip() == "yes", good

    for bad in ["https://example.test/watch/lesson",
                "https://vimeo.com/123456789",
                "not a url"]:
        assert moodle("kaivideo-playable", bad).strip() == "no", bad
