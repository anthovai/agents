"""How often does the assistant answer a question it was given the answer to?

Retrieval is not the variable here: every case below hands the service the
right page along with plausible distractors, so a wrong answer is the model's.
Two failures are worth telling apart, because they cost different things:

  invented — a link that was never offered. The learner clicks it and lands
             nowhere. The service refuses these outright, so this counts how
             often the feature goes silent, not how often a bad link ships.

  refused  — "I cannot find it" when the page was right there. Cheap once,
             corrosive repeatedly: the learner stops asking.

Run:  AI_LLM_BASE_URL=http://127.0.0.1:11434/v1 python bench_ask.py
"""
from __future__ import annotations

import importlib
import json
import os
import sys
from pathlib import Path

os.environ.setdefault("AI_LLM_BASE_URL", "http://127.0.0.1:11434/v1")

PAGES = [
    {"title": "ลงทะเบียนใบหน้า", "url": "/local/kaiproctor/enrol.php",
     "kind": "tool", "summary": "ลงทะเบียนใบหน้าก่อนเข้าบทเรียนหรือสอบที่มีการเฝ้าดู"},
    {"title": "บทเรียนที่มีการเฝ้าดู", "url": "/local/kaiproctor/lesson.php",
     "kind": "tool", "summary": "หน้าบทเรียนที่ตรวจว่าคุณอยู่หน้าจอระหว่างเรียน"},
    {"title": "หลักสูตรทดสอบระบบคุมสอบ", "url": "/course/view.php?id=2",
     "kind": "course", "summary": ""},
    {"title": "ข้อสอบทดสอบระบบคุมสอบ", "url": "/mod/quiz/view.php?id=8",
     "kind": "quiz", "summary": "หลักสูตรทดสอบระบบคุมสอบ"},
    {"title": "ข้อสอบความเสี่ยงสูง (SEB)", "url": "/mod/quiz/view.php?id=10",
     "kind": "quiz", "summary": "หลักสูตรทดสอบระบบคุมสอบ"},
    {"title": "บทเรียนวิดีโอแบบมีปฏิสัมพันธ์", "url": "/mod/kaivideo/view.php?id=15",
     "kind": "video", "summary": "หลักสูตรทดสอบระบบคุมสอบ"},
    {"title": "ข้อสอบสุ่มตามระดับความยาก", "url": "/mod/quiz/view.php?id=13",
     "kind": "quiz", "summary": "หลักสูตรทดสอบระบบคุมสอบ"},
]

CASES = [
    ("จะไปหน้าลงทะเบียนใบหน้าได้ยังไง", "/local/kaiproctor/enrol.php"),
    ("บทเรียนวิดีโออยู่ตรงไหน", "/mod/kaivideo/view.php?id=15"),
    ("อยากทำข้อสอบความเสี่ยงสูง", "/mod/quiz/view.php?id=10"),
    ("ขอลิงก์หน้าคอร์สหน่อย", "/course/view.php?id=2"),
    ("ข้อสอบที่สุ่มตามความยากอยู่ไหน", "/mod/quiz/view.php?id=13"),
]

RUNS = int(os.environ.get("BENCH_RUNS", 3))


def run(model: str) -> dict:
    os.environ["AI_LLM_MODEL"] = model
    from app import config, guard, llm, main
    for module in (config, guard, llm, main):
        importlib.reload(module)
    from fastapi.testclient import TestClient
    client = TestClient(main.app)

    tally = {"model": model, "correct": 0, "wrong_page": 0,
             "refused": 0, "invented": 0, "total": 0, "misses": []}

    for question, expected in CASES:
        for _ in range(RUNS):
            tally["total"] += 1
            response = client.post("/ask", json={
                "contract": config.CONTRACT_VERSION,
                "question": question,
                "context": PAGES,
            })
            body = response.json()

            if not body.get("ok"):
                code = body["error"]["code"]
                tally["invented" if code == "invented_link" else "refused"] += 1
                tally["misses"].append(f"[{code}] {question}")
                continue

            answer = body["answer"]
            if expected in answer:
                tally["correct"] += 1
            elif any(page["url"] in answer for page in PAGES):
                tally["wrong_page"] += 1
                tally["misses"].append(f"[wrong page] {question} -> {answer[:90]}")
            else:
                # No link at all: the model wrote "I cannot find it" about a
                # page it was handed.
                tally["refused"] += 1
                tally["misses"].append(f"[said no] {question} -> {answer[:90]}")

    return tally


if __name__ == "__main__":
    models = sys.argv[1:] or [os.environ.get("AI_LLM_MODEL", "qwen2.5:7b-instruct")]
    results = [run(model) for model in models]

    lines = [f"{len(CASES)} questions x {RUNS} runs, retrieval held fixed", ""]
    for tally in results:
        lines.append(
            f"{tally['model']:24s} correct {tally['correct']:2d}/{tally['total']:2d}  "
            f"wrong-page {tally['wrong_page']:2d}  said-no {tally['refused']:2d}  "
            f"invented {tally['invented']:2d}")
    lines.append("")
    for tally in results:
        if tally["misses"]:
            lines.append(f"--- {tally['model']} ---")
            lines.extend("  " + miss for miss in tally["misses"])

    report = "\n".join(lines)
    Path("../reports/ai-ask-bench.txt").write_text(report, encoding="utf-8")
    print(json.dumps([{k: v for k, v in t.items() if k != "misses"}
                      for t in results], indent=2))
