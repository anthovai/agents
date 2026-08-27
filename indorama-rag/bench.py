"""Measure the assistant against a fixed question set, one model at a time.

    python bench.py --models qwen2.5:7b-instruct,qwen3:8b --out reports/MODEL-COMPARISON.md

Written because the alternative is picking a model by reading its benchmark
scores, and the thing that decides whether this assistant is usable is not on
any of them. Two numbers matter here:

**Grounded answers.** Did the model say the true thing — the total from the
digest, the column that exists — rather than something adjacent to it.

**Guardrail bounces.** How often the answer was dropped by app.guard. A bounce
is the correct outcome for a wrong answer, and it is still a failure the user
experiences: they asked a question and got a refusal. A model that bounces
often is not a safe model, it is an unusable one behind a safe service.

The expectations are deliberately shallow — a substring that must appear, and
the codes that count as success. A grader that scored prose would need a model
to run it, and a measurement that depends on a model is the thing this is
supposed to replace.
"""

import argparse
import json
import os
import re
import time

CASES = [
    # (question, must appear in a successful answer, note)
    ("มีตารางทั้งหมดกี่ตาราง", "192", "total, straight from a digest"),
    ("ตารางไหนบ้างที่มีข้อมูลอ่อนไหว", "26", "total over a 26-row list"),
    ("which tables hold personal data", "26", "same question in English"),
    ("route ทั้งหมดมีกี่ route", "427", "total over a 98-group list"),
    ("tbl_company มีคอลัมน์อะไรบ้าง", "default_user_password",
     "columns of one table, where the model invented com_ prefixes"),
    ("ตาราง tbl_certificate เก็บอะไร", "cert_fullname", "one table, prose answer"),
    ("Authorization_Token.php มีเมธอดอะไรบ้าง", "generateAccessToken",
     "methods of one file"),
    ("ci_sessions มีคอลัมน์อะไร", "ip_address", "a table whose rows are not indexed"),
    ("ความสัมพันธ์ระหว่างตารางมีอะไรบ้าง", "28", "the foreign-key count"),
    ("route ของ Channels มีอะไรบ้าง", "channels", "a route group by name"),
]

OFF_TOPIC = [
    "วันนี้อากาศเป็นยังไง",
    "ช่วยเขียนกลอนเกี่ยวกับทะเลให้หน่อย",
    "How do I cook pasta?",
    "ใครเป็นนายกรัฐมนตรี",
]

# Reasoning models emit their working before the answer. Stripped before
# grading, not before the guards — a table name invented inside <think> and
# then dropped is not something the reader ever sees.
_THINK = re.compile(r"<think>.*?</think>", re.DOTALL | re.IGNORECASE)


def run_case(client, question: str) -> dict:
    started = time.monotonic()
    response = client.post("/ask", json={"question": question})
    seconds = time.monotonic() - started
    body = response.json()
    return {
        "question": question,
        "ok": bool(body.get("ok")),
        "code": body.get("code", "ok"),
        "answer": _THINK.sub("", body.get("answer") or "").strip(),
        "detail": body.get("detail", ""),
        "sources": [s["ref"] for s in body.get("sources", [])],
        "seconds": round(seconds, 1),
    }


def measure(model: str, index_path: str) -> dict:
    os.environ["RAG_LLM_MODEL"] = model
    os.environ["RAG_INDEX_PATH"] = index_path

    # The whole package goes, not just its modules.
    #
    # app.config reads the environment at import time, so it has to be
    # re-imported for each model. Dropping only "app.config" from sys.modules
    # is not enough and fails silently in the worst way: importing a submodule
    # also binds it as an attribute of the parent package, so ``from . import
    # config`` inside app.main finds the stale attribute on the surviving
    # ``app`` package and never re-imports anything. Every model after the
    # first would have been measured against the first one's model name, and
    # the report would have looked entirely reasonable.
    #
    # The assertion below is what caught it. It stays.
    import sys
    for name in [n for n in list(sys.modules) if n == "app" or n.startswith("app.")]:
        del sys.modules[name]
    from fastapi.testclient import TestClient
    import app.main as main_mod
    client = TestClient(main_mod.app)
    assert main_mod.config.LLM_MODEL == model, "the model did not take effect"

    results = [run_case(client, q) for q, _, _ in CASES]
    for result, (_, expected, note) in zip(results, CASES):
        result["expected"] = expected
        result["note"] = note
        result["grounded"] = result["ok"] and expected.lower() in result["answer"].lower()

    refusals = [run_case(client, q) for q in OFF_TOPIC]

    return {
        "model": model,
        "grounded": sum(1 for r in results if r["grounded"]),
        "answered": sum(1 for r in results if r["ok"]),
        "bounced": sum(1 for r in results if r["code"] == "ungrounded_answer"),
        "errored": sum(1 for r in results
                       if not r["ok"] and r["code"] != "ungrounded_answer"),
        "total": len(results),
        "median_seconds": sorted(r["seconds"] for r in results)[len(results) // 2],
        "off_topic_refused": sum(1 for r in refusals if r["code"] == "off_topic"),
        "off_topic_total": len(refusals),
        "cases": results,
    }


def combine(runs: list[dict]) -> dict:
    """Fold repeats of one model into a single row with its spread.

    Added after a prompt change appeared to move the score by one and turned
    out to move different questions in both directions. Two runs of the same
    model on the same prompt had already scored 8/10 and 9/10, so a one-point
    difference between configurations was never evidence of anything — and a
    benchmark that cannot separate a change from its own noise is a benchmark
    that will be used to justify the wrong decision.

    The spread is reported rather than averaged away. A model that scores 7 to
    9 is a different proposition from one that scores 8 every time, even where
    the means agree.
    """
    scores = sorted(r["grounded"] for r in runs)
    first = runs[0]
    return {
        "model": first["model"],
        "runs": len(runs),
        "grounded_low": scores[0],
        "grounded_high": scores[-1],
        "grounded_median": scores[len(scores) // 2],
        "total": first["total"],
        "bounced": sum(r["bounced"] for r in runs),
        "errored": sum(r["errored"] for r in runs),
        "median_seconds": sorted(r["median_seconds"] for r in runs)[len(runs) // 2],
        "off_topic_refused": first["off_topic_refused"],
        "off_topic_total": first["off_topic_total"],
        # Which questions were not answered the same way every time. These are
        # the ones a single run would have reported as a settled fact.
        "unstable": [case["question"] for n, case in enumerate(first["cases"])
                     if len({r["cases"][n]["grounded"] for r in runs}) > 1],
        "cases": first["cases"],
    }


def report(runs: list[dict], stamp: str) -> str:
    lines = [
        "# เปรียบเทียบโมเดลสำหรับผู้ช่วยระบบ Indorama",
        "",
        f"รันเมื่อ **{stamp}** · คำถามในเรื่อง {len(CASES)} ข้อ · "
        f"คำถามนอกเรื่อง {len(OFF_TOPIC)} ข้อ",
        "",
        "สร้างโดย `python bench.py` — รันซ้ำได้ ตัวเลขในไฟล์นี้ไม่ใช่ตัวเลขที่พิมพ์มือ",
        "",
        "## สรุป",
        "",
        "| โมเดล | ตอบถูก (ต่ำ–สูง) | รอบ | โดน guard ทิ้ง | ผิดพลาดอื่น | มัธยฐาน (วิ) | กันนอกเรื่อง |",
        "|---|---|---|---|---|---|---|",
    ]
    for run in runs:
        if run["grounded_low"] == run["grounded_high"]:
            score = f"**{run['grounded_median']}/{run['total']}**"
        else:
            score = (f"**{run['grounded_low']}–{run['grounded_high']}"
                     f"/{run['total']}**")
        lines.append(
            f"| `{run['model']}` | {score} | {run['runs']} | {run['bounced']} | "
            f"{run['errored']} | {run['median_seconds']} | "
            f"{run['off_topic_refused']}/{run['off_topic_total']} |")

    lines += [
        "",
        "**ตอบถูก** = ตอบสำเร็จ *และ* มีข้อเท็จจริงที่ตรวจได้อยู่ในคำตอบ (ยอดรวมจาก digest,",
        "ชื่อคอลัมน์ที่มีจริง) — ไม่ใช่แค่ตอบออกมาได้",
        "",
        "**โดน guard ทิ้ง** = คำตอบถูก `app.guard` ปฏิเสธเพราะมีชื่อหรือตัวเลขที่ไม่ได้อยู่ใน",
        "material นี่คือผลลัพธ์*ที่ถูกต้อง*สำหรับคำตอบที่ผิด และยังเป็นความล้มเหลวที่ผู้ใช้เจอ —",
        "เขาถามแล้วได้ข้อความปฏิเสธ โมเดลที่โดนทิ้งบ่อยไม่ใช่โมเดลที่ปลอดภัย แต่คือโมเดลที่",
        "ใช้ไม่ได้ซึ่งถูกกันไว้ด้วยบริการที่ปลอดภัย",
        "",
        "**กันนอกเรื่อง** ตัดสินใน `app.scope` ก่อนเรียกโมเดล จึงเท่ากันทุกโมเดลโดยการออกแบบ",
        "ถ้าคอลัมน์นี้ไม่เต็มแปลว่ามีบั๊ก ไม่ใช่แปลว่าโมเดลแย่",
        "",
        "**ช่วง ต่ำ–สูง คือสิ่งที่ต้องอ่านก่อนอย่างอื่น** โมเดลเดียวกัน prompt เดียวกัน ให้คะแนน",
        "ต่างกันได้ระหว่างรอบ ความต่างหนึ่งคะแนนระหว่างสองการตั้งค่าจึงไม่ใช่หลักฐานของอะไรเลย",
        "ถ้ารันด้วย `--repeat 1` ช่วงจะยุบเป็นตัวเลขเดียวที่ดูหนักแน่นกว่าความจริง",
        "",
        "## รายข้อ",
        "",
    ]
    for run in runs:
        lines += [f"### `{run['model']}`", ""]
        lines += ["| คำถาม | ต้องมี | ผล | วิ |", "|---|---|---|---|"]
        for case in run["cases"]:
            if case["grounded"]:
                verdict = "ถูก"
            elif case["ok"]:
                verdict = "ตอบได้ แต่ไม่มีข้อเท็จจริงที่ตรวจ"
            else:
                verdict = f"`{case['code']}`"
            lines.append(f"| {case['question']} | `{case['expected']}` | "
                         f"{verdict} | {case['seconds']} |")
        lines.append("")
    return "\n".join(lines)


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--models", required=True,
                        help="comma-separated model names to compare")
    parser.add_argument("--index", default="index.sqlite")
    parser.add_argument("--out", default="reports/MODEL-COMPARISON.md")
    parser.add_argument("--repeat", type=int, default=1,
                        help="runs per model. One run cannot tell a real "
                             "difference from this harness's own noise — see "
                             "combine().")
    parser.add_argument("--stamp", default="",
                        help="timestamp for the report header; the harness does "
                             "not read the clock so a rerun is comparable")
    args = parser.parse_args()

    runs = []
    for model in [m.strip() for m in args.models.split(",") if m.strip()]:
        print(f"--- {model}", flush=True)
        repeats = []
        for n in range(args.repeat):
            run = measure(model, args.index)
            repeats.append(run)
            print(f"    run {n + 1}: grounded {run['grounded']}/{run['total']}, "
                  f"bounced {run['bounced']}, median {run['median_seconds']}s",
                  flush=True)
        runs.append(combine(repeats))

    os.makedirs(os.path.dirname(args.out) or ".", exist_ok=True)
    with open(args.out, "w", encoding="utf-8") as fh:
        fh.write(report(runs, args.stamp or "(ไม่ได้ระบุเวลา)"))
    with open(args.out.rsplit(".", 1)[0] + ".json", "w", encoding="utf-8") as fh:
        json.dump(runs, fh, ensure_ascii=False, indent=2)
    print(f"\nwrote {args.out}")


if __name__ == "__main__":
    main()
