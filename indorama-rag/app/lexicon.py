"""The Thai vocabulary this assistant understands, stated explicitly.

Written after counting what Thai actually exists in the corpus: 28 distinct
runs, every one of them a label this codebase wrote. The export contains no
Thai at all — it is schema, routes and PHP identifiers.

That changes what Thai matching can honestly be. Indexing all of it as
character trigrams and searching them, which is what the first version did,
means matching a question against our own scaffolding. It worked by accident
for "ข้อมูลอ่อนไหว", because a label happens to name that concept, and it
failed the way accidents do: "วันนี้อากาศเป็นยังไง" retrieved three tables,
because Thai function words produce trigrams that collide with the function
words in the labels. Five of six off-topic questions got material, and material
is all it takes — the model answers whatever it is handed.

So the Thai side is a controlled vocabulary instead. Each term names something
this corpus actually contains, and a question with no term in it is out of
scope by definition rather than by score. The list is short because the domain
is small, and it is meant to be read and argued with — a term missing from here
is a question that will be refused, which is a visible failure somebody can
report, not a silent one that returns a confident answer about nothing.

``anchors`` are Latin strings that appear in the chunks themselves, so a Thai
term reaches its material through the same lexical index everything else uses.
``kinds`` narrows which chunk kinds the term is about; empty means all of them.
"""

# term -> (anchors, kinds)
TERMS: dict[str, tuple[tuple[str, ...], tuple[str, ...]]] = {
    # ---- structure ----
    "ตาราง": ((), ("table",)),
    "คอลัมน์": ((), ("table",)),
    "ฟิลด์": ((), ("table",)),
    "สคีมา": ((), ("table",)),
    "โครงสร้าง": ((), ("table",)),
    "ชนิดข้อมูล": ((), ("table",)),
    "ค่าตั้งต้น": (("DEFAULT",), ("table",)),
    "ค่าเริ่มต้น": (("DEFAULT",), ("table",)),

    # ---- keys and joins ----
    "คีย์": (("key",), ("table", "rule")),
    "กุญแจ": (("key",), ("table", "rule")),
    "ดัชนี": (("Index", "UNIQUE"), ("table",)),
    "ความสัมพันธ์": (("foreign_keys",), ("rule", "table")),
    "เชื่อม": (("foreign_keys",), ("rule", "table")),
    "ผูก": (("foreign_keys",), ("rule", "table")),
    "อ้างอิง": (("foreign_keys",), ("rule", "table")),

    # ---- classification. The reason a migration asks anything at all. ----
    "อ่อนไหว": (("sensitive",), ("table",)),
    "ส่วนบุคคล": (("sensitive",), ("table",)),
    "ความเป็นส่วนตัว": (("sensitive",), ("table",)),
    "รหัสผ่าน": (("password",), ("table",)),
    "อีเมล": (("mail", "email"), ("table",)),

    # ---- routing ----
    "เส้นทาง": ((), ("endpoint",)),
    "เราต์": ((), ("endpoint",)),
    "เมนู": ((), ("endpoint",)),
    "หน้าเว็บ": ((), ("endpoint",)),

    # ---- source ----
    "ไฟล์": ((), ("source",)),
    "คลาส": ((), ("source",)),
    "เมธอด": ((), ("source",)),
    "ฟังก์ชัน": ((), ("source",)),
    "คอนโทรลเลอร์": ((), ("source",)),
    "โมเดล": ((), ("source",)),
    "ไลบรารี": ((), ("source",)),
    "โค้ด": ((), ("source",)),

    # ---- real quantities, answered from ingest/counts.py ----
    #
    # Added after "มีการลงทะเบียนเรียนทั้งหมดกี่รายการ" was refused as
    # off-topic: the count existed, the digest holding it existed, and no word
    # in the question was in this file. Exactly the failure this list is
    # documented as having, arriving on schedule.
    # The words a learner reaches for, not the words a schema uses. Added
    # after "บทเรียนทั้งหมด" was refused as off-topic: the count existed, the
    # digest holding it existed, and nobody had written down that a lesson is
    # what this corpus calls content.
    "บทเรียน": (("digest_counts",), ()),
    "วิชา": (("digest_counts",), ()),
    "อบรม": (("digest_counts",), ()),
    "การอบรม": (("digest_counts",), ()),
    "เรียน": (("digest_counts",), ()),
    "ลงทะเบียน": (("digest_counts",), ()),
    "การลงทะเบียน": (("digest_counts",), ()),
    "ผู้เรียน": (("digest_counts",), ()),
    "คอร์ส": (("digest_counts",), ()),
    "รายวิชา": (("digest_counts",), ()),
    "หลักสูตร": (("digest_counts",), ()),
    "เนื้อหา": (("digest_counts",), ()),
    "หน่วยงาน": (("digest_counts",), ()),
    "แผนก": (("digest_counts",), ()),
    "ตำแหน่ง": (("digest_counts",), ()),
    "ทักษะ": (("digest_counts",), ()),
    "ใบรับรอง": (("digest_counts",), ()),
    "แบบทดสอบ": (("digest_counts",), ()),
    "แบบสำรวจ": (("digest_counts",), ()),
    "จำนวน": (("digest_counts",), ()),
    "สถิติ": (("digest_counts",), ()),
    "ผู้ใช้": (("digest_unindexed_tables",), ()),

    # ---- general, no kind bias ----
    "ฐานข้อมูล": ((), ()),
    "ระบบ": ((), ()),
    "log": ((), ()),
    "บันทึก": ((), ()),
}

# The same map for English, so a question in either language reaches the
# material the same way. Kept separate from the schema's own vocabulary because
# that vocabulary contains column names like ``data``, ``name``, ``type`` and
# ``status``, and admitting those would put "what is your name" in scope.
ENGLISH: dict[str, tuple[tuple[str, ...], tuple[str, ...]]] = {
    "table": ((), ("table",)),
    "tables": ((), ("table",)),
    "column": ((), ("table",)),
    "columns": ((), ("table",)),
    "schema": ((), ("table",)),
    "index": (("Index",), ("table",)),
    "indexes": (("Index",), ("table",)),
    "default": (("DEFAULT",), ("table",)),
    "primary": (("key",), ("table",)),
    "foreign": (("foreign_keys",), ("rule", "table")),
    "key": (("key",), ("table", "rule")),
    "keys": (("key",), ("table", "rule")),
    "relationship": (("foreign_keys",), ("rule", "table")),
    "relationships": (("foreign_keys",), ("rule", "table")),
    "join": (("foreign_keys",), ("rule", "table")),
    # The classification, under every name somebody planning a migration is
    # likely to reach for.
    "sensitive": (("sensitive",), ("table",)),
    "personal": (("sensitive",), ("table",)),
    "pii": (("sensitive",), ("table",)),
    "privacy": (("sensitive",), ("table",)),
    "password": (("password",), ("table",)),
    "email": (("mail", "email"), ("table",)),
    "route": ((), ("endpoint",)),
    "routes": ((), ("endpoint",)),
    "endpoint": ((), ("endpoint",)),
    "endpoints": ((), ("endpoint",)),
    "api": ((), ("endpoint",)),
    "controller": ((), ("source",)),
    "controllers": ((), ("source",)),
    "model": ((), ("source",)),
    "models": ((), ("source",)),
    "library": ((), ("source",)),
    "libraries": ((), ("source",)),
    "helper": ((), ("source",)),
    "class": ((), ("source",)),
    "classes": ((), ("source",)),
    "method": ((), ("source",)),
    "methods": ((), ("source",)),
    "file": ((), ("source",)),
    "files": ((), ("source",)),
    "database": ((), ()),
    "migration": ((), ()),
    "enrolment": (("digest_counts",), ()),
    "enrolments": (("digest_counts",), ()),
    "enrollment": (("digest_counts",), ()),
    "course": (("digest_counts",), ()),
    "courses": (("digest_counts",), ()),
    "learner": (("digest_counts",), ()),
    "learners": (("digest_counts",), ()),
    "department": (("digest_counts",), ()),
    "departments": (("digest_counts",), ()),
    "skill": (("digest_counts",), ()),
    "skills": (("digest_counts",), ()),
    "statistics": (("digest_counts",), ()),
    "user": (("digest_unindexed_tables",), ()),
    "users": (("digest_unindexed_tables",), ()),
    "sql": ((), ()),
    "query": ((), ()),
}

ENGLISH_TERMS = frozenset(ENGLISH)

# Which count a word is asking for.
#
# The anchors above get a counting question to the right tool; this says which
# figure inside it the question actually wants. Both are needed, and the second
# was missing: asked "มีแบบทดสอบกี่ชุด" the model called get_count and chose
# ``certificates``, answering 58 where the true figure is 6. The number was
# real, it came from a tool, and every guard passed — because the guards check
# where a number came from and not whether it answers the question asked.
#
# So the tool is given the question's own reading and refuses a mismatch. See
# app/tools.py _one_count.
COUNT_KEYS = {
    # Thai
    "คอร์ส": "courses", "รายวิชา": "courses", "บทเรียน": "courses",
    "วิชา": "courses", "อบรม": "courses", "การอบรม": "courses",
    "เนื้อหา": "content", "หลักสูตร": "content",
    "ช่องทาง": "channels", "แหล่งข้อมูล": "resources",
    "ใบรับรอง": "certificates",
    "หน่วยงาน": "departments", "แผนก": "departments",
    "ตำแหน่ง": "positions", "ทักษะ": "skills", "บริษัท": "companies",
    "แบบทดสอบ": "quizzes", "ข้อสอบ": "quizzes", "แบบสำรวจ": "surveys",
    "บทความ": "blogs", "บล็อก": "blogs",
    "ลงทะเบียน": "enrolments", "การลงทะเบียน": "enrolments",
    "ผู้เรียน": "learner_ids",
    # English
    "course": "courses", "courses": "courses", "lesson": "courses",
    "lessons": "courses", "content": "content",
    "channel": "channels", "channels": "channels",
    "certificate": "certificates", "certificates": "certificates",
    "department": "departments", "departments": "departments",
    "position": "positions", "positions": "positions",
    "skill": "skills", "skills": "skills",
    "quiz": "quizzes", "quizzes": "quizzes",
    "survey": "surveys", "surveys": "surveys",
    "enrolment": "enrolments", "enrolments": "enrolments",
    "enrollment": "enrolments", "enrollments": "enrolments",
    "learner": "learner_ids", "learners": "learner_ids",
}


def counts_wanted(question: str, english_tokens: set) -> set:
    """Which count keys this question could reasonably be asking for."""
    wanted = {key for term, key in COUNT_KEYS.items()
              if term in question or term in english_tokens}
    return wanted


# Words that ask for a subset of something the counts do not break down.
#
# Every figure is a plain row count of the whole table. Asked "คอร์สที่เปิดอยู่
# ตอนนี้มีกี่คอร์ส" the model answered 16,431 — the total — with the word
# "เปิดอยู่" still in the sentence, which reads as a filtered figure and is not
# one. There is no status breakdown to give, so the tool says so rather than
# handing over a number that will be read as something it is not.
FILTER_WORDS = (
    "เปิดอยู่", "ใช้งานอยู่", "ที่ยังเปิด", "ที่ปิด", "ยกเลิก", "ปีนี้",
    "เดือนนี้", "ล่าสุด", "ที่เสร็จ", "ที่ยังไม่", "active", "inactive",
    "current", "ongoing", "completed", "recent",
)


def has_filter(question: str, english_tokens: set) -> bool:
    return (any(w in question for w in FILTER_WORDS)
            or bool({w for w in FILTER_WORDS} & english_tokens))


# The complete list that covers each chunk kind, by reference.
#
# Two jobs. It is what an aggregate question about a kind is steered to, and it
# is the fallback when a question produces a kind but no search term at all —
# "มีตารางทั้งหมดกี่ตาราง" is entirely Thai, and every Thai term in it maps to a
# kind rather than to an anchor, so there is nothing to put in an FTS query.
# Before this, that question returned nothing: in scope, understood, and empty.
KIND_DIGEST = {
    "table": "digest_tables",
    "endpoint": "digest_routes",
    "source": "digest_source_files",
}


def terms_in(question: str) -> list[str]:
    """Thai lexicon terms present in the question, longest first.

    Substring matching rather than tokenisation, because Thai has no spaces and
    a segmenter here would be a dictionary that fails silently — the same
    reasoning that put a controlled vocabulary in this file to begin with.
    """
    found = [term for term in TERMS if term in question]
    found.sort(key=len, reverse=True)
    return found


def expand(question: str, english_tokens: set[str] = frozenset()
           ) -> tuple[list[str], set[str]]:
    """(anchor strings to search for, chunk kinds the question is about)."""
    anchors: list[str] = []
    kinds: set[str] = set()
    for term in terms_in(question):
        found_anchors, found_kinds = TERMS[term]
        anchors += list(found_anchors)
        kinds |= set(found_kinds)
    for token in english_tokens:
        found_anchors, found_kinds = ENGLISH.get(token, ((), ()))
        anchors += list(found_anchors)
        kinds |= set(found_kinds)
    return anchors, kinds
