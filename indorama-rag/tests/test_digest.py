"""Questions about sets, and the failure of answering one from a sample.

The failure these guard against does not look like a failure. Asked which
tables hold personal data, the retriever returns four tables, every one of them
correct, and the answer names four of twenty-six without anything in it saying
so. It survives being checked — each table really does hold personal data — and
it is wrong in the way that matters, because somebody scopes a migration with
it.
"""

import os

import pytest

from app import guard, scope, store as store_mod
from ingest import build as build_mod

EXPORT = os.environ.get("INDORAMA_EXPORT")
needs_export = pytest.mark.skipif(
    not (EXPORT and os.path.exists(EXPORT)),
    reason="set INDORAMA_EXPORT to the export archive to run these")


@pytest.fixture(scope="module")
def index(tmp_path_factory):
    out = tmp_path_factory.mktemp("digest-index")
    path = str(out / "index.sqlite")
    build_mod.build(EXPORT, path, str(out / "report.json"))
    store = store_mod.Store(path)
    yield store
    store.close()


# ---------- recognising the question ----------

@pytest.mark.parametrize("question", [
    "ตารางไหนบ้างที่มีข้อมูลอ่อนไหว",
    "มีตารางทั้งหมดกี่ตาราง",
    "route ทั้งหมดมีกี่ route",
    "list all tables",
    "how many routes are there",
    "which tables hold personal data",
])
def test_a_question_about_a_set_is_recognised(question):
    assert scope.assess(question, set()).aggregate


@pytest.mark.parametrize("question", [
    "tbl_company มีคอลัมน์อะไรบ้าง",
    "ตาราง tbl_certificate เก็บอะไร",
    "Authorization_Token.php ทำอะไร",
])
def test_a_question_about_one_thing_is_not(question):
    """Why the very common Thai "บ้าง" is not on its own an aggregate marker.

    "tbl_company มีคอลัมน์อะไรบ้าง" asks about one table. Treating "บ้าง" as a
    request for a set would pull a corpus-wide list into every second question.
    """
    assert not scope.assess(question, set()).aggregate


# ---------- the lists themselves ----------

@needs_export
def test_the_sensitive_digest_holds_every_table_not_a_sample(index):
    row = index.db.execute(
        "SELECT text FROM chunk WHERE ref = 'digest_sensitive_columns'").fetchone()
    text = row["text"]
    assert "26 ตาราง" in text and "62 คอลัมน์" in text
    # It states its own completeness. A list that does not is indistinguishable
    # from the sample it was built to replace.
    assert "นี่คือรายการทั้งหมด ไม่ใช่ตัวอย่าง" in text
    # Spot-check both ends of the alphabet, so a truncated build fails here.
    assert "ci_sessions: ip_address" in text
    assert "tbl_users" in text


@needs_export
def test_the_table_inventory_holds_all_192(index):
    row = index.db.execute(
        "SELECT text FROM chunk WHERE ref = 'digest_tables'").fetchone()
    assert row["text"].count("\n  - ") == 192


@needs_export
def test_the_unindexed_digest_explains_a_gap_rather_than_hiding_it(index):
    """The answer to "why does it not know how many learners there are".

    A capability that is absent by policy reads as a defect unless the policy
    is somewhere the assistant can quote.
    """
    row = index.db.execute(
        "SELECT text FROM chunk WHERE ref = 'digest_unindexed_tables'").fetchone()
    assert "tbl_users" in row["text"]
    assert "ไม่มีข้อมูลในแถวแม้แต่แถวเดียว" in row["text"]


@needs_export
def test_the_source_digest_states_what_was_left_out(index):
    """253 third-party files were excluded, and silence about that reads as
    "the index has everything"."""
    row = index.db.execute(
        "SELECT text FROM chunk WHERE ref = 'digest_source_files'").fetchone()
    assert "ตัดออก 253 ไฟล์" in row["text"]


# ---------- routing ----------

@needs_export
@pytest.mark.parametrize("question,expected", [
    ("ตารางไหนบ้างที่มีข้อมูลอ่อนไหว", "digest_sensitive_columns"),
    ("which tables hold personal data", "digest_sensitive_columns"),
    ("มีตารางทั้งหมดกี่ตาราง", "digest_tables"),
    ("list all tables", "digest_tables"),
    ("route ทั้งหมดมีกี่ route", "digest_routes"),
    ("how many routes are there", "digest_routes"),
])
def test_a_set_question_leads_with_the_complete_list(index, question, expected):
    hits = index.search(question, limit=4)
    assert hits, f"nothing matched {question!r}"
    assert hits[0]["ref"] == expected, [h["ref"] for h in hits]


@needs_export
def test_only_one_complete_list_is_offered(index):
    """Three at once caused the failure the digests were built to end.

    Asked "which tables hold personal data" with the sensitive list, the
    unindexed list and the full inventory all in front of it, the model
    answered from the wrong one — two tables out of twenty-six, stated
    plainly. A complete list only helps if it is unmistakably *the* list.
    """
    hits = index.search("which tables hold personal data", limit=4)
    assert sum(1 for h in hits if h["kind"] == "digest") == 1


@needs_export
def test_a_question_about_one_table_gets_no_corpus_wide_list(index):
    """A complete list is the answer to a question about a set and clutter in
    a question about one thing."""
    hits = index.search("tbl_company มีคอลัมน์อะไรบ้าง", limit=4)
    assert hits[0]["ref"] == "tbl_company"
    assert [h["ref"] for h in hits if h["kind"] == "digest"] == []


@needs_export
def test_a_kind_named_in_thai_alone_still_reaches_its_list(index):
    """Every term in "มีตารางทั้งหมดกี่ตาราง" maps to a chunk kind and none to a
    searchable anchor, so the FTS query is empty. Before the fallback this
    returned nothing: in scope, understood, and silently unanswered."""
    hits = index.search("มีตารางทั้งหมดกี่ตาราง", limit=4)
    assert hits and hits[0]["ref"] == "digest_tables"


# ---------- the safety net under the digests ----------

MATERIAL = ("รายการครบถ้วน: ตารางที่มีคอลัมน์อ่อนไหว\n"
            "มีทั้งหมด 26 ตาราง รวม 62 คอลัมน์\n"
            "  - tbl_company: com_mail\n  - tbl_contact: ct_email")


def test_a_total_the_model_worked_out_itself_is_refused():
    """The figure a real model produced from a list it had just copied.

    Handed a digest saying "26 ตาราง", qwen2.5:7b-instruct transcribed the
    list, recounted the rows it had written, and reported 22. Every table it
    named was real; the total was not, and the total is the part that gets
    carried into a meeting.
    """
    assert guard.unsupported_numbers(
        "รวม 22 ตาราง", MATERIAL, "which tables hold personal data") == ["22"]


def test_a_total_read_from_the_material_passes():
    assert guard.unsupported_numbers("รวม 26 ตาราง", MATERIAL, "") == []


def test_list_numbering_is_not_a_claim():
    """"1." at the start of a line is formatting.

    Refusing an answer for numbering its own list would fire this guard on the
    correct answers as often as the wrong ones, which is how a guard ends up
    switched off.
    """
    answer = "ตารางที่พบ:\n1. tbl_company\n2. tbl_contact\n3. tbl_users"
    assert guard.unsupported_numbers(answer, MATERIAL, "") == []


def test_a_figure_from_the_question_is_not_invented():
    """Somebody asking "the first 5" may be answered about 5 of them."""
    assert guard.unsupported_numbers("5 ตารางแรกคือ", MATERIAL, "ขอ 5 ตารางแรก") == []


@needs_export
def test_a_partial_result_is_countable(index):
    """What the service needs in order to say "this is a sample".

    The digests cover the sets that could be worked out in advance. This covers
    every set that could not, which is the larger half.
    """
    assessment = scope.assess("ตารางไหนเก็บรหัสผ่าน", index.vocabulary())
    shown = index.search("ตารางไหนเก็บรหัสผ่าน", limit=2, assessment=assessment)
    total = index.total_matches(assessment.named,
                               assessment.english + assessment.anchors,
                               assessment.kinds)
    assert total >= len(shown)
