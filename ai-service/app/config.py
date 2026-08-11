"""Configuration for the AI reviewer service.

The service sits between a learning platform and whichever model is answering.
Both ends are configuration, deliberately: the platform may be our Moodle
build or a customer's own LMS, and the model may be one running on this
machine or one behind a vendor API. Neither choice belongs in the code.
"""
import os

# --------------------------------------------------------------------------
# The model behind us
# --------------------------------------------------------------------------
# Anything that speaks the OpenAI chat-completions API: Ollama's compatibility
# endpoint, a LiteLLM gateway, or OpenAI itself. The default points at Ollama
# on the container host, because a model on our own hardware is the only
# configuration where no learner data leaves the building.
LLM_BASE_URL = os.environ.get(
    "AI_LLM_BASE_URL", "http://host.docker.internal:11434/v1").rstrip("/")
LLM_MODEL = os.environ.get("AI_LLM_MODEL", "qwen2.5:7b-instruct")

# One model per task, because measuring them found no single best.
#
# qwen3:8b answered 15/15 navigation questions correctly where qwen2.5:7b
# managed 9/15 — but qwen3:8b then failed the summary guardrail outright,
# reaching a verdict even after being told not to. Forcing one model on both
# jobs would mean giving up half the navigation accuracy or shipping a
# summariser that gets blocked. Two names in a config file is the cheaper
# answer, and it costs nothing to point them at the same model later.
#
# Each defaults to AI_LLM_MODEL, so a deployment that wants one model has one
# setting to change. Measurements: reports/ai-ask-bench.txt.
MODEL_SUMMARISE = os.environ.get("AI_MODEL_SUMMARISE", LLM_MODEL)
MODEL_ASK = os.environ.get("AI_MODEL_ASK", LLM_MODEL)
MODEL_QUESTIONS = os.environ.get("AI_MODEL_QUESTIONS", LLM_MODEL)
LLM_API_KEY = os.environ.get("AI_LLM_API_KEY", "").strip()

# Low, not zero: a summary that reads like a person wrote it is more useful
# than one that reads like a template, and nothing here is a decision that
# needs to be reproducible.
TEMPERATURE = float(os.environ.get("AI_TEMPERATURE", 0.2))
MAX_TOKENS = int(os.environ.get("AI_MAX_TOKENS", 700))

# Long enough that a request which was going to succeed is never cut off.
#
# Measured rather than guessed (reports/ai-latency.txt): qwen3:8b answers in
# 22-49 seconds warm on this hardware, 35 seconds of that being a cold model
# load, and a reply that trips a guard is asked again — so the honest worst
# case is roughly two slow calls plus a load. Under load, when a browser suite
# and two 8B models are competing for the same laptop, it is several times
# that; the old 120 was hit exactly there, and the learner got a failure for
# an answer that was on its way.
#
# Three timeouts sit in a line, and the ordering matters more than any single
# value:
#
#     this  <  Moodle's ai_client::TIMEOUT  <  whatever the browser waits
#
# Reversed, the useful diagnosis — "the model did not answer in time", raised
# where the model is — is replaced by a curl timeout in Moodle that says
# nothing about why.
#
# Raising it makes failures slow rather than frequent, which is the right way
# round but not free: a learner waiting a minute needs the page to look busy,
# and the real answer to slowness is a faster model, not a shorter limit.
TIMEOUT = float(os.environ.get("AI_TIMEOUT", 300))

# --------------------------------------------------------------------------
# Access control
# --------------------------------------------------------------------------
# Shared secret sent by the calling platform as X-Proctor-Key. Same pattern as
# the face service.
API_KEY = os.environ.get("AI_API_KEY", "").strip()

SERVICE_VERSION = "1.0.0"

# The payload contract version. Callers send it and we check it, so that a
# customer running an older integration gets a clear refusal rather than a
# summary built from fields we have since redefined.
CONTRACT_VERSION = "1.1"

# Older callers still work. Adding an endpoint breaks nobody, and forcing every
# integration to move on the same day is how a version check turns into an
# outage instead of a safeguard.
SUPPORTED_CONTRACTS = {"1.0", "1.1"}
