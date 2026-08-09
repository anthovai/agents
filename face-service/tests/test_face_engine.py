"""Smoke tests for the YuNet + SFace pipeline.

These prove the engine loads and behaves on the error paths. They do NOT prove
matching accuracy — that needs real enrolment photos and a calibration run.
See test_calibration.py for the harness that does.
"""
from __future__ import annotations

import sys
from pathlib import Path

import cv2
import numpy as np
import pytest

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from app import config, face_engine  # noqa: E402

MODELS_PRESENT = (
    (config.MODELS_DIR / face_engine.DETECTOR_MODEL).is_file()
    and (config.MODELS_DIR / face_engine.RECOGNIZER_MODEL).is_file()
)
needs_models = pytest.mark.skipif(not MODELS_PRESENT, reason="run models/fetch.sh first")


def encode(img: np.ndarray) -> bytes:
    return cv2.imencode(".jpg", img)[1].tobytes()


def test_decode_rejects_garbage():
    with pytest.raises(face_engine.FaceEngineError) as e:
        face_engine.decode_image(b"not an image")
    assert e.value.code == "invalid_image"


def test_decode_rejects_oversized_payload():
    with pytest.raises(face_engine.FaceEngineError) as e:
        face_engine.decode_image(b"x" * (config.MAX_IMAGE_BYTES + 1))
    assert e.value.code == "image_too_large"


@needs_models
def test_blank_frame_reports_no_face():
    blank = np.zeros((480, 640, 3), dtype=np.uint8)
    with pytest.raises(face_engine.FaceEngineError) as e:
        face_engine.extract_face(blank)
    assert e.value.code == "no_face"


@needs_models
def test_noise_frame_reports_no_face():
    rng = np.random.default_rng(0)
    noise = rng.integers(0, 255, (480, 640, 3), dtype=np.uint8)
    with pytest.raises(face_engine.FaceEngineError) as e:
        face_engine.extract_face(noise)
    assert e.value.code == "no_face"


def test_decision_bands_follow_thresholds():
    assert face_engine.decide(config.MATCH_THRESHOLD) == "pass"
    assert face_engine.decide(config.MATCH_THRESHOLD + 0.1) == "pass"
    assert face_engine.decide(config.REVIEW_MIN) == "review"
    assert face_engine.decide(config.REVIEW_MIN - 0.01) == "fail"


def test_cosine_similarity_of_identical_vectors_is_one():
    v = np.array([0.6, 0.8], dtype=np.float32)
    assert face_engine.cosine_similarity(v, v) == pytest.approx(1.0)
