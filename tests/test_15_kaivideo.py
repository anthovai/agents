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


def graded(timeline: list) -> list:
    """The items that carry a mark. Info cards are not questions and are not in
    the grade's denominator, so a test that counts them counts wrongly."""
    return [item for item in timeline if item["type"] != "info"]


def right(item: dict) -> int:
    """The correct option of a single-answer question."""
    return item["answers"][0]


def wrong(item: dict) -> int:
    """Any option that is not correct."""
    return next(index for index in range(len(item["choices"]))
                if index not in item["answers"])


def sent_for(item: dict, correct: bool = True) -> str:
    """What the browser would send for this item, without a browser.

    The same string the player builds, so the helper exercises the path the
    player uses rather than one invented for testing.
    """
    if item["type"] == "info":
        return ""
    if item["type"] == "shorttext":
        return item["answers"][0] if correct else "คำตอบที่ไม่มีในรายการ"
    return json.dumps(item["answers"] if correct else [wrong(item)])


def answer_first(session, item: dict, index: int):
    """Click one option and wait for the verdict."""
    session.page.query_selector_all('[data-action="choose"]')[index].click()
    session.page.wait_for_selector('[data-region="outcome"]:not([hidden])',
                                   timeout=20_000)


def answer_correctly(session, item: dict):
    """Give the right answer, whatever kind of question it is."""
    if item["type"] == "choice":
        answer_first(session, item, right(item))
        return

    if item["type"] == "multichoice":
        for index in item["answers"]:
            session.page.query_selector_all('[data-action="choose"]')[index].check()
    elif item["type"] == "shorttext":
        session.page.fill('[data-region="typedinput"]', item["answers"][0])

    session.page.click('[data-action="submit"]')
    session.page.wait_for_selector('[data-region="outcome"]:not([hidden])',
                                   timeout=20_000)


def work_through(session, timeline: list, upto: int):
    """Deal with the first `upto` items so a test can reach a later one."""
    for item in timeline[:upto]:
        if item["type"] == "info":
            # No answer to give: it is shown, acknowledged and dismissed.
            session.page.wait_for_selector('[data-region="outcome"]:not([hidden])',
                                           timeout=20_000)
        else:
            wait_for_question(session)
            answer_correctly(session, item)
        session.page.click('[data-action="continue"]')
        session.beat(1)


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
    picked = wrong(first)

    open_video(session)
    play(session)
    wait_for_question(session)

    session.note(f"answer wrongly on purpose (choice {picked})")
    answer_first(session, first, picked)
    session.beat(1.5)

    assert session.page.get_attribute('[data-region="kaivideo"]', "data-state") == "wrong"
    feedback = session.page.inner_text('[data-region="feedback"]').strip()
    session.note(f"feedback shown after a wrong answer: {feedback or '(none)'}")
    assert feedback == "", "the explanation was handed over before the retry"

    # And the whole page: the correct answer must not be anywhere in it.
    correct_text = first["choices"][right(first)]
    outcome = session.page.inner_text('[data-region="outcome"]')
    assert correct_text not in outcome, "the right answer was printed with the verdict"

    assert session.page.query_selector('[data-action="retry"]').is_visible()


def test_answering_correctly_lets_the_video_continue(session, fresh_video):
    first = fresh_video[0]

    open_video(session)
    play(session)
    wait_for_question(session)

    session.note(f"answer correctly (choice {right(first)})")
    answer_first(session, first, right(first))
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
    answer_first(session, first, right(first))
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
    assert '"answers"' not in content.replace(" ", ""), \
        "the answer key was shipped to the browser"

    # And by their content, not only by the key that would carry them. A typed
    # question is the case that would go unnoticed: its accepted answers are
    # words, so they would read as ordinary text in the page rather than as
    # something obviously named.
    for item in fresh_video:
        if item["type"] != "shorttext":
            continue
        for accepted in item["answers"]:
            assert accepted not in content, \
                f"an accepted answer ({accepted}) is sitting in the page"

    session.note(f"none of the {len(fresh_video)} answers are in the page")


# --------------------------------------------------------------------------
# The other kinds of interruption
# --------------------------------------------------------------------------

def test_a_multiple_answer_question_needs_all_of_them(session, fresh_video):
    """Part of the set earns nothing.

    Half a mark for half the boxes reads as generous and is not: it invites an
    argument about the scheme that neither the learner nor the teacher can
    settle from the record, and it lets somebody who ticked everything but the
    hard one score the same as somebody who understood the question.
    """
    item = next(i for i in fresh_video if i["type"] == "multichoice")
    index = fresh_video.index(item)

    open_video(session)
    play(session)
    work_through(session, fresh_video, index)

    asked = wait_for_question(session)
    session.note(f"a question with {len(item['answers'])} right answers: {asked[:50]}")

    boxes = session.page.query_selector_all('[data-action="choose"]')
    assert len(boxes) == len(item["choices"])
    assert session.page.query_selector('[data-action="submit"]').is_visible(), \
        "there is no way to submit more than one answer"

    session.note("tick all but one of the right answers")
    for pick in item["answers"][:-1]:
        boxes[pick].check()
    session.page.click('[data-action="submit"]')
    session.page.wait_for_selector('[data-region="outcome"]:not([hidden])',
                                   timeout=20_000)
    session.beat(1.5)

    assert session.page.get_attribute('[data-region="kaivideo"]',
                                      "data-state") == "wrong", \
        "an incomplete set of answers was marked correct"

    session.note("now all of them")
    session.page.click('[data-action="retry"]')
    session.beat(1)
    answer_correctly(session, item)
    session.beat(1.5)

    assert session.page.get_attribute('[data-region="kaivideo"]',
                                      "data-state") == "correct"


def test_a_typed_answer_forgives_spacing_but_not_spelling(session, fresh_video):
    """Extra spaces and English capitals are forgiven, and nothing else.

    Stripping Thai tone marks would make ผู้ and ผู the same word, which is not
    leniency — it is accepting a misspelling as correct. Authors who want a
    variant list it, which keeps the decision with the person who knows the
    subject.
    """
    item = next(i for i in fresh_video if i["type"] == "shorttext")
    index = fresh_video.index(item)
    accepted = item["answers"][0]

    open_video(session)
    play(session)
    work_through(session, fresh_video, index)

    asked = wait_for_question(session)
    session.note(f"a typed question: {asked[:50]}")

    assert not session.page.query_selector_all('[data-action="choose"]'), \
        "a typed question is showing options to pick from"
    assert session.page.query_selector('[data-region="typedinput"]').is_visible()

    session.note(f"type it with stray spacing: '  {accepted}  '")
    session.page.fill('[data-region="typedinput"]', f"  {accepted}  ")
    session.page.click('[data-action="submit"]')
    session.page.wait_for_selector('[data-region="outcome"]:not([hidden])',
                                   timeout=20_000)
    session.beat(1.5)

    assert session.page.get_attribute('[data-region="kaivideo"]',
                                      "data-state") == "correct", \
        "the same word with extra spaces was marked wrong"


def test_an_empty_box_is_not_submitted_as_an_answer(session, fresh_video):
    """Sending it would record a wrong answer they did not give, and where
    retries are off it would spend their only attempt on a mis-click."""
    item = next(i for i in fresh_video if i["type"] == "shorttext")
    index = fresh_video.index(item)

    open_video(session)
    play(session)
    work_through(session, fresh_video, index)
    wait_for_question(session)

    session.note("press submit with nothing typed")
    session.page.click('[data-action="submit"]')
    session.beat(2)

    assert session.page.query_selector('[data-region="outcome"][hidden]'), \
        "an empty box was marked"
    assert session.page.query_selector('[data-region="typedinput"]').is_visible(), \
        "the question was dismissed without an answer"


def test_a_message_is_not_marked_right_or_wrong(session, fresh_video):
    """The type exists so authors stop writing a question with one obvious
    answer in order to say something. Calling it "correct" would put the
    confusion straight back."""
    item = next(i for i in fresh_video if i["type"] == "info")
    index = fresh_video.index(item)

    open_video(session)
    play(session)
    work_through(session, fresh_video, index)

    session.page.wait_for_selector('[data-region="question"]:not([hidden])',
                                   timeout=30_000)
    session.beat(1.5)

    shown = session.page.inner_text('[data-region="questiontext"]')
    session.note(f"the video stopped and said: {shown[:60]}")
    assert shown.strip() == item["questiontext"].strip()

    assert session.page.get_attribute('[data-region="kaivideo"]',
                                      "data-state") == "info"
    assert session.page.inner_text('[data-region="verdict"]').strip() == "", \
        "a message was given a right/wrong verdict"
    assert not session.page.query_selector_all('[data-action="choose"]'), \
        "a message is offering something to answer"

    session.page.click('[data-action="continue"]')
    session.beat(2)
    assert session.page.query_selector('[data-region="question"][hidden]')


def test_a_message_does_not_move_the_grade(session, fresh_video):
    """Reading a message is not a question. Counting it would inflate everyone's
    mark by however many an author happened to add."""
    questions = graded(fresh_video)
    info = next(i for i in fresh_video if i["type"] == "info")
    index = fresh_video.index(info)

    moodle("kaivideo-answer", "learner", str(KAIVIDEO_CMID), str(index), "")
    state = json.loads(moodle("kaivideo-state", "learner", str(KAIVIDEO_CMID)))
    session.note(f"after acknowledging the message only: {state}")

    assert state["fraction"] == 0, "a message earned marks"

    first = questions[0]
    moodle("kaivideo-answer", "learner", str(KAIVIDEO_CMID), "0", sent_for(first))
    state = json.loads(moodle("kaivideo-state", "learner", str(KAIVIDEO_CMID)))
    session.note(f"and after one real question: {state}")

    assert abs(state["fraction"] - 1 / len(questions)) < 0.001, \
        "the message is in the denominator"


# --------------------------------------------------------------------------
# What it records
# --------------------------------------------------------------------------

def test_the_grade_is_the_fraction_answered_correctly(session, fresh_video):
    """One right out of four is a quarter of the marks. Unanswered counts as
    wrong: a learner who skipped half the video has not earned the same mark as
    one who answered everything.

    The denominator is the graded items. Info cards are not questions, and
    counting them would inflate everybody's mark by however many an author
    happened to add.
    """
    first = fresh_video[0]
    questions = len(graded(fresh_video))

    open_video(session)
    play(session)
    wait_for_question(session)
    answer_first(session, first, right(first))
    session.beat(2)

    state = json.loads(moodle("kaivideo-state", "learner", str(KAIVIDEO_CMID)))
    session.note(f"after one correct answer of {questions} questions "
                 f"({len(fresh_video)} items on the timeline): {state}")

    assert state["correct"] == 1
    assert abs(state["fraction"] - 1 / questions) < 0.001
    assert abs(state["grade"] - (100 / questions)) < 0.01, \
        f"the gradebook says {state['grade']}"


def test_every_attempt_is_kept_not_just_the_last(session, fresh_video):
    """A teacher asking "did they guess twice and then get it" has to be able
    to find out, and a table that overwrites cannot answer that."""
    first = fresh_video[0]

    open_video(session)
    play(session)
    wait_for_question(session)

    answer_first(session, first, wrong(first))
    session.page.click('[data-action="retry"]')
    session.beat(1)
    answer_first(session, first, right(first))
    session.beat(2)

    state = json.loads(moodle("kaivideo-state", "learner", str(KAIVIDEO_CMID)))
    session.note(f"attempts recorded: {state['attempts']}, counted correct: {state['correct']}")

    assert state["attempts"] == 2, "the first attempt was overwritten"
    assert state["correct"] == 1, "the latest answer is not the one that counts"


def test_a_returning_learner_resumes_where_they_left_off(session, fresh_video):
    """The video remembers how far they got, across sessions.

    Half of this lives in the back office (the furthest point, recorded per
    learner) and half in the player (seek there on arrival). Either half can
    break with the other still working, and the symptom — every return visit
    starts from zero — is the kind of thing nobody files a bug about; they
    just scrub forward by hand and resent it.

    Answered questions stay answered: resuming must not re-ask what the first
    sitting already dealt with.
    """
    session.note("answer everything, the way a finished first sitting did")
    for index, item in enumerate(fresh_video):
        moodle("kaivideo-answer", "learner", str(KAIVIDEO_CMID), str(index),
               sent_for(item))
    moodle("kaivideo-reach", "learner", str(KAIVIDEO_CMID), "20")

    session.note("come back to the activity in a new page")
    open_video(session)
    session.beat(2)

    position = session.page.evaluate(
        "() => document.querySelector('[data-region=video]').currentTime")
    session.note(f"the playhead opened at {position:.1f}s")
    assert position >= 19, \
        f"a returning learner was put back at {position:.1f}s, not where they left off"

    assert not session.page.is_visible('[data-region="question"]'), \
        "resuming re-asked a question that was already answered"


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


def test_a_teacher_can_reach_the_monitoring_switch_by_clicking(session):
    """Turning monitoring on has to be reachable from the activity itself.

    Every test that needed monitoring on set it through the CLI helper, which
    is fine for arranging a test and useless as evidence that a teacher can do
    it. They could not: the callback offering the link was named
    local_kaiproctor_extend_navigation_course_module, which is not a callback
    Moodle has — core calls local_<plugin>_extend_settings_navigation for local
    plugins, so the function sat there never being called and the link never
    appeared. The only way in was to already know the URL.
    """
    session.note("sign in as the teacher and open the activity")
    session.login("instructor")
    session.goto(f"/mod/kaivideo/view.php?id={KAIVIDEO_CMID}")
    session.beat(1.5)

    link = session.page.locator('a[href*="kaiproctor/monitor.php"]')
    assert link.count() >= 1, (
        "a teacher has no way to reach the monitoring switch but to know the URL"
    )
    session.note(f"offered as: {link.first.inner_text().strip()}")

    session.note("follow it, the way a teacher would")
    link.first.click()
    session.page.wait_for_load_state("domcontentloaded")
    session.beat(1.5)

    assert "monitor.php" in session.page.url, "the link does not lead to the switch"
    assert session.page.locator('input[type="checkbox"], select').count() >= 1, (
        "the page offers nothing to switch"
    )


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
    picked = wrong(first)

    session.note("arrange a class that mostly got the first question wrong")
    for who in ("learner", "learner2"):
        moodle("kaivideo-reset", who, str(KAIVIDEO_CMID))
        moodle("kaivideo-answer", who, str(KAIVIDEO_CMID), "0",
               json.dumps([picked]))

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
    assert first["choices"][picked] in table


def test_results_are_broken_down_by_topic(session, fresh_video):
    """One video usually covers several subjects, and "60%" does not say which
    one somebody is weak on.

    The per-question table finds the badly worded question; this finds the
    section of video that did not teach what it was meant to. Different
    findings, different fixes.
    """
    # Graded items only: an info card has no topic score to contribute, and
    # picking by position would land on one as soon as an author adds a card.
    graded = [(index, item) for index, item in enumerate(fresh_video)
              if item["type"] != "info"]
    (rightindex, rightitem), (wrongindex, wrongitem) = graded[0], graded[1]

    moodle("kaivideo-categorise", str(KAIVIDEO_CMID), str(rightindex), "ความปลอดภัย")
    moodle("kaivideo-categorise", str(KAIVIDEO_CMID), str(wrongindex), "คุณภาพ")

    session.note("a class that gets the first topic right and the second wrong")
    for who in ("learner", "learner2"):
        moodle("kaivideo-reset", who, str(KAIVIDEO_CMID))
        moodle("kaivideo-answer", who, str(KAIVIDEO_CMID), str(rightindex),
               sent_for(rightitem, True))
        moodle("kaivideo-answer", who, str(KAIVIDEO_CMID), str(wrongindex),
               sent_for(wrongitem, False))

    rows = {row["category"]: row
            for row in json.loads(moodle("kaivideo-categories", str(KAIVIDEO_CMID)))}
    session.note(f"per topic: {rows}")

    assert rows["ความปลอดภัย"]["correctshare"] == 100
    assert rows["คุณภาพ"]["correctshare"] == 0
    assert rows["คุณภาพ"]["struggled"], "the failing topic is not flagged"

    session.note("and the teacher sees it, weakest topic first")
    session.login("instructor")
    session.goto(f"/mod/kaivideo/report.php?cmid={KAIVIDEO_CMID}")
    session.beat(2)

    table = session.page.locator('[data-region="by-category"]')
    assert table.count() == 1, "the report has no topic table"

    text = table.inner_text()
    session.note(f"the topic table says:\n{text[:300]}")
    assert "ความปลอดภัย" in text and "คุณภาพ" in text

    # 0% and "nobody answered" are opposite findings, and mustache treats zero
    # as absent — so the worst topic on the page rendered as having no result.
    assert "0%" in text, "a topic the class got wrong is not shown as 0%"

    first_row = table.locator("tbody tr").first.inner_text()
    session.note(f"top row: {first_row}")
    assert "คุณภาพ" in first_row, "the weakest topic is not first"


def test_a_recategorised_question_does_not_rewrite_past_results(session, fresh_video):
    """What a topic scored last term stays what it scored.

    The answer carries the topic it was marked under, rather than reading it
    back through the question. Refiling a question is an ordinary thing to do
    between cohorts and must not silently change a report somebody has already
    acted on.
    """
    index, item = next((n, i) for n, i in enumerate(fresh_video)
                       if i["type"] != "info")
    moodle("kaivideo-categorise", str(KAIVIDEO_CMID), str(index), "หมวดเดิม")

    moodle("kaivideo-reset", "learner", str(KAIVIDEO_CMID))
    moodle("kaivideo-answer", "learner", str(KAIVIDEO_CMID), str(index),
           sent_for(item, True))

    before = json.loads(moodle("kaivideo-response-categories", str(KAIVIDEO_CMID)))
    session.note(f"stamped on the answer: {before}")
    assert any(row["category"] == "หมวดเดิม" for row in before)

    session.note("the author refiles the question under a different topic")
    moodle("kaivideo-categorise", str(KAIVIDEO_CMID), str(index), "หมวดใหม่")

    after = json.loads(moodle("kaivideo-response-categories", str(KAIVIDEO_CMID)))
    session.note(f"the answer still says: {after}")
    assert any(row["category"] == "หมวดเดิม" for row in after), (
        "refiling the question rewrote what the learner was marked under"
    )
    assert not any(row["category"] == "หมวดใหม่" for row in after)


def test_an_author_can_add_each_kind_from_the_editor(session):
    """The four types share one form, and this is the only test that opens it.

    Everything else on this page reaches the timeline through timeline::save,
    which is not how an author reaches it. The form is where a type can be
    right in the model and unusable in practice — a field hidden for the wrong
    type, a tick box the save path never reads — and none of that shows up
    anywhere else.
    """
    # Start from the seeded timeline even if a previous run died mid-way. Two
    # questions cannot sit at the same moment, so a leftover would make the
    # save fail rather than make this test fail — which is the worse of the two.
    for item in json.loads(moodle("kaivideo-timeline", str(KAIVIDEO_CMID))):
        if item["attime"] in (40.0, 45.0):
            moodle("kaivideo-delete-item", str(item["id"]))

    session.login("instructor")
    session.goto(f"/mod/kaivideo/edit.php?cmid={KAIVIDEO_CMID}")
    session.beat(1.5)

    before = len(json.loads(moodle("kaivideo-timeline", str(KAIVIDEO_CMID))))

    session.note("add a typed question at 40s")
    session.page.select_option('select[name="type"]', "shorttext")
    session.beat(1)

    # The options are for the other types and must be out of the way, or the
    # author is asked to fill in boxes that will be thrown away on save.
    assert not session.page.is_visible('input[name="choice0"]'), \
        "a typed question is asking for multiple-choice options"

    session.page.fill('input[name="attime"]', "40")
    session.page.fill('textarea[name="questiontext"]', "ระบบนี้ชื่อย่อว่าอะไร")
    session.page.fill('textarea[name="acceptedanswers"]', "kaiproctor\nไคโปรคเตอร์")
    session.page.click('#id_submitbutton')
    session.beat(2)

    timeline = json.loads(moodle("kaivideo-timeline", str(KAIVIDEO_CMID)))
    added = next((i for i in timeline if abs(i["attime"] - 40) < 0.01), None)
    assert added is not None, "the typed question was not saved"
    session.note(f"saved as {added['type']}, accepts: {added['answerlabel']}")
    assert added["type"] == "shorttext"
    assert added["answers"] == ["kaiproctor", "ไคโปรคเตอร์"]

    session.note("and a multiple-answer question at 45s")
    session.page.select_option('select[name="type"]', "multichoice")
    session.beat(1)
    assert session.page.is_visible('input[name="choice0"]')
    assert not session.page.is_visible('textarea[name="acceptedanswers"]')

    session.page.fill('input[name="attime"]', "45")
    session.page.fill('textarea[name="questiontext"]', "ข้อใดถูกบ้าง")
    for index, text in enumerate(["ก", "ข", "ค"]):
        session.page.fill(f'input[name="choice{index}"]', text)
    # Typed, because advcheckbox renders a hidden input of the same name
    # alongside the box to carry the unticked value.
    session.page.check('input[type="checkbox"][name="correct0"]')
    session.page.check('input[type="checkbox"][name="correct2"]')
    session.page.click('#id_submitbutton')
    session.beat(2)

    timeline = json.loads(moodle("kaivideo-timeline", str(KAIVIDEO_CMID)))
    added = next((i for i in timeline if abs(i["attime"] - 45) < 0.01), None)
    assert added is not None, "the multiple-answer question was not saved"
    session.note(f"saved as {added['type']}, right answers: {added['answerlabel']}")
    assert added["answers"] == [0, 2], \
        "the ticks did not survive the round trip through the form"

    assert len(timeline) == before + 2

    session.note("tidy up so the rest of the suite sees the seeded timeline")
    for item in timeline:
        if item["attime"] in (40.0, 45.0):
            moodle("kaivideo-delete-item", str(item["id"]))


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
    questions = graded(timeline)
    moodle("kaivideo-reset", "learner", str(KAIVIDEO_CMID))
    moodle("kaivideo-set-completion", str(KAIVIDEO_CMID), "1", "0")

    try:
        state = json.loads(moodle("kaivideo-completion", "learner", str(KAIVIDEO_CMID)))
        assert state["completionanswerall"] is False, "complete before answering anything"

        # Everything except the first: one short is not all of them.
        for index, item in enumerate(timeline):
            if index == 0:
                continue
            moodle("kaivideo-answer", "learner", str(KAIVIDEO_CMID), str(index),
                   sent_for(item))
        state = json.loads(moodle("kaivideo-completion", "learner", str(KAIVIDEO_CMID)))
        assert state["completionanswerall"] is False, \
            "one short of every question counted as all of them"

        # And the one left over answered wrongly, which still counts: the rule
        # is about working through the video, not about being right.
        moodle("kaivideo-answer", "learner", str(KAIVIDEO_CMID), "0",
               sent_for(timeline[0], correct=False))
        state = json.loads(moodle("kaivideo-completion", "learner", str(KAIVIDEO_CMID)))
        assert state["completionanswerall"] is True, \
            f"working through all {len(timeline)} items ({len(questions)} of them " \
            "questions) did not complete the activity"
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

    answer_first(session, timeline[0], right(timeline[0]))
    session.beat(1.5)
    assert session.page.get_attribute('[data-region="kaivideo"]', "data-state") == "correct"


# --------------------------------------------------------------------------
# The video living in Moodle rather than somewhere else
# --------------------------------------------------------------------------

def test_an_uploaded_video_plays_and_stops_for_its_question(session):
    """Uploading is the option most teachers can actually take.

    Pointing at a URL assumes somewhere to put the file, which most people
    teaching a course do not have — and a lesson that depends on an address
    outside Moodle stops working whenever that address does.

    Nothing downstream knows the difference: it is the same <video> element,
    the same due-question rule, the same proctoring adapter. That is the claim
    worth checking, so this drives it exactly like the linked one.
    """
    cmid = int(moodle("kaivideo-cmid", "upload").strip())
    timeline = json.loads(moodle("kaivideo-timeline", str(cmid)))
    moodle("kaivideo-reset", "learner", str(cmid))

    session.login("learner")
    session.goto(f"/mod/kaivideo/view.php?id={cmid}")
    session.beat(1.5)

    src = session.page.get_attribute('[data-region="video"]', "src")
    session.note(f"served from {src}")
    assert "/pluginfile.php/" in src, "the uploaded video is not served through Moodle"

    session.page.evaluate(
        "() => document.querySelector('[data-region=video]').play()")
    asked = wait_for_question(session)
    session.note(f"stopped at {timeline[0]['attime']}s and asked: {asked[:50]}")
    assert asked.strip() == timeline[0]["questiontext"].strip()

    answer_first(session, timeline[0], right(timeline[0]))
    session.beat(1.5)
    assert session.page.get_attribute('[data-region="kaivideo"]',
                                      "data-state") == "correct"


def test_the_uploaded_video_is_not_served_to_a_stranger(session):
    """Course material, not a public URL.

    A pluginfile address that plays for anybody with an account is an address
    that gets passed around, and the video is the part of a paid course that is
    worth passing around.
    """
    cmid = int(moodle("kaivideo-cmid", "upload").strip())
    session.login("learner")
    session.goto(f"/mod/kaivideo/view.php?id={cmid}")
    session.beat(1)
    src = session.page.get_attribute('[data-region="video"]', "src")

    session.note("fetch the same address signed out")
    # On the content type, not the status. Moodle answers an unauthenticated
    # request with the login page, which is a perfectly successful 200 — a test
    # that only looked at the status would have passed while serving the video.
    #
    # no-store because the page has already played it: without that the fetch
    # is answered out of the browser's own cache and never reaches Moodle, so
    # the check reports on a copy the browser already had rather than on what
    # the server will hand a stranger.
    answer = session.page.evaluate(
        """async (url) => {
            const response = await fetch(url, {credentials: 'omit', cache: 'no-store'});
            return {status: response.status,
                    type: response.headers.get('content-type') || ''};
        }""", src)
    session.note(f"without a session: {answer['status']} {answer['type']}")
    assert not answer["type"].startswith("video/"), \
        "the uploaded video is readable without logging in"


def test_the_form_knows_which_source_an_activity_already_uses(session):
    """The choice is derived from what is there, not stored in a column.

    A column saying "this one uses an upload" can end up disagreeing with
    whether a file exists — after a restore, after an admin deletes the file,
    after a half-finished save — and the activity is then broken in a way the
    form cannot show. The file area is the fact. This checks the form reads it
    back correctly for both kinds.
    """
    session.login("instructor")

    uploaded = int(moodle("kaivideo-cmid", "upload").strip())
    session.goto(f"/course/modedit.php?update={uploaded}")
    session.beat(1.5)

    chosen = session.page.input_value('select[name="sourcetype"]')
    session.note(f"the uploaded activity opens on '{chosen}'")
    assert chosen == "file"
    assert not session.page.is_visible('input[name="videourl"]'), \
        "the address box is on screen for an uploaded video"

    linked = cmid_for("youtube")
    session.goto(f"/course/modedit.php?update={linked}")
    session.beat(1.5)

    chosen = session.page.input_value('select[name="sourcetype"]')
    session.note(f"the linked activity opens on '{chosen}'")
    assert chosen == "url"
    assert session.page.is_visible('input[name="videourl"]')
    assert "youtube" in session.page.input_value('input[name="videourl"]')

    session.note("switch it to upload without choosing a file, and save")
    session.page.select_option('select[name="sourcetype"]', "file")
    session.beat(1)
    session.page.click('#id_submitbutton')
    session.beat(2)

    # Refused, because an activity with neither a file nor an address is a
    # black rectangle with nothing to explain it.
    assert session.page.query_selector('select[name="sourcetype"]'), \
        "the empty upload was accepted"
    body = session.page.inner_text("body")
    session.note("the form came back with an error rather than saving")
    assert "เลือกไฟล์วิดีโอ" in body or "Choose a video file" in body

    # And nothing was written: the linked activity still plays.
    session.goto(f"/mod/kaivideo/view.php?id={linked}")
    session.beat(1.5)
    assert session.page.get_attribute('[data-region="kaivideo"]',
                                      "data-provider") == "youtube", \
        "the refused save changed the activity anyway"


def test_the_uploaded_video_survives_backup_and_restore():
    """The reason uploading is worth having at all.

    A linked video does not travel with the course; the copy restores with a
    working timeline against nothing to play, which reads as the questions
    having broken. The file area has to be annotated in the backup step for
    this to be true, and nothing but a round trip shows whether it was.
    """
    result = json.loads(moodle("kaivideo-backup-restore"))

    assert result["activities"] >= 1, "the activity did not survive the round trip"
    assert result["files"] >= 1, "the uploaded video did not come back with the course"


def test_a_vimeo_video_plays_and_still_stops_for_a_question(session):
    """The same guarantee across a second postMessage boundary.

    Vimeo's controls are not hidden here the way YouTube's are. They can be
    asked to go away, but whether the request is honoured depends on the
    account the video sits in — and a guarantee that holds only on some
    customers' accounts is not a guarantee. A transparent sheet over the iframe
    puts them out of reach instead, so ours stay the only controls whatever the
    account allows.

    Skipped when Vimeo is unreachable: on a sealed network this cannot work,
    and pretending otherwise would be a test that lies about the environment.
    """
    cmid = cmid_for("vimeo")
    timeline = json.loads(moodle("kaivideo-timeline", str(cmid)))
    moodle("kaivideo-reset", "learner", str(cmid))

    session.login("learner")
    session.goto(f"/mod/kaivideo/view.php?id={cmid}")
    session.beat(4)

    root = session.page.query_selector('[data-region="kaivideo"]')
    assert root.get_attribute("data-provider") == "vimeo"

    if root.get_attribute("data-state") != "ready":
        # Not a skip. Vimeo refuses to embed a video on a site its privacy
        # settings do not list, and answers with a 401 inside the iframe where
        # the SDK never sees it — so the player's ready promise simply never
        # settles. The first version sat on "loading" for ever with a blank box
        # and nothing on the page to explain it, which is what a customer with
        # the wrong domain setting would have reported as "your player is
        # broken". What has to be true here is that they are told.
        session.beat(16)
        problem = session.page.query_selector('[data-region="problem"]')
        message = session.page.inner_text('[data-region="problem"]').strip()
        session.note(f"the embed was refused, and the page says: {message}")

        assert problem.is_visible(), \
            "the player gave up silently and left an empty box"
        assert "Vimeo" in message or "vimeo" in message, \
            "the message does not say which player failed or what to check"
        pytest.skip("vimeo.com will not embed here — the failure path was checked instead")

    session.note("the iframe loaded and published the shared player interface")
    assert session.page.query_selector("iframe"), "no iframe was created"
    assert session.page.evaluate("() => !!window.KAIVIDEO"), \
        "the proctoring monitor would have nothing to watch"

    # The sheet has to be over the iframe, not merely present: without it a
    # learner reaches Vimeo's own seek bar and the whole rule collapses.
    covered = session.page.evaluate(
        """() => {
            const shield = document.querySelector('[data-region="shield"]');
            const box = shield.getBoundingClientRect();
            const at = document.elementFromPoint(
                box.left + box.width / 2, box.top + box.height / 2);
            return at === shield;
        }""")
    session.note(f"the middle of the video belongs to the shield: {covered}")
    assert covered, "Vimeo's own controls are reachable"

    session.note("press our own Play")
    session.page.click('[data-action="play"]')

    session.page.wait_for_selector('[data-region="question"]:not([hidden])',
                                   timeout=60_000)
    session.beat(2)

    asked = session.page.inner_text('[data-region="questiontext"]')
    session.note(f"stopped at {timeline[0]['attime']}s and asked: {asked[:50]}")
    assert asked.strip() == timeline[0]["questiontext"].strip()
    assert session.page.evaluate("() => window.KAIVIDEO.isPaused()"), \
        "the question is up but Vimeo is still playing behind it"

    answer_first(session, timeline[0], right(timeline[0]))
    session.beat(1.5)
    assert session.page.get_attribute('[data-region="kaivideo"]',
                                      "data-state") == "correct"


def test_an_hls_stream_plays_in_an_ordinary_video_element(session):
    """The cheapest of the four backends, and the reason it was cheap.

    The stream is attached to a real <video> by video.js, which Moodle already
    ships with @videojs/http-streaming inside it. Nothing downstream knows: the
    same element, the same controls, the same due-question rule, the same
    proctoring adapter. This checks that claim rather than taking it — if the
    element were replaced or wrapped, everything built on it would be looking
    at the wrong thing.

    Skipped when the demo stream is unreachable, for the same reason as Vimeo.
    """
    cmid = cmid_for("hls")
    timeline = json.loads(moodle("kaivideo-timeline", str(cmid)))
    moodle("kaivideo-reset", "learner", str(cmid))

    session.login("learner")
    session.goto(f"/mod/kaivideo/view.php?id={cmid}")
    session.beat(4)

    root = session.page.query_selector('[data-region="kaivideo"]')
    assert root.get_attribute("data-provider") == "hls"
    assert not session.page.query_selector("iframe"), \
        "an HLS stream should not need an iframe"

    if root.get_attribute("data-state") != "ready":
        pytest.skip("video.js did not load")

    playing = session.page.evaluate(
        """async () => {
            const video = document.querySelector('[data-region="video"]');
            try {
                await video.play();
            } catch (error) {
                return {error: error.name};
            }
            await new Promise(r => setTimeout(r, 3000));
            return {time: video.currentTime, tag: video.tagName};
        }""")
    session.note(f"after three seconds of playback: {playing}")

    if playing.get("error") or not playing.get("time"):
        pytest.skip("the demo stream did not start — no route to it")

    assert playing["tag"] == "VIDEO", \
        "the stream is not on a video element any more"

    session.page.wait_for_selector('[data-region="question"]:not([hidden])',
                                   timeout=60_000)
    session.beat(1.5)
    asked = session.page.inner_text('[data-region="questiontext"]')
    session.note(f"stopped at {timeline[0]['attime']}s and asked: {asked[:50]}")
    assert asked.strip() == timeline[0]["questiontext"].strip()

    assert session.page.evaluate(
        "() => document.querySelector('[data-region=video]').paused"), \
        "the question is on screen but the stream is still playing behind it"


def test_an_address_nothing_can_play_is_refused_when_it_is_typed():
    """The commonest authoring mistake is pasting a page that contains a video
    rather than the video. Caught at the form, because otherwise it reaches the
    learner as an empty player with nothing to explain it."""
    for good in ["https://www.youtube.com/watch?v=aqz-KE-bpKQ",
                 "https://youtu.be/aqz-KE-bpKQ",
                 "https://vimeo.com/76979871",
                 "https://player.vimeo.com/video/76979871",
                 "https://example.test/live/stream.m3u8",
                 "https://example.test/lesson.mp4"]:
        assert moodle("kaivideo-playable", good).strip() == "yes", good

    for bad in ["https://example.test/watch/lesson",
                # Too short to be a Vimeo id, so it is not one: guessing would
                # put whatever was pasted into an embed address.
                "https://vimeo.com/12345",
                "not a url"]:
        assert moodle("kaivideo-playable", bad).strip() == "no", bad


def test_an_unlisted_vimeo_keeps_its_hash():
    """The case the feature exists for.

    A customer putting a paid course behind Vimeo uses an unlisted video, and
    an unlisted video will not load without the hash in its address. Dropping
    it would leave the feature working for exactly the videos nobody needed it
    for.
    """
    described = json.loads(moodle("kaivideo-describe",
                                  "https://vimeo.com/123456789/abcdef1234"))
    assert described["provider"] == "vimeo"
    assert described["videoid"] == "123456789:abcdef1234", \
        "the unlisted hash was thrown away"

    plain = json.loads(moodle("kaivideo-describe", "https://vimeo.com/123456789"))
    assert plain["videoid"] == "123456789"
