"""Is this question about the course catalogue at all?

Answered before retrieval and before any model call, for the reason set out in
:mod:`app.scope` — once material comes back the service has nothing left to
refuse on, and a model handed four course descriptions and a question about
the weather will produce something that reads exactly like an answer.

The developer index gates on vocabulary: a question is in scope when it names
a table, a route or a term from a written lexicon. That approach does not
transfer here. This corpus is Thai prose — course titles, descriptions,
outcomes — and Thai is written without spaces, so there is no list of words to
check a question against without a segmenter and its dictionary.

What works instead is measured rather than reasoned: **the longest run of
characters the question shares with the corpus.** A question about a course
carries a long stretch of the catalogue's own wording; a question about the
weather shares only fragments that any two Thai sentences share.

    ตรงประเด็น  สั้นสุด  8 ตัวอักษร
    นอกเรื่อง   ยาวสุด   6 ตัวอักษร

so the floor sits at 7, in the gap. See ``bench_learner.py``, which recomputes
this and writes the table.

The cost of this design is a question phrased entirely in words the catalogue
does not use is refused even when the catalogue could have answered it. That
is the failure worth having: it names itself, and whoever hits it can report
the wording. The alternative — a confident answer assembled from whatever
ranked — is invisible to the person it misleads.
"""

from __future__ import annotations

import re
from dataclasses import dataclass, field

# Below this many characters, a shared run is coincidence. Measured on a set
# of fourteen questions, which is small: it separates them with a gap on
# either side, and it should be recomputed against questions real learners
# typed before anything depends on it. Fourteen cannot resolve better than
# "there is a gap roughly here".
MIN_RUN = 7

# n-grams shorter than this are not worth searching: every Thai sentence
# contains "การ" and "ที่", and including them means every chunk matches
# every question and bm25 decides on noise.
MIN_GRAM = 4
MAX_GRAM = 10

# A ceiling on how many n-grams one question turns into. A long question
# generates hundreds; past a few hundred the extra terms are all short and
# common, and FTS5 has a hard limit on expression size that a runaway query
# hits as an error rather than a bad answer.
MAX_TERMS = 400

_WORDISH = re.compile(r"[^\w฀-๿]+", re.UNICODE)


@dataclass
class Assessment:
    """What the gate decided, and why."""

    in_scope: bool
    longest_match: str = ""
    terms: list[str] = field(default_factory=list)

    def why(self) -> str:
        if self.in_scope:
            return f"พบข้อความตรงกับคลังข้อมูล: {self.longest_match!r}"
        if not self.longest_match:
            return "ไม่มีข้อความใดในคำถามตรงกับคลังข้อมูลเลย"
        return (f"ข้อความที่ตรงยาวเพียง {len(self.longest_match)} ตัวอักษร "
                f"({self.longest_match!r}) ซึ่งสั้นเกินกว่าจะถือว่าเกี่ยวข้อง")


def grams(question: str, lo: int = MIN_GRAM, hi: int = MAX_GRAM) -> list[str]:
    """Every run of `lo`..`hi` characters in the question.

    Sliding windows rather than words, because the index is tokenised as
    trigrams and Thai has no word boundaries to split on. The windows overlap
    heavily and that is the point: whichever way a learner phrases a course
    name, some window of it will be a substring of the catalogue's phrasing.

    :param question: as typed
    :return: distinct windows, longest first so a truncated list keeps the
        specific ones and drops the common short ones
    """
    cleaned = _WORDISH.sub(" ", question)
    out: set[str] = set()
    for run in cleaned.split():
        for size in range(lo, hi + 1):
            for start in range(len(run) - size + 1):
                out.add(run[start:start + size])
    return sorted(out, key=len, reverse=True)[:MAX_TERMS]


def longest_shared(question: str, corpus: str) -> str:
    """The longest stretch of the question that appears in the corpus.

    :param question: as typed
    :param corpus: every chunk's title and text, lowercased and joined
    :return: the matching run, or "" if nothing of length 3 or more matched
    """
    cleaned = _WORDISH.sub(" ", question.lower())
    best = ""
    for run in cleaned.split():
        # Longest first, and stop as soon as this run cannot beat what we
        # already have — the answer is a maximum, so shorter windows of a run
        # that already lost are not worth testing.
        for size in range(len(run), 2, -1):
            if size <= len(best):
                break
            found = next((run[i:i + size] for i in range(len(run) - size + 1)
                          if run[i:i + size] in corpus), None)
            if found:
                best = found
                break
    return best


def assess(question: str, corpus: str) -> Assessment:
    """Decide whether this question may reach retrieval.

    :param question: as typed
    :param corpus: from :func:`corpus_text`
    """
    match = longest_shared(question, corpus)
    if len(match) < MIN_RUN:
        return Assessment(in_scope=False, longest_match=match)
    return Assessment(in_scope=True, longest_match=match, terms=grams(question))


# Openings and pleasantries, which are not questions about the catalogue and
# are also not the weather. A learner's first message is very often one of
# these, and a greeting that comes back as HTTP 400 makes the assistant look
# broken in the first exchange somebody has with it.
#
# Matched against the message as a whole rather than searched for inside it,
# so "สวัสดีครับ" is a greeting and "สวัสดีครับ มีหลักสูตรอะไรบ้าง" is a
# question, which is the distinction that matters. No model call: the reply is
# fixed, and there is nothing here worth spending a call or a chunk on.
_GREETINGS = (
    "สวัสดี", "หวัดดี", "ดีครับ", "ดีค่ะ", "hello", "hi", "hey",
    "ขอบคุณ", "ขอบใจ", "thanks", "thank you", "โอเค", "ok", "okay",
    "ทดสอบ", "test", "ช่วยอะไรได้บ้าง", "ทำอะไรได้บ้าง", "คุณคือใคร",
    "help", "สวัสดีตอนเช้า",
)


def is_greeting(question: str) -> bool:
    """True when the whole message is an opening, a thank-you or a "what are
    you for" — not when it merely starts with one.

    :param question: as typed
    """
    stripped = _WORDISH.sub("", question.lower())
    if len(stripped) > 24:
        return False
    return any(stripped.startswith(_WORDISH.sub("", g)) for g in _GREETINGS)


def corpus_text(store) -> str:
    """One lowercased string of everything indexed, for the gate to test against.

    Held in memory because it is small — under 100 kB for a catalogue of this
    size — and because the alternative, a LIKE query per candidate window, is
    hundreds of scans of the same table per question.
    """
    rows = store.db.execute("SELECT title, text FROM chunk").fetchall()
    return " \x00 ".join(f"{r[0]} {r[1]}" for r in rows).lower()


def query(assessment: Assessment) -> str:
    """The FTS5 expression for an in-scope question.

    An OR of every window. Windows drawn from common words appear in every
    chunk and so carry almost no weight under bm25; the ones that decide the
    ranking are the long specific runs. No stop-word list is needed, and none
    is written, because there is no reliable way to write one for a language
    this code does not segment.
    """
    return " OR ".join(f'"{term}"' for term in assessment.terms)
