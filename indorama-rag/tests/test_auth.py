"""Who gets in, and how often.

The rate limit is the only thing between a caller's retry loop and somebody's
invoice: a turn is several model calls, and the caller is trusted with its own
users' conversations, not with the bill.
"""

import pytest
from fastapi import HTTPException

from app import auth, config


@pytest.fixture
def keys(monkeypatch):
    """Two configured callers, and no limit unless a test asks for one."""
    monkeypatch.setattr(config, "API_KEYS", {"acme": "key-acme", "internal": "key-int"})
    monkeypatch.setattr(config, "RATE_PER_MINUTE", 0)
    auth._buckets.clear()
    yield
    auth._buckets.clear()


def test_a_configured_key_is_accepted_and_named(keys):
    assert auth.require_key("key-acme") == "acme"
    assert auth.require_key("key-int") == "internal"


def test_an_unknown_key_is_refused(keys):
    with pytest.raises(HTTPException) as caught:
        auth.require_key("key-nobody")
    assert caught.value.status_code == 401


def test_a_missing_key_is_refused(keys):
    with pytest.raises(HTTPException) as caught:
        auth.require_key(None)
    assert caught.value.status_code == 401


def test_no_keys_configured_lets_everything_through(monkeypatch):
    """The local-test escape hatch, stated so that it is a decision."""
    monkeypatch.setattr(config, "API_KEYS", {})
    assert auth.require_key(None) == "anonymous"


# --------------------------------------------------------------------------
# The limit
# --------------------------------------------------------------------------


@pytest.fixture
def tight(monkeypatch, keys):
    """Three calls in hand, refilled slowly enough to be observable."""
    monkeypatch.setattr(config, "RATE_PER_MINUTE", 60)
    monkeypatch.setattr(config, "RATE_BURST", 3)
    auth._buckets.clear()


def test_a_burst_is_allowed_then_refused(tight):
    for _ in range(3):
        assert auth.require_key_and_allowance("key-acme") == "acme"

    with pytest.raises(HTTPException) as caught:
        auth.require_key_and_allowance("key-acme")
    assert caught.value.status_code == 429
    assert caught.value.detail == "rate_limited"
    # A caller told how long to wait can wait. One left to guess retries,
    # which is the behaviour that spent the allowance.
    assert int(caught.value.headers["Retry-After"]) >= 1


def test_one_caller_cannot_spend_anothers_allowance(tight):
    """The reason keys are per-caller rather than one shared secret."""
    for _ in range(3):
        auth.require_key_and_allowance("key-acme")
    with pytest.raises(HTTPException):
        auth.require_key_and_allowance("key-acme")

    assert auth.require_key_and_allowance("key-int") == "internal"


def test_the_allowance_refills(tight, monkeypatch):
    for _ in range(3):
        auth.require_key_and_allowance("key-acme")
    with pytest.raises(HTTPException):
        auth.require_key_and_allowance("key-acme")

    # Advance the clock rather than sleep: a test that waits a real second to
    # prove a refill is a test people stop running.
    bucket = auth._buckets["acme"]
    bucket.checked -= 1.0
    assert auth.require_key_and_allowance("key-acme") == "acme"


def test_the_limit_can_be_switched_off(keys, monkeypatch):
    monkeypatch.setattr(config, "RATE_PER_MINUTE", 0)
    for _ in range(50):
        auth.require_key_and_allowance("key-acme")


def test_a_refused_call_costs_nothing(tight):
    """A wrong key must not spend a real caller's allowance.

    Otherwise anyone who can reach the port can exhaust a paying caller's
    limit by guessing keys, which turns a failed break-in into a denial of
    service against somebody else.
    """
    for _ in range(10):
        with pytest.raises(HTTPException):
            auth.require_key_and_allowance("key-nobody")

    for _ in range(3):
        assert auth.require_key_and_allowance("key-acme") == "acme"


# --------------------------------------------------------------------------
# How keys are written down
# --------------------------------------------------------------------------


def test_labelled_keys_are_parsed():
    parsed = config._parse_keys("acme:aaa, internal:bbb")
    assert parsed == {"acme": "aaa", "internal": "bbb"}


def test_a_bare_key_still_works():
    """A single-caller deployment should not have to learn the syntax."""
    assert config._parse_keys("just-a-key") == {"default": "just-a-key"}


def test_an_empty_setting_means_no_keys():
    assert config._parse_keys("") == {}
    assert config._parse_keys("  ,  ") == {}
