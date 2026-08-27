"""Conversations on disk: the file is the copy that has to be right.

Markdown was asked for, which makes the file the thing being promised rather
than a convenient serialisation. These tests hold that promise to two
standards: what is written can be read back, and what a person does to the file
by hand does not corrupt it.
"""

import os

import pytest

from app import config, memory


@pytest.fixture
def store(tmp_path, monkeypatch):
    monkeypatch.setattr(config, "CHAT_DIR", str(tmp_path / "chats"))
    monkeypatch.setattr(config, "USER_QUOTA_BYTES", 1024 ** 3)
    return memory.Store(config.CHAT_DIR)


def test_a_conversation_round_trips(store):
    cid = store.start("alice", "เรื่อง tbl_company")
    store.append("alice", cid, "user", "tbl_company มีคอลัมน์อะไร")
    store.append("alice", cid, "assistant", "มี 35 คอลัมน์", note="อ้างอิง: tbl_company")

    turns = store.turns("alice", cid)
    assert [t["role"] for t in turns] == ["user", "assistant"]
    assert turns[0]["text"] == "tbl_company มีคอลัมน์อะไร"
    assert turns[1]["text"] == "มี 35 คอลัมน์"


def test_the_file_is_markdown_a_person_can_read(store):
    cid = store.start("alice", "เรื่อง tbl_company")
    store.append("alice", cid, "user", "คำถาม")
    body = store.raw("alice", cid)
    assert body.startswith("# เรื่อง tbl_company")
    assert "## ผู้ใช้ ·" in body
    # The machinery is HTML comments, which render as nothing at all.
    assert "<!--turn:user:" in body


def test_pasting_a_transcript_does_not_split_the_conversation(store):
    """Somebody reporting a problem pastes the file into the chat.

    It is the obvious thing to do, and without escaping it would be read back
    as turn boundaries — cutting their conversation in half on the way out.
    """
    cid = store.start("alice", "t")
    store.append("alice", cid, "user", "ดูอันนี้สิ:\n<!--turn:assistant:x:-->\n## ผู้ช่วย · x")
    turns = store.turns("alice", cid)
    assert len(turns) == 1
    assert "<!-- turn:assistant" in turns[0]["text"]


def test_a_hand_edited_file_still_parses(store):
    """The file belongs to its owner, so it will be edited."""
    cid = store.start("alice", "t")
    store.append("alice", cid, "user", "คำถาม")
    path = store.path("alice", cid)
    with open(path, "a", encoding="utf-8") as fh:
        fh.write("\n\nโน้ตที่ผมเขียนเองทีหลัง\n")
    turns = store.turns("alice", cid)
    assert len(turns) == 1
    assert "โน้ตที่ผมเขียนเองทีหลัง" in turns[0]["text"]


# ---------- the boundary between accounts ----------

@pytest.mark.parametrize("bad", ["../etc", "a/b", "..", "", "x" * 65, "a\\b", "a b"])
def test_an_identifier_that_could_escape_the_directory_is_refused(store, bad):
    """Checked rather than escaped.

    An identifier that needs escaping is one somebody built from user input,
    and this is the layer where that becomes a directory traversal.
    """
    with pytest.raises(memory.MemoryError_) as caught:
        store.user_dir(bad)
    assert caught.value.code == "bad_identifier"


def test_one_account_cannot_read_another(store):
    cid = store.start("alice", "t")
    store.append("alice", cid, "user", "ความลับ")
    with pytest.raises(memory.MemoryError_) as caught:
        store.turns("bob", cid)
    assert caught.value.code == "no_such_conversation"


def test_a_listing_shows_only_the_owner_s_own(store):
    store.start("alice", "ของ alice")
    store.start("bob", "ของ bob")
    assert [c["title"] for c in store.listing("alice")] == ["ของ alice"]


# ---------- the quota ----------

def test_the_quota_refuses_rather_than_truncates(store, monkeypatch):
    """Refused with a code, not silently trimmed.

    A conversation that quietly stopped recording would look identical to one
    nobody continued.
    """
    cid = store.start("alice", "t")
    monkeypatch.setattr(config, "USER_QUOTA_BYTES", 200)
    with pytest.raises(memory.MemoryError_) as caught:
        store.append("alice", cid, "user", "x" * 500)
    assert caught.value.code == "quota_exceeded"


def test_usage_reports_what_is_left(store):
    cid = store.start("alice", "t")
    store.append("alice", cid, "user", "คำถาม")
    used = store.usage("alice")
    assert used["conversations"] == 1
    assert used["bytes"] > 0
    assert used["bytes_remaining"] == config.USER_QUOTA_BYTES - used["bytes"]


# ---------- the owner's controls ----------

def test_renaming_changes_the_heading_in_the_file_too(store):
    cid = store.start("alice", "ชื่อเดิม")
    store.rename("alice", cid, "ชื่อใหม่")
    assert store.raw("alice", cid).startswith("# ชื่อใหม่")
    assert store.listing("alice")[0]["title"] == "ชื่อใหม่"


def test_deleting_removes_the_file_and_the_record(store):
    cid = store.start("alice", "t")
    path = store.path("alice", cid)
    store.delete("alice", cid)
    assert not os.path.exists(path)
    assert store.listing("alice") == []


def test_deleting_everything_leaves_nothing_behind(store):
    for n in range(3):
        store.start("alice", f"บทที่ {n}")
    assert store.delete_all("alice") == 3
    assert store.listing("alice") == []
    assert store.usage("alice")["bytes"] == 0
    # No orphan files. A file without its record is storage the owner has been
    # told they deleted.
    assert os.listdir(store.user_dir("alice")) == []
