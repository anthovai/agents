"""What comes back from the model, before anything else looks at it.

Both cases here were found by running the benchmark against real models rather
than by reasoning about the API contract, which is why they are the two the
service had no handling for.
"""

import pytest

from app import llm


class _Response:
    def __init__(self, payload, status=200):
        self._payload = payload
        self.status_code = status
        self.text = str(payload)

    def json(self):
        return self._payload


def _reply(content: str) -> dict:
    return {"model": "test-model",
            "choices": [{"message": {"content": content}}]}


@pytest.fixture
def posted(monkeypatch):
    """Captures the outbound request so no model is needed."""
    sent = {}

    def fake_post(url, **kwargs):
        sent["url"] = url
        sent["body"] = kwargs.get("json")
        return sent["response"]

    monkeypatch.setattr(llm.httpx, "post", fake_post)
    return sent


def test_an_ordinary_answer_comes_through(posted):
    posted["response"] = _Response(_reply("ตาราง tbl_company มี 35 คอลัมน์"))
    content, model = llm.ask("system", "user")
    assert content == "ตาราง tbl_company มี 35 คอลัมน์"
    assert model == "test-model"


def test_an_empty_answer_is_a_failure_not_a_success(posted):
    """gemma4 returned one of these for two of the ten benchmark questions.

    HTTP 200, a well-formed body, and nothing in it. Every guard downstream
    passed — there was nothing to be ungrounded about — and the service handed
    the caller ``ok: true`` with a blank string, which reads as "the assistant
    had nothing to say" rather than "the model failed".
    """
    posted["response"] = _Response(_reply("   \n  "))
    with pytest.raises(llm.LlmError) as caught:
        llm.ask("system", "user")
    assert caught.value.code == "llm_empty"


def test_a_reasoning_model_s_working_is_not_the_answer(posted):
    """Stripped here so the guards never see it either.

    A table name invented inside <think> and then discarded by the model itself
    is not something anybody reads, and refusing the answer over it would drop
    a correct reply because of a thought.
    """
    posted["response"] = _Response(_reply(
        "<think>maybe tbl_invented_name? no, that is not in the list</think>\n"
        "ตาราง tbl_company มี 35 คอลัมน์"))
    content, _ = llm.ask("system", "user")
    assert content == "ตาราง tbl_company มี 35 คอลัมน์"
    assert "tbl_invented_name" not in content


def test_a_reply_that_is_only_thinking_is_empty(posted):
    """The qwen3:8b shape: the budget went to reasoning and the answer never
    arrived. Named as empty rather than passed on as a blank success."""
    posted["response"] = _Response(_reply("<think>still working on it</think>"))
    with pytest.raises(llm.LlmError) as caught:
        llm.ask("system", "user")
    assert caught.value.code == "llm_empty"


def test_an_http_error_names_itself(posted):
    posted["response"] = _Response({"error": "no such model"}, status=404)
    with pytest.raises(llm.LlmError) as caught:
        llm.ask("system", "user")
    assert caught.value.code == "llm_error"


# --------------------------------------------------------------------------
# Which sampling fields go out
# --------------------------------------------------------------------------
# A reasoning model refuses max_tokens and refuses any temperature but its
# default, and refuses them as 400s rather than by ignoring them. Both were
# found by calling gpt-5-mini with the body that had worked against Ollama for
# every model before it.


def test_a_local_model_gets_temperature_and_max_tokens(posted, monkeypatch):
    monkeypatch.setattr(llm.config, "LLM_MODEL", "qwen2.5:7b-instruct")
    posted["response"] = _Response(_reply("ok"))
    llm.ask("system", "user")

    assert posted["body"]["max_tokens"] == llm.config.MAX_TOKENS
    assert posted["body"]["temperature"] == llm.config.TEMPERATURE
    assert "max_completion_tokens" not in posted["body"]


def test_a_reasoning_model_gets_neither(posted, monkeypatch):
    monkeypatch.setattr(llm.config, "LLM_MODEL", "gpt-5-mini")
    posted["response"] = _Response(_reply("ok"))
    llm.ask("system", "user")

    assert "max_tokens" not in posted["body"],         "max_tokens is a 400 from this family, not an ignored field"
    assert "temperature" not in posted["body"],         "only the default temperature is accepted, so the field is omitted"
    # Room for the thinking as well as the answer: the two are drawn from the
    # same allowance, and a ceiling that fits only the answer returns an empty
    # string with HTTP 200.
    assert posted["body"]["max_completion_tokens"] > llm.config.MAX_TOKENS


def test_the_tool_calling_path_agrees_with_the_plain_one(posted, monkeypatch):
    """converse() builds its own body, so it can drift from ask() silently.

    It did not here, and the test exists so that it cannot later: the agent
    path is the one customers use, and a 400 there is every question failing.
    """
    monkeypatch.setattr(llm.config, "LLM_MODEL", "gpt-5-mini")
    posted["response"] = _Response(
        {"choices": [{"message": {"role": "assistant", "content": "ok"}}]})
    llm.converse([{"role": "user", "content": "hi"}], [])

    assert "max_tokens" not in posted["body"]
    assert "temperature" not in posted["body"]
    assert "max_completion_tokens" in posted["body"]


def test_dated_snapshots_are_recognised_too(monkeypatch):
    """The dated ids are what a deployment pins to, so they must match."""
    assert llm._is_reasoning("gpt-5-mini-2025-08-07")
    assert llm._is_reasoning("gpt-5.4-mini")
    assert llm._is_reasoning("o3-mini")
    assert not llm._is_reasoning("gpt-4o-mini")
    assert not llm._is_reasoning("qwen2.5:7b-instruct")
