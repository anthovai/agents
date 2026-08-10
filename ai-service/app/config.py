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
LLM_API_KEY = os.environ.get("AI_LLM_API_KEY", "").strip()

# Low, not zero: a summary that reads like a person wrote it is more useful
# than one that reads like a template, and nothing here is a decision that
# needs to be reproducible.
TEMPERATURE = float(os.environ.get("AI_TEMPERATURE", 0.2))
MAX_TOKENS = int(os.environ.get("AI_MAX_TOKENS", 700))

# A reviewer is waiting, but not forever. Local models on a laptop GPU are
# slower than a hosted API, so this is generous by hosted-API standards.
TIMEOUT = float(os.environ.get("AI_TIMEOUT", 120))

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
CONTRACT_VERSION = "1.0"
