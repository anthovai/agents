"""Whether an answer was right, and what to keep as the record of it.

Separated from everything else because this is the part somebody will be asked
to justify. A learner disputing a mark months later is owed an answer better
than "the system said so", and the answer has to be readable by whoever is
asked — so the rules live in one file, in one place, with the reasons written
next to them.

Nothing here touches a database or a request. It takes an item and what the
person said, and returns a verdict.
"""
from __future__ import annotations

import json
import re

from .models import Item, ItemType

_WHITESPACE = re.compile(r"\s+", re.UNICODE)


class BadResponse(Exception):
    """The submission is not a possible answer to this item.

    Raised rather than scored as wrong. A response naming option 7 of a
    four-option question did not come from the player, and recording it as an
    incorrect answer would put a mark in somebody's record that no learner
    earned.
    """

    def __init__(self, code: str, message: str):
        self.code = code
        self.message = message
        super().__init__(f"{code}: {message}")


def normalise(text: str) -> str:
    """Fold the differences that are not differences.

    Runs of whitespace become one space, the ends are trimmed, and Latin case
    is folded. **Nothing else** — in particular no Unicode normalisation of
    Thai, because that would make ผู้ and ผู the same word, and accepting a
    misspelling is not leniency, it is marking the wrong thing right.

    :param text: what was typed
    :return: the form both sides are compared in
    """
    return _WHITESPACE.sub(" ", text).strip().lower()


def judge(item: Item, response: str) -> tuple[str, bool]:
    """Mark one answer.

    :param item: the timeline item, including its expected answers
    :param response: what the player sent — typed text, or a JSON array of
        option indexes
    :return: (what to store, whether it was correct)
    :raises BadResponse: when the response could not have come from the player
    """
    if item.type == ItemType.INFO:
        # Acknowledged, not answered. Recorded so that a report can say they
        # reached this point, and counted correct because there was nothing
        # here to get wrong — see the note in summary() about why these are
        # then excluded from the score.
        return "", True

    if item.type == ItemType.SHORTTEXT:
        typed = normalise(response)
        if not typed:
            # An empty box is not an answer. Scoring it would spend an attempt
            # on a mis-click and record a wrong answer nobody gave.
            raise BadResponse("empty_response", "no answer was given")
        expected = [normalise(a) for a in item.answers]
        return typed, typed in expected

    # choice and multichoice, both arriving as a JSON array of indexes.
    try:
        chosen = json.loads(response)
    except (ValueError, TypeError):
        raise BadResponse("bad_choice", "the response is not a list of options")
    if not isinstance(chosen, list):
        raise BadResponse("bad_choice", "the response is not a list of options")

    clean: list[int] = []
    for raw in chosen:
        try:
            index = int(raw)
        except (ValueError, TypeError):
            raise BadResponse("bad_choice", f"option {raw!r} is not a number")
        if index < 0 or index >= len(item.choices):
            raise BadResponse(
                "bad_choice",
                f"option {index} does not exist on an item with "
                f"{len(item.choices)} choices")
        if index not in clean:
            clean.append(index)
    clean.sort()

    if not clean:
        raise BadResponse("empty_response", "no option was chosen")
    if item.type == ItemType.CHOICE and len(clean) != 1:
        raise BadResponse(
            "bad_choice",
            "a single-answer question was sent more than one option")

    # All of them and nothing else. No partial credit: half a mark for half
    # the boxes invites an argument about the scheme that neither the learner
    # nor the teacher can settle from the record.
    expected_indexes = sorted({int(a) for a in item.answers})
    return json.dumps(clean), clean == expected_indexes


def disclosure(item: Item, correct: bool, may_retry: bool) -> list[str]:
    """The right answer in words, when it is theirs to see.

    Withheld while a retry is still available, because showing it then makes
    the retry free; and withheld after a correct answer, where it is noise.

    :param item: the item just answered
    :param correct: the verdict
    :param may_retry: whether another attempt is on offer
    :return: the expected answers, or an empty list
    """
    if correct or may_retry or item.type == ItemType.INFO:
        return []
    if item.type == ItemType.SHORTTEXT:
        return list(item.answers)
    return [item.choices[int(i)] for i in sorted(item.answers)
            if 0 <= int(i) < len(item.choices)]
