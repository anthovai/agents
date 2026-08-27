"""Staying on the subject — the three layers that keep an answer inside the corpus.

Each layer is here because the layer above it was measured and found to leak.
The numbers in the docstrings are from runs against the real export; the
parametrised cases are the questions those runs used, kept so that a change
which quietly re-opens one of the holes fails here instead of in front of a
user.
"""

import os

import pytest

from app import guard, lexicon, scope, store as store_mod
from ingest import build as build_mod

EXPORT = os.environ.get("INDORAMA_EXPORT")
needs_export = pytest.mark.skipif(
    not (EXPORT and os.path.exists(EXPORT)),
    reason="set INDORAMA_EXPORT to the export archive to run these")

VOCABULARY = {
    "tbl_contentEnroll", "tbl_certificate", "tbl_company", "ci_sessions",
    "application/libraries/Authorization_Token.php", "foreign_keys",
}

# Asked of a system assistant by somebody who wandered off, or by somebody
# testing whether it will wander off with them.
OFF_TOPIC = [
    "วันนี้อากาศเป็นยังไง",
    "ช่วยเขียนกลอนให้หน่อย",
    "ประเทศไทยมีกี่จังหวัด",
    "How do I cook pasta?",
    "อธิบายทฤษฎีสัมพัทธภาพ",
    "ราคาทองวันนี้เท่าไหร่",
    "ใครเป็นนายกรัฐมนตรี",
    "แนะนำร้านอาหารแถวนี้",
    "who is the CEO of Indorama",
    "เล่าเรื่องตลกให้ฟังหน่อย",
]

IN_SCOPE = [
    "tbl_contentEnroll ผูกกับตารางไหน",
    "โครงสร้างของ tbl_certificate",
    "ci_sessions มีคอลัมน์อะไร",
    "Authorization_Token.php ทำอะไร",
    "ตารางไหนเก็บข้อมูลอ่อนไหวบ้าง",
    "คอลัมน์ไหนเก็บรหัสผ่าน",
    "ความสัมพันธ์ระหว่างตารางมีอะไรบ้าง",
    "which tables hold personal data",
    "ไฟล์ไหนเป็น controller บ้าง",
    "what are the foreign keys",
]


# ---------- layer one: scope, before anything else runs ----------

@pytest.mark.parametrize("question", OFF_TOPIC)
def test_an_off_topic_question_is_out_of_scope(question):
    """No retrieval, no model call, no answer.

    The version before this one searched anyway and got material for five of
    these six Thai questions, because Thai function words produced trigrams
    that matched the Thai function words in our own chunk labels. Once material
    comes back there is nothing left to refuse on — the model is handed three
    table definitions and a question about the weather, and it answers.
    """
    assert not scope.assess(question, VOCABULARY).in_scope


@pytest.mark.parametrize("question", IN_SCOPE)
def test_a_real_question_is_in_scope(question):
    assert scope.assess(question, VOCABULARY).in_scope


def test_a_file_named_with_its_extension_is_recognised():
    """``Authorization_Token.php`` is one name, not two tokens and a dot.

    Splitting on the dot stopped the basename matching the corpus entry, and
    the case that broke was a question naming a file exactly — the one thing
    that was supposed to be certain.
    """
    found = scope.assess("Authorization_Token.php ทำอะไร", VOCABULARY)
    assert found.named == ["application/libraries/Authorization_Token.php"]


def test_a_common_column_name_does_not_put_a_question_in_scope():
    """Why the English list is curated rather than taken from the schema.

    ``data``, ``name``, ``type`` and ``status`` are all real columns in this
    database. Admitting them would put most sentences in either language in
    scope, which is the same as having no gate.
    """
    for question in ("what is your name", "give me the data", "what type is it"):
        assert not scope.assess(question, VOCABULARY).in_scope, question


def test_a_thai_term_reaches_english_material():
    """Thai is a controlled vocabulary pointing at Latin anchors.

    The corpus contains no Thai at all — 28 distinct runs, every one a label
    this codebase wrote. So a Thai question cannot match content directly, and
    the lexicon is what carries it to the English the corpus is made of.
    """
    found = scope.assess("ตารางไหนเก็บข้อมูลอ่อนไหวบ้าง", VOCABULARY)
    assert "อ่อนไหว" in found.thai
    assert "sensitive" in found.anchors
    assert found.kinds == {"table"}


def test_every_thai_term_maps_to_a_kind_or_an_anchor():
    """A term that steers nothing is a term that only widens the gate."""
    for term, (anchors, kinds) in lexicon.TERMS.items():
        assert anchors or kinds or term in ("ฐานข้อมูล", "ระบบ", "log", "บันทึก"), term


# ---------- layer two: the answer may only name what it was shown ----------

MATERIAL = [
    {"chunk_id": "table_0001", "kind": "table", "ref": "tbl_company",
     "title": "ตาราง tbl_company",
     "text": "ตาราง tbl_company\n  - com_mail nvarchar(200)\n"
             "  - default_user_password nvarchar(255)"},
]


def test_a_name_from_the_material_passes():
    allowed = guard.allowed_from(MATERIAL)
    assert guard.unknown_identifiers("ดูที่ tbl_company", allowed) == []


def test_a_real_table_that_was_not_shown_is_still_refused():
    """The check is 'was it in the material', not 'does it exist'.

    ``tbl_certificate`` is a real table in this database. If it turns up in an
    answer to a question that never retrieved it, the model did not read it
    here — and somewhere else is exactly what this assistant is not allowed to
    draw on.
    """
    allowed = guard.allowed_from(MATERIAL)
    assert guard.unknown_identifiers("ลองดู tbl_certificate", allowed) == ["tbl_certificate"]


# ---------- layer three: qualified column references ----------

def test_a_qualified_column_that_exists_passes():
    assert guard.ungrounded_columns("tbl_company.com_mail คือเมล", MATERIAL) == []


def test_a_qualified_column_that_does_not_exist_is_caught():
    """Bare column names are unfixable; qualified ones are exact.

    ``status`` on its own could be a column or the English word. ``tbl_company.
    status`` names its own table, so it can be checked against that table's
    chunk with no ambiguity at all.
    """
    assert guard.ungrounded_columns(
        "ดูที่ tbl_company.user_email", MATERIAL) == ["tbl_company.user_email"]


def test_a_column_name_assembled_from_its_neighbours_is_caught():
    """The invention a real model actually produced.

    Asked what tbl_company holds, qwen2.5:7b-instruct replied
    ``com_default_user_password``.
    The real column is ``default_user_password``; every other column in that
    table begins ``com_``, and the model completed the pattern. Nothing about
    the sentence signals that one of the names in it is made up.
    """
    allowed = guard.allowed_from(MATERIAL)
    assert guard.unknown_identifiers(
        "คอลัมน์ com_default_user_password เป็นข้อมูลอ่อนไหว",
        allowed) == ["com_default_user_password"]


def test_the_real_column_passes():
    allowed = guard.allowed_from(MATERIAL)
    assert guard.unknown_identifiers(
        "คอลัมน์ default_user_password เป็นข้อมูลอ่อนไหว", allowed) == []


def test_prose_without_underscores_is_never_snake_case():
    """Why an underscore is the whole test.

    Bare single-word columns stay unchecked — ``status`` cannot be told from
    the English word, and refusing correct answers is what gets a guard turned
    off. An underscore inside a word does not occur in Thai or English prose,
    so the shape carries the distinction on its own.
    """
    allowed = guard.allowed_from(MATERIAL)
    prose = ("ตารางนี้เก็บสถานะการลงทะเบียน โดยมี status เป็นคอลัมน์หลัก "
             "the table stores enrolment records with a status column")
    assert guard.unknown_identifiers(prose, allowed) == []


def test_a_qualified_column_on_an_unshown_table_is_not_double_reported():
    """One mistake, reported once.

    The table is already an unknown identifier; naming its column as a second
    finding would describe a single error as two and make the refusal message
    read as a worse failure than it is.
    """
    assert guard.ungrounded_columns("tbl_certificate.cert_id", MATERIAL) == []


# ---------- end to end, against the real index ----------

@pytest.fixture(scope="module")
def index(tmp_path_factory):
    out = tmp_path_factory.mktemp("scope-index")
    path = str(out / "index.sqlite")
    build_mod.build(EXPORT, path, str(out / "report.json"))
    store = store_mod.Store(path)
    yield store
    store.close()


@needs_export
@pytest.mark.parametrize("question", OFF_TOPIC)
def test_off_topic_retrieval_is_empty(index, question):
    """The scope decision, confirmed against the built index rather than the
    fixture vocabulary. 10 of 10 return nothing."""
    assert index.search(question, limit=3) == []


@needs_export
@pytest.mark.parametrize("question,expected", [
    ("tbl_contentEnroll ผูกกับตารางไหน", "tbl_contentEnroll"),
    ("โครงสร้างของ tbl_certificate", "tbl_certificate"),
    ("ci_sessions มีคอลัมน์อะไร", "ci_sessions"),
    ("Authorization_Token.php ทำอะไร", "application/libraries/Authorization_Token.php"),
    ("ความสัมพันธ์ระหว่างตารางมีอะไรบ้าง", "foreign_keys"),
    ("what are the foreign keys", "foreign_keys"),
    ("ดัชนีของ tbl_content มีอะไรบ้าง", "tbl_content"),
])
def test_a_question_reaches_the_chunk_that_answers_it(index, question, expected):
    hits = index.search(question, limit=3)
    assert hits, f"nothing matched {question!r}"
    assert hits[0]["ref"] == expected, [h["ref"] for h in hits]


@needs_export
@pytest.mark.parametrize("question", IN_SCOPE)
def test_every_in_scope_question_retrieves_something(index, question):
    assert index.search(question, limit=3)
