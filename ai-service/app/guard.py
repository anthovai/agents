"""Checking what came back, not just what went in.

The prompt tells the model not to reach a verdict. Measured against
qwen2.5:7b, that instruction alone held in one run out of five: the model kept
writing sentences of the form "there is no clear evidence of wrongdoing",
which is a verdict phrased as a denial and gets read as one.

An instruction a model follows most of the time is not a guarantee, and this
product's whole claim is that the AI decides nothing. So the rule is enforced
here, on the text that actually came back, where compliance does not depend on
which model is configured or how well it understands Thai.

Failing loudly is the intended behaviour. If a deployment cannot produce a
summary without a verdict in it, the operator needs to know that the model
they chose is not fit for this job — not to be shown the verdict.
"""
from __future__ import annotations

import re

# Words that only appear when the model has started adjudicating. Both the
# accusation and the exoneration are listed, because "no evidence of cheating"
# is the same failure as "the learner cheated": it invites the reader to treat
# the model's opinion as a finding.
_VERDICT_WORDS = [
    # Thai
    "ทุจริต", "ฉ้อโกง", "โกงข้อสอบ", "กระทำผิด", "กระทำความผิด",
    "ประพฤติมิชอบ", "เจตนาหลีกเลี่ยง", "ผ่านการสอบ", "ไม่ผ่านการสอบ",
    "ควรลงโทษ", "ตัดสิทธิ์", "บริสุทธิ์",
    # English, for a deployment configured to answer in English
    "cheat", "cheating", "misconduct", "dishonest", "malpractice",
    "should pass", "should fail", "disqualif",
]

_PATTERN = re.compile("|".join(re.escape(word) for word in _VERDICT_WORDS),
                      re.IGNORECASE)

# Appended when asking again. Kept separate from the main prompt because it is
# a correction, and a correction that is always present stops reading as one.
RETRY_NOTE = """\

Your previous answer contained a judgement about whether wrongdoing occurred.
Rewrite it describing only what the record shows and what a reviewer should
look at. Do not use the words for cheating, wrongdoing, misconduct, passing or
failing at all, in any language, including to say that none occurred.
"""


def verdicts_in(summary: str) -> list[str]:
    """Which forbidden words the summary contains, in the order found."""
    seen: list[str] = []
    for match in _PATTERN.finditer(summary):
        word = match.group(0)
        if word not in seen:
            seen.append(word)
    return seen


# --------------------------------------------------------------------------
# Links in an answer
# --------------------------------------------------------------------------
# The failure mode for a navigation assistant is not a wrong sentence, it is a
# link that looks right and goes nowhere. A model asked for the path to a page
# it half-remembers will build one that matches the pattern of the others, and
# the learner clicks it, lands on a 404, and stops believing the next answer.
#
# The prompt says to copy links exactly. This checks that it did, because
# "usually copies correctly" is not a property worth shipping.

_URL_IN_TEXT = re.compile(r"https?://[^\s<>\"'()\[\]]+|(?<![\w/])/[\w./?=&%#:-]+")

# Trailing punctuation gets swept up when a link ends a Thai sentence.
_TRAILING = ".,;:!?)]}ๆ"


def links_in(answer: str) -> list[str]:
    found = []
    for match in _URL_IN_TEXT.finditer(answer):
        link = match.group(0).rstrip(_TRAILING)
        if link and link not in found:
            found.append(link)
    return found


def invented_links(answer: str, allowed: list[str]) -> list[str]:
    """Links in the answer that were not among the pages supplied."""
    permitted = set(allowed)
    return [link for link in links_in(answer) if link not in permitted]


LINK_NOTE = """

Your previous answer contained a link that was not in the list you were given.
Answer again using only the links exactly as they appear in the list. If the
right page is not in the list, say that you cannot find it.
"""


# --------------------------------------------------------------------------
# Numbers in an answer
# --------------------------------------------------------------------------
# Same idea as the link check, for the same reason. A learner asking about
# their mark gets a number, and a number a model worked out itself is one
# nobody can trace back to the gradebook — worse than a broken link, because
# it looks authoritative and is quotable in a complaint.
#
# So the caller computes every figure, including percentages, and hands them
# over finished. Anything numeric in the answer that was not supplied means the
# model did arithmetic, and the answer is dropped.

_NUMBER = re.compile(r"\d+(?:[.,]\d+)?")

# Thai digits, in case a model helpfully localises "80" to "๘๐".
_THAI_DIGITS = str.maketrans("๐๑๒๓๔๕๖๗๘๙", "0123456789")


def _numbers(text: str) -> list[str]:
    plain = text.translate(_THAI_DIGITS)
    found = []
    for match in _NUMBER.finditer(plain):
        token = match.group(0).replace(",", ".")
        # "8" and "8.0" and "8.00" are the same figure; comparing them as
        # written would reject correct answers over formatting.
        try:
            token = f"{float(token):g}"
        except ValueError:
            continue
        found.append(token)
    return found


def unsupported_numbers(answer: str, supplied: str) -> list[str]:
    """Figures in the answer that were not among the figures disclosed.

    `supplied` must be the facts and the question, NOT the whole prompt. The
    first version of this compared against the rendered page list, which
    numbers its entries and carries ids inside URLs — so "1" through "8" and
    every activity id counted as supplied. Asked "how many marks am I short
    of full", the model answered "2 marks", having subtracted 8 from 10, and
    the check waved it through because a list item happened to be numbered 2.

    Links are stripped from the answer first: the ids inside them are the link
    guard's business, and counting them here would reject correct answers.
    """
    allowed = set(_numbers(supplied))
    prose = _URL_IN_TEXT.sub(" ", answer)
    return [n for n in _numbers(prose) if n not in allowed]


NUMBER_NOTE = """

Your previous answer contained a figure that was not in the material you were
given. Answer again using only the numbers exactly as they appear there. Do not
add them up, convert them, or work out a percentage. If the number the learner
asked for is not in the material, say that you do not have it.
"""
