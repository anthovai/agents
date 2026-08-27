"""Check the answer against the corpus before anybody reads it.

The failure this exists for is specific, and it is the one that would make the
assistant worse than useless to the people who will use it. A model that half
remembers a schema writes ``tbl_user_enrollment`` — a name that follows every
convention in this database and does not exist in it. A developer takes that
name, writes a query, and gets an error they then spend an hour explaining. An
answer that is confidently wrong about an identifier costs more than no answer.

The check is shaped rather than vocabulary-wide. Five things are verified:

* table-shaped names — the ones this database uses, ``tbl_*`` and ``ci_*``
* file paths ending ``.php``
* anything in ``snake_case``, which in this corpus means a column or a method
* qualified ``table.column`` references, against that table's own chunk
* every figure, against the numbers the material and the question contain

Ordinary prose cannot accidentally match any of them, so a false positive is
close to impossible, and all four are exactly what a query or an import
statement gets built from. Checking every *word* against a vocabulary would
catch more inventions and would also start refusing correct answers for using
an English word the corpus happens not to contain, which is the trade that
turns a guardrail into a nuisance somebody switches off.

Bare single-word column names are still not checked — ``status`` cannot be told
from the English word. See the note on ``_SNAKE_CASE`` for why that residue is
accepted and why the underscored case is not.

Everything is checked against the material retrieved for *this* question, not
against the corpus as a whole. See :func:`allowed_from`.
"""

import re

# Both anchored on the naming this database actually uses, taken from the 192
# table names in the export rather than from a general idea of what a table is
# called.
_TABLE_SHAPED = re.compile(r"\b(?:tbl_[A-Za-z0-9_]+|ci_[A-Za-z0-9_]+)\b")
_FILE_SHAPED = re.compile(r"\b[A-Za-z0-9_/\.]+\.php\b")

# Anything in snake_case, which in this corpus means a column or a method.
#
# Added after watching a real model answer. Asked what tbl_company holds,
# qwen2.5:7b-instruct replied ``com_default_user_password`` — the real column is
# ``default_user_password``, and it had prefixed ``com_`` because every other
# column in that table does. The guard let it through: a bare column name is
# neither table-shaped nor file-shaped, and the argument for not checking bare
# names was that they collide with ordinary words like ``status`` and ``data``.
#
# That argument holds for single words and collapses for this shape. An
# underscore inside a word does not occur in Thai or English prose, so the
# false-positive case the exemption was protecting does not arise here — while
# the invention it was letting through turned out to be systematic rather than
# occasional, because assembling a name from the pattern of its neighbours is
# exactly what a language model is built to do.
#
# Single-word columns — ``id``, ``status``, ``ordering`` — are still unchecked.
# That residue is accepted knowingly: there is no way to tell the column from
# the English word, and refusing correct answers is the failure that gets a
# guard switched off.
_SNAKE_CASE = re.compile(r"\b[A-Za-z][A-Za-z0-9]*(?:_[A-Za-z0-9]+)+\b")


def identifiers_in(text: str) -> set[str]:
    """Every table-shaped or file-shaped name in the text, counted once.

    The two patterns overlap: ``tbl_foo.php`` is a file path whose first part
    is also table-shaped, and matching both would report one mistake under two
    names — which reads to whoever gets the refusal as two separate problems.
    Overlaps are resolved by span rather than by spelling, so ``tbl_content``
    and ``tbl_contentEnroll`` appearing side by side in a correct answer are
    still two identifiers, as they should be.
    """
    spans = [m.span() for m in _TABLE_SHAPED.finditer(text)]
    spans += [m.span() for m in _FILE_SHAPED.finditer(text)]
    spans += [m.span() for m in _SNAKE_CASE.finditer(text)]
    kept = [(a, b) for a, b in spans
            if not any((x <= a and b < y) or (x < a and b <= y) for x, y in spans)]
    return {text[a:b] for a, b in kept}


def unknown_identifiers(answer: str, vocabulary: set[str]) -> list[str]:
    """Names in the answer that are not in the indexed corpus.

    Matched case-insensitively and by basename as well as full path: the model
    writing ``Authorization_Token.php`` when the corpus holds
    ``application/libraries/Authorization_Token.php`` is being helpful, not
    inventing anything.
    """
    known = {v.lower() for v in vocabulary}
    known |= {v.rsplit("/", 1)[-1].lower() for v in vocabulary}
    return sorted({name for name in identifiers_in(answer)
                   if name.lower() not in known})


# A qualified reference: tbl_company.default_user_password. Unambiguous, which
# is why it can be checked where a bare column name cannot.
_QUALIFIED = re.compile(r"\b((?:tbl_|ci_)[A-Za-z0-9_]+)\.([A-Za-z0-9_]+)\b")


def allowed_from(hits: list[dict]) -> set[str]:
    """Every name the model was actually shown.

    Narrower than the corpus vocabulary on purpose. Checking an answer against
    all 514 chunk references asks "does this name exist somewhere in the
    system", when the question that matters is "was this name in the material
    you were given". A real table name the model was never shown did not come
    from the material — it came from somewhere else, and somewhere else is
    exactly what this assistant is not supposed to draw on.
    """
    allowed = {h["ref"] for h in hits}
    for hit in hits:
        allowed |= identifiers_in(hit["text"])
    return allowed


def ungrounded_columns(answer: str, hits: list[dict]) -> list[str]:
    """Qualified ``table.column`` references the material does not support.

    Bare column names are not checked — they are short and collide with
    ordinary words, and refusing an answer for containing ``status`` would make
    this guard a nuisance somebody switches off. A qualified name has no such
    problem: it names its own table, so it can be checked against that table's
    chunk exactly.

    Only tables actually present in the material are checked. A qualified name
    on a table that was never shown is already an unknown identifier, and
    reporting it twice would describe one mistake as two.
    """
    by_ref = {h["ref"].lower(): h["text"] for h in hits}
    bad = []
    for table, column in _QUALIFIED.findall(answer):
        text = by_ref.get(table.lower())
        if text is None:
            continue
        if not re.search(rf"\b{re.escape(column)}\b", text):
            bad.append(f"{table}.{column}")
    return sorted(set(bad))


# Numbers, and the list markers that are not numbers.
_NUMBER = re.compile(r"\d[\d,]*")
_LIST_MARKER = re.compile(r"^\s*\d+[.)]\s", re.MULTILINE)


def unsupported_numbers(answer: str, material: str, question: str) -> list[str]:
    """Figures in the answer that were not in the material or the question.

    A model asked which tables hold personal data was handed a list stating
    "26 ตาราง", copied the list out, recounted the rows it had transcribed and
    reported 22. Every table it named was real. The total was not, and a total
    is the part somebody carries into a meeting.

    Nothing here recomputes the right number — that is the point. The service
    cannot know what the answer *should* say, only that a figure with no source
    in front of it was produced rather than read, and a produced figure cannot
    be traced back to the schema by whoever is asked to justify it later.

    List markers are excluded before checking. "1." at the start of a line is
    formatting, and refusing an answer for numbering its own list would make
    this guard fire on the correct answers as often as the wrong ones.
    """
    body = _LIST_MARKER.sub("", answer)
    allowed = {m.replace(",", "") for m in _NUMBER.findall(material + " " + question)}
    found = {m.replace(",", "") for m in _NUMBER.findall(body)}
    return sorted(found - allowed, key=lambda n: (len(n), n))


def disclosed_identifiers(answer: str, question: str) -> list[str]:
    """Technical names the answer put in front of the reader uninvited.

    A stricter rule than :func:`unknown_identifiers`, and it replaces rather
    than extends it in the places it applies: the answer is meant to read as
    ordinary Thai, so *any* table, column or file name in it is wrong, not only
    an invented one.

    This follows the same move the proctor's navigation assistant made when it
    stopped showing the model any URLs. A name the model never writes is a name
    it cannot mistype, and the reader loses nothing — the real ones are
    rendered beside the answer, from the chunks the tools returned, where they
    can be copied exactly.

    Names the reader used themselves are allowed back. Somebody who asked about
    ``tbl_company`` is not being told anything new when the reply says
    ``tbl_company``, and an answer that refused to repeat the subject of the
    question would read as evasion. Same principle as the figures rule, which
    already lets a number through if the question contained it.
    """
    asked = {name.lower() for name in identifiers_in(question)}
    return sorted({name for name in identifiers_in(answer)
                   if name.lower() not in asked})


# Han characters. Enough to catch a Chinese sentence; not so much that a stray
# symbol trips it.
_CJK = re.compile(r"[一-鿿]")


def wrong_language(answer: str) -> list[str]:
    """Stretches of an answer that are not in the language it was asked for.

    qwen2.5 is a Chinese model, and on a long turn it drifts: asked in Thai
    what a table holds, it opened in Thai and finished in Mandarin —
    "其中有 3 个被认为是敏感数据的列" — with the figures correct and the
    sentence unreadable to the person who asked.

    The prompt says to answer in Thai. It said so before this happened. A model
    that stops following an instruction halfway through a paragraph is not
    going to be fixed by the instruction being firmer, which is the whole
    reason anything in this file exists.

    Reported as the offending characters rather than a count, so the retry note
    can show the model what it did.
    """
    found = _CJK.findall(answer)
    return ["".join(dict.fromkeys(found))] if found else []


LANGUAGE_NOTE = """

Your previous answer switched out of Thai partway through, into: {names}. The
person reading it cannot read that. Answer again entirely in Thai, from the
first word to the last.
"""


PROSE_NOTE = """

Your previous answer contained technical names: {names}. Answer again in
ordinary Thai, describing what these are for rather than naming them. The
reader is shown the exact names beside your answer, so you do not need to write
any — say what the tables hold and what it means for them, in words.
"""


RETRY_NOTE = """

Your previous answer contained something that is not in the material you were
given: {names}. If it is a number, take the figure stated in the material
instead of counting anything yourself. Answer again using only the table names, column names and file
paths that appear in the material, spelled exactly as they appear there. If the
material does not contain what the question asks about, say so plainly instead
of supplying it from anywhere else.
"""
