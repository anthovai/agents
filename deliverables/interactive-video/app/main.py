"""Interactive video — the HTTP API.

A video with questions on its timeline. This half holds the definitions, marks
the answers and keeps the record; the browser half plays the video and decides
when a question is due. They talk over the endpoints below and nothing else.

The property worth stating first, because everything else is arranged around
it: **the correct answers never leave this service** except to an admin key.
A timeline fetched for playback has them stripped, so a learner reading the
network tab sees the questions and not the marking scheme. That is not a
front-end concern that can be tightened later — it is decided here, once, by
sending a different object.
"""
from __future__ import annotations

from datetime import datetime, timezone

from fastapi import Depends, FastAPI, HTTPException
from fastapi.responses import JSONResponse

from . import auth, config, grading, store as store_mod
from .models import AnswerIn, AnswerOut, Item, ProgressIn, PublicItem, Video

app = FastAPI(title="Interactive video service", version=config.SERVICE_VERSION)

_store: store_mod.Store | None = None


def store() -> store_mod.Store:
    global _store
    if _store is None:
        _store = store_mod.Store(config.DB_PATH)
    return _store


def _error(code: str, message: str, status: int = 422) -> JSONResponse:
    """Every failure names itself.

    The caller gets a code it can branch on and a sentence a person can act
    on. A service that returns an empty body when something went wrong is
    indistinguishable from one that had nothing to say.
    """
    return JSONResponse(status_code=status,
                        content={"ok": False, "error": {"code": code,
                                                        "message": message}})


def _video_or_404(video_id: str) -> Video:
    video = store().video(video_id)
    if video is None:
        raise HTTPException(status_code=404, detail="no_such_video")
    return video


@app.get("/health")
def health() -> dict:
    return {
        "ok": True,
        "service_version": config.SERVICE_VERSION,
        "timestamp": datetime.now(timezone.utc).isoformat(),
        "videos": len(store().videos()),
        # An operator needs to see at a glance whether this is wide open.
        "player_key_set": bool(config.API_KEY),
        "admin_key_set": bool(config.ADMIN_KEY),
    }


# --------------------------------------------------------------------------
# Authoring — admin only, because a timeline carries the answers
# --------------------------------------------------------------------------


@app.put("/videos/{video_id}", dependencies=[Depends(auth.require_admin)])
def put_video(video_id: str, video: Video):
    """Create or replace a video and its whole timeline.

    Every item is checked before anything is written. An item that cannot be
    answered correctly — a multichoice with no correct option, a shorttext
    with no accepted answer — is refused here rather than discovered by the
    first learner to meet it and reported as a complaint.
    """
    video.id = video_id
    seen: set[str] = set()
    for index, item in enumerate(video.timeline):
        if item.id in seen:
            return _error("duplicate_item",
                          f"two items share the id {item.id!r}; answers are "
                          f"filed against that id and would collide")
        seen.add(item.id)
        try:
            item.check()
        except ValueError as exc:
            return _error("bad_item", f"item {index + 1} ({item.id}): {exc}")

    store().save_video(video)
    return {"ok": True, "id": video.id, "items": len(video.timeline)}


@app.get("/videos", dependencies=[Depends(auth.require_admin)])
def list_videos() -> dict:
    return {"ok": True, "videos": store().videos()}


@app.get("/videos/{video_id}/definition", dependencies=[Depends(auth.require_admin)])
def definition(video_id: str) -> dict:
    """The video as authored, answers included. Admin key only."""
    return {"ok": True, "video": _video_or_404(video_id).model_dump()}


@app.delete("/videos/{video_id}", dependencies=[Depends(auth.require_admin)])
def delete_video(video_id: str):
    if not store().delete_video(video_id):
        raise HTTPException(status_code=404, detail="no_such_video")
    return {"ok": True, "deleted": video_id}


# --------------------------------------------------------------------------
# Playback
# --------------------------------------------------------------------------


@app.get("/videos/{video_id}/play", dependencies=[Depends(auth.require_player)])
def play(video_id: str, user_id: str):
    """Everything the player needs, and nothing it must not have.

    The timeline comes back as PublicItems — no expected answers, no feedback
    text. What this user has already answered comes back too, so a returning
    learner is not asked the same questions again, along with the furthest
    point they reached so playback can resume there.
    """
    video = _video_or_404(video_id)
    answers = store().answers(video_id, user_id)
    progress = store().progress(video_id, user_id)

    return {
        "ok": True,
        "video": {
            "id": video.id,
            "title": video.title,
            "provider": video.provider.value,
            "source": video.source,
            "must_answer": video.must_answer,
            "allow_retry": video.allow_retry,
        },
        "timeline": [PublicItem.of(item).model_dump()
                     for item in video.ordered()],
        "answered": [
            {"item_id": row["item_id"], "correct": row["correct"]}
            for row in answers.values()
        ],
        "resume_at": progress["seconds"],
    }


@app.post("/videos/{video_id}/answer", response_model=None,
          dependencies=[Depends(auth.require_player)])
def answer(video_id: str, body: AnswerIn):
    """Mark one answer and record it.

    Marked here rather than in the browser, and that is the whole point of the
    endpoint: a verdict the page worked out is a verdict the page can be told
    to reach.
    """
    if len(body.response) > config.MAX_RESPONSE_CHARS:
        return _error("response_too_long",
                      f"an answer may be at most "
                      f"{config.MAX_RESPONSE_CHARS} characters")

    video = _video_or_404(video_id)
    item = next((i for i in video.timeline if i.id == body.item_id), None)
    if item is None:
        return _error("no_such_item",
                      f"this video has no item {body.item_id!r}", 404)

    existing = store().answers(video_id, body.user_id).get(body.item_id)
    if existing and existing["correct"]:
        # Already right. Re-answering cannot make it wrong, and letting a
        # second submission overwrite it would let a correct answer be undone
        # by a stray click.
        return _error("already_answered",
                      "this item has already been answered correctly", 409)
    if existing and not video.allow_retry:
        return _error("no_retry",
                      "this video does not allow a second attempt", 409)

    try:
        stored, correct = grading.judge(item, body.response)
    except grading.BadResponse as exc:
        # Refused rather than scored wrong: a response that could not have
        # come from the player must not put a mark in somebody's record.
        return _error(exc.code, exc.message)

    store().record_answer(video_id, body.user_id, item.id, stored, correct)

    may_retry = bool(video.allow_retry and not correct)
    return AnswerOut(
        correct=correct,
        answers=grading.disclosure(item, correct, may_retry),
        feedback=item.feedback,
        may_retry=may_retry,
    )


@app.post("/videos/{video_id}/progress", dependencies=[Depends(auth.require_player)])
def progress(video_id: str, body: ProgressIn) -> dict:
    """Where the playhead got to.

    The furthest point is kept rather than the latest — see Store.record_progress
    for why that matters when a page closing sends two reports at once.
    """
    _video_or_404(video_id)
    store().record_progress(video_id, body.user_id, body.seconds, body.finished)
    return {"ok": True}


# --------------------------------------------------------------------------
# Results
# --------------------------------------------------------------------------


@app.get("/videos/{video_id}/result", dependencies=[Depends(auth.require_player)])
def result(video_id: str, user_id: str) -> dict:
    """One person's standing, for showing them their own.

    Open to the player key because an application shows a learner their own
    result. It carries no correct answers — only what they got right.
    """
    video = _video_or_404(video_id)
    answers = store().answers(video_id, user_id)
    return {
        "ok": True,
        "user_id": user_id,
        "summary": store_mod.summary(video, answers, store().progress(video_id, user_id)),
        "by_category": store_mod.by_category(video, answers),
    }


@app.get("/videos/{video_id}/report", dependencies=[Depends(auth.require_admin)])
def report(video_id: str) -> dict:
    """Everyone's standing on this video. Admin key only."""
    video = _video_or_404(video_id)
    rows = []
    for user_id in store().viewers(video_id):
        answers = store().answers(video_id, user_id)
        rows.append({
            "user_id": user_id,
            **store_mod.summary(video, answers, store().progress(video_id, user_id)),
        })
    # Least finished first: a report is read to find who needs chasing.
    rows.sort(key=lambda r: (r["fraction"] is None, r["fraction"] or 0))
    return {"ok": True, "video_id": video_id, "viewers": len(rows), "rows": rows}


@app.delete("/users/{user_id}", dependencies=[Depends(auth.require_admin)])
def forget_user(user_id: str) -> dict:
    """Erase one person from every video.

    Here because a service that records what a named person answered needs an
    answer to "delete my data" that is one call rather than a project. The
    video definitions are untouched; only this person's answers and progress
    go.
    """
    removed = store().forget_user(user_id)
    return {"ok": True, "user_id": user_id, "rows_deleted": removed}
