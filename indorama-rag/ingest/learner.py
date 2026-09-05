"""Build a learner index from an LMS knowledge export.

    python -m ingest.learner path/to/lms-agent-knowledge-company-1.json

This is a **second, separate index**, not an addition to the developer one, and
the export itself is why. Its usage_rules say:

    "Use learner_routes URLs for navigation answers; never show controller,
     API, database, or source-code route names to learners."

The developer index is nothing but controller, API and database names. Put the
two in one place and every learner question can retrieve the thing the export
forbids showing them — not as a bug to be tightened later, but as the ordinary
behaviour of a search that ranks by wording. Two indexes cannot make that
mistake.

What goes in:

* one chunk per course — titles and descriptions in every language the export
  carries, outcomes, skills, tags, and the learner-facing detail URL
* one chunk per learner route, which is the only kind of link that may be
  given out
* complete lists, so "how many courses are there" is answered from a number
  the export states rather than by counting what happened to rank
* the export's own rules, so the assistant can be told what it must not do
  from the same file that says it

What stays out is already out: the export excludes user profiles, enrolment
records, learning history, quiz answers, face data, secrets, and every admin
or internal route. Nothing here has to redact anything, because nothing
sensitive was shipped — which is checked rather than assumed, below.
"""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
from app import store as store_mod  # noqa: E402

# Fields the export promises never to include. Checked on the way in: if a
# future export starts carrying them, this build fails rather than quietly
# indexing somebody's enrolment history and serving it to whoever asks.
FORBIDDEN_KEYS = {
    "users", "user", "enrollments", "enrollment_records", "learning_history",
    "quiz_answers", "answers", "face_data", "secrets", "tokens",
    "admin_routes", "api_routes", "filesystem_paths",
}

LANGUAGE_NAMES = {"thai": "ไทย", "english": "อังกฤษ", "japanese": "ญี่ปุ่น"}


class ExportProblem(Exception):
    """The file is not the shape this builder was written for."""


def _text(value, *, prefer=("th", "thai")) -> str:
    """One string out of a field that may be a string or a language map."""
    if isinstance(value, str):
        return value.strip()
    if isinstance(value, dict):
        for key in prefer:
            if value.get(key):
                return str(value[key]).strip()
        return " / ".join(str(v).strip() for v in value.values() if v)
    return ""


def _all_languages(value) -> list[str]:
    """Every translation of a field, so a question in any of them matches.

    The export carries Thai, English and Japanese. Indexing only Thai would
    mean an English-speaking learner searching "safety" finds nothing, in a
    catalogue that has the English title right there.
    """
    if isinstance(value, dict):
        return [str(v).strip() for v in value.values() if v]
    return [str(value).strip()] if value else []


def _json_list(value) -> list[str]:
    """Outcomes and skills arrive as a JSON string inside the JSON."""
    if isinstance(value, list):
        return [str(v) for v in value]
    if isinstance(value, str) and value.strip().startswith("["):
        try:
            parsed = json.loads(value)
            return [str(v) for v in parsed] if isinstance(parsed, list) else []
        except ValueError:
            return []
    return [value] if value else []


def check_nothing_sensitive(document: dict) -> None:
    """Refuse an export that carries what this one promises to exclude.

    :raises ExportProblem: if a forbidden top-level section is present
    """
    present = FORBIDDEN_KEYS & set(document)
    if present:
        raise ExportProblem(
            f"this export contains {sorted(present)}, which the learner index "
            f"must never hold — check the exporter before rebuilding")


def course_chunk(course: dict) -> dict:
    """One course, as a learner would ask about it.

    Everything searchable goes in the body in prose rather than as a record
    dump: the reader of this chunk is a language model composing an answer for
    somebody choosing a course, and a table of internal fields gives it
    nothing to say.
    """
    titles, descriptions, shorts = [], [], []
    for row in course.get("translations", []):
        language = LANGUAGE_NAMES.get(row.get("language", ""), row.get("language", ""))
        if row.get("title"):
            titles.append(f"{row['title']} ({language})")
        if row.get("description"):
            descriptions.append(row["description"])
        if row.get("short_description"):
            shorts.append(row["short_description"])

    # The Thai title if there is one, because it is what a report or a chat
    # reply will call this course.
    display = next((r["title"] for r in course.get("translations", [])
                    if r.get("language") == "thai" and r.get("title")), None)
    display = display or (titles[0].split(" (")[0] if titles else
                          course.get("alias", str(course.get("id"))))

    lines = [f"หลักสูตร: {display}"]
    if len(titles) > 1:
        lines.append("ชื่อในภาษาอื่น: " + " | ".join(titles))

    duration = course.get("duration_minutes")
    if duration:
        hours, minutes = divmod(int(duration), 60)
        spoken = f"{hours} ชั่วโมง" + (f" {minutes} นาที" if minutes else "")
        lines.append(f"ใช้เวลาเรียน: {spoken} ({duration} นาที)")

    for text in shorts:
        lines.append(f"สรุปสั้น: {text}")
    for text in descriptions:
        lines.append(f"รายละเอียด: {text}")

    outcomes = _json_list(course.get("learning_outcomes"))
    if outcomes:
        lines.append("สิ่งที่จะได้เรียนรู้:")
        lines += [f"  - {o}" for o in outcomes]

    skills = _json_list(course.get("skills_summary")) or [
        _text(s.get("name", s)) for s in course.get("skills", []) if s]
    if skills:
        lines.append("ทักษะที่เกี่ยวข้อง: " + ", ".join(x for x in skills if x))

    tags = [_text(t.get("name", t)) if isinstance(t, dict) else str(t)
            for t in course.get("tags", [])]
    tags = [t for t in tags if t]
    if tags:
        lines.append("แท็ก: " + ", ".join(tags))

    enrolment = course.get("enrollment") or {}
    notes = []
    if enrolment.get("mandatory"):
        notes.append("เป็นหลักสูตรบังคับ")
    if enrolment.get("self_enrollment"):
        notes.append("ลงทะเบียนเองได้")
    if enrolment.get("manager_approval"):
        notes.append("ต้องได้รับอนุมัติจากหัวหน้างาน")
    if notes:
        lines.append("การลงทะเบียน: " + ", ".join(notes))

    resources = course.get("resources") or []
    if resources:
        kinds: dict[str, int] = {}
        for item in resources:
            kinds[item.get("type", "อื่นๆ")] = kinds.get(item.get("type", "อื่นๆ"), 0) + 1
        lines.append("สื่อประกอบ: " + ", ".join(
            f"{kind} {n} รายการ" for kind, n in sorted(kinds.items())))
        # Resource titles carry a lot of the searchable Thai — a learner may
        # remember the video rather than the course it sits in.
        for item in resources[:12]:
            if item.get("title"):
                lines.append(f"  - {item['title']}")

    # The only link a learner may be given, and the reason the route chunks
    # exist: every other URL in the source system is out of bounds.
    if course.get("detail_url"):
        lines.append(f"ลิงก์: {course['detail_url']}")

    return {
        "kind": "course",
        "ref": course.get("alias") or f"course_{course.get('id')}",
        "title": display,
        "text": "\n".join(lines),
    }


def route_chunk(route: dict) -> dict:
    """One page a learner may be sent to."""
    title = _text(route.get("title"))
    lines = [f"หน้า: {title}"]

    others = [t for t in _all_languages(route.get("title")) if t and t != title]
    if others:
        lines.append("ชื่อในภาษาอื่น: " + " | ".join(others))

    purposes = _all_languages(route.get("purpose"))
    if purposes:
        lines.append("ใช้ทำอะไร: " + " / ".join(purposes))
    if route.get("url"):
        lines.append(f"ลิงก์: {route['url']}")

    return {
        "kind": "route",
        "ref": route.get("id") or title,
        "title": f"หน้า {title}",
        "text": "\n".join(lines),
    }


def digest_chunks(document: dict, courses: list[dict],
                  routes: list[dict]) -> list[dict]:
    """Complete lists, each stating its own total.

    Here for the same reason as in the developer index: asked "how many
    courses are there", a model handed four course chunks will answer four.
    A list that states its total lets it answer from a number somebody counted
    rather than from however many chunks happened to rank.
    """
    stats = document.get("statistics") or {}
    chunks = []

    lines = [f"รายการครบถ้วน: หลักสูตรทั้งหมด",
             f"มี {stats.get('courses', len(courses))} หลักสูตรในระบบ นี่คือรายชื่อทั้งหมด",
             ""]
    for course in courses:
        display = next((r["title"] for r in course.get("translations", [])
                        if r.get("language") == "thai" and r.get("title")), None)
        display = display or course.get("alias", "")
        minutes = course.get("duration_minutes")
        lines.append(f"  - {display}" + (f" ({minutes} นาที)" if minutes else ""))
    chunks.append({"kind": "digest", "ref": "digest_courses",
                   "title": "รายการครบ: หลักสูตรทั้งหมด", "text": "\n".join(lines)})

    lines = ["รายการครบถ้วน: หน้าที่ผู้เรียนเข้าได้",
             f"มี {len(routes)} หน้า นี่คือรายการทั้งหมด", ""]
    for route in routes:
        lines.append(f"  - {_text(route.get('title'))}: {route.get('url', '')}")
    chunks.append({"kind": "digest", "ref": "digest_routes",
                   "title": "รายการครบ: หน้าที่ผู้เรียนเข้าได้",
                   "text": "\n".join(lines)})

    if stats:
        lines = ["รายการครบถ้วน: จำนวนสิ่งต่างๆ ในระบบ",
                 "ตัวเลขเหล่านี้มาจากไฟล์ export ณ เวลาที่ส่งออก ไม่ใช่ข้อมูลสด", ""]
        readable = {"courses": "หลักสูตร", "translations": "คำแปล",
                    "resources": "สื่อประกอบ", "quiz_questions": "ข้อสอบ",
                    "skills": "ทักษะ", "tags": "แท็ก"}
        for key, value in stats.items():
            lines.append(f"  - {readable.get(key, key)}: {value}")
        chunks.append({"kind": "digest", "ref": "digest_statistics",
                       "title": "รายการครบ: จำนวนสิ่งต่างๆ", "text": "\n".join(lines)})

    return chunks


def rules_chunk(document: dict) -> dict:
    """What the export says this assistant may and may not do.

    Indexed rather than only copied into a prompt, so that the rules travel
    with the data they govern. An index rebuilt from a stricter export brings
    its own stricter rules with it.
    """
    lines = ["กติกาการใช้ข้อมูลชุดนี้ (มาจากไฟล์ export เอง)", ""]
    lines += [f"  - {rule}" for rule in document.get("usage_rules", [])]
    excluded = document.get("excluded_data") or []
    if excluded:
        lines += ["", "ข้อมูลที่ไม่ได้อยู่ในชุดนี้เลย จึงตอบไม่ได้:"]
        lines += [f"  - {item}" for item in excluded]
    return {"kind": "rule", "ref": "usage_rules",
            "title": "กติกาการใช้ข้อมูลและสิ่งที่ตอบไม่ได้", "text": "\n".join(lines)}


def build(source_path: str, index_path: str, report_path: str) -> dict:
    with open(source_path, encoding="utf-8") as handle:
        document = json.load(handle)

    check_nothing_sensitive(document)
    if document.get("audience") != "learner":
        raise ExportProblem(
            f"audience is {document.get('audience')!r}, expected 'learner'. "
            f"This builder writes the learner index; a developer export goes "
            f"through ingest.build instead.")

    courses = document.get("courses") or []
    routes = document.get("learner_routes") or []

    chunks = [course_chunk(c) for c in courses]
    chunks += [route_chunk(r) for r in routes]
    chunks += digest_chunks(document, courses, routes)
    chunks.append(rules_chunk(document))

    for number, chunk in enumerate(chunks, start=1):
        chunk["chunk_id"] = f"{chunk['kind']}_{number:04d}"

    if os.path.exists(index_path):
        os.remove(index_path)
    store = store_mod.Store(index_path)
    # Trigram, not unicode61. See Store.create — Thai has no spaces, and with
    # the default tokenizer a word in the middle of a title cannot be found by
    # any query at all.
    store.create(tokenizer="trigram")
    store.add(chunks)

    kinds: dict[str, int] = {}
    for chunk in chunks:
        kinds[chunk["kind"]] = kinds.get(chunk["kind"], 0) + 1

    report = {
        "source_file": os.path.basename(source_path),
        "source_sha256": _digest(source_path),
        "schema_version": document.get("schema_version"),
        "document_type": document.get("document_type"),
        "audience": document.get("audience"),
        "tenant": document.get("tenant"),
        "export_generated_at": document.get("generated_at"),
        "tokenizer": "trigram",
        "chunks_total": len(chunks),
        "chunks_by_kind": kinds,
        "courses": len(courses),
        "learner_routes": len(routes),
        "statistics": document.get("statistics"),
        "excluded_data": document.get("excluded_data"),
        "characters_indexed": sum(len(c["text"]) for c in chunks),
    }
    for key, value in report.items():
        store.set_meta(key, json.dumps(value, ensure_ascii=False))
    store.close()

    with open(report_path, "w", encoding="utf-8") as handle:
        json.dump(report, handle, ensure_ascii=False, indent=2)
    return report


def _digest(path: str) -> str:
    digest = hashlib.sha256()
    with open(path, "rb") as handle:
        for block in iter(lambda: handle.read(1 << 20), b""):
            digest.update(block)
    return digest.hexdigest()


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("source", help="the learner knowledge JSON")
    parser.add_argument("--index", default="learner-index.sqlite")
    parser.add_argument("--report", default="learner-build-report.json")
    args = parser.parse_args()

    report = build(args.source, args.index, args.report)
    print(json.dumps(report, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
