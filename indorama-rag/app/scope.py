"""Is this question about the system at all?

Answered before retrieval and before any model call, because the alternative
was measured and it does not work. Searching the index for
"วันนี้อากาศเป็นยังไง" returned three tables — Thai function words collided
with Thai function words in our own labels — and once material comes back, the
service has nothing left to refuse on. The model is handed three table
definitions and a question about the weather, and it will produce something.
What it produces is not about this system, but it arrives in the same shape as
an answer that is.

A question is in scope when it contains at least one of:

* **a name the corpus holds** — a table, a file path, a controller
* **an English domain term** — ``schema``, ``route``, ``sensitive``
* **a Thai lexicon term** — see :mod:`app.lexicon`

Nothing else counts. In particular, matching text anywhere in a chunk does not
count: the corpus contains column names like ``data``, ``name``, ``type`` and
``status``, and any rule generous enough to admit those admits most sentences
in either language.

The cost of this design is that a question phrased entirely outside both
vocabularies is refused even when the corpus could have answered it. That is
the failure worth having: it is visible, it names itself, and the person who
hits it can report the missing word. The alternative failure — a confident
answer assembled from whatever happened to rank — is invisible to the person
it misleads.
"""

import re
from dataclasses import dataclass, field

from . import lexicon

_LATIN = re.compile(r"[A-Za-z0-9_]{2,}")
# A path or filename, kept whole. Without this, ``Authorization_Token.php``
# splits at the dot into two tokens and stops matching the corpus entry
# ``application/libraries/Authorization_Token.php`` by basename — a question
# naming a file exactly was the one thing that was supposed to always work.
_PATHLIKE = re.compile(r"[A-Za-z0-9_./-]*\.php\b")


# Wording that asks for a set rather than for one thing. Matters because the
# same retrieval that answers "what does tbl_company hold" perfectly will hand
# back four tables for "which tables hold personal data", where the answer is
# twenty-six — and four correct tables presented as the list is worse than no
# answer, because nothing in it says it is a sample.
#
# Deliberately not including the very common Thai "บ้าง" on its own: it appears
# in "tbl_company มีคอลัมน์อะไรบ้าง", which is a question about one table.
# Paired with a word that means "all" it counts, and that pairing is what the
# phrases below encode.
_AGGREGATE = (
    "ทั้งหมด", "ทุกตาราง", "ทุกไฟล์", "ทุกคอลัมน์", "มีกี่", "กี่ตาราง",
    "กี่ไฟล์", "กี่รายการ", "จำนวนตาราง", "รายชื่อ", "ลิสต์", "สรุป",
    "ตารางไหนบ้าง", "ไฟล์ไหนบ้าง", "อะไรบ้างที่",
)
_AGGREGATE_EN = frozenset({
    "all", "list", "every", "total", "count", "how", "many", "which",
    "inventory", "overview", "summary",
})


# There is deliberately no "is this a follow-up" heuristic here.
#
# The first attempt matched referential words — "มัน", "นั้น", "นี้" — in a short
# question, so that "แล้วมันผูกกับตารางไหน" could inherit the conversation's
# scope. It classified "วันนี้อากาศเป็นยังไง" as a follow-up on its first run,
# because "นี้" is inside "วันนี้". Thai has no word boundaries to anchor on,
# and a substring test for function words is the same mistake as the character
# trigrams this module already replaced once.
#
# A follow-up is instead handled where it can be settled by fact rather than by
# guessing at grammar: see app.agent, where a turn in an existing conversation
# is allowed past the gate and then required to have actually looked something
# up. A question about the weather reaches no tool result and is refused for
# that, in any language.


@dataclass
class Assessment:
    in_scope: bool
    named: list[str] = field(default_factory=list)
    english: list[str] = field(default_factory=list)
    thai: list[str] = field(default_factory=list)
    anchors: list[str] = field(default_factory=list)
    kinds: set[str] = field(default_factory=set)
    aggregate: bool = False
    # Which count keys the question could be asking for, and whether it asks
    # for a subset the figures do not break down by. Both are handed to
    # app.tools so a count that does not answer the question can be refused
    # rather than served.
    count_keys: set = field(default_factory=set)
    filtered: bool = False
    # True when the question was let through because it refers back to the
    # conversation rather than because it named anything itself.
    followup: bool = False

    def why(self) -> str:
        """What put the question in scope, in the words it used."""
        signals = self.named + self.english + self.thai
        return ", ".join(signals)


def assess(question: str, vocabulary: set[str]) -> Assessment:
    tokens = {t.lower() for t in _LATIN.findall(question)}
    tokens |= {t.lower() for t in _PATHLIKE.findall(question)}

    # A name is recognised by full path or by basename: somebody writing
    # Authorization_Token.php means the file the corpus holds under
    # application/libraries/Authorization_Token.php.
    named = sorted({name for name in vocabulary
                    if name.lower() in tokens
                    or name.rsplit("/", 1)[-1].lower() in tokens})

    english = sorted(tokens & lexicon.ENGLISH_TERMS)
    thai = lexicon.terms_in(question)
    anchors, kinds = lexicon.expand(question, set(english))

    aggregate = (any(phrase in question for phrase in _AGGREGATE)
                 or bool(tokens & _AGGREGATE_EN))

    return Assessment(
        in_scope=bool(named or english or thai),
        named=named, english=english, thai=thai,
        anchors=anchors, kinds=kinds, aggregate=aggregate,
        count_keys=lexicon.counts_wanted(question, tokens),
        filtered=lexicon.has_filter(question, tokens))
