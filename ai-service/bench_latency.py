"""How long does an answer actually take?

The timeout was 120 seconds because that seemed generous. It is not a number
anybody measured, and a reasoning-heavy question against qwen3:8b on a laptop
GPU hit it during testing — so the learner got a failure for a question the
model would have answered.

Three timeouts sit in a line and their order matters more than any one value:

    ai-service AI_TIMEOUT  <  Moodle ai_client::TIMEOUT  <  the browser's wait

Set them the other way round and the useful error — "the model did not answer
in time", raised where the model is — is replaced by a curl timeout in Moodle
that says nothing about why.

Cold and warm are measured separately. Ollama unloads a model after a few
minutes idle, so the first question of the morning pays for the load, and that
is a real learner's first question rather than an artefact of benchmarking.

Run:  AI_LLM_BASE_URL=http://127.0.0.1:11434/v1 python bench_latency.py
"""
from __future__ import annotations

import os
import statistics
import time
from pathlib import Path

os.environ.setdefault("AI_LLM_BASE_URL", "http://127.0.0.1:11434/v1")
# Generous on purpose: this run is measuring the timeout, so it must not be
# bounded by one.
os.environ.setdefault("AI_TIMEOUT", "600")

PAGES = [
    {"title": "ลงทะเบียนใบหน้า", "url": "/local/kaiproctor/enrol.php",
     "kind": "tool", "summary": "ลงทะเบียนใบหน้าก่อนเข้าบทเรียนหรือสอบที่มีการเฝ้าดู"},
    {"title": "หลักสูตรทดสอบระบบคุมสอบ", "url": "/course/view.php?id=2",
     "kind": "course", "summary": ""},
    {"title": "ข้อสอบทดสอบระบบคุมสอบ", "url": "/mod/quiz/view.php?id=8",
     "kind": "quiz", "summary": "หลักสูตรทดสอบระบบคุมสอบ",
     "facts": {"grade": 8, "gradeoutof": 10, "gradepercent": 80,
               "passmark": 6, "passed": True, "attemptsused": 1}},
    {"title": "ข้อสอบความเสี่ยงสูง (SEB)", "url": "/mod/quiz/view.php?id=10",
     "kind": "quiz", "summary": "หลักสูตรทดสอบระบบคุมสอบ",
     "facts": {"notattempted": True, "attemptsused": 0}},
    {"title": "บทเรียนวิดีโอแบบมีปฏิสัมพันธ์",
     "url": "/mod/interactivevideo/view.php?id=11",
     "kind": "video", "summary": "หลักสูตรทดสอบระบบคุมสอบ"},
]

QUESTIONS = [
    "บทเรียนวิดีโออยู่ตรงไหน",
    "จะไปหน้าลงทะเบียนใบหน้าได้ยังไง",
    "ข้อสอบทดสอบระบบคุมสอบได้กี่คะแนน",
    "ฉันสอบผ่านไหม",
    # The shape that hit the old limit: it invites reasoning, and the guard
    # then makes it ask twice.
    "ข้อสอบทดสอบระบบคุมสอบ ผมขาดอีกกี่คะแนนถึงจะได้เต็ม",
    "ข้อสอบความเสี่ยงสูงได้กี่คะแนน",
]


def time_one(client, config, question: str) -> tuple[float, str]:
    started = time.monotonic()
    response = client.post("/ask", json={
        "contract": config.CONTRACT_VERSION,
        "question": question,
        "context": PAGES,
    })
    elapsed = time.monotonic() - started
    body = response.json()
    return elapsed, "ok" if body.get("ok") else body["error"]["code"]


if __name__ == "__main__":
    from fastapi.testclient import TestClient
    from app import config
    from app.main import app

    client = TestClient(app)
    lines = [f"model: {config.MODEL_ASK}   backend: {config.LLM_BASE_URL}", ""]

    # Cold: whatever state Ollama is in right now, which after an idle spell is
    # an unloaded model. Only the first question can measure this.
    cold, cold_outcome = time_one(client, config, QUESTIONS[0])
    lines.append(f"cold start (model not resident): {cold:6.1f}s  [{cold_outcome}]")
    lines.append("")

    timings = []
    for question in QUESTIONS:
        elapsed, outcome = time_one(client, config, question)
        timings.append(elapsed)
        lines.append(f"{elapsed:6.1f}s  [{outcome:20s}] {question}")

    timings.sort()
    lines += [
        "",
        f"warm: median {statistics.median(timings):.1f}s   "
        f"slowest {timings[-1]:.1f}s   n={len(timings)}",
        "",
        "The timeout has to clear the slowest, not the median: the question that",
        "takes longest is the one a learner is most likely to be waiting on, and",
        "cutting it off returns a failure for an answer that was coming.",
    ]

    report = "\n".join(lines)
    Path("../reports/ai-latency.txt").write_text(report, encoding="utf-8")
    print(report)
