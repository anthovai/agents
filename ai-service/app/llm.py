"""Talking to whichever model is configured.

Only the OpenAI chat-completions shape is used, and nothing else, so that
Ollama on this machine, a LiteLLM gateway and OpenAI itself are all reachable
without a branch in the code. The choice is then an operational one — which
is what a customer with data-residency rules actually needs it to be.
"""
from __future__ import annotations

import json

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


def ask(system: str, user: str) -> tuple[str, str]:
    """Return (content, model name that answered)."""
    body = {
        "model": config.LLM_MODEL,
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
            headers=_headers(), json=body, timeout=config.TIMEOUT)
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

    return content.strip(), str(decoded.get("model") or config.LLM_MODEL)


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
