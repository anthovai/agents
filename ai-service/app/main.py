"""The AI reviewer, as a service.

It drafts a summary of one monitored sitting for whoever has to review a
hundred of them, and proof-reads Thai questions imported from a PDF. It never
sees a face, a clip, a score or a name — see contract.py, which enforces that
rather than asking for it.

Deliberately stateless: nothing is written down here. The calling platform
already holds the record, and a second copy of learner activity on our
infrastructure would be a data-protection liability we have no use for.
"""
from __future__ import annotations

import json

from fastapi import Depends, FastAPI, Header, HTTPException
from fastapi.responses import JSONResponse

from . import config, contract, guard, llm, prompts

app = FastAPI(title="KAISER Proctor AI reviewer", version=config.SERVICE_VERSION)


def require_key(x_proctor_key: str = Header(default="")) -> None:
    if config.API_KEY and x_proctor_key != config.API_KEY:
        raise HTTPException(status_code=401, detail="bad key")


def _failed(code: str, message: str, status: int = 502) -> JSONResponse:
    return JSONResponse(status_code=status,
                        content={"ok": False, "error": {"code": code, "message": message}})


@app.get("/health")
def health() -> dict:
    ok, problem = llm.reachable()
    return {
        "ok": True,
        "service": "ai-reviewer",
        "version": config.SERVICE_VERSION,
        "contract": config.CONTRACT_VERSION,
        "models": {
            "summarise": config.MODEL_SUMMARISE,
            "ask": config.MODEL_ASK,
            "questions": config.MODEL_QUESTIONS,
        },
        "model": config.LLM_MODEL,
        # Which host is answering, so an operator can see at a glance whether
        # this deployment is sending anything off the premises.
        "backend": config.LLM_BASE_URL,
        "backend_reachable": ok,
        "backend_problem": problem,
    }


@app.get("/prompts")
def get_prompts() -> dict:
    """The instructions the model is given, verbatim.

    Exposed because they are part of what is being sold: a customer's auditor
    can read the guardrails without being given the source, and our tests can
    assert on them from outside the process.
    """
    return {"ok": True, "contract": config.CONTRACT_VERSION, "prompts": prompts.ALL}


@app.post("/summarise", dependencies=[Depends(require_key)], response_model=None)
def summarise(body: dict) -> JSONResponse | dict:
    if body.get("contract") not in config.SUPPORTED_CONTRACTS:
        return _failed("contract_mismatch",
                       f"this service speaks contract {config.CONTRACT_VERSION}", 400)

    try:
        facts = contract.sitting(body.get("sitting"))
    except contract.ContractError as error:
        return _failed("invalid_payload", str(error), 422)

    material = "บันทึกการเรียน 1 ครั้ง:\n" + json.dumps(
        facts, ensure_ascii=False, indent=2)

    try:
        content, model = llm.ask(prompts.SUMMARISE, material, config.MODEL_SUMMARISE)
        # Ask once more if it reached a verdict; small models do this often
        # enough that one retry is worth the wait, and rare enough that the
        # wait is usually not incurred.
        verdicts = guard.verdicts_in(content)
        if verdicts:
            content, model = llm.ask(prompts.SUMMARISE + guard.RETRY_NOTE, material,
                                     config.MODEL_SUMMARISE)
            verdicts = guard.verdicts_in(content)
    except llm.LlmError as error:
        return _failed(error.code, error.message)

    if verdicts:
        # Nothing improper is shown to a reviewer. An operator whose model
        # cannot manage this needs to know it is unfit for the job, and the
        # word it used is the fastest way to see why.
        return _failed("guardrail_violation",
                       "the model reached a verdict: " + ", ".join(verdicts), 502)

    return {
        "ok": True,
        "summary": content,
        "model": model,
        "contract": config.CONTRACT_VERSION,
        # Echoed back so a reviewer looking at a stored summary can tell what
        # it was actually written from, months later.
        "reviewed": facts,
    }


@app.post("/ask", dependencies=[Depends(require_key)], response_model=None)
def ask(body: dict) -> JSONResponse | dict:
    """Answer a learner's question about finding their way around the site.

    The retrieval happens on the calling platform, because it is the only side
    that knows what this learner is allowed to open. If it found nothing it
    must not call here at all — an assistant that answers from general
    knowledge when the site has no matching page is worse than one that says
    it does not know, and refusing here is the second line, not the first.
    """
    if body.get("contract") not in config.SUPPORTED_CONTRACTS:
        return _failed("contract_mismatch",
                       f"this service speaks contract {config.CONTRACT_VERSION}", 400)

    try:
        # The version marker is envelope, not payload — the other endpoints
        # never see it because theirs is nested one level down.
        asked = contract.ask({k: v for k, v in body.items() if k != "contract"})
    except contract.ContractError as error:
        return _failed("invalid_payload", str(error), 422)

    if not asked["context"]:
        return _failed("no_context",
                       "no pages were supplied, so there is nothing to answer from", 422)

    pages = "\n".join(
        f"{n + 1}. [{item['kind']}] {item['title']}\n   ลิงก์: {item['url']}"
        + (f"\n   {item['summary']}" if item["summary"] else "")
        for n, item in enumerate(asked["context"])
    )
    material = f"คำถามของผู้เรียน:\n{asked['question']}\n\nหน้าที่ผู้เรียนเปิดได้:\n{pages}"

    allowed = [item["url"] for item in asked["context"]]

    try:
        content, model = llm.ask(prompts.ASK, material, config.MODEL_ASK)
        if guard.invented_links(content, allowed):
            content, model = llm.ask(prompts.ASK + guard.LINK_NOTE, material, config.MODEL_ASK)
    except llm.LlmError as error:
        return _failed(error.code, error.message)

    invented = guard.invented_links(content, allowed)
    if invented:
        # A link that looks right and goes nowhere is the failure that costs
        # the feature its credibility, so the answer is dropped rather than
        # shown with a broken link in it.
        return _failed("invented_link",
                       "the model produced links that were not offered: "
                       + ", ".join(invented), 502)

    return {
        "ok": True,
        "answer": content,
        "model": model,
        "contract": config.CONTRACT_VERSION,
        # Which pages the answer was allowed to draw on, so the caller can show
        # them alongside and a learner is never relying on prose alone.
        "sources": asked["context"],
    }


@app.post("/check-questions", dependencies=[Depends(require_key)], response_model=None)
def check_questions(body: dict) -> JSONResponse | dict:
    if body.get("contract") not in config.SUPPORTED_CONTRACTS:
        return _failed("contract_mismatch",
                       f"this service speaks contract {config.CONTRACT_VERSION}", 400)

    try:
        items = contract.questions(body.get("questions"))
    except contract.ContractError as error:
        return _failed("invalid_payload", str(error), 422)

    try:
        content, model = llm.ask(
            prompts.CHECK_QUESTIONS,
            json.dumps(items, ensure_ascii=False, indent=2),
            config.MODEL_QUESTIONS)
    except llm.LlmError as error:
        return _failed(error.code, error.message)

    findings = [
        finding for finding in llm.extract_json_array(content)
        if isinstance(finding, dict) and finding.get("id")
    ]

    return {
        "ok": True,
        "findings": findings,
        "model": model,
        "contract": config.CONTRACT_VERSION,
    }
