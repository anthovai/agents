"""The rules the service enforces itself, without a model in the loop.

Each of these is a case where the model would probably behave, and "probably"
is the reason the check is in code. None of them start a model.
"""

import pytest

from app import guard

VOCABULARY = {
    "tbl_contentEnroll",
    "tbl_certificate",
    "ci_sessions",
    "application/libraries/Authorization_Token.php",
}


def test_a_real_table_passes():
    assert guard.unknown_identifiers("ดูที่ tbl_contentEnroll ครับ", VOCABULARY) == []


def test_a_plausible_invention_is_caught():
    """The failure this guard exists for.

    ``tbl_user_enrollment`` follows every convention in this database. It is
    also not in it, and nothing about how the sentence reads would tell the
    reader that.
    """
    assert guard.unknown_identifiers(
        "ข้อมูลอยู่ใน tbl_user_enrollment", VOCABULARY) == ["tbl_user_enrollment"]


def test_case_does_not_make_a_name_invented():
    assert guard.unknown_identifiers("tbl_contentenroll", VOCABULARY) == []


def test_a_basename_is_not_an_invention():
    """The corpus holds full paths; naming just the file is being helpful."""
    assert guard.unknown_identifiers(
        "อยู่ใน Authorization_Token.php", VOCABULARY) == []


def test_ordinary_thai_and_english_prose_is_not_flagged():
    """Why the check is shaped rather than vocabulary-wide.

    A guard that compared every word against the corpus would refuse this
    sentence for containing the word "table", and a guard that refuses correct
    answers is one somebody turns off.
    """
    prose = ("ตารางนี้เก็บสถานะการลงทะเบียน โดยมี status, data และ type "
             "เป็นคอลัมน์หลัก the table stores enrolment records")
    assert guard.unknown_identifiers(prose, VOCABULARY) == []


@pytest.mark.parametrize("answer,expected", [
    ("ดูที่ tbl_foo.php และ tbl_certificate", ["tbl_foo.php"]),
    ("application/models/Nope_model.php", ["application/models/Nope_model.php"]),
])
def test_invented_files_are_caught_too(answer, expected):
    assert guard.unknown_identifiers(answer, VOCABULARY) == expected
