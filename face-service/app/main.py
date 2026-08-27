"""face-service — stateless face API consumed by Moodle's local_kaiproctor.

Three endpoints, mirroring what the browser modules need:

    POST /analyze  frame -> presence, pose, liveness   (active-liveness polling)
    POST /embed    photo -> embedding                  (enrolment)
    POST /verify   frame + reference -> decision       (identity re-check)

Nothing is written to disk. Evidence, consent and audit trails belong to
Moodle, where the Privacy API can reach them.
"""
from __future__ import annotations

import base64
from datetime import datetime, timezone

import numpy as np
from fastapi import Depends, FastAPI, File, Form, Header, HTTPException, UploadFile
from fastapi.responses import JSONResponse

from . import config, exam_pdf, face_engine, liveness

app = FastAPI(title="KAISER Proctor face service", version=config.SERVICE_VERSION)


def require_key(x_proctor_key: str | None = Header(default=None)) -> None:
    """Reject calls that do not carry the shared secret.

    An unset PROCTOR_API_KEY disables the check — convenient for local test
    runs, and the reason the service is never published to the host in
    docker-compose.
    """
    if not config.API_KEY:
        return
    if x_proctor_key != config.API_KEY:
        raise HTTPException(status_code=401, detail="invalid_api_key")


def _now() -> str:
    return datetime.now(timezone.utc).isoformat()


def _error(code: str, message: str, status: int = 422) -> JSONResponse:
    return JSONResponse(
        status_code=status,
        content={"ok": False, "error": {"code": code, "message": message}},
    )


def _meta() -> dict:
    return {
        "timestamp": _now(),
        "service_version": config.SERVICE_VERSION,
        "model_pack": config.MODEL_PACK,
    }


def _encode(embedding: np.ndarray) -> str:
    return base64.b64encode(embedding.astype(np.float32).tobytes()).decode("ascii")


def _thresholds(match: str | None, review: str | None) -> dict:
    """The thresholds to decide on, from the caller or from configuration.

    Refused rather than silently corrected. A caller sending nonsense here has
    a broken setting, and a service that quietly substituted its own default
    would hide that while writing decisions the platform cannot account for —
    the exact failure this argument exists to end.

    :raises ValueError: if a supplied value is not a usable threshold
    """
    def one(raw: str | None, fallback: float, name: str) -> float:
        if raw is None or raw == "":
            return fallback
        try:
            value = float(raw)
        except ValueError:
            raise ValueError(f"{name} is not a number: {raw!r}")
        # Cosine similarity of two L2-normalised vectors cannot leave [-1, 1],
        # so a threshold outside it is a mistake rather than a strict policy:
        # above 1 nothing can ever pass, below -1 everything does.
        if not -1.0 <= value <= 1.0:
            raise ValueError(f"{name} must be between -1 and 1, got {value}")
        return value

    resolved = {
        "match": one(match, config.MATCH_THRESHOLD, "match_threshold"),
        "review_min": one(review, config.REVIEW_MIN, "review_min"),
        "liveness": config.LIVENESS_THRESHOLD,
    }
    if resolved["review_min"] > resolved["match"]:
        raise ValueError(
            f"review_min ({resolved['review_min']}) is above match_threshold "
            f"({resolved['match']}), which leaves no band to review"
        )
    return resolved


def _decode(raw: str) -> np.ndarray:
    try:
        arr = np.frombuffer(base64.b64decode(raw), dtype=np.float32)
    except Exception:
        raise face_engine.FaceEngineError("invalid_embedding", "Reference embedding is not valid base64")
    if arr.size == 0:
        raise face_engine.FaceEngineError("invalid_embedding", "Reference embedding is empty")
    return arr


@app.get("/health")
def health() -> dict:
    """Readiness plus which models actually loaded — an operator needs to see
    at a glance whether liveness is silently disabled."""
    models = sorted(p.name for p in config.MODELS_DIR.glob("*.onnx"))
    return {
        "ok": True,
        **_meta(),
        "models_present": models,
        "liveness_available": any("MiniFASNet" in m for m in models),
        "thresholds": {
            "match": config.MATCH_THRESHOLD,
            "review_min": config.REVIEW_MIN,
            "liveness": config.LIVENESS_THRESHOLD,
        },
    }


@app.post("/analyze", dependencies=[Depends(require_key)])
async def analyze(image: UploadFile = File(...)):
    """Presence + head pose + liveness for one frame.

    Used by the active-liveness challenge, which polls this a few times a
    second, so it never raises on "no face" — absence is a normal answer here.
    """
    try:
        img = face_engine.decode_image(await image.read())
    except face_engine.FaceEngineError as e:
        return _error(e.code, e.message)

    try:
        face = face_engine.extract_face(img)
    except face_engine.FaceEngineError as e:
        if e.code == "multiple_faces":
            return {"ok": True, **_meta(), "present": True, "warning": "multiple_faces"}
        if e.code in ("no_face", "face_too_small"):
            return {"ok": True, **_meta(), "present": False, "reason": e.code}
        return _error(e.code, e.message)

    pitch, yaw, roll = face.pose
    return {
        "ok": True,
        **_meta(),
        "present": True,
        "det_score": round(face.det_score, 4),
        "bbox": [round(v, 1) for v in face.bbox],
        "pose": {"pitch": round(pitch, 1), "yaw": round(yaw, 1), "roll": round(roll, 1)},
        "liveness": liveness.check_liveness(img, face.bbox),
    }


@app.post("/embed", dependencies=[Depends(require_key)])
async def embed(image: UploadFile = File(...)):
    """Enrolment: one photo in, one embedding out. Moodle stores it."""
    try:
        img = face_engine.decode_image(await image.read())
        face = face_engine.extract_face(img)
    except face_engine.FaceEngineError as e:
        return _error(e.code, e.message)

    return {
        "ok": True,
        **_meta(),
        "embedding": _encode(face.embedding),
        "dimensions": int(face.embedding.size),
        "det_score": round(face.det_score, 4),
        "liveness": liveness.check_liveness(img, face.bbox),
    }


@app.post("/parse-questions", dependencies=[Depends(require_key)])
async def parse_questions(file: UploadFile = File(...)):
    """Turn a Thai licence-exam PDF into a question bank.

    Not face work, and it sits here for a plain reason: the parser is Python
    that already exists and is already tested, extracting text from a PDF is
    something PHP does badly, and this container is the Python side of the
    system. Rewriting it in PHP would mean re-deriving Thai font repairs and
    answer-key matching that took real effort to get right.

    Nothing is stored. Moodle receives the questions and imports them into its
    own question bank, where they belong.
    """
    data = await file.read()
    if len(data) > config.MAX_PDF_BYTES:
        return _error("pdf_too_large", "PDF exceeds the maximum allowed size")

    try:
        _, title, note, questions = exam_pdf.parse_pdf(data)
    except exam_pdf.ExamPdfError as e:
        return _error(e.code, e.message)

    return {
        "ok": True,
        **_meta(),
        "title": title,
        "note": note,
        "count": len(questions),
        "questions": questions,
    }


@app.post("/verify", dependencies=[Depends(require_key)])
async def verify(
    live_image: UploadFile = File(...),
    reference_embedding: str = Form(...),
    match_threshold: str | None = Form(default=None),
    review_min: str | None = Form(default=None),
):
    """Identity re-check against a stored embedding.

    `decision` is one of pass / review / fail / fail_liveness. Liveness is
    checked first: a spoof that happens to match must never come back as a
    pass, so the similarity score is reported but the decision overridden.

    The two thresholds are the caller's to set, and it should send them: the
    platform is where an administrator configures them and where they are
    written into the record of the decision. Omitting them falls back to this
    service's own configuration, which keeps an older integration working —
    but a deployment where the two disagree records one number and decides on
    another, and the record is the only one anybody reads afterwards.

    The thresholds actually applied come back in `thresholds`, so the caller
    can store what was used rather than what it hoped would be used.
    """
    try:
        thresholds = _thresholds(match_threshold, review_min)
    except ValueError as e:
        return _error("invalid_threshold", str(e))

    try:
        img = face_engine.decode_image(await live_image.read())
        reference = _decode(reference_embedding)
        face = face_engine.extract_face(img)
    except face_engine.FaceEngineError as e:
        return _error(e.code, e.message)

    if reference.size != face.embedding.size:
        return _error(
            "embedding_mismatch",
            f"Reference embedding has {reference.size} dimensions, "
            f"the current model produces {face.embedding.size} — re-enrol this learner",
        )

    live = liveness.check_liveness(img, face.bbox)
    similarity = face_engine.cosine_similarity(face.embedding, reference)
    decision = face_engine.decide(similarity, thresholds["match"],
                                  thresholds["review_min"])
    if live["evaluated"] and not live["live"]:
        decision = "fail_liveness"

    return {
        "ok": True,
        **_meta(),
        "thresholds": thresholds,
        "liveness": live,
        "similarity": round(similarity, 4),
        "decision": decision,
        "det_score": round(face.det_score, 4),
    }
