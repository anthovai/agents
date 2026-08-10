"""What a caller is allowed to send, enforced at the boundary.

The point of running this as a separate service is that the boundary becomes
somewhere a rule can be *enforced* rather than merely promised. Inside a
single application, "the payload contains no biometric data" is a claim about
the caller's discipline. Here it is a claim about this file, and a caller that
gets it wrong receives a 422 instead of quietly leaking.

That distinction matters commercially as much as legally: it lets us tell a
customer that their learners' biometric data cannot reach us, and point at the
code that makes it so, rather than at a paragraph in a contract.

Two mechanisms, because they fail differently:

  1. A whitelist of shape. Unknown keys are refused, not ignored. A field
     added to the caller's database next year does not start flowing here
     because somebody forgot to filter it.

  2. Counts are integers. Every per-category figure — checks, events,
     evidence — must be a whole number. A similarity score, a liveness score
     and a confidence are all fractions, so the shape itself refuses them.
     This is the check that survives someone renaming a field to slip it past
     a keyword filter.
"""
from __future__ import annotations

import re
from typing import Any

# Free text (a termination reason, a question) is the one place a caller could
# smuggle something without breaking the shape, so its *content* is checked.
_LOOKS_LIKE_A_FILE = re.compile(
    r"\.(jpe?g|png|webm|mp4|bin|npy)\b", re.IGNORECASE)
_LOOKS_LIKE_EMBEDDED_DATA = re.compile(
    r"data:[a-z]+/[a-z0-9.+-]+;base64,", re.IGNORECASE)
# Long unbroken runs of base64-ish characters: an encoded image or embedding
# pasted into a text field. Thai and English prose contain spaces long before
# this length.
_LOOKS_LIKE_BASE64 = re.compile(r"[A-Za-z0-9+/=]{120,}")

# Policy keys come from the calling platform and legitimately differ between
# them, so they are constrained by shape rather than enumerated.
_POLICY_KEY = re.compile(r"^[a-z][a-z0-9_]{0,40}$")

# Category keys — "identity:pass", "blur", "focus_lost". Slugs, not sentences.
_CATEGORY_KEY = re.compile(r"^[a-z][a-z0-9_:.-]{0,60}$", re.IGNORECASE)

_ALLOWED_STATUS = {"active", "completed", "terminated", "abandoned"}

MAX_REASON = 200
MAX_QUESTION_TEXT = 2000
MAX_QUESTIONS = 20
MAX_CATEGORIES = 60


class ContractError(ValueError):
    """A payload that will not be forwarded to any model.

    Carries the path to the offending field, because "invalid payload" with no
    location is the kind of error message that turns a five-minute
    integration fix into a support call.
    """

    def __init__(self, path: str, problem: str):
        self.path = path
        self.problem = problem
        super().__init__(f"{path}: {problem}")


def _text(value: Any, path: str, limit: int) -> str:
    if not isinstance(value, str):
        raise ContractError(path, "must be a string")
    if len(value) > limit:
        raise ContractError(path, f"longer than {limit} characters")
    if _LOOKS_LIKE_EMBEDDED_DATA.search(value):
        raise ContractError(path, "contains embedded binary data")
    if _LOOKS_LIKE_BASE64.search(value):
        raise ContractError(path, "contains what looks like encoded data")
    if _LOOKS_LIKE_A_FILE.search(value):
        raise ContractError(path, "contains a file name")
    return value


def _counts(value: Any, path: str) -> dict[str, int]:
    """A mapping of category to how many times it happened.

    Integer-only is the load-bearing rule here, not a tidiness preference: it
    is what makes it impossible to send a score through a field meant for a
    tally, whatever the field is called.
    """
    if not isinstance(value, dict):
        raise ContractError(path, "must be an object of counts")
    if len(value) > MAX_CATEGORIES:
        raise ContractError(path, f"more than {MAX_CATEGORIES} categories")

    out: dict[str, int] = {}
    for key, count in value.items():
        where = f"{path}.{key}"
        if not isinstance(key, str) or not _CATEGORY_KEY.match(key):
            raise ContractError(where, "is not a category name")
        # bool is an int in Python; excluding it keeps the refusal honest.
        if isinstance(count, bool) or not isinstance(count, int):
            raise ContractError(
                where, "must be a whole-number count — scores are not accepted")
        if count < 0:
            raise ContractError(where, "must not be negative")
        out[key] = count
    return out


def _policy(value: Any, path: str) -> dict[str, Any]:
    """The rules that were in force, as the calling platform recorded them.

    Thresholds belong here. A configured threshold is a rule somebody set, not
    a measurement taken from a person's face, and a summary that cannot say
    how strict the settings were is less useful to a reviewer.
    """
    if not isinstance(value, dict):
        raise ContractError(path, "must be an object")
    if len(value) > MAX_CATEGORIES:
        raise ContractError(path, f"more than {MAX_CATEGORIES} settings")

    out: dict[str, Any] = {}
    for key, setting in value.items():
        where = f"{path}.{key}"
        if not isinstance(key, str) or not _POLICY_KEY.match(key):
            raise ContractError(where, "is not a setting name")
        if isinstance(setting, bool) or isinstance(setting, (int, float)):
            out[key] = setting
        elif isinstance(setting, str):
            out[key] = _text(setting, where, 80)
        else:
            raise ContractError(where, "must be a number, boolean or short string")
    return out


def sitting(payload: Any) -> dict[str, Any]:
    """Validate one sitting, returning exactly what may be forwarded."""
    if not isinstance(payload, dict):
        raise ContractError("sitting", "must be an object")

    allowed = {"status", "reason", "minutes", "checks", "events",
               "evidence", "policy"}
    unknown = sorted(set(payload) - allowed)
    if unknown:
        # Refused rather than dropped: silently ignoring an unexpected field
        # lets an integrator believe they are sending something that matters,
        # and lets a future field arrive here unnoticed.
        raise ContractError(f"sitting.{unknown[0]}", "is not part of the contract")

    status = payload.get("status")
    if status not in _ALLOWED_STATUS:
        raise ContractError(
            "sitting.status", f"must be one of {', '.join(sorted(_ALLOWED_STATUS))}")

    minutes = payload.get("minutes")
    if minutes is not None:
        if isinstance(minutes, bool) or not isinstance(minutes, (int, float)):
            raise ContractError("sitting.minutes", "must be a number or null")
        if minutes < 0:
            raise ContractError("sitting.minutes", "must not be negative")

    reason = payload.get("reason")
    if reason is not None:
        reason = _text(reason, "sitting.reason", MAX_REASON)

    return {
        "status": status,
        "reason": reason,
        "minutes": minutes,
        "checks": _counts(payload.get("checks") or {}, "sitting.checks"),
        "events": _counts(payload.get("events") or {}, "sitting.events"),
        "evidence": _counts(payload.get("evidence") or {}, "sitting.evidence"),
        "policy": _policy(payload.get("policy") or {}, "sitting.policy"),
    }


def questions(payload: Any) -> list[dict[str, Any]]:
    """Validate questions submitted for a proof-read."""
    if not isinstance(payload, list):
        raise ContractError("questions", "must be a list")
    if not payload:
        raise ContractError("questions", "is empty")
    if len(payload) > MAX_QUESTIONS:
        raise ContractError("questions", f"more than {MAX_QUESTIONS} questions")

    out = []
    for index, item in enumerate(payload):
        where = f"questions[{index}]"
        if not isinstance(item, dict):
            raise ContractError(where, "must be an object")
        unknown = sorted(set(item) - {"id", "text", "choices"})
        if unknown:
            raise ContractError(f"{where}.{unknown[0]}", "is not part of the contract")

        choices = item.get("choices") or []
        if not isinstance(choices, list):
            raise ContractError(f"{where}.choices", "must be a list")

        out.append({
            "id": _text(item.get("id", ""), f"{where}.id", 60),
            "text": _text(item.get("text", ""), f"{where}.text", MAX_QUESTION_TEXT),
            "choices": [
                _text(choice, f"{where}.choices[{n}]", MAX_QUESTION_TEXT)
                for n, choice in enumerate(choices)
            ],
        })
    return out
