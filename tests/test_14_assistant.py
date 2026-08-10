"""The navigation assistant, and the two ways it could do harm.

It answers "where is X" with a link. That is a small feature with two failure
modes that are not small:

  - telling somebody about a page they are not allowed to open, which is a
    disclosure even when the link then refuses them;
  - handing back a link that does not work, which the learner clicks once,
    lands nowhere, and afterwards stops believing anything it says.

Most of what is below is about those two rather than about answer quality.
"""
from __future__ import annotations

import json

import pytest

from conftest import moodle


def model_available() -> bool:
    return moodle("ai-configured").strip() == "yes"


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


def test_the_assistant_is_off_until_somebody_turns_it_on(session, assistant_off):
    """And when off, says so rather than showing an empty page: a learner who
    was told it exists and finds nothing assumes the site is broken."""
    state = json.loads(moodle("ai-state"))
    assert state["defaultenabled"] == "0", "the assistant is enabled by default"

    session.login("learner")
    session.goto("/local/kaiproctor/ask.php")
    session.beat(1)

    body = session.page.inner_text("body")
    session.note("the page with the assistant switched off")
    assert "ปิดอยู่" in body or "switched off" in body
    assert not session.page.query_selector('[data-action="send"]'), \
        "the question box is still there with the assistant off"


def test_one_learners_courses_are_invisible_to_another(session):
    """The rule that matters most here.

    Answering "you are not enrolled in คอร์สลับเฉพาะบุคคล" is itself a
    disclosure, so nothing outside the learner's own courses is ever indexed —
    the assistant cannot decline to mention what it was never given.
    """
    private = json.loads(moodle("seed-private-course", "learner2"))
    moodle("ask-purge-index")
    session.note(f"course only learner2 is enrolled in: {private['fullname']}")

    mine = json.loads(moodle("ask-index", "learner2"))
    theirs = json.loads(moodle("ask-index", "learner"))

    titles_for_owner = [item["title"] for item in mine]
    titles_for_other = [item["title"] for item in theirs]

    assert private["fullname"] in titles_for_owner, "the owner cannot find their own course"
    assert private["fullname"] not in titles_for_other, \
        "another learner's course is in the index"
    session.note(f"learner sees {len(theirs)} pages, none of them learner2's course")


def test_a_question_the_site_cannot_answer_never_reaches_a_model(session, assistant_on):
    """Refused by retrieval, not by the model.

    A model asked a question with no supporting material answers it anyway,
    from what it learned elsewhere. Deciding this in code is both cheaper and
    more reliable than asking a model to decline.
    """
    result = json.loads(moodle("ask", "learner", "เมืองหลวงของฝรั่งเศสคืออะไร"))
    session.note(f"off-topic question: {json.dumps(result, ensure_ascii=False)[:200]}")

    assert result["ok"] is False
    assert result["error"]["code"] == "no_match"


def test_retrieval_finds_the_right_page_without_a_model(session):
    """Ranking is separable from generation, and worth testing on its own: a
    retrieval regression blamed on the model is a day wasted."""
    moodle("ask-purge-index")
    ranked = json.loads(moodle("ask-rank", "learner", "บทเรียนวิดีโออยู่ตรงไหน"))

    assert ranked, "nothing matched a question about a page that exists"
    session.note(f"top match: {ranked[0]['title']} (score {ranked[0]['score']})")
    assert "วิดีโอ" in ranked[0]["title"]


@pytest.mark.skipif(not model_available(), reason="no model behind the reviewer service")
def test_the_answer_links_only_to_pages_the_learner_can_open(session, assistant_on):
    """Whatever the model writes, every link in the answer must be one this
    learner was already entitled to open."""
    moodle("ask-purge-index")
    allowed = {item["url"] for item in json.loads(moodle("ask-index", "learner"))}

    result = json.loads(moodle("ask", "learner", "จะไปหน้าลงทะเบียนใบหน้าได้ยังไง"))
    session.note(f"answer: {str(result)[:300]}")

    assert result["ok"] is True, f"the assistant failed: {result}"
    for source in result["sources"]:
        # Absolute links go out; the index holds paths.
        path = source["url"].split("://", 1)[-1].split("/", 1)[-1]
        assert "/" + path in allowed, f"{source['url']} was not in this learner's index"


@pytest.mark.skipif(not model_available(), reason="no model behind the reviewer service")
def test_a_learner_asks_where_something_is_and_the_link_works(session, assistant_on):
    """The whole feature, through the browser, ending on the page itself.

    Following the link is the point: an answer that reads well and 404s is the
    failure this feature has to avoid, and only opening it proves it did.
    """
    session.login("learner")
    session.goto("/local/kaiproctor/ask.php")
    session.beat(1.5)

    session.note("ask where the interactive video lesson is")
    session.page.fill('[data-region="question"]', "บทเรียนวิดีโออยู่ตรงไหน")
    session.beat(1)
    session.page.click('[data-action="send"]')

    session.page.wait_for_selector('[data-region="answer"]:not([hidden])', timeout=180_000)
    session.beat(2)

    answer = session.page.inner_text('[data-region="answer-text"]')
    session.note(f"answer shown: {answer[:200]}")
    assert answer.strip(), "the answer came back empty"

    links = session.page.query_selector_all('[data-region="sources"] a')
    assert links, "no source links were offered"

    target = links[0].get_attribute("href")
    session.note(f"following the first link: {target}")
    session.page.goto(target)
    session.beat(2)

    # Moodle renders its own error pages with a 200, so the body is what says
    # whether the link actually landed somewhere.
    body = session.page.inner_text("body")
    for broken in ["Page not found", "ไม่พบหน้า", "error/invalidcoursemodule"]:
        assert broken not in body, f"the link the assistant gave leads to: {broken}"
    session.note("the link opened a real page")
