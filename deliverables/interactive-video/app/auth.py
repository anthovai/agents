"""Who may call, in which role, and how often.

Two roles rather than one, because the two audiences want different things and
one of them wants the answer key.

**player** (``X-Video-Key``) — the application showing the lesson. It fetches
timelines with the answers stripped out, submits answers on behalf of a named
user, and reads that user's own progress.

**admin** (``X-Video-Admin-Key``) — authoring and reporting. Creating videos,
editing timelines (which is where the correct answers are written), and reading
everyone's results.

The admin key satisfies a player check as well, so an internal tool holding one
key can do both. The reverse is not true.

**Neither key may reach a browser.** The caller passes ``user_id`` with each
answer and this service takes it on trust — it has no way to check, and
inventing one would mean holding accounts for people who already have them
somewhere else. So anyone holding the player key can answer as anybody, and
anyone holding the admin key can read the answer key.
"""
from __future__ import annotations

import hmac
import threading
import time

from fastapi import Header, HTTPException

from . import config


class _Bucket:
    """One caller's allowance, refilled continuously.

    A token bucket rather than a fixed window: a window boundary hands out the
    next minute's allowance the instant the clock turns over, which is exactly
    the burst a limit exists to stop.
    """

    def __init__(self, capacity: float, per_minute: float):
        self.capacity = capacity
        self.rate = per_minute / 60.0
        self.tokens = capacity
        self.checked = time.monotonic()

    def take(self) -> float:
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
    if config.RATE_PER_MINUTE <= 0:
        return 0.0
    with _lock:
        bucket = _buckets.get(label)
        if bucket is None:
            bucket = _Bucket(config.RATE_BURST, config.RATE_PER_MINUTE)
            _buckets[label] = bucket
        return bucket.take()


def _spend(label: str) -> None:
    wait = _charge(label)
    if wait > 0:
        raise HTTPException(
            status_code=429, detail="rate_limited",
            headers={"Retry-After": str(max(1, int(wait + 0.5)))})


def _matches(presented: str | None, configured: str) -> bool:
    if not presented or not configured:
        return False
    # compare_digest rather than ==: string comparison returns early on the
    # first differing byte, and the time it took says how much of the key was
    # right.
    return hmac.compare_digest(presented, configured)


def require_player(
    x_video_key: str | None = Header(default=None),
    x_video_admin_key: str | None = Header(default=None),
) -> str:
    """Either key opens this. Returns the role, for logging.

    :raises HTTPException: 401 for a bad key, 429 when the allowance is spent
    """
    if not config.API_KEY and not config.ADMIN_KEY:
        # No keys configured at all: the local-test escape hatch, and wrong
        # anywhere a second machine can reach the port.
        return "anonymous"
    if _matches(x_video_admin_key, config.ADMIN_KEY):
        _spend("admin")
        return "admin"
    if _matches(x_video_key, config.API_KEY):
        _spend("player")
        return "player"
    raise HTTPException(status_code=401, detail="invalid_api_key")


def require_admin(x_video_admin_key: str | None = Header(default=None)) -> str:
    """Only the admin key opens this — the timelines with answers in them.

    :raises HTTPException: 401 for a bad key, 429 when the allowance is spent
    """
    if not config.ADMIN_KEY:
        return "anonymous"
    if _matches(x_video_admin_key, config.ADMIN_KEY):
        _spend("admin")
        return "admin"
    raise HTTPException(status_code=401, detail="invalid_admin_key")
