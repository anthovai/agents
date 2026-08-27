"""Who may call this service, how often, and under whose name.

**The key identifies the calling system, not the person asking.** The caller
passes a ``user_id`` with every question so that conversations are filed
separately, and this service takes that id on trust — it has no way to check
it, and inventing one would mean holding accounts and passwords for people who
already have them somewhere else.

Which means the key holder can read and delete any conversation it can name.
That is correct for a back-end calling on behalf of its own users, and wrong
for anything else. **The key must never reach a browser, a mobile app, or
anywhere else an end user can read it** — anyone holding it can pass any
``user_id`` they like, including somebody else's.

Keys are configured one per caller rather than one for everybody, so that a
leaked key can be revoked without taking the others down with it, and so that
a rate limit and a log line can name which caller they belong to.

An unset key list disables both the check and the limit. Convenient for a local
test run and wrong anywhere a second machine can reach the port.
"""
from __future__ import annotations

import hmac
import threading
import time

from fastapi import Header, HTTPException

from . import config


def _resolve(presented: str | None) -> str | None:
    """Which configured caller this key belongs to.

    :param presented: what arrived in the header
    :return: the caller's label, or None if the key matches nothing
    """
    if presented is None:
        return None
    # Every configured key is compared, and the loop is not cut short on the
    # first match. Returning early would make the reply time depend on the
    # key's position in the list, which leaks the order to anyone patient
    # enough to measure it.
    found = None
    for label, key in config.API_KEYS.items():
        if hmac.compare_digest(presented, key):
            found = label
    return found


class _Bucket:
    """One caller's allowance, refilled continuously.

    A token bucket rather than a fixed window: a window boundary lets a caller
    spend the whole next minute's allowance the instant the clock ticks over,
    which is exactly the burst the limit exists to stop. Refilling smoothly
    means a caller that has been quiet may still burst — up to `capacity`,
    which is the point — but cannot do it twice in a row.
    """

    def __init__(self, capacity: float, per_minute: float):
        self.capacity = capacity
        self.rate = per_minute / 60.0
        self.tokens = capacity
        self.checked = time.monotonic()

    def take(self) -> float:
        """Spend one token.

        :return: 0 when the call may proceed, otherwise the seconds to wait
        """
        now = time.monotonic()
        self.tokens = min(self.capacity,
                          self.tokens + (now - self.checked) * self.rate)
        self.checked = now
        if self.tokens >= 1:
            self.tokens -= 1
            return 0.0
        return (1 - self.tokens) / self.rate if self.rate > 0 else 3600.0


_buckets: dict[str, _Bucket] = {}
_lock = threading.Lock()


def _charge(label: str) -> float:
    """Spend one of this caller's tokens, creating their bucket if new.

    :param label: the caller, from _resolve
    :return: 0 when the call may proceed, otherwise the seconds to wait
    """
    if config.RATE_PER_MINUTE <= 0:
        return 0.0
    with _lock:
        bucket = _buckets.get(label)
        if bucket is None:
            bucket = _Bucket(config.RATE_BURST, config.RATE_PER_MINUTE)
            _buckets[label] = bucket
        return bucket.take()


def require_key(x_agent_key: str | None = Header(default=None)) -> str:
    """Authenticate the caller. No limit — for the endpoints that cost nothing.

    :return: the caller's label, so a handler can log who it was
    :raises HTTPException: 401 when a key is configured and does not match
    """
    if not config.API_KEYS:
        return "anonymous"
    label = _resolve(x_agent_key)
    if label is None:
        raise HTTPException(status_code=401, detail="invalid_api_key")
    return label


def require_key_and_allowance(x_agent_key: str | None = Header(default=None)) -> str:
    """Authenticate, then spend one of the caller's tokens.

    For the endpoints that reach a model. Each of those costs real money at
    whoever is paying for the backend, and nothing else here would stop a
    retry loop from spending it — the caller is trusted with conversations,
    not with the invoice.

    429 rather than a silent delay: a caller told to wait can wait, and a
    caller left hanging retries, which is the behaviour that caused the
    limit to be reached.

    :return: the caller's label
    :raises HTTPException: 401 for a bad key, 429 when the allowance is spent
    """
    label = require_key(x_agent_key)
    wait = _charge(label)
    if wait > 0:
        raise HTTPException(
            status_code=429,
            detail="rate_limited",
            headers={"Retry-After": str(max(1, int(wait + 0.5)))})
    return label
