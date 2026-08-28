"""Configuration, all of it from the environment.

Same shape as the other services in this family, so that one deployment looks
like the next and an operator who has run one has run all of them.
"""
import os

SERVICE_VERSION = "1.0.0"

# Where the SQLite file lives. Under a mounted directory in Docker, so that
# answers survive the container being rebuilt — which they must, because they
# are the record of what somebody was marked on.
DB_PATH = os.environ.get("VIDEO_DB_PATH", "data/interactive-video.sqlite")

# --------------------------------------------------------------------------
# Access control
# --------------------------------------------------------------------------
# Two keys, doing two different jobs.
#
# VIDEO_API_KEY is for the calling application: it may fetch a timeline, submit
# an answer on behalf of a named user, and read that user's own progress. It
# never reaches a browser — the caller passes user_id and this service takes
# it on trust, so anyone holding this key can answer as anybody.
#
# VIDEO_ADMIN_KEY is for authoring and reporting: creating videos, editing
# timelines (which contain the correct answers), and reading everyone's
# results. Separate because the two audiences are different, and because a
# leak of the first must not hand over the answer key.
API_KEY = os.environ.get("VIDEO_API_KEY", "").strip()
ADMIN_KEY = os.environ.get("VIDEO_ADMIN_KEY", "").strip()

# --------------------------------------------------------------------------
# Limits
# --------------------------------------------------------------------------
# Calls per minute per key, and how many may arrive at once after a quiet
# spell. Answering is cheap here — no model, no image work — so this is a
# guard against a loop rather than a cost control.
RATE_PER_MINUTE = float(os.environ.get("VIDEO_RATE_PER_MINUTE", 300))
RATE_BURST = float(os.environ.get("VIDEO_RATE_BURST", 100))

# The longest a typed answer may be. Long enough for a sentence, short enough
# that the column is not a place to put a file.
MAX_RESPONSE_CHARS = int(os.environ.get("VIDEO_MAX_RESPONSE_CHARS", 500))
