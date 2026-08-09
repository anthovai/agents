"""Threshold calibration harness — run this before trusting any match decision.

The old prototype shipped a threshold of 0.42 for InsightFace buffalo_l but its
test images were never committed, so the number was never checked against real
photographs. SFace is a different model with a different score distribution;
carrying 0.42 across would be meaningless.

Drop enrolment photos into ``face-service/tests/faces/`` named
``<person>_<n>.jpg`` (e.g. ``somchai_1.jpg``, ``somchai_2.jpg``, ``nid_1.jpg``)
and run::

    pytest tests/test_calibration.py -s -m calibration

It prints genuine-vs-impostor score distributions and the threshold that
separates them, which is the value to put in FACE_MATCH_THRESHOLD.
"""
from __future__ import annotations

import itertools
import sys
from collections import defaultdict
from pathlib import Path

import cv2
import pytest

sys.path.insert(0, str(Path(__file__).resolve().parent.parent))

from app import config, face_engine  # noqa: E402

FACES = Path(__file__).resolve().parent / "faces"


def _load() -> dict[str, list]:
    """Group embeddings by person, taken from the filename prefix."""
    by_person: dict[str, list] = defaultdict(list)
    for path in sorted(FACES.glob("*.jpg")) + sorted(FACES.glob("*.png")):
        person = path.stem.rsplit("_", 1)[0]
        img = cv2.imread(str(path))
        if img is None:
            print(f"  skip {path.name}: unreadable")
            continue
        try:
            by_person[person].append(face_engine.extract_face(img).embedding)
        except face_engine.FaceEngineError as e:
            print(f"  skip {path.name}: {e.code}")
    return by_person


@pytest.mark.calibration
def test_report_score_distribution():
    if not FACES.is_dir() or not any(FACES.iterdir()):
        pytest.skip(f"no photos in {FACES} — see this file's docstring")

    by_person = _load()
    genuine, impostor = [], []

    for person, embeddings in by_person.items():
        for a, b in itertools.combinations(embeddings, 2):
            genuine.append((person, face_engine.cosine_similarity(a, b)))

    for (p1, e1), (p2, e2) in itertools.combinations(by_person.items(), 2):
        for a in e1:
            for b in e2:
                impostor.append((f"{p1}/{p2}", face_engine.cosine_similarity(a, b)))

    print(f"\npeople: {len(by_person)}  genuine pairs: {len(genuine)}  "
          f"impostor pairs: {len(impostor)}")

    if genuine:
        scores = sorted(s for _, s in genuine)
        print(f"genuine  min={scores[0]:.4f}  median={scores[len(scores)//2]:.4f}  "
              f"max={scores[-1]:.4f}")
    if impostor:
        scores = sorted(s for _, s in impostor)
        print(f"impostor min={scores[0]:.4f}  median={scores[len(scores)//2]:.4f}  "
              f"max={scores[-1]:.4f}")

    if genuine and impostor:
        worst_genuine = min(s for _, s in genuine)
        best_impostor = max(s for _, s in impostor)
        print(f"\nworst genuine  {worst_genuine:.4f}")
        print(f"best impostor  {best_impostor:.4f}")
        if worst_genuine > best_impostor:
            suggested = (worst_genuine + best_impostor) / 2
            print(f"SEPARATED — suggested FACE_MATCH_THRESHOLD={suggested:.4f}")
        else:
            print("OVERLAP — no threshold separates these photos cleanly.")
            print("Collect more/better enrolment photos before going live.")
        print(f"currently configured: {config.MATCH_THRESHOLD}")

    # Reporting harness, not a pass/fail gate — a human reads the numbers.
    assert by_person, "no usable faces found"
