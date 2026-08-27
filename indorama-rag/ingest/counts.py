"""How many of each thing there are — counted at build time, stored as numbers.

The assistant was asked to answer like "there are N of these in the system"
rather than only describing structure. That needs the row data, which the rest
of this package is built never to open.

The way through is that a count is not the rows. This module opens the data
sections, walks them, and emits **integers only** — never a name, never an
address, never an identifier. Nothing that could identify a person survives the
walk, because nothing but a number leaves the function. The archive itself
still never reaches the index: :mod:`ingest.chunk` cannot read these sections
at all, and this module cannot write anything except the digest below.

Two things are deliberately not counted.

**Users.** The obvious question — how many people use this system — has no
answer in this archive. ``tbl_users`` appears in the schema, with seven columns
the data dictionary marks sensitive, and **its rows were never exported**.
There is no file. A number could be assembled from the identifiers appearing in
other tables, and that number would answer a different question while looking
exactly like an answer to this one, which is the failure this whole codebase is
organised against.

**Anything from the logs.** ``tbl_lg`` and ``ci_sessions`` are where the 864
email addresses live. Counting them would be safe by the argument above, and
they are excluded anyway: a log row count answers nothing anybody asks, and the
narrower the door the easier it is to be sure about what goes through it.
"""

import json

# Sections this module may walk. Narrower than a permission — a section listed
# here can be counted and still cannot be quoted, because the only values that
# leave this file are integers.
COUNTABLE = ("learning", "master", "assessments", "activities")

# key -> (label in Thai, path, what one row is)
#
# Keyed, because the key is what the assistant asks for. Handing a model
# fourteen figures in one list and asking it to pick produced two wrong answers
# on the first run: "how many enrolments" came back 16,431, which is the course
# count, and "how many departments" came back 192, which is the number of
# database tables. Both figures were real and both came from a tool, so every
# guard passed — the number answered a different question in the shape of an
# answer to the one asked.
#
# One question, one number. See app/tools.py get_count.
_SIMPLE = {
    "courses": ("รายวิชาในระบบเดิม (legacy)", "learning/legacy_courses_0001.jsonl", "คอร์ส"),
    "content": ("เนื้อหา/หลักสูตร", "learning/tbl_content_0001.jsonl", "รายการ"),
    "channels": ("ช่องทางการเรียน (channel)", "learning/tbl_channel_0001.jsonl", "ช่อง"),
    "resources": ("แหล่งข้อมูลประกอบ", "learning/tbl_resources_0001.jsonl", "รายการ"),
    "certificates": ("ใบรับรองที่ตั้งค่าไว้", "learning/tbl_certificate_0001.jsonl", "แบบ"),
    "departments": ("หน่วยงาน", "master/tbl_department_0001.jsonl", "หน่วยงาน"),
    "positions": ("ตำแหน่งงาน", "master/tbl_position_0001.jsonl", "ตำแหน่ง"),
    "skills": ("ทักษะ", "master/tbl_skill_0001.jsonl", "ทักษะ"),
    "companies": ("บริษัทในระบบ", "master/tbl_company_0001.jsonl", "บริษัท"),
    "quizzes": ("แบบทดสอบ", "assessments/tbl_quiz_0001.jsonl", "ชุด"),
    "surveys": ("แบบสำรวจ", "assessments/tbl_survey_0001.jsonl", "ชุด"),
    "blogs": ("บทความ/บล็อก", "activities/tbl_blog_0001.jsonl", "เรื่อง"),
}

# Enrolments are split across six files of a hundred megabytes each, so they
# are counted separately and streamed.
_ENROL_PREFIX = "learning/tbl_contentEnroll"


def _rows(archive, name: str) -> int:
    try:
        with archive.open(name) as fh:
            return sum(1 for _ in fh)
    except KeyError:
        return -1


def _enrolments(archive) -> tuple[int, int]:
    """(enrolment rows, distinct learner identifiers seen).

    The second number is held as a set of identifiers while the walk runs and
    then thrown away; only its length is returned. That is the whole privacy
    argument for this function, and it is why the set never leaves the frame.

    It is also **not a user count**, and is labelled accordingly wherever it is
    shown. A learner with no enrolment is invisible here, and an identifier
    that no longer belongs to anybody is still counted. Calling this "จำนวน
    ผู้ใช้" would be a number that answers a different question in the shape of
    an answer to the one asked.
    """
    # Matched case-insensitively. The table is ``tbl_contentEnroll`` in the
    # schema and ``tbl_contentenroll`` in the filename, and a prefix written
    # from the schema matched nothing — so this returned zero enrolments, the
    # caller's ``if rows:`` skipped the line, and the digest was published
    # without it. No error, no empty section, just two figures that were never
    # there. Loud below rather than absent.
    parts = sorted(n for n in archive.namelist()
                   if n.lower().startswith(_ENROL_PREFIX.lower())
                   and n.endswith(".jsonl"))
    if not parts:
        raise LookupError(
            f"no enrolment files matching {_ENROL_PREFIX!r} — either the export "
            f"changed shape or this prefix is wrong. Refusing to publish counts "
            f"with a figure silently missing.")
    learners: set = set()
    rows = 0
    for part in parts:
        with archive.open(part) as fh:
            for line in fh:
                rows += 1
                try:
                    learners.add(json.loads(line)["data"].get("learner_id"))
                except (ValueError, KeyError, TypeError):
                    continue
    learners.discard(None)
    return rows, len(learners)


def build(archive, san) -> tuple[list[dict], dict]:
    """(the digest chunk, the counts keyed for one-at-a-time lookup)."""
    counted = {}
    missing = []
    for key, (label, path, unit) in _SIMPLE.items():
        n = _rows(archive, path)
        if n >= 0:
            counted[key] = {"label": label, "value": n, "unit": unit}
        else:
            missing.append(path)
    if missing:
        # Same reasoning as the enrolment prefix below: a count that quietly
        # fails to appear is indistinguishable from a count nobody asked for.
        raise LookupError(
            "these files are named in _SIMPLE but are not in the export: "
            + ", ".join(missing))

    rows, learners = _enrolments(archive)
    counted["enrolments"] = {"label": "การลงทะเบียนเรียน", "value": rows,
                             "unit": "รายการ"}
    counted["learner_ids"] = {
        "label": "รหัสผู้เรียนที่ไม่ซ้ำกันในรายการลงทะเบียน", "value": learners,
        "unit": "รหัส",
        "caveat": "ไม่ใช่จำนวนผู้ใช้ นับเฉพาะคนที่มีการลงทะเบียนอย่างน้อยหนึ่งรายการ"}

    lines = [
        "รายการครบถ้วน: จำนวนของสิ่งต่างๆ ในระบบ ณ วันที่ export",
        "",
        "ตัวเลขทั้งหมดนับจากข้อมูลจริงในไฟล์ export **ไม่ใช่ข้อมูลสด** "
        "และเป็นภาพ ณ วันที่ export เท่านั้น",
        "",
    ]
    lines += [f"  - {c['label']}: {c['value']:,} {c['unit']}"
              for c in counted.values()]

    lines += [
        "",
        "**สิ่งที่ตัวเลขข้างบนไม่ได้บอก**",
        "",
        "จำนวนผู้ใช้ในระบบ — ตอบไม่ได้ ตาราง tbl_users ไม่ได้ถูก export มา "
        "มีแต่โครงสร้างของมัน ไม่มีข้อมูลแถวแม้แต่แถวเดียว",
        "",
        "\"รหัสผู้เรียนที่ไม่ซ้ำกัน\" ข้างบน **ไม่ใช่จำนวนผู้ใช้** "
        "มันนับเฉพาะคนที่มีรายการลงทะเบียนอย่างน้อยหนึ่งรายการ "
        "คนที่ยังไม่เคยลงทะเบียนจะไม่ถูกนับ และรหัสที่ไม่มีเจ้าของแล้วก็ยังถูกนับอยู่ "
        "ห้ามนำตัวเลขนี้ไปตอบคำถามว่ามีผู้ใช้กี่คน",
        "",
        "จำนวนผู้ใช้ที่ \"กำลังใช้งานอยู่\" — ตอบไม่ได้ ไม่มีทั้งข้อมูลผู้ใช้และข้อมูลสด",
        "",
        "keywords: how many count total users learners courses enrolments "
        "statistics numbers",
    ]

    # Its own id, assigned here. chunk.build_all numbers the chunks it makes,
    # and this one is added afterwards because it needs the raw archive rather
    # than the guarded one — so it has to carry an id of its own or the insert
    # fails on a missing key.
    chunk = {
        "chunk_id": "digest_counts",
        "kind": "digest",
        "ref": "digest_counts",
        "title": "รายการครบ: จำนวนของสิ่งต่างๆ ในระบบ",
        "text": san.scrub("\n".join(lines)),
    }
    return [chunk], counted
