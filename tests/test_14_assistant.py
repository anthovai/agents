"""The navigation assistant, driven through the page a learner actually uses.

It answers "where is X" with a link. That is a small feature with two failure
modes that are not small:

  - telling somebody about a page they are not allowed to open, which is a
    disclosure even when the link then refuses them;
  - handing back a link that does not work, which the learner clicks once,
    lands nowhere, and afterwards stops believing anything it says.

Both are checked here the way a learner would meet them — typing a question and
reading what comes back — rather than by calling the code behind the page. The
two are not the same test: a refusal that works in PHP and never reaches the
screen is still a broken feature, and only the browser catches that.

The measurement tests at the bottom are the exception, and are marked as such:
a threshold has no page.
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


@pytest.fixture
def assistant_on():
    """Switch it on for one test and put the setting back.

    Off is the shipped default and one of the guarantees, so no fixture may
    leave it on.
    """
    original = json.loads(moodle("ai-state"))
    moodle("set-setting", "aienabled", "1")
    yield
    moodle("set-setting", "aienabled", original["enabled"] or "0")


@pytest.fixture
def assistant_off():
    """Switched off explicitly, rather than trusting whatever the site is set
    to. A test that only passes because of leftover state from another one is
    worse than no test."""
    original = json.loads(moodle("ai-state"))
    moodle("set-setting", "aienabled", "0")
    yield
    moodle("set-setting", "aienabled", original["enabled"] or "0")


def ask_on_the_page(session, question: str, timeout: int = 450_000) -> str:
    """Type a question, submit it, and wait for the page to settle.

    Returns 'answer' or 'problem' — which of the two regions appeared. Waiting
    for either rather than for one of them is what makes a failure show up as
    "it said the wrong thing" instead of as a timeout with nothing to read.

    The default is the outermost limit in the chain — ai-service 300s, Moodle
    330s, Apache 420s, this — so a slow answer that does arrive reaches the
    assertion as an answer rather than as a test timeout.
    """
    session.page.fill('[data-region="question"]', question)
    session.beat(1)
    session.page.click('[data-action="send"]')

    session.page.wait_for_selector(
        '[data-region="answer"]:not([hidden]), [data-region="problem"]:not([hidden])',
        timeout=timeout)
    session.beat(2)

    answered = session.page.query_selector('[data-region="answer"]:not([hidden])')
    return "answer" if answered else "problem"


# --------------------------------------------------------------------------
# What a learner sees
# --------------------------------------------------------------------------

def test_the_assistant_is_off_until_somebody_turns_it_on(session, assistant_off):
    """And when off, says so rather than showing an empty page: a learner who
    was told it exists and finds nothing assumes the site is broken."""
    state = json.loads(moodle("ai-state"))
    assert state["defaultenabled"] == "0", "the assistant is enabled by default"

    session.login("learner")
    session.goto("/local/kaiproctor/ask.php")
    session.beat(1.5)

    body = session.page.inner_text("body")
    session.note("the page with the assistant switched off")
    assert "ปิดอยู่" in body or "switched off" in body
    assert not session.page.query_selector('[data-action="send"]'), \
        "the question box is still there with the assistant off"


def test_an_empty_question_does_nothing(session, assistant_on):
    """Pressing the button with nothing typed should not produce an error, and
    should not spend a model call finding that out."""
    session.login("learner")
    session.goto("/local/kaiproctor/ask.php")
    session.beat(1.5)

    session.note("press Ask with the box empty")
    session.page.click('[data-action="send"]')
    session.beat(2.5)

    assert session.page.query_selector('[data-region="answer"][hidden]')
    assert session.page.query_selector('[data-region="problem"][hidden]')
    session.note("nothing happened, which is the right amount of happening")


def test_a_question_the_site_cannot_answer_is_refused_on_the_page(session, assistant_on):
    """Refused by retrieval, and the refusal reaches the screen.

    A model asked a question with no supporting material answers it anyway,
    from what it learned elsewhere. Deciding this in code is cheaper and more
    reliable than asking a model to decline — but a refusal that never renders
    is still a broken page, which is why this is a browser test.
    """
    session.login("learner")
    session.goto("/local/kaiproctor/ask.php")
    session.beat(1.5)

    session.note("ask something this site has nothing to do with")
    outcome = ask_on_the_page(session, "เมืองหลวงของฝรั่งเศสคืออะไร", timeout=60_000)

    assert outcome == "problem", "an off-topic question produced an answer"
    shown = session.page.inner_text('[data-region="problem"]')
    session.note(f"the page says: {shown[:120]}")
    assert "ไม่พบ" in shown, "the refusal does not say anything a learner can act on"


def test_another_learners_course_cannot_be_found_by_name(session, assistant_on):
    """Named exactly, and still not found.

    This is the disclosure test done the way it actually matters: the learner
    types the other course's real name, and the assistant has nothing to say —
    not because it declined, but because that course was never in its index.
    Answering "you are not enrolled in X" would confirm X exists.
    """
    private = json.loads(moodle("seed-private-course", "learner2"))
    moodle("ask-purge-index")
    session.note(f"a course only learner2 is enrolled in: {private['fullname']}")

    session.login("learner")
    session.goto("/local/kaiproctor/ask.php")
    session.beat(1.5)

    session.note("ask for it by its exact name")
    outcome = ask_on_the_page(session, private["fullname"], timeout=60_000)

    assert outcome == "problem", \
        "another learner's course was described to somebody not enrolled in it"
    shown = session.page.inner_text('[data-region="problem"]')
    assert private["fullname"] not in shown, \
        "the refusal repeats the course name back, which confirms it exists"
    session.note("not found, and the name is not echoed back")

    # And the reason it could not be found: it is not in this learner's index
    # at all, rather than filtered out on the way past.
    titles = [item["title"] for item in json.loads(moodle("ask-index", "learner"))]
    assert private["fullname"] not in titles


def test_the_page_says_so_when_the_service_is_down(session, assistant_on):
    """An advisory feature may be unavailable. It may not go quiet and leave a
    learner thinking they asked a bad question."""
    original = json.loads(moodle("ai-state"))
    try:
        moodle("set-setting", "aibaseurl", "http://127.0.0.1:9998")

        session.login("learner")
        session.goto("/local/kaiproctor/ask.php")
        session.beat(1.5)

        session.note("ask a real question with the service unreachable")
        outcome = ask_on_the_page(session, "บทเรียนวิดีโออยู่ตรงไหน", timeout=90_000)

        assert outcome == "problem", "a dead service still produced an answer"
        shown = session.page.inner_text('[data-region="problem"]').strip()
        session.note(f"the page says: {shown[:150]}")
        assert shown, "the page failed silently"
    finally:
        moodle("set-setting", "aibaseurl", original["baseurl"])


def test_a_learner_asks_where_something_is_and_the_link_works(needs_model, session, assistant_on):
    """The whole feature, through the browser, ending on the page itself.

    Following the link is the point: an answer that reads well and 404s is the
    failure this feature has to avoid, and only opening it proves it did not.
    """
    moodle("ask-purge-index")
    session.login("learner")
    session.goto("/local/kaiproctor/ask.php")
    session.beat(1.5)

    session.note("ask where the interactive video lesson is")
    outcome = ask_on_the_page(session, "บทเรียนวิดีโออยู่ตรงไหน")
    assert outcome == "answer", \
        f"expected an answer, got: {session.page.inner_text('[data-region=problem]')}"

    answer = session.page.inner_text('[data-region="answer-text"]')
    session.note(f"answer shown: {answer[:200]}")
    assert answer.strip(), "the answer came back empty"

    links = session.page.query_selector_all('[data-region="sources"] a')
    assert links, "no source links were offered"

    target = links[0].get_attribute("href")
    session.note(f"following the first link: {target}")
    session.page.goto(target)
    session.beat(2.5)

    # Moodle renders its own error pages with a 200, so the body is what says
    # whether the link actually landed somewhere.
    body = session.page.inner_text("body")
    for broken in ["Page not found", "ไม่พบหน้า", "error/invalidcoursemodule",
                   "do not currently have permissions"]:
        assert broken not in body, f"the link the assistant gave leads to: {broken}"
    session.note("the link opened a real page")


def test_every_link_offered_is_one_this_learner_may_open(needs_model, session, assistant_on):
    """Whatever the model writes, the links on screen must all be pages this
    learner was already entitled to open."""
    moodle("ask-purge-index")
    allowed = {item["url"] for item in json.loads(moodle("ask-index", "learner"))}

    session.login("learner")
    session.goto("/local/kaiproctor/ask.php")
    session.beat(1.5)

    session.note("ask about face enrolment")
    outcome = ask_on_the_page(session, "จะไปหน้าลงทะเบียนใบหน้าได้ยังไง")
    assert outcome == "answer"

    for link in session.page.query_selector_all('[data-region="sources"] a'):
        href = link.get_attribute("href")
        path = "/" + href.split("://", 1)[-1].split("/", 1)[-1]
        assert path in allowed, f"{href} was not in this learner's index"
    session.note("every link on the page came from this learner's own index")


# --------------------------------------------------------------------------
# The switch
# --------------------------------------------------------------------------

def test_an_administrator_can_switch_it_on_and_off_from_the_console(session):
    """Through the page, not the setting: the point of the console is that the
    person deciding sees the consequences on the same screen."""
    original = json.loads(moodle("ai-state"))
    try:
        moodle("set-setting", "aienabled", "0")

        session.login("admin")
        session.goto("/local/kaiproctor/ai.php")
        session.beat(1.5)

        console = session.page.query_selector('[data-region="ai-console"]')
        assert console.get_attribute("data-enabled") == "0"

        session.note("turn it on")
        session.page.click('[data-action="kaiproctor-ai-toggle"]')
        session.page.wait_for_selector('[data-region="ai-console"][data-enabled="1"]',
                                       timeout=20_000)
        session.beat(2)
        assert json.loads(moodle("ai-state"))["enabled"] == "1"

        session.note("and off again")
        session.page.click('[data-action="kaiproctor-ai-toggle"]')
        session.page.wait_for_selector('[data-region="ai-console"][data-enabled="0"]',
                                       timeout=20_000)
        session.beat(2)
        assert json.loads(moodle("ai-state"))["enabled"] == "0"
    finally:
        moodle("set-setting", "aienabled", original["enabled"] or "0")


def test_the_console_shows_which_model_answers_and_where_it_runs(session):
    """A switch on its own asks somebody to decide blind. Whether learner
    activity leaves the organisation depends on which machine answers, and the
    person deciding has to be able to read that off the screen."""
    session.login("admin")
    session.goto("/local/kaiproctor/ai.php")
    session.beat(2)

    facts = session.page.inner_text('[data-region="facts"]')
    session.note(f"the console reports:\n{facts}")

    expected = json.loads(moodle("ai-console"))
    assert expected["backend"] in facts, "the console does not show the model endpoint"
    for task in expected["tasks"]:
        assert task["model"] in facts, f"no model shown for {task['task']}"

    # Which of the two banners is correct depends on where this deployment
    # points its model, so the test asks the same question the page does
    # rather than assuming the answer. It used to assume on-premises, and
    # started failing the day somebody configured a hosted model — reporting
    # the safeguard working as though it were a fault.
    #
    # What must hold either way: exactly one of them is shown, and it is the
    # one that matches reality. A console that says nothing leaves the network
    # while it does is the failure worth catching here.
    offpremises = expected["offpremises"]
    session.note(f"this deployment answers off the premises: {offpremises}")

    shown = session.page.query_selector(
        '[data-region="offpremises"]' if offpremises else '[data-region="onpremises"]')
    hidden = session.page.query_selector(
        '[data-region="onpremises"]' if offpremises else '[data-region="offpremises"]')

    assert shown, "the console does not say where the model runs"
    assert not hidden, "the console says both, so it says nothing"

    # Not the wording, which follows whichever language the administrator
    # reads the console in — only that the banner has something to say. An
    # empty one is a missing string, and a missing string is the warning not
    # arriving at the moment it is supposed to be read.
    assert shown.inner_text().strip(), "the banner is shown but says nothing"


def test_a_learner_cannot_reach_the_console(session):
    """It decides whether learner activity leaves the organisation."""
    session.login("learner")
    session.goto("/local/kaiproctor/ai.php")
    session.beat(1.5)

    # Boost's own nav drawer button is [data-action="toggle"], so the switch
    # carries a namespaced attribute — a selector that matches the theme would
    # have made this test pass or fail for reasons of its own.
    assert not session.page.query_selector('[data-action="kaiproctor-ai-toggle"]'), \
        "a learner was shown the switch"
    assert not session.page.query_selector('[data-region="ai-console"]')
    session.note("the console refuses a learner")


# --------------------------------------------------------------------------
# Measurements, which have no page
# --------------------------------------------------------------------------

def test_the_shipped_threshold_still_earns_its_number():
    """MIN_SCORE was measured, so a change that quietly undoes the measurement
    should fail here rather than reach a learner.

    The two figures are not symmetrical. Letting an off-topic question through
    breaks something this feature claims — that a question with no matching
    page never reaches a model — so it is asserted at zero. Recall is a quality
    figure, so it gets a floor with room for a question set that grows.
    """
    moodle("ask-purge-index")
    score = json.loads(moodle("ask-score", "learner"))

    assert score["falseaccept"] == 0, \
        f"off-topic questions now reach a model: {score['wronglyaccepted']}"
    assert score["recall"] >= 0.90, f"retrieval got worse; missing {score['missed']}"

    # Recall is the figure that binds. The model is shown up to eight candidate
    # pages and picks among them, so a page ranked third is a page it can still
    # choose; a page missing from the list is one it cannot.
    #
    # Top-1 fell from 92% to 83% when the demo course gained a second and third
    # interactive video, and that is the measurement being right rather than
    # retrieval being wrong: "where is the video lesson" has three defensible
    # answers now, and the fixture can only name one of them.
    assert score["top1"] >= 0.80, "the right page is often not even near the top"


def test_retrieval_finds_the_right_page_without_a_model():
    """Ranking is separable from generation, and worth testing on its own: a
    retrieval regression blamed on the model is a day wasted."""
    moodle("ask-purge-index")
    ranked = json.loads(moodle("ask-rank", "learner", "บทเรียนวิดีโออยู่ตรงไหน"))

    assert ranked, "nothing matched a question about a page that exists"
    assert "วิดีโอ" in ranked[0]["title"]


def test_a_vendor_endpoint_is_not_mistaken_for_your_own_hardware():
    """The console's warning is only worth having if it is right about the
    boundary it draws."""
    assert moodle("ai-islocal", "http://host.docker.internal:11434/v1").strip() == "yes"
    assert moodle("ai-islocal", "http://ai-service:9100").strip() == "yes"
    assert moodle("ai-islocal", "https://api.openai.com/v1").strip() == "no"


# --------------------------------------------------------------------------
# Exams and marks
# --------------------------------------------------------------------------

@pytest.fixture
def a_graded_quiz():
    """Put a known mark in the gradebook so the answers can be checked.

    Written through grade_update, so what the assistant reads is the same
    gradebook the learner sees on the grade report. Returns the figures the
    answer is allowed to contain.
    """
    moodle("seed-grade", "learner", "8", "8")
    moodle("set-passmark", "8", "6")
    moodle("ask-purge-index")
    return {"grade": "8", "outof": "10", "percent": "80", "passmark": "6"}


def test_a_learner_is_told_their_own_mark(session, assistant_on, a_graded_quiz):
    """The feature, from the box on the page."""
    session.login("learner")
    session.goto("/local/kaiproctor/ask.php")
    session.beat(1.5)

    session.note("ask for the mark on the proctored quiz")
    outcome = ask_on_the_page(session, "ข้อสอบทดสอบระบบคุมสอบได้กี่คะแนน")
    assert outcome == "answer", \
        f"no answer: {session.page.inner_text('[data-region=problem]')}"

    answer = session.page.inner_text('[data-region="answer-text"]')
    session.note(f"answer shown: {answer}")
    assert a_graded_quiz["grade"] in answer, "the mark is not in the answer"
    assert a_graded_quiz["outof"] in answer, "the answer does not say out of what"


def test_pass_or_fail_is_reported_as_the_gradebook_has_it(session, assistant_on,
                                                          a_graded_quiz):
    """Reporting a pass is not the same act as the reviewer deciding whether
    somebody cheated. The pass mark is a rule a teacher set and the gradebook
    already applied; repeating its answer is reporting, not judging."""
    session.login("learner")
    session.goto("/local/kaiproctor/ask.php")
    session.beat(1.5)

    session.note("ask whether they passed")
    outcome = ask_on_the_page(session, "ฉันสอบผ่านไหม")
    assert outcome == "answer"

    answer = session.page.inner_text('[data-region="answer-text"]')
    session.note(f"answer shown: {answer}")
    assert "ผ่าน" in answer, "the answer does not say whether they passed"


def test_the_assistant_will_not_do_arithmetic_on_a_mark(session, assistant_on,
                                                        a_graded_quiz):
    """8 out of 10, asked how many marks short of full: the answer is two, and
    the assistant must not be the one to work that out.

    Not pedantry. A figure a model calculated cannot be traced back to the
    gradebook, and it is the figure a learner quotes in a complaint. Either it
    declines, or the service drops the answer — both are acceptable; producing
    an unsourced number is not.
    """
    session.login("learner")
    session.goto("/local/kaiproctor/ask.php")
    session.beat(1.5)

    session.note("invite it to subtract")
    outcome = ask_on_the_page(
        session, "ข้อสอบทดสอบระบบคุมสอบ ผมขาดอีกกี่คะแนนถึงจะได้เต็ม")

    if outcome == "problem":
        shown = session.page.inner_text('[data-region="problem"]')
        session.note(f"the service refused the answer: {shown[:150]}")
        return

    answer = session.page.inner_text('[data-region="answer-text"]')
    session.note(f"answer shown: {answer}")
    # Whatever it said, every figure in it has to be one it was given.
    supplied = set(a_graded_quiz.values())
    import re
    prose = re.sub(r"https?://\S+", " ", answer)
    for number in re.findall(r"\d+", prose):
        assert number in supplied, f"{number} was worked out, not supplied"


def test_only_the_asking_learners_record_is_ever_sent(session, a_graded_quiz):
    """Checked on the payload rather than on the prose: the model cannot
    disclose what it was never given, and this is the place that holds."""
    facts = json.loads(moodle("ask-facts", "learner", "ฉันสอบผ่านไหม"))
    session.note(f"what would be disclosed: {json.dumps(facts, ensure_ascii=False)[:300]}")

    flat = json.dumps(facts, ensure_ascii=False).lower()
    for forbidden in ["classaverage", "cohort", "otherlearner", "email",
                      "username", "firstname", "userid"]:
        assert forbidden not in flat, f"the payload carries {forbidden}"

    # learner2 has their own mark on the same quiz; it must be nowhere here.
    moodle("seed-grade", "learner2", "8", "3")
    moodle("ask-purge-index")
    again = json.dumps(json.loads(moodle("ask-facts", "learner", "ฉันสอบผ่านไหม")))
    assert '"grade": 3' not in again and '"grade":3' not in again, \
        "another learner's mark reached the payload"
    session.note("learner2's mark is absent from learner's payload")


# --------------------------------------------------------------------------
# The launcher, on every page
# --------------------------------------------------------------------------

def test_the_assistant_can_be_opened_from_any_page(session, assistant_on):
    """A learner who cannot find something is already on the wrong page.

    Sending them to a separate page to ask is asking them to navigate their way
    out of a navigation problem, so the assistant opens in place.
    """
    session.login("learner")
    session.goto("/course/view.php?id=2")
    session.beat(1.5)

    assert session.page.query_selector('[data-action="assistant-toggle"]'), \
        "no way to open the assistant from a course page"
    assert not session.page.is_visible('[data-region="assistant-panel"]'), \
        "the panel is open before anybody asked for it"

    session.note("open it from the course page")
    session.page.click('[data-action="assistant-toggle"]')
    session.beat(1.5)
    assert session.page.is_visible('[data-region="assistant-panel"]')

    session.note("close it again")
    session.page.click('[data-action="assistant-close"]')
    session.beat(1)
    assert not session.page.is_visible('[data-region="assistant-panel"]')


def test_the_launcher_is_absent_when_the_assistant_is_off(session, assistant_off):
    """It is on every page in the site, so it has to disappear completely when
    switched off rather than open onto an apology."""
    session.login("learner")
    session.goto("/course/view.php?id=2")
    session.beat(1.5)

    assert not session.page.query_selector('[data-action="assistant-toggle"]'), \
        "the launcher is on the page with the assistant switched off"
