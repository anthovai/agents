"""What counts as right.

This is the file to read when somebody disputes a mark, so each test says what
rule it is holding and why that rule was chosen rather than the obvious
alternative.
"""

import pytest

from app.grading import BadResponse, disclosure, judge, normalise
from app.models import Item, ItemType


def choice(**kwargs) -> Item:
    base = dict(id="q1", at=10, type=ItemType.CHOICE, text="?",
                choices=["a", "b", "c"], answers=[1])
    return Item(**{**base, **kwargs})


# --------------------------------------------------------------------------
# Typed answers
# --------------------------------------------------------------------------


def test_typing_the_answer_is_correct():
    item = choice(type=ItemType.SHORTTEXT, choices=[], answers=["ผู้ป่วย"])
    stored, correct = judge(item, "ผู้ป่วย")
    assert correct
    assert stored == "ผู้ป่วย"


def test_spacing_and_latin_case_are_forgiven():
    item = choice(type=ItemType.SHORTTEXT, choices=[], answers=["Safety First"])
    assert judge(item, "  safety   FIRST ")[1]


def test_thai_tone_marks_are_not_forgiven():
    """The line between leniency and marking the wrong thing right.

    Folding Thai combining marks would make ผู้ and ผู the same word. That is
    not a typo being forgiven, it is a different word being accepted, and the
    learner who wrote it would be told they were right about something they
    were not.
    """
    item = choice(type=ItemType.SHORTTEXT, choices=[], answers=["ผู้ป่วย"])
    assert not judge(item, "ผูป่วย")[1]


def test_any_of_the_accepted_answers_will_do():
    item = choice(type=ItemType.SHORTTEXT, choices=[],
                  answers=["หมวกนิรภัย", "helmet"])
    assert judge(item, "helmet")[1]
    assert judge(item, "หมวกนิรภัย")[1]


def test_an_empty_box_is_refused_not_marked_wrong():
    """Otherwise a mis-click spends the only attempt and records a wrong
    answer nobody gave."""
    item = choice(type=ItemType.SHORTTEXT, choices=[], answers=["x"])
    with pytest.raises(BadResponse) as caught:
        judge(item, "   ")
    assert caught.value.code == "empty_response"


# --------------------------------------------------------------------------
# Options
# --------------------------------------------------------------------------


def test_the_right_option_is_correct():
    assert judge(choice(), "[1]")[1]


def test_a_wrong_option_is_not():
    assert not judge(choice(), "[0]")[1]


def test_a_single_answer_question_refuses_two_options():
    with pytest.raises(BadResponse) as caught:
        judge(choice(), "[0, 1]")
    assert caught.value.code == "bad_choice"


def test_multichoice_needs_all_of_them_and_nothing_else():
    """No partial credit, deliberately.

    Half a mark for half the boxes invites an argument about the scheme that
    neither the learner nor the teacher can settle from the record.
    """
    item = choice(type=ItemType.MULTICHOICE, answers=[0, 2])
    assert judge(item, "[0, 2]")[1]
    assert judge(item, "[2, 0]")[1], "order must not matter"
    assert not judge(item, "[0]")[1], "not all of them"
    assert not judge(item, "[0, 1, 2]")[1], "one too many"


def test_a_repeated_option_is_counted_once():
    item = choice(type=ItemType.MULTICHOICE, answers=[0, 2])
    stored, correct = judge(item, "[0, 2, 2, 0]")
    assert correct
    assert stored == "[0, 2]"


def test_an_option_that_does_not_exist_is_refused():
    """Refused rather than scored wrong: it did not come from the player, and
    recording it would put a mark in a record that no learner earned."""
    with pytest.raises(BadResponse) as caught:
        judge(choice(), "[7]")
    assert caught.value.code == "bad_choice"


def test_rubbish_is_refused():
    with pytest.raises(BadResponse):
        judge(choice(), "not json at all")


# --------------------------------------------------------------------------
# Info cards
# --------------------------------------------------------------------------


def test_an_info_card_is_acknowledged_not_marked():
    item = choice(type=ItemType.INFO, choices=[], answers=[])
    stored, correct = judge(item, "")
    assert correct, "there was nothing to get wrong"
    assert stored == ""


# --------------------------------------------------------------------------
# When the answer may be shown
# --------------------------------------------------------------------------


def test_the_answer_is_withheld_while_a_retry_is_offered():
    """Showing it then would make the retry free."""
    assert disclosure(choice(), correct=False, may_retry=True) == []


def test_the_answer_is_shown_once_there_is_no_retry_left():
    assert disclosure(choice(), correct=False, may_retry=False) == ["b"]


def test_nothing_is_shown_after_a_correct_answer():
    assert disclosure(choice(), correct=True, may_retry=False) == []


def test_a_typed_answer_is_disclosed_as_the_words():
    item = choice(type=ItemType.SHORTTEXT, choices=[], answers=["helmet", "หมวก"])
    assert disclosure(item, correct=False, may_retry=False) == ["helmet", "หมวก"]


# --------------------------------------------------------------------------
# Items an author should not be able to save
# --------------------------------------------------------------------------


def test_a_multichoice_with_no_correct_option_is_refused():
    """Caught when written, not when a learner meets it and everybody is
    marked wrong."""
    with pytest.raises(ValueError):
        choice(type=ItemType.MULTICHOICE, answers=[]).check()


def test_a_choice_with_two_correct_options_is_refused():
    with pytest.raises(ValueError):
        choice(answers=[0, 1]).check()


def test_an_answer_pointing_past_the_options_is_refused():
    with pytest.raises(ValueError):
        choice(answers=[9]).check()


def test_a_shorttext_with_nothing_accepted_is_refused():
    with pytest.raises(ValueError):
        choice(type=ItemType.SHORTTEXT, choices=[], answers=["  "]).check()


def test_an_info_card_needs_nothing():
    choice(type=ItemType.INFO, choices=[], answers=[]).check()


def test_normalise_leaves_thai_alone_but_folds_latin():
    assert normalise("  Hello   World ") == "hello world"
    assert normalise("ความปลอดภัย") == "ความปลอดภัย"
