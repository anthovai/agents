"""The API, including the one guarantee everything else is arranged around:
the correct answers do not leave this service to a player.
"""

import pytest
from fastapi.testclient import TestClient

from app import config, main


@pytest.fixture
def client(tmp_path, monkeypatch):
    monkeypatch.setattr(config, "DB_PATH", str(tmp_path / "t.sqlite"))
    monkeypatch.setattr(config, "API_KEY", "player-key")
    monkeypatch.setattr(config, "ADMIN_KEY", "admin-key")
    monkeypatch.setattr(config, "RATE_PER_MINUTE", 0)
    main._store = None
    with TestClient(main.app) as c:
        yield c
    main._store = None


PLAYER = {"X-Video-Key": "player-key"}
ADMIN = {"X-Video-Admin-Key": "admin-key"}

VIDEO = {
    "id": "safety-101",
    "title": "ความปลอดภัยเบื้องต้น",
    "provider": "file",
    "source": "https://example.test/safety.mp4",
    "must_answer": True,
    "allow_retry": True,
    "timeline": [
        {"id": "q2", "at": 120, "type": "shorttext", "text": "อุปกรณ์ใด",
         "answers": ["หมวกนิรภัย"], "category": "อุปกรณ์"},
        {"id": "q1", "at": 30, "type": "choice", "text": "สีใดคืออันตราย",
         "choices": ["เขียว", "แดง", "ฟ้า"], "answers": [1],
         "feedback": "แดงคือหยุด", "category": "สัญญาณ"},
        {"id": "i1", "at": 10, "type": "info", "text": "เริ่มบทเรียน"},
    ],
}


def put(client):
    return client.put("/videos/safety-101", json=VIDEO, headers=ADMIN)


# --------------------------------------------------------------------------
# The guarantee
# --------------------------------------------------------------------------


def test_the_player_never_receives_the_answers(client):
    """The whole reason marking happens server-side.

    A timeline handed to the browser with its answers in it is a lesson
    anybody can pass by opening the network tab, and no amount of front-end
    care fixes that afterwards.
    """
    put(client)
    body = client.get("/videos/safety-101/play?user_id=u1", headers=PLAYER).json()

    raw = str(body)
    assert "answers" not in str(body["timeline"])
    assert "หมวกนิรภัย" not in raw, "the typed answer leaked"
    assert "แดงคือหยุด" not in raw, "the feedback leaked before it was earned"
    for item in body["timeline"]:
        assert set(item) == {"id", "at", "type", "text", "choices"}


def test_an_admin_can_read_the_answers(client):
    put(client)
    body = client.get("/videos/safety-101/definition", headers=ADMIN).json()
    assert body["video"]["timeline"][0]["answers"]


def test_a_player_key_cannot_read_the_definition(client):
    put(client)
    assert client.get("/videos/safety-101/definition", headers=PLAYER).status_code == 401


def test_a_player_key_cannot_author(client):
    assert client.put("/videos/x", json=VIDEO, headers=PLAYER).status_code == 401


def test_no_key_at_all_is_refused(client):
    put(client)
    assert client.get("/videos/safety-101/play?user_id=u1").status_code == 401


# --------------------------------------------------------------------------
# Playback
# --------------------------------------------------------------------------


def test_the_timeline_comes_back_in_time_order(client):
    """The player shows the earliest unanswered item first, so somebody who
    seeks past three of them answers in the order the author wrote them."""
    put(client)
    body = client.get("/videos/safety-101/play?user_id=u1", headers=PLAYER).json()
    assert [i["id"] for i in body["timeline"]] == ["i1", "q1", "q2"]


def test_answering_correctly_is_recorded_and_shown_next_time(client):
    put(client)
    reply = client.post("/videos/safety-101/answer", headers=PLAYER,
                        json={"user_id": "u1", "item_id": "q1", "response": "[1]"})
    assert reply.json()["correct"] is True
    assert reply.json()["feedback"] == "แดงคือหยุด"

    body = client.get("/videos/safety-101/play?user_id=u1", headers=PLAYER).json()
    assert body["answered"] == [{"item_id": "q1", "correct": True}]


def test_a_wrong_answer_withholds_the_solution_while_a_retry_remains(client):
    put(client)
    reply = client.post("/videos/safety-101/answer", headers=PLAYER,
                        json={"user_id": "u1", "item_id": "q1",
                              "response": "[0]"}).json()
    assert reply["correct"] is False
    assert reply["may_retry"] is True
    assert reply["answers"] == [], "showing it now would make the retry free"


def test_the_solution_arrives_when_no_retry_is_allowed(client):
    body = dict(VIDEO, allow_retry=False)
    client.put("/videos/safety-101", json=body, headers=ADMIN)
    reply = client.post("/videos/safety-101/answer", headers=PLAYER,
                        json={"user_id": "u1", "item_id": "q1",
                              "response": "[0]"}).json()
    assert reply["answers"] == ["แดง"]


def test_a_correct_answer_cannot_be_undone_by_a_later_one(client):
    put(client)
    client.post("/videos/safety-101/answer", headers=PLAYER,
                json={"user_id": "u1", "item_id": "q1", "response": "[1]"})
    again = client.post("/videos/safety-101/answer", headers=PLAYER,
                        json={"user_id": "u1", "item_id": "q1", "response": "[0]"})
    assert again.status_code == 409
    assert again.json()["error"]["code"] == "already_answered"


def test_no_second_attempt_when_retries_are_off(client):
    client.put("/videos/safety-101", json=dict(VIDEO, allow_retry=False), headers=ADMIN)
    client.post("/videos/safety-101/answer", headers=PLAYER,
                json={"user_id": "u1", "item_id": "q1", "response": "[0]"})
    again = client.post("/videos/safety-101/answer", headers=PLAYER,
                        json={"user_id": "u1", "item_id": "q1", "response": "[1]"})
    assert again.status_code == 409


def test_answering_an_item_that_is_not_there(client):
    put(client)
    reply = client.post("/videos/safety-101/answer", headers=PLAYER,
                        json={"user_id": "u1", "item_id": "nope", "response": "[0]"})
    assert reply.status_code == 404


# --------------------------------------------------------------------------
# Progress
# --------------------------------------------------------------------------


def test_progress_keeps_the_furthest_point_not_the_latest(client):
    """Two reports race when a page closes — one from it hiding, one from it
    unloading. Whichever lands last must not decide where the learner resumes.
    """
    put(client)
    for seconds in (95, 40):
        client.post("/videos/safety-101/progress", headers=PLAYER,
                    json={"user_id": "u1", "seconds": seconds})
    body = client.get("/videos/safety-101/play?user_id=u1", headers=PLAYER).json()
    assert body["resume_at"] == 95


# --------------------------------------------------------------------------
# Results
# --------------------------------------------------------------------------


def test_info_cards_do_not_count_towards_the_score(client):
    """Otherwise every score is lifted by however many messages the author
    happened to write, which is a number nothing to do with the learner."""
    put(client)
    for item, response in (("i1", ""), ("q1", "[1]")):
        client.post("/videos/safety-101/answer", headers=PLAYER,
                    json={"user_id": "u1", "item_id": item, "response": response})

    summary = client.get("/videos/safety-101/result?user_id=u1",
                         headers=PLAYER).json()["summary"]
    assert summary["graded_items"] == 2, "the info card is not graded"
    assert summary["correct"] == 1
    assert summary["fraction"] == 0.5


def test_results_split_by_the_authors_categories(client):
    put(client)
    client.post("/videos/safety-101/answer", headers=PLAYER,
                json={"user_id": "u1", "item_id": "q1", "response": "[1]"})
    body = client.get("/videos/safety-101/result?user_id=u1",
                      headers=PLAYER).json()
    names = {row["category"]: row for row in body["by_category"]}
    assert names["สัญญาณ"]["fraction"] == 1.0
    assert names["อุปกรณ์"]["fraction"] == 0.0


def test_the_report_is_admin_only(client):
    put(client)
    assert client.get("/videos/safety-101/report", headers=PLAYER).status_code == 401
    assert client.get("/videos/safety-101/report", headers=ADMIN).status_code == 200


# --------------------------------------------------------------------------
# Authoring guards
# --------------------------------------------------------------------------


def test_an_unanswerable_item_is_refused_at_save(client):
    broken = dict(VIDEO, timeline=[
        {"id": "q1", "at": 5, "type": "multichoice", "text": "?",
         "choices": ["a", "b"], "answers": []},
    ])
    reply = client.put("/videos/broken", json=broken, headers=ADMIN)
    assert reply.status_code == 422
    assert reply.json()["error"]["code"] == "bad_item"


def test_the_id_need_not_be_repeated_in_the_body(client):
    """The URL already says which video this is.

    Demanding it again inside the payload rejected every request an
    integrator would naturally write — the path carries the id, so the body
    should not have to — and the 422 that came back named a field the caller
    had no reason to think was missing.
    """
    body = {k: v for k, v in VIDEO.items() if k != "id"}
    reply = client.put("/videos/no-id-in-body", json=body, headers=ADMIN)
    assert reply.status_code == 200
    assert reply.json()["id"] == "no-id-in-body"


def test_the_path_wins_over_an_id_in_the_body(client):
    """So the two can never disagree about what was just saved."""
    reply = client.put("/videos/the-real-one", json=dict(VIDEO, id="something-else"),
                       headers=ADMIN)
    assert reply.json()["id"] == "the-real-one"
    assert client.get("/videos/the-real-one/play?user_id=u1",
                      headers=PLAYER).status_code == 200


def test_two_items_sharing_an_id_are_refused(client):
    """Answers are filed against the item id, so a duplicate silently merges
    two questions' results."""
    clash = dict(VIDEO, timeline=[
        {"id": "same", "at": 5, "type": "info", "text": "one"},
        {"id": "same", "at": 9, "type": "info", "text": "two"},
    ])
    reply = client.put("/videos/clash", json=clash, headers=ADMIN)
    assert reply.json()["error"]["code"] == "duplicate_item"


# --------------------------------------------------------------------------
# Erasure
# --------------------------------------------------------------------------


def test_a_person_can_be_erased_from_every_video(client):
    put(client)
    client.post("/videos/safety-101/answer", headers=PLAYER,
                json={"user_id": "u1", "item_id": "q1", "response": "[1]"})
    client.post("/videos/safety-101/progress", headers=PLAYER,
                json={"user_id": "u1", "seconds": 50})

    removed = client.delete("/users/u1", headers=ADMIN).json()
    assert removed["rows_deleted"] == 2

    body = client.get("/videos/safety-101/play?user_id=u1", headers=PLAYER).json()
    assert body["answered"] == []
    assert body["resume_at"] == 0
    # The video itself is untouched.
    assert len(body["timeline"]) == 3
