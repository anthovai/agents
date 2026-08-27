"""What the index is allowed to contain, and what it must be able to find.

The tests split in two. The first group runs anywhere and pins the privacy
guarantee to code rather than to a paragraph in a README. The second group
needs the real archive and is skipped without it, because an assertion about
retrieval quality that passes on a fixture proves nothing about the corpus the
assistant will actually answer from.

Point the second group at the archive with:

    INDORAMA_EXPORT=/path/to/export_20260804_065758.zip pytest
"""

import io
import json
import os
import re
import zipfile

import pytest

from app import store as store_mod
from ingest import build as build_mod
from ingest import sanitize

EXPORT = os.environ.get("INDORAMA_EXPORT")
needs_export = pytest.mark.skipif(
    not (EXPORT and os.path.exists(EXPORT)),
    reason="set INDORAMA_EXPORT to the export archive to run these")

EMAIL = re.compile(r"[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}")


def _archive(names: dict[str, bytes]) -> sanitize.GuardedArchive:
    buf = io.BytesIO()
    with zipfile.ZipFile(buf, "w") as zf:
        for name, data in names.items():
            zf.writestr(name, data)
    buf.seek(0)
    return sanitize.GuardedArchive(zipfile.ZipFile(buf))


# ---------- the guarantee ----------

def test_a_row_data_section_cannot_be_opened():
    """The whole privacy argument, as one assertion.

    Every email address in the archive is in row data. If this stops raising,
    the argument in sanitize.py is no longer true and the README's claim about
    what reaches the index becomes a claim nobody is checking.
    """
    archive = _archive({"learning/tbl_content_0001.jsonl": b"{}"})
    with pytest.raises(sanitize.SectionRefused):
        archive.read("learning/tbl_content_0001.jsonl")


@pytest.mark.parametrize("name", [
    "master/tbl_lg_0001.jsonl",            # 781 addresses
    "master/ci_sessions_0001.jsonl",       # 149 addresses, plus IPs
    "activities/tbl_activity_log_0001.jsonl",
    "integrations/tbl_skillsoft_learning_activity_import_0001.jsonl",
    "assessments/tbl_quiz_0001.jsonl",
    "knowledge/rag_chunks_0001.jsonl",     # refused as duplicate, not as private
])
def test_every_section_holding_rows_is_refused(name):
    archive = _archive({name: b"{}"})
    with pytest.raises(sanitize.SectionRefused):
        archive.read(name)


def test_a_permitted_section_is_readable():
    archive = _archive({"schema/tables.json": b"[]"})
    assert archive.read("schema/tables.json") == b"[]"
    assert archive.sections_read == {"schema"}


def test_the_net_redacts_what_the_sections_would_not_have_caught():
    san = sanitize.Sanitiser([])
    out = san.scrub("contact somebody@indorama.net from 10.0.0.7")
    assert "indorama.net" not in out
    assert "10.0.0.7" not in out
    assert san.report() == {"email": 1, "ipv4": 1, "token": 0}


# ---------- retrieval, against the real corpus ----------

@pytest.fixture(scope="module")
def index(tmp_path_factory):
    out = tmp_path_factory.mktemp("index")
    path = str(out / "index.sqlite")
    build_mod.build(EXPORT, path, str(out / "report.json"))
    store = store_mod.Store(path)
    yield store
    store.close()


@needs_export
def test_no_email_address_survives_into_the_index(index):
    """End to end, over every chunk that was actually built.

    The section guard is the reason this passes, but the two are worth keeping
    apart: the guard states the intent and this states the outcome, and a
    future chunk type could satisfy the first while breaking the second.
    """
    rows = index.db.execute("SELECT chunk_id, text FROM chunk").fetchall()
    assert rows, "the index is empty"
    offenders = [r["chunk_id"] for r in rows if EMAIL.search(r["text"])]
    assert offenders == []


@needs_export
def test_the_build_recorded_which_archive_it_came_from(index):
    meta = {k: json.loads(v) for k, v in index.meta().items()}
    assert len(meta["source_sha256"]) == 64
    # The archive says it is anonymised and it is not. Recorded rather than
    # corrected, so that an index built from the re-export can be told apart
    # from this one by something other than its filename.
    assert meta["export_claims_anonymised"] is True
    assert meta["sections_read"] == sorted(set(meta["sections_read"]))


@needs_export
@pytest.mark.parametrize("question,expected", [
    # Asked in the casing a person would type, matched against the casing the
    # database actually uses. The table is tbl_contentEnroll; nobody writing a
    # question is going to reproduce that E.
    ("tbl_contentenroll เก็บอะไรบ้าง", "tbl_contentEnroll"),
    ("โครงสร้างของ tbl_certificate", "tbl_certificate"),
    ("ci_sessions มีคอลัมน์อะไร", "ci_sessions"),
    ("Authorization_Token.php ทำอะไร", "application/libraries/Authorization_Token.php"),
])
def test_a_question_naming_a_table_finds_that_table(index, question, expected):
    """The reason this is FTS5 and not embeddings.

    ``tbl_content``, ``tbl_contentLang`` and ``tbl_contentenroll`` are three
    different tables whose names embed to nearly the same vector. Asked about
    one, a dense index will happily answer about another, and the answer reads
    exactly as confidently as a correct one.
    """
    hits = index.search(question, limit=3)
    assert hits, f"nothing matched {question!r}"
    assert hits[0]["ref"] == expected, [h["ref"] for h in hits]


@needs_export
def test_a_question_in_thai_alone_still_matches(index):
    """No identifier in the question at all, so only the trigrams can carry it.

    Without the Thai trigram column this returns nothing: unicode61 hands back
    the whole clause as one token, which matches no document, and the failure
    is silent — an empty result looks the same as a question with no answer.
    """
    hits = index.search("คอลัมน์ไหนเก็บข้อมูลอ่อนไหว", limit=5)
    assert hits


@needs_export
def test_the_sensitive_classification_reaches_the_chunk(index):
    row = index.db.execute(
        "SELECT text FROM chunk WHERE ref = 'tbl_company'").fetchone()
    assert "default_user_password" in row["text"]
    assert "sensitive" in row["text"]


@needs_export
def test_the_default_password_does_not_reach_the_index(index):
    """A secret that travelled with the structure, not with the rows.

    ``tbl_company.default_user_password`` carries a DEFAULT constraint holding
    the client's live initial password. Everything guarding this index was
    aimed at row data, and a DEFAULT clause is part of the column definition —
    so it walked through every one of those guards untouched, and was found by
    reading a built chunk rather than by any check.
    """
    rows = index.db.execute("SELECT chunk_id, text FROM chunk").fetchall()
    assert [r["chunk_id"] for r in rows if "Indorama2025" in r["text"]] == []

    company = next(r["text"] for r in rows if "default_user_password" in r["text"])
    # The column, its type and its classification all survive. Only the value
    # is gone: a reader still learns that new accounts get a fixed password,
    # which is the part worth knowing.
    assert "default_user_password nvarchar(255)" in company
    # A numeric default on another sensitive column in the same table is kept —
    # suppressing it would cost information and protect nothing.
    assert "allow_send_email bit DEFAULT ((1))" in company
