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


# --------------------------------------------------------------------------
# Finding your way around the site
# --------------------------------------------------------------------------
# The calling platform does the retrieval, because it is the only side that
# knows what this learner is allowed to see. What arrives here is already
# filtered; the contract's job is to make sure it is only navigation — titles
# and links — and never a grade, an attempt or anything about a person.

MAX_QUESTION = 500
MAX_CONTEXT = 12
MAX_TITLE = 200
MAX_SUMMARY = 400

_LINK = re.compile(r"^(?:https?://[^\s<>\"']+|/[^\s<>\"']*)$")

_ALLOWED_KINDS = {
    "course", "section", "activity", "page", "quiz", "lesson", "video",
    "resource", "tool",
}

# What may be said about the asking learner's own record.
#
# Enumerated rather than shape-checked, unlike the policy snapshot: policy keys
# differ between platforms and are settings, while these are somebody's exam
# results. A field that appears here is a field somebody decided to disclose.
#
# Everything is about the person asking. There is no key for another learner,
# no key for a cohort average, and no key for a name — an assistant that can
# describe one learner to another is a different and much worse product.
_ALLOWED_FACTS = {
    "opens", "closes", "timelimitminutes",
    "attemptsallowed", "attemptsused",
    "grade", "gradeoutof", "gradepercent",
    "passmark", "passed", "notattempted",
}


def ask(payload: Any) -> dict[str, Any]:
    """Validate a navigation question and the pages offered as context."""
    if not isinstance(payload, dict):
        raise ContractError("ask", "must be an object")

    unknown = sorted(set(payload) - {"question", "context"})
    if unknown:
        raise ContractError(f"ask.{unknown[0]}", "is not part of the contract")

    question = _text(payload.get("question"), "ask.question", MAX_QUESTION)
    if not question.strip():
        raise ContractError("ask.question", "is empty")

    items = payload.get("context")
    if not isinstance(items, list):
        raise ContractError("ask.context", "must be a list")
    if len(items) > MAX_CONTEXT:
        raise ContractError("ask.context", f"more than {MAX_CONTEXT} pages")

    out = []
    for index, item in enumerate(items):
        where = f"ask.context[{index}]"
        if not isinstance(item, dict):
            raise ContractError(where, "must be an object")
        unknown = sorted(set(item) - {"title", "url", "kind", "summary", "facts"})
        if unknown:
            raise ContractError(f"{where}.{unknown[0]}", "is not part of the contract")

        url = _text(item.get("url", ""), f"{where}.url", 500)
        if not _LINK.match(url):
            raise ContractError(f"{where}.url", "is not a link")

        kind = item.get("kind", "page")
        if kind not in _ALLOWED_KINDS:
            raise ContractError(f"{where}.kind",
                                f"must be one of {', '.join(sorted(_ALLOWED_KINDS))}")

        out.append({
            "title": _text(item.get("title", ""), f"{where}.title", MAX_TITLE),
            "url": url,
            "kind": kind,
            "summary": _text(item.get("summary") or "", f"{where}.summary", MAX_SUMMARY),
            "facts": _facts(item.get("facts"), f"{where}.facts"),
        })

    return {"question": question, "context": out}


def _facts(value: Any, path: str) -> dict[str, Any]:
    """The asking learner's own record for one page.

    Values are scalars the caller has already finished computing — including
    any percentage. The model is never asked to do arithmetic on somebody's
    result, because a figure it worked out itself is a figure nobody can trace
    back to the gradebook.
    """
    if value is None:
        return {}
    if not isinstance(value, dict):
        raise ContractError(path, "must be an object")

    unknown = sorted(set(value) - _ALLOWED_FACTS)
    if unknown:
        raise ContractError(f"{path}.{unknown[0]}", "is not part of the contract")

    out: dict[str, Any] = {}
    for key, fact in value.items():
        where = f"{path}.{key}"
        if isinstance(fact, bool) or isinstance(fact, (int, float)):
            out[key] = fact
        elif isinstance(fact, str):
            out[key] = _text(fact, where, 60)
        else:
            raise ContractError(where, "must be a number, boolean or short string")
    return out
