"""The learner assistant: its gate, and the separation it exists to enforce.

The property under test throughout is that a learner cannot be shown what the
export forbids. That is enforced by there being two indexes and no code path
from one endpoint to the other — so the tests check the separation itself, not
just that today's wording happens to come out clean.
"""

import json

import pytest

from app import learner_scope
from ingest import learner as build_learner

EXPORT = {
    "schema_version": "1.0.0",
    "document_type": "learner_lms_knowledge",
    "generated_at": "2026-09-04T02:13:54+00:00",
    "audience": "learner",
    "tenant": {"company_id": 1},
    "usage_rules": [
        "Use learner_routes URLs for navigation answers; never show "
        "controller, API, database, or source-code route names to learners.",
        "Never infer enrollment, progress, scores, identity, or permissions "
        "for an individual learner from this file.",
    ],
    "excluded_data": ["user profiles", "enrollment records", "quiz answers"],
    "statistics": {"courses": 2},
    "learner_routes": [
        {"id": "course_catalog",
         "title": {"th": "ค้นหาหลักสูตร", "en": "Course catalog"},
         "purpose": {"th": "ค้นหาและเลือกดูหลักสูตรทั้งหมด"},
         "url": "https://example.test/courses"},
    ],
    "courses": [
        {"id": 1, "alias": "bls-2026", "duration_minutes": 240,
         "detail_url": "https://example.test/learning/detail/1",
         "enrollment": {"mandatory": True, "self_enrollment": True},
         "learning_outcomes": json.dumps(["ใช้ AED ได้อย่างปลอดภัย"],
                                         ensure_ascii=False),
         "skills_summary": json.dumps(["Basic Life Support"]),
         "translations": [
             {"language": "thai",
              "title": "การช่วยชีวิตขั้นพื้นฐานสำหรับบุคลากรทางการแพทย์",
              "description": "ครอบคลุมการประเมินภาวะฉุกเฉิน การใช้เครื่อง AED"},
             {"language": "english",
              "title": "Basic Life Support for Healthcare Personnel",
              "description": "Emergency assessment and AED use"},
         ],
         "tags": [{"name": "ความปลอดภัยผู้ป่วย"}],
         "resources": []},
        {"id": 2, "alias": "finance-2026", "duration_minutes": 90,
         "detail_url": "https://example.test/learning/detail/2",
         "enrollment": {"mandatory": False},
         "learning_outcomes": "[]", "skills_summary": "[]",
         "translations": [
             {"language": "thai", "title": "การวิเคราะห์งบการเงิน",
              "description": "อ่านงบการเงินและตีความอัตราส่วนทางการเงิน"},
         ],
         "tags": [], "resources": []},
    ],
}


@pytest.fixture
def index(tmp_path):
    source = tmp_path / "export.json"
    source.write_text(json.dumps(EXPORT, ensure_ascii=False), encoding="utf-8")
    path = tmp_path / "learner.sqlite"
    build_learner.build(str(source), str(path), str(tmp_path / "report.json"))
    from app import store as store_mod
    store = store_mod.Store(str(path))
    yield store
    store.close()


@pytest.fixture
def corpus(index):
    return learner_scope.corpus_text(index)


def search(store, question, corpus, limit=4):
    assessment = learner_scope.assess(question, corpus)
    if not assessment.in_scope:
        return None
    rows = store.db.execute(
        """SELECT c.kind, c.ref, c.title, c.text, bm25(chunk_fts, 10.0, 3.0) s
           FROM chunk_fts JOIN chunk c ON c.rowid = chunk_fts.rowid
           WHERE chunk_fts MATCH ? ORDER BY s LIMIT ?""",
        (learner_scope.query(assessment), limit)).fetchall()
    return [dict(r) for r in rows]


# --------------------------------------------------------------------------
# Thai retrieval — the reason this index uses a different tokenizer
# --------------------------------------------------------------------------


def test_a_word_in_the_middle_of_a_thai_title_can_be_found(index, corpus):
    """The whole reason the learner index is built with trigrams.

    With unicode61 a Thai title is one token, because Thai is written without
    spaces. "การช่วยชีวิต" at the start could be found by prefix search and
    "ฉุกเฉิน" in the middle could not be found by any query at all — so a
    learner asking about emergencies would be told the course does not exist.
    """
    hits = search(index, "มีหลักสูตรเรื่องฉุกเฉินไหม", corpus)
    assert hits, "a word inside a Thai description was invisible"
    assert any("การช่วยชีวิต" in h["title"] for h in hits)


def test_english_finds_the_course_too(index, corpus):
    """The export carries translations; indexing only Thai would waste them."""
    hits = search(index, "is there a Basic Life Support course", corpus)
    assert hits
    assert any(h["ref"] == "bls-2026" for h in hits)


def test_a_route_question_finds_the_route(index, corpus):
    hits = search(index, "ค้นหาหลักสูตรได้ที่ไหน", corpus)
    assert hits
    assert any(h["kind"] == "route" for h in hits)


def test_a_how_many_question_reaches_the_complete_list(index, corpus):
    """So the model answers from a stated total rather than counting chunks."""
    hits = search(index, "ในระบบมีทั้งหมดกี่หลักสูตร", corpus)
    assert any(h["kind"] == "digest" and "หลักสูตร" in h["title"] for h in hits)


# --------------------------------------------------------------------------
# The gate
# --------------------------------------------------------------------------


@pytest.mark.parametrize("question", [
    "เมืองหลวงของฝรั่งเศสคืออะไร",
    "วันนี้อากาศเป็นยังไง",
    "2+2 เท่ากับเท่าไหร่",
])
def test_an_off_topic_question_never_reaches_retrieval(question, corpus):
    """Refused before the model, not after.

    Once material comes back the service has nothing left to refuse on: a
    model handed two course descriptions and a question about the weather
    produces something that reads exactly like an answer.
    """
    assert not learner_scope.assess(question, corpus).in_scope


@pytest.mark.parametrize("question", [
    "มีหลักสูตรเรื่องฉุกเฉินไหม",
    "อยากเรียนเรื่องการเงิน",
    "ค้นหาหลักสูตรได้ที่ไหน",
    "ในระบบมีทั้งหมดกี่หลักสูตร",
])
def test_an_on_topic_question_gets_through(question, corpus):
    assert learner_scope.assess(question, corpus).in_scope


# --------------------------------------------------------------------------
# What the learner index must not contain
# --------------------------------------------------------------------------


def test_the_catalogue_holds_no_developer_names(index):
    """The export's first rule, checked against the index rather than trusted.

    Not a test of today's wording: if somebody later points this builder at a
    developer export, or merges the two corpora to save a container, this is
    what says no.
    """
    everything = " ".join(
        f"{r[0]} {r[1]}" for r in index.db.execute("SELECT title, text FROM chunk"))
    for forbidden in ("tbl_", "application/controllers", "api/", ".php",
                      "SELECT ", "schema dbo"):
        assert forbidden not in everything, f"{forbidden!r} reached the learner index"


def test_an_export_carrying_excluded_data_is_refused(tmp_path):
    """A future export that starts shipping enrolment records fails the build
    rather than quietly indexing somebody's history."""
    bad = dict(EXPORT, enrollments=[{"user_id": 7, "course_id": 1}])
    source = tmp_path / "bad.json"
    source.write_text(json.dumps(bad, ensure_ascii=False), encoding="utf-8")
    with pytest.raises(build_learner.ExportProblem) as caught:
        build_learner.build(str(source), str(tmp_path / "x.sqlite"),
                            str(tmp_path / "r.json"))
    assert "enrollments" in str(caught.value)


def test_a_developer_export_is_refused_by_the_learner_builder(tmp_path):
    """The two builders are not interchangeable and say so."""
    wrong = dict(EXPORT, audience="developer")
    source = tmp_path / "wrong.json"
    source.write_text(json.dumps(wrong, ensure_ascii=False), encoding="utf-8")
    with pytest.raises(build_learner.ExportProblem):
        build_learner.build(str(source), str(tmp_path / "x.sqlite"),
                            str(tmp_path / "r.json"))


def test_the_usage_rules_travel_with_the_data(index):
    """Indexed, not only copied into a prompt, so a stricter export brings its
    own stricter rules."""
    rows = index.db.execute(
        "SELECT text FROM chunk WHERE kind = 'rule'").fetchall()
    assert rows
    assert "never show controller" in rows[0][0]


# --------------------------------------------------------------------------
# The endpoints
# --------------------------------------------------------------------------
#
# These run the real route, with only the model call replaced — everything the
# endpoint decides before reaching a model is decided here for real.


@pytest.fixture
def client(index, monkeypatch):
    from fastapi.testclient import TestClient

    from app import config as config_mod, llm, main

    monkeypatch.setattr(config_mod, "LEARNER_INDEX_PATH", index.path)
    monkeypatch.setattr(main, "_learner", None)
    monkeypatch.setattr(main, "_learner_corpus", "")
    monkeypatch.setattr(config_mod, "API_KEYS", {})

    asked = {}

    def fake_ask(system, user, timeout=None):
        asked["system"], asked["user"] = system, user
        return "คำตอบ", "test-model"

    monkeypatch.setattr(llm, "ask", fake_ask)
    client = TestClient(main.app)
    client.asked = asked
    return client


def test_health_reports_the_learner_index_not_the_developer_one(client):
    body = client.get("/learner/health").json()
    assert body["ok"] is True
    # Two courses, one route, the digests and the rules — the fixture export,
    # not the 520-chunk index the developer endpoints answer from.
    assert body["chunks"] < 20


def test_health_says_so_when_no_learner_index_was_configured(monkeypatch):
    from fastapi.testclient import TestClient

    from app import config as config_mod, main

    monkeypatch.setattr(config_mod, "LEARNER_INDEX_PATH", "")
    monkeypatch.setattr(main, "_learner", None)
    body = TestClient(main.app).get("/learner/health").json()
    assert body == {"ok": False, "code": "not_configured",
                    "detail": "this deployment has no learner index; set "
                              "RAG_LEARNER_INDEX_PATH"}


def test_a_catalogue_question_is_answered_from_the_catalogue(client):
    response = client.post("/learner/ask",
                           json={"question": "มีหลักสูตรเรื่องฉุกเฉินไหม"})
    assert response.status_code == 200
    body = response.json()
    assert body["ok"] is True
    assert body["answer"] == "คำตอบ"
    assert "bls-2026" in body["sources"]
    # The extracts come back with the answer, so a reader can check it against
    # the catalogue rather than against their memory of it.
    assert body["lists"]


def test_the_model_is_given_the_learner_prompt(client):
    """Not the developer one. They differ on what may be shown."""
    from app import learner_prompts

    client.post("/learner/ask", json={"question": "มีหลักสูตรเรื่องฉุกเฉินไหม"})
    assert client.asked["system"] == learner_prompts.LEARNER


def test_an_off_topic_question_is_refused_without_a_model_call(client):
    response = client.post("/learner/ask",
                           json={"question": "เมืองหลวงของฝรั่งเศสคืออะไร"})
    assert response.status_code == 400
    assert response.json()["code"] == "off_topic"
    assert not client.asked, "the model was called for an off-topic question"


def test_an_empty_question_is_refused(client):
    response = client.post("/learner/ask", json={"question": "   "})
    assert response.status_code == 422
    assert response.json()["code"] == "empty_question"


def test_asking_without_an_index_says_which_thing_is_missing(client, monkeypatch):
    """Not "nothing matched".

    A deployment given no learner index and one whose catalogue is empty are
    different problems, and only one of them is fixed by editing an env file.
    """
    from app import config as config_mod, main

    monkeypatch.setattr(config_mod, "LEARNER_INDEX_PATH", "")
    monkeypatch.setattr(main, "_learner", None)
    response = client.post("/learner/ask",
                           json={"question": "มีหลักสูตรเรื่องฉุกเฉินไหม"})
    assert response.status_code == 503
    assert response.json()["code"] == "not_configured"


def test_a_key_is_required_when_one_is_configured(client, monkeypatch):
    from app import config as config_mod

    monkeypatch.setattr(config_mod, "API_KEYS", {"acme": "s3cret"})
    unauthorised = client.post("/learner/ask",
                               json={"question": "มีหลักสูตรเรื่องฉุกเฉินไหม"})
    assert unauthorised.status_code == 401

    allowed = client.post("/learner/ask",
                          json={"question": "มีหลักสูตรเรื่องฉุกเฉินไหม"},
                          headers={"X-Agent-Key": "s3cret"})
    assert allowed.status_code == 200


# --------------------------------------------------------------------------
# Greetings
# --------------------------------------------------------------------------


@pytest.mark.parametrize("question", [
    "สวัสดีครับ", "สวัสดีค่ะ", "หวัดดี", "hello", "Hi!", "ขอบคุณครับ",
    "คุณคือใคร", "ช่วยอะไรได้บ้าง",
])
def test_an_opening_is_a_greeting(question):
    assert learner_scope.is_greeting(question)


@pytest.mark.parametrize("question", [
    "สวัสดีครับ มีหลักสูตรเรื่องความปลอดภัยไหม",
    "hello, is there a first aid course",
    "มีหลักสูตรเรื่องฉุกเฉินไหม",
])
def test_a_greeting_with_a_question_after_it_is_a_question(question):
    """The distinction that matters.

    Somebody who says hello and then asks something has asked something, and
    answering the hello and dropping the question is the rudest possible
    reading of it.
    """
    assert not learner_scope.is_greeting(question)


def test_a_greeting_is_answered_without_a_model_call(client):
    """A learner's first message is very often "สวัสดีครับ".

    Before this it was measured against the corpus, found to share four
    characters with it, and refused with HTTP 400 — which is the assistant
    looking broken in the first exchange anybody has with it.
    """
    response = client.post("/learner/ask", json={"question": "สวัสดีครับ"})
    assert response.status_code == 200
    body = response.json()
    assert "หลักสูตร" in body["answer"]
    assert not client.asked, "a fixed reply should not cost a model call"


def test_a_refusal_does_not_show_the_learner_the_measurement(client):
    """`why` is for whoever reads the log; `detail` is for the person.

    The gate's reasoning is a character count against a corpus. Told that,
    a learner learns nothing they can act on and quite a lot about how the
    thing is built.
    """
    body = client.post("/learner/ask",
                       json={"question": "เมืองหลวงของฝรั่งเศสคืออะไร"}).json()
    assert "ตัวอักษร" not in body["detail"]
    assert "ตัวอักษร" in body["why"]
