"""Talking to whichever model is configured.

Only the OpenAI chat-completions shape is used, so Ollama on this machine, a
LiteLLM gateway and OpenAI itself are all reachable without a branch here.
"""

import json
import re
import time

import httpx

from . import config

# A reasoning model's working, which is not the answer.
#
# Stripped here rather than left to the caller, because a reader should never
# be shown the scratchpad and — more to the point — the guards should never be
# shown it either. A table name invented inside <think> and then discarded by
# the model itself is not something anybody reads, and refusing the answer for
# it would drop a correct reply over a thought.
_THINK = re.compile(r"<think>.*?</think>", re.DOTALL | re.IGNORECASE)


def _sampling(model: str) -> dict:
    """The token and temperature fields this model will actually accept.

    The chat-completions shape is shared, but two families disagree about the
    names inside it, and both disagreements are hard errors rather than
    ignored fields:

    * a reasoning model refuses ``max_tokens`` and wants
      ``max_completion_tokens``;
    * it also refuses any ``temperature`` but the default, so the field is
      omitted rather than sent as 1 — sending it would work today and break
      the day a model narrows what it takes.

    Selected on the model name because that is what the caller configured; the
    API offers nothing to ask beforehand, and a probe request per call would
    cost a round trip to learn something that never changes.

    The token budget is raised for reasoning models because reasoning tokens
    are drawn from the same allowance as the answer. gpt-5-mini spent 64 of
    them replying "ok"; on a real question the budget that fits a local
    model's answer is consumed before the answer starts, and what arrives is
    an empty string — which ask() below correctly refuses, having been given
    nothing to work with.
    """
    if _is_reasoning(model):
        return {"max_completion_tokens": config.MAX_TOKENS + config.REASONING_HEADROOM}
    return {"temperature": config.TEMPERATURE, "max_tokens": config.MAX_TOKENS}


def _is_reasoning(model: str) -> bool:
    """Whether this name belongs to a family that reasons before answering.

    Prefix matching, deliberately: the families keep the prefix across
    versions and dated snapshots (``gpt-5-mini-2025-08-07``, ``o3-mini``), and
    a list of exact ids would be wrong on the day one is added — silently, in
    the direction of sending parameters that are refused.
    """
    name = model.lower()
    return name.startswith(("gpt-5", "gpt-6", "o1", "o3", "o4"))


class LlmError(Exception):
    """Failed in a way the caller must be told about, not swallowed."""

    def __init__(self, code: str, message: str):
        self.code = code
        self.message = message
        super().__init__(f"{code}: {message}")


class Budget:
    """What is left of the request's time.

    A guardrail catch costs a second call, and a second call given the full
    timeout can put the request past the caller's own limit — at which point
    the caller sees a timeout instead of the refusal that explains what
    happened. The diagnosis is the more useful of the two, so it gets the
    remaining time rather than a fresh allowance.
    """

    def __init__(self, total: float):
        self.total = total
        self.started = time.monotonic()

    def remaining(self) -> float:
        return max(0.0, self.total - (time.monotonic() - self.started))

    def enough_for_another(self) -> bool:
        # Half the budget is the point past which a retry is more likely to
        # time out than to finish, on the local models this was measured with.
        return self.remaining() > self.total / 2


def ask(system: str, user: str, timeout: float | None = None) -> tuple[str, str]:
    """Return (content, the model that answered)."""
    headers = {"Content-Type": "application/json"}
    if config.LLM_API_KEY:
        headers["Authorization"] = f"Bearer {config.LLM_API_KEY}"

    body = {
        "model": config.LLM_MODEL,
        "messages": [{"role": "system", "content": system},
                     {"role": "user", "content": user}],
        **_sampling(config.LLM_MODEL),
    }

    try:
        response = httpx.post(f"{config.LLM_BASE_URL}/chat/completions",
                              json=body, headers=headers,
                              timeout=config.TIMEOUT if timeout is None else timeout)
    except httpx.TimeoutException as exc:
        raise LlmError("llm_timeout", f"the model did not answer in time: {exc}")
    except httpx.HTTPError as exc:
        raise LlmError("llm_unreachable", f"could not reach the model: {exc}")

    if response.status_code != 200:
        raise LlmError("llm_error",
                       f"the model returned {response.status_code}: {response.text[:200]}")

    try:
        payload = response.json()
        content = payload["choices"][0]["message"]["content"]
    except (ValueError, KeyError, IndexError) as exc:
        raise LlmError("llm_malformed", f"could not read the model's reply: {exc}")

    content = _THINK.sub("", content).strip()
    if not content:
        # An empty reply is a failure that arrives dressed as a success.
        #
        # gemma4 returned one for two of the ten benchmark questions — both of
        # them the ones carrying a long list. HTTP 200, a well-formed body, and
        # nothing in it. Every guard downstream passed, because there was
        # nothing to be ungrounded about, and the service answered the caller
        # with ok:true and a blank string. Raised here so it is named once,
        # rather than by each caller noticing the emptiness separately.
        raise LlmError("llm_empty",
                       "the model returned an empty answer; on this corpus that "
                       "has meant a reply consumed entirely by reasoning, or a "
                       "model that gave up on a long list")

    return content, payload.get("model", config.LLM_MODEL)


def converse(messages: list[dict], tool_definitions: list[dict],
             timeout: float | None = None) -> dict:
    """A multi-message call that may come back asking for a tool.

    Separate from ask() rather than folded into it. ask() promises a string and
    every caller of it relies on that; this returns the assistant message
    whole, because the interesting part may be tool_calls with no text at all —
    and an empty content field is a normal, correct reply here, where in ask()
    it is a failure.
    """
    headers = {"Content-Type": "application/json"}
    if config.LLM_API_KEY:
        headers["Authorization"] = f"Bearer {config.LLM_API_KEY}"

    body = {
        "model": config.LLM_MODEL,
        "messages": messages,
        "tools": tool_definitions,
        **_sampling(config.LLM_MODEL),
    }

    try:
        response = httpx.post(f"{config.LLM_BASE_URL}/chat/completions",
                              json=body, headers=headers,
                              timeout=config.TIMEOUT if timeout is None else timeout)
    except httpx.TimeoutException as exc:
        raise LlmError("llm_timeout", f"the model did not answer in time: {exc}")
    except httpx.HTTPError as exc:
        raise LlmError("llm_unreachable", f"could not reach the model: {exc}")

    if response.status_code != 200:
        raise LlmError("llm_error",
                       f"the model returned {response.status_code}: {response.text[:200]}")

    try:
        message = dict(response.json()["choices"][0]["message"])
    except (ValueError, KeyError, IndexError) as exc:
        raise LlmError("llm_malformed", f"could not read the model's reply: {exc}")

    if message.get("content"):
        message["content"] = _THINK.sub("", message["content"]).strip()
    return message


def arguments_of(call: dict) -> dict:
    """The arguments of a tool call, whatever shape they arrived in.

    The API says this is a JSON string. Local models send a dict about as often
    as they send a string, and one that sends malformed JSON is asking for a
    tool with no arguments rather than crashing the turn — the tool will say it
    found nothing, which the model can act on.
    """
    raw = call.get("function", {}).get("arguments")
    if isinstance(raw, dict):
        return raw
    try:
        parsed = json.loads(raw or "{}")
    except (ValueError, TypeError):
        return {}
    return parsed if isinstance(parsed, dict) else {}
