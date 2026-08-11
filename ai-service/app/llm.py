"""Talking to whichever model is configured.

Only the OpenAI chat-completions shape is used, and nothing else, so that
Ollama on this machine, a LiteLLM gateway and OpenAI itself are all reachable
without a branch in the code. The choice is then an operational one — which
is what a customer with data-residency rules actually needs it to be.
"""
from __future__ import annotations

import json
import time

import httpx

from . import config


class LlmError(Exception):
    """Failed in a way the caller must be told about, not swallowed.

    An advisory feature is allowed to be unavailable. It is not allowed to
    fail silently and leave a reviewer believing there was nothing to report.
    """

    def __init__(self, code: str, message: str):
        self.code = code
        self.message = message
        super().__init__(f"{code}: {message}")


def _headers() -> dict[str, str]:
    headers = {"Content-Type": "application/json"}
    if config.LLM_API_KEY:
        headers["Authorization"] = f"Bearer {config.LLM_API_KEY}"
    return headers


def ask(system: str, user: str, model: str | None = None,
        timeout: float | None = None) -> tuple[str, str]:
    """Return (content, model name that answered).

    `timeout` bounds this one call. Endpoints that may ask twice pass what is
    left of the request's budget rather than the full limit — see budget()
    below for why that is not a detail.
    """
    model = model or config.LLM_MODEL
    timeout = config.TIMEOUT if timeout is None else timeout
    body = {
        "model": model,
        "messages": [
            {"role": "system", "content": system},
            {"role": "user", "content": user},
        ],
        "temperature": config.TEMPERATURE,
        "max_tokens": config.MAX_TOKENS,
    }

    try:
        response = httpx.post(
            f"{config.LLM_BASE_URL}/chat/completions",
            headers=_headers(), json=body, timeout=timeout)
    except httpx.TimeoutException as error:
        raise LlmError("timeout", str(error) or "the model did not answer in time")
    except httpx.HTTPError as error:
        raise LlmError("unreachable", str(error) or "no route to the model")

    if response.status_code >= 400:
        # Pass the provider's own words through: "you didn't provide an API
        # key" is the whole diagnosis, and paraphrasing it wastes an hour.
        detail = response.text[:400]
        try:
            detail = response.json()["error"]["message"]
        except (ValueError, KeyError, TypeError):
            pass
        raise LlmError("rejected", detail)

    try:
        decoded = response.json()
    except ValueError:
        raise LlmError("bad_response", "the model did not return JSON")

    try:
        content = decoded["choices"][0]["message"]["content"]
    except (KeyError, IndexError, TypeError):
        raise LlmError("bad_response", "no message in the model's reply")

    if not content or not content.strip():
        raise LlmError("empty", "the model returned nothing")

    return content.strip(), str(decoded.get("model") or model)


def reachable() -> tuple[bool, str]:
    """Whether the configured backend answers, for the health endpoint."""
    try:
        response = httpx.get(f"{config.LLM_BASE_URL}/models",
                             headers=_headers(), timeout=5)
    except httpx.HTTPError as error:
        return False, str(error) or "no route to the model"
    if response.status_code >= 400:
        return False, f"HTTP {response.status_code}"
    return True, ""


def extract_json_array(content: str) -> list:
    """Pull a JSON array out of a reply that may be wrapped in prose.

    The model was asked for JSON and is not trusted to have obeyed. A reply
    that cannot be parsed is reported as no findings: this feature points a
    human at suspect text, so failing to point is a non-event, while crashing
    an import screen is not.
    """
    start = content.find("[")
    end = content.rfind("]")
    if start < 0 or end < start:
        return []
    try:
        parsed = json.loads(content[start:end + 1])
    except ValueError:
        return []
    return parsed if isinstance(parsed, list) else []


class budget:
    """How much of a request's time is left.

    AI_TIMEOUT bounds one call to the model. It did not bound a *request*,
    because an answer that trips a guard is asked again — so /ask could take
    two full timeouts, 600 seconds against a 300-second limit, and Moodle's
    330-second outer limit fired first. What came back was a bare curl timeout
    instead of the service's own account of what happened, which is exactly
    the failure the ordered chain exists to prevent.

    The chain was right; the arithmetic was wrong. A retry has to come out of
    the same budget, not start a new one.
    """

    def __init__(self, seconds: float | None = None):
        self.total = config.TIMEOUT if seconds is None else seconds
        self.started = time.monotonic()

    def remaining(self) -> float:
        return max(0.0, self.total - (time.monotonic() - self.started))

    def enough_for_another(self, minimum: float = 20.0) -> bool:
        """Whether a second attempt could plausibly finish.

        Starting one with eight seconds left buys a timeout instead of an
        answer, and the caller then has neither the retry nor the first reply.
        """
        return self.remaining() >= minimum
