"""Configuration for the Indorama system assistant.

Same shape as the proctor's ai-service, and for the same reason: which model
answers is an operational choice, not a code one. The default points at Ollama
on the container host, because a model on our own hardware is the only
configuration where the client's schema does not leave the building — and this
corpus is a complete map of their database, which is not something to send to a
third party by accident.
"""

import os

LLM_BASE_URL = os.environ.get(
    "RAG_LLM_BASE_URL", "http://host.docker.internal:11434/v1").rstrip("/")
LLM_MODEL = os.environ.get("RAG_LLM_MODEL", "qwen2.5:7b-instruct")
LLM_API_KEY = os.environ.get("RAG_LLM_API_KEY", "").strip()

INDEX_PATH = os.environ.get("RAG_INDEX_PATH", "index.sqlite")

# The learner catalogue, which is a second index rather than more rows in the
# first one. The export it is built from says why in its own usage_rules:
# learners must never be shown controller, API or database names, and the
# first index is nothing else. One index cannot hold both and honour that;
# two cannot fail to.
#
# Unset means the learner endpoints report that they are not configured, and
# the developer ones carry on unaffected.
LEARNER_INDEX_PATH = os.environ.get("RAG_LEARNER_INDEX_PATH", "").strip()

# --------------------------------------------------------------------------
# Access control
# --------------------------------------------------------------------------
# The keys callers send as X-Agent-Key. Empty disables the check, which is
# right for a test run on one laptop and wrong the moment a second machine can
# reach the port: every answer here describes the client's database, and the
# conversation endpoints will hand over any transcript they can name. See
# app/auth.py for what a key does and does not establish.
#
# One key per caller, written as `label:key`, separated by commas:
#
#     RAG_API_KEY=acme:9f3c...,internal:2b71...
#
# The label is not a secret and never leaves the process except in a log line
# and a rate-limit bucket; it exists so that one caller can be revoked, or
# found in a log, without the others being touched. A bare key with no colon
# is accepted and labelled "default", so a single-caller deployment does not
# have to learn the syntax.


def _parse_keys(raw: str) -> dict[str, str]:
    keys: dict[str, str] = {}
    for index, entry in enumerate(raw.split(",")):
        entry = entry.strip()
        if not entry:
            continue
        label, _, key = entry.partition(":")
        if not key:
            label, key = ("default" if index == 0 else f"caller{index}"), label
        keys[label.strip()] = key.strip()
    return keys


API_KEYS = _parse_keys(os.environ.get("RAG_API_KEY", ""))

# How many model-backed calls one caller may make per minute, and how many of
# them may arrive at once after a quiet spell.
#
# There is nothing else standing between a retry loop and the bill: the caller
# is trusted with its users' conversations, not with the invoice, and a turn
# here is several model calls. 0 disables the limit.
#
# The default is deliberately generous for a person and mean for a script — a
# reader asking questions will never see it, and a loop hits it within seconds.
RATE_PER_MINUTE = float(os.environ.get("RAG_RATE_PER_MINUTE", 20))
RATE_BURST = float(os.environ.get("RAG_RATE_BURST", 10))

# Low, not zero. Nothing here is a decision that has to be reproducible, and a
# schema explanation that reads like prose is more useful than one that reads
# like a dump of the chunk it came from.
TEMPERATURE = float(os.environ.get("RAG_TEMPERATURE", 0.2))
MAX_TOKENS = int(os.environ.get("RAG_MAX_TOKENS", 900))

# Extra allowance for a reasoning model's working, added to MAX_TOKENS and
# only for those models — see llm._sampling.
#
# Their thinking is billed and budgeted from the same ceiling as the answer, so
# a limit that fits an answer leaves nothing to answer with: what comes back is
# HTTP 200 carrying an empty string. The number is not a measurement of how
# much thinking a question needs — nothing here can know that — it is the
# margin at which the failure stops being "the ceiling was too low".
REASONING_HEADROOM = int(os.environ.get("RAG_REASONING_HEADROOM", 2000))

# The whole request, not one call. A retry after a guardrail catch has to fit
# inside what is left of this, or the caller times out and loses the diagnosis
# along with the answer.
TIMEOUT = float(os.environ.get("RAG_TIMEOUT", 90))

# How many chunks are put in front of the model. Six of these chunks is a large
# prompt — a table chunk for a wide table runs past 3,000 characters — and the
# corpus is precise enough that the answer is almost always in the first two.
CONTEXT_CHUNKS = int(os.environ.get("RAG_CONTEXT_CHUNKS", 4))

# --------------------------------------------------------------------------
# Conversations
# --------------------------------------------------------------------------
CHAT_DIR = os.environ.get("RAG_CHAT_DIR", "chats")

# One gigabyte per account, as specified.
#
# Worth knowing what that is in practice: a turn of Markdown conversation runs
# two to five kilobytes, so this ceiling is somewhere past two hundred thousand
# messages for one person. It will not be reached by talking. It is a backstop
# against a loop or a script, not a budget anybody needs to manage, and the
# number to watch on a shared machine is the sum across accounts rather than
# this.
USER_QUOTA_BYTES = int(os.environ.get("RAG_USER_QUOTA_BYTES", 1024 ** 3))

# How many earlier turns are replayed to the model.
#
# Not the whole conversation: the context also carries tool results, which are
# far larger than the turns, and a long history crowds them out. Old turns stay
# in the file either way — this bounds what is reasoned over, not what is kept.
HISTORY_TURNS = int(os.environ.get("RAG_HISTORY_TURNS", 8))

# How many tool calls one question may make before the agent stops asking.
#
# A ceiling rather than a target. A model that has not answered after this many
# lookups is looping, and the honest end to a loop is a stated limit rather
# than a timeout somewhere further out.
MAX_TOOL_CALLS = int(os.environ.get("RAG_MAX_TOOL_CALLS", 5))

# The whole of one agent turn, which is not one model call.
#
# TIMEOUT above was sized for /ask, where the model is asked once. A turn here
# costs a call to decide on a tool, a call to read what it returned, and
# sometimes a third after a guardrail catch — each of them twenty to forty
# seconds on the hardware this was measured on. Under the /ask budget the third
# turn of a conversation timed out every time, and the timeout was reported as
# the failure when the real one was a limit set for a different shape of work.
AGENT_TIMEOUT = float(os.environ.get("RAG_AGENT_TIMEOUT", 300))
