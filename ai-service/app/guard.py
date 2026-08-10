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
