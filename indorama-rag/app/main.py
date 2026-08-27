"""The service: a question in, an answer with its sources out.

Five properties are enforced here rather than asked for in the prompt, because
each is something a model will get right most of the time, and the times it
does not are the times that matter:

* **An off-topic question never reaches the model.** Decided by app.scope
  before retrieval runs. Measured: without it, five of six off-topic Thai
  questions retrieved material, and material is all it takes.
* **Nothing found means no model call either.** A model handed a question with
  no material will answer anyway, from whatever it learned about CodeIgniter
  applications elsewhere, and that answer describes a system that is not this
  one. Deciding it in code is cheaper and more certain than asking the model
  to decline.
* **The answer may only name what it was shown.** Not what exists in the
  corpus — what was in the material for this question. See guard.py.
* **The answer may not do arithmetic.** Every figure has to appear in the
  material or the question. Handed a list stating "26 ตาราง", a model copied
  the list out, recounted its own transcription, and reported 22.
* **The sources come back with the answer.** Not a citation the model wrote —
  the actual chunks that were put in front of it, so the reader can check the
  answer against the schema rather than against their memory of it.
"""

import os

from fastapi import Depends, FastAPI
from fastapi.responses import FileResponse, JSONResponse

from . import (agent, auth, config, guard, llm, memory, prompts, scope,
               store as store_mod)

app = FastAPI(title="Indorama LMS system assistant", version="1.0.0")

_store: store_mod.Store | None = None
_vocabulary: set[str] = set()
_chats: memory.Store | None = None


def _memory() -> memory.Store:
    global _chats
    if _chats is None:
        _chats = memory.Store(config.CHAT_DIR)
    return _chats


def _open() -> store_mod.Store:
    global _store, _vocabulary
    if _store is None:
        _store = store_mod.Store(config.INDEX_PATH)
        _vocabulary = _store.vocabulary()
    return _store


def _failed(code: str, message: str, status: int) -> JSONResponse:
    """Every failure names itself.

    The caller gets a code it can branch on and a sentence a person can act on.
    An assistant that returns an empty answer when something went wrong is
    indistinguishable from one that had nothing to say.
    """
    return JSONResponse({"ok": False, "code": code, "detail": message},
                        status_code=status)


_PAGE = os.path.join(os.path.dirname(__file__), "static", "chat.html")


@app.get("/")
def page() -> FileResponse:
    """The chat page, served by the same process as the API.

    A separate front end would need its own server, its own build and its own
    CORS story, for a demo that runs on one laptop. One file, no build step,
    same origin — and it exists because ``curl`` on Windows mangles Thai on the
    way to the process, which makes the API unusable by hand in the language
    every question is asked in.
    """
    return FileResponse(_PAGE, media_type="text/html; charset=utf-8")


@app.get("/health")
def health() -> dict:
    if not os.path.exists(config.INDEX_PATH):
        return {"ok": False, "code": "no_index",
                "detail": f"no index at {config.INDEX_PATH}; run ingest.build"}
    store = _open()
    return {
        "ok": True,
        "chunks": store.count(),
        "vocabulary": len(_vocabulary),
        "model": config.LLM_MODEL,
        "build": store.meta(),
    }


@app.post("/search", response_model=None,
          dependencies=[Depends(auth.require_key)])
def search(body: dict) -> JSONResponse | dict:
    """Retrieval on its own, with no model involved.

    Kept as its own endpoint because it is how a retrieval complaint gets
    diagnosed. When an answer is wrong, the first question is whether the right
    chunk was even found, and that should not require running a model to learn.
    """
    question = (body.get("question") or "").strip()
    if not question:
        return _failed("empty_question", "no question was supplied", 422)
    store = _open()
    assessment = scope.assess(question, _vocabulary)
    hits = store.search(question, limit=body.get("limit", 8), assessment=assessment)
    # The scope decision is reported rather than only acted on. When a question
    # comes back empty the first thing worth knowing is whether it was refused
    # as off-topic or searched and not found, and those look identical from the
    # outside.
    return {"ok": True, "question": question,
            "in_scope": assessment.in_scope,
            "matched_terms": {"named": assessment.named,
                              "english": assessment.english,
                              "thai": assessment.thai},
            "kinds_searched": sorted(assessment.kinds),
            "hits": [{"chunk_id": h["chunk_id"], "kind": h["kind"],
                      "ref": h["ref"], "title": h["title"]} for h in hits]}


@app.post("/ask", response_model=None,
          dependencies=[Depends(auth.require_key_and_allowance)])
def ask(body: dict) -> JSONResponse | dict:
    question = (body.get("question") or "").strip()
    if not question:
        return _failed("empty_question", "no question was supplied", 422)

    store = _open()

    # Scope first, and a refusal here never reaches the model. An off-topic
    # question used to retrieve three tables anyway — Thai function words
    # collided with the Thai in our own labels — and a model handed material
    # answers from it. The two refusals are kept apart because they mean
    # different things to whoever reads them: one says this assistant is the
    # wrong place to ask, the other says it is the right place and the corpus
    # does not go that deep.
    assessment = scope.assess(question, _vocabulary)
    if not assessment.in_scope:
        return _failed("off_topic",
                       "this assistant only answers about the structure of the "
                       "Indorama LMS — its tables, routes and source files. The "
                       "question does not name anything in it.", 400)

    hits = store.search(question, limit=config.CONTEXT_CHUNKS, assessment=assessment)
    if not hits:
        return _failed("no_material",
                       "the question is about the system, but nothing in the "
                       f"indexed schema, routes or source structure matches "
                       f"[{assessment.why()}]", 404)

    material = "\n\n".join(
        f"--- {h['title']} ---\n{h['text']}" for h in hits)

    # Say when what was shown is a sample.
    #
    # This is the safety net under the digests, and it matters more than they
    # do. A digest exists for the sets that could be worked out in advance; this
    # covers every set that could not. Four correct table definitions handed to
    # a model that was asked "which tables" produces four correct table names
    # and no indication that there were twenty-two more — the answer is wrong
    # in the only way that survives being checked, because everything in it is
    # true.
    #
    # A digest already in the material means the complete list is present, so
    # the warning would contradict it.
    total = store.total_matches(assessment.named,
                                assessment.english + assessment.anchors,
                                assessment.kinds)
    has_digest = any(h["kind"] == "digest" for h in hits)
    if total > len(hits) and not has_digest:
        material += (
            f"\n\n--- ขอบเขตของข้อมูลข้างต้น ---\n"
            f"ข้อมูลที่ให้มาข้างบนมี {len(hits)} รายการ แต่ในระบบมีอีกรวม {total} รายการ "
            f"ที่ตรงกับคำถามนี้ — ที่แสดงคือ**ตัวอย่างที่ตรงที่สุด ไม่ใช่รายการครบ** "
            f"ถ้าคำถามขอรายการทั้งหมดหรือจำนวนรวม ต้องบอกผู้ถามว่านี่ไม่ใช่รายการครบ "
            f"และห้ามสรุปจำนวนรวมจากสิ่งที่เห็น")

    user = f"คำถาม:\n{question}\n\nข้อมูลจากระบบ:\n{material}"

    # Checked against what was shown, not against the whole corpus. A real
    # table name the model was never given did not come from the material.
    allowed = guard.allowed_from(hits)

    def ungrounded(answer: str) -> list[str]:
        return (guard.unknown_identifiers(answer, allowed)
                + guard.ungrounded_columns(answer, hits)
                + guard.unsupported_numbers(answer, material, question))

    budget = llm.Budget(config.TIMEOUT)
    try:
        content, model = llm.ask(prompts.ASK, user, budget.remaining())

        invented = ungrounded(content)
        if invented and budget.enough_for_another():
            note = guard.RETRY_NOTE.format(names=", ".join(invented))
            content, model = llm.ask(prompts.ASK + note, user, budget.remaining())
            invented = ungrounded(content)
    except llm.LlmError as exc:
        return _failed(exc.code, exc.message, 502)

    if invented:
        # Dropped rather than shown with a warning beside it. A reader who
        # wanted the answer will take the name anyway, and the whole cost of
        # this failure is that the name gets used.
        return _failed("ungrounded_answer",
                       "the model produced names or figures that were not in the "
                       "material it was given: " + ", ".join(invented), 502)

    return {
        "ok": True,
        "answer": content,
        "model": model,
        "sources": [{"chunk_id": h["chunk_id"], "kind": h["kind"],
                     "ref": h["ref"], "title": h["title"]} for h in hits],
        # Complete lists are returned whole, for the caller to render beside
        # the answer.
        #
        # The prompt asks the model not to transcribe them, and this is what
        # makes that instruction affordable rather than a loss of information.
        # Asked which tables hold sensitive data, qwen2.5:7b-instruct copied out
        # the 26-row list and wrote ``tbl_meetup`` — the real name is
        # ``tbl_meetings_zoom``. The guard caught it and the whole answer was
        # dropped, which is correct and useless: the list was right there in the
        # material, and the only thing that went wrong was asking a model to
        # retype it.
        "lists": [{"ref": h["ref"], "title": h["title"], "text": h["text"]}
                  for h in hits if h["kind"] == "digest"],
    }


# --------------------------------------------------------------------------
# The agent: a conversation, with tools
# --------------------------------------------------------------------------
#
# /ask above is kept rather than replaced. It is one question, one retrieval,
# no model discretion about what to look at — which makes it the thing to reach
# for when an agent answer is wrong and the question is whether retrieval or
# the agent loop is at fault.


@app.post("/chat", response_model=None,
          dependencies=[Depends(auth.require_key_and_allowance)])
def chat(body: dict) -> JSONResponse | dict:
    """One turn of a conversation. Starts one if no id is given."""
    user_id = (body.get("user_id") or "").strip()
    question = (body.get("message") or "").strip()
    if not user_id:
        return _failed("no_user", "user_id is required", 422)
    if not question:
        return _failed("empty_question", "no message was supplied", 422)

    store = _open()
    chats = _memory()

    try:
        conversation_id = body.get("conversation_id") or ""
        if conversation_id:
            turns = chats.turns(user_id, conversation_id)
        else:
            # Titled from the first question, trimmed. A list of conversations
            # all called "New chat" is a list nobody can navigate.
            title = question[:60] + ("…" if len(question) > 60 else "")
            conversation_id = chats.start(user_id, title)
            turns = []

        # The question is stored before the answer exists.
        #
        # If the model fails, the turn that caused it is still in the file. A
        # transcript that silently drops the questions that went wrong is the
        # transcript somebody is reading precisely because something went wrong.
        chats.append(user_id, conversation_id, "user", question)
    except memory.MemoryError_ as exc:
        return _failed(exc.code, exc.message,
                       413 if exc.code == "quota_exceeded" else 404)

    try:
        result = agent.answer(store, question, turns, _vocabulary)
    except agent.Refusal as exc:
        chats.append(user_id, conversation_id, "assistant",
                     f"[{exc.code}] {exc.message}")
        payload = {"ok": False, "code": exc.code, "detail": exc.message,
                   "conversation_id": conversation_id}
        return JSONResponse(payload, status_code=exc.status)
    except llm.LlmError as exc:
        chats.append(user_id, conversation_id, "assistant",
                     f"[{exc.code}] {exc.message}")
        payload = {"ok": False, "code": exc.code, "detail": exc.message,
                   "conversation_id": conversation_id}
        return JSONResponse(payload, status_code=502)

    note = ", ".join(result["sources"])
    chats.append(user_id, conversation_id, "assistant", result["answer"],
                 note=f"อ้างอิง: {note}" if note else "")

    return {"ok": True, "conversation_id": conversation_id, **result}


# --------------------------------------------------------------------------
# The owner's controls over their own history
# --------------------------------------------------------------------------

@app.get("/conversations", response_model=None,
         dependencies=[Depends(auth.require_key)])
def conversations(user_id: str) -> JSONResponse | dict:
    try:
        chats = _memory()
        return {"ok": True, "usage": chats.usage(user_id),
                "conversations": chats.listing(user_id)}
    except memory.MemoryError_ as exc:
        return _failed(exc.code, exc.message, 400)


@app.get("/conversations/{conversation_id}", response_model=None,
         dependencies=[Depends(auth.require_key)])
def conversation(conversation_id: str, user_id: str,
                 raw: bool = False) -> JSONResponse | dict:
    """The conversation, as turns or as the Markdown file itself.

    ``raw=true`` returns exactly what is on disk. That is the file the format
    promised, and being able to take it away is most of what owning it means.
    """
    try:
        chats = _memory()
        if raw:
            return {"ok": True, "conversation_id": conversation_id,
                    "markdown": chats.raw(user_id, conversation_id)}
        return {"ok": True, "conversation_id": conversation_id,
                "turns": chats.turns(user_id, conversation_id)}
    except memory.MemoryError_ as exc:
        return _failed(exc.code, exc.message, 404)


@app.patch("/conversations/{conversation_id}", response_model=None,
           dependencies=[Depends(auth.require_key)])
def rename(conversation_id: str, body: dict) -> JSONResponse | dict:
    user_id = (body.get("user_id") or "").strip()
    title = (body.get("title") or "").strip()
    if not title:
        return _failed("empty_title", "a title is required", 422)
    try:
        _memory().rename(user_id, conversation_id, title)
        return {"ok": True, "conversation_id": conversation_id, "title": title}
    except memory.MemoryError_ as exc:
        return _failed(exc.code, exc.message, 404)


@app.delete("/conversations/{conversation_id}", response_model=None,
            dependencies=[Depends(auth.require_key)])
def delete(conversation_id: str, user_id: str) -> JSONResponse | dict:
    try:
        _memory().delete(user_id, conversation_id)
        return {"ok": True, "deleted": conversation_id}
    except memory.MemoryError_ as exc:
        return _failed(exc.code, exc.message, 400)


@app.delete("/conversations", response_model=None,
            dependencies=[Depends(auth.require_key)])
def delete_all(user_id: str, confirm: str = "") -> JSONResponse | dict:
    """Everything this account owns.

    ``confirm`` must repeat the user id. Not ceremony: this endpoint is one
    stray request away from erasing somebody's entire history, and there is no
    undo behind it — the files are removed, not flagged.
    """
    if confirm != user_id:
        return _failed("not_confirmed",
                       "pass confirm=<user_id> to delete every conversation "
                       "this account owns; there is no undo", 428)
    try:
        removed = _memory().delete_all(user_id)
        return {"ok": True, "deleted": removed}
    except memory.MemoryError_ as exc:
        return _failed(exc.code, exc.message, 400)
