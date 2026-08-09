"""Configuration for the face service.

Every threshold is an environment variable because the value in use has to be
documented and auditable — a regulator asking "what threshold decided this
learner failed?" must get an answer from configuration, not from a code diff.
"""
import os
from pathlib import Path

ROOT = Path(__file__).resolve().parent.parent
MODELS_DIR = Path(os.environ.get("PROCTOR_MODELS_DIR", ROOT / "models"))


def _env_float(name: str, default: float) -> float:
    try:
        return float(os.environ.get(name, default))
    except ValueError:
        return default


# --------------------------------------------------------------------------
# Matching
# --------------------------------------------------------------------------
# SFace produces 128-d embeddings compared by cosine similarity. OpenCV's own
# reference threshold is 0.363; it is the starting point, NOT a calibrated
# value. Calibrate against real enrolment photos before production — see
# docs/MIGRATION.md. The old buffalo_l value (0.42) does not transfer.
MATCH_THRESHOLD = _env_float("FACE_MATCH_THRESHOLD", 0.363)
REVIEW_MIN = _env_float("FACE_REVIEW_MIN", 0.30)

LIVENESS_THRESHOLD = _env_float("FACE_LIVENESS_THRESHOLD", 0.60)

MIN_FACE_SIZE = int(_env_float("FACE_MIN_SIZE", 80))
MAX_IMAGE_BYTES = int(_env_float("FACE_MAX_IMAGE_BYTES", 8 * 1024 * 1024))

# Face detector confidence — YuNet scores are well separated, 0.9 is strict.
DET_SCORE_THRESHOLD = _env_float("FACE_DET_SCORE", 0.9)

SERVICE_VERSION = "1.0.0"
MODEL_PACK = "yunet+sface"

# --------------------------------------------------------------------------
# Access control
# --------------------------------------------------------------------------
# Shared secret sent by Moodle as X-Proctor-Key. The service is not exposed to
# the host in docker-compose, but defence in depth is cheap here.
API_KEY = os.environ.get("PROCTOR_API_KEY", "").strip()
