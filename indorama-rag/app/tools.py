"""What the agent is allowed to do, and nothing else.

Every tool reads the index. None of them reach the export archive, the network,
or the filesystem, so the agent cannot widen its own access by deciding to —
the set below *is* its reach, and it is short enough to read in a minute.

The tools are shaped around the questions rather than around the storage. There
is no ``run_query`` taking arbitrary SQL, which would be one tool instead of
six and would also hand a language model a query language pointed at the
index — every guarantee about what the answers are built from would then depend
on what it chose to type. ``list_sensitive_tables`` cannot return the wrong set;
a SELECT can.

Each result carries the chunks it came from, because the guards downstream
check the answer against the material the agent actually saw, and with tool
calling that material is whatever the tools returned.
"""

from . import lexicon, scope

# The schemas handed to the model. Descriptions matter more than names here —
# they are the only thing it reads when choosing.
DEFINITIONS = [
    {"type": "function", "function": {
        "name": "get_table",
        "description": ("Get one database table in full: its columns and their "
                        "types, primary key, foreign keys, indexes, and which "
                        "of its columns are classified sensitive. Use whenever "
                        "the question names a table."),
        "parameters": {"type": "object", "properties": {
            "name": {"type": "string",
                     "description": "Table name, e.g. tbl_company. "
                                    "Case does not matter."}},
            "required": ["name"]}}},
    {"type": "function", "function": {
        "name": "search",
        "description": ("Search the indexed schema, routes and source files by "
                        "keyword. Use when the question does not name anything "
                        "exactly, or to find what a table or file is called."),
        "parameters": {"type": "object", "properties": {
            "query": {"type": "string",
                      "description": "Words or an identifier to look for."}},
            "required": ["query"]}}},
    {"type": "function", "function": {
        "name": "list_sensitive_tables",
        "description": ("The complete list of tables holding columns classified "
                        "sensitive, with a total. Use for any question about "
                        "personal data, privacy, or what a migration must "
                        "handle — never assemble this list yourself."),
        "parameters": {"type": "object", "properties": {}, "required": []}}},
    {"type": "function", "function": {
        "name": "list_tables",
        "description": ("The complete list of every DATABASE TABLE with its "
                        "column count. Use ONLY when the question is literally "
                        "about database tables or columns. It is not a count of "
                        "anything in the business: departments, courses, "
                        "skills and enrolments are counted by get_count, and "
                        "people by explain_missing_data. Answering 'how many "
                        "departments' with the number of tables is the mistake "
                        "this warning exists to stop."),
        "parameters": {"type": "object", "properties": {}, "required": []}}},
    {"type": "function", "function": {
        "name": "list_routes",
        "description": ("The complete list of route groups with a total. Use "
                        "for questions about the API surface as a whole."),
        "parameters": {"type": "object", "properties": {}, "required": []}}},
    {"type": "function", "function": {
        "name": "get_source_file",
        "description": ("One PHP source file: the classes and methods it "
                        "defines and the tables it references. Accepts a full "
                        "path or just the file name."),
        "parameters": {"type": "object", "properties": {
            "path": {"type": "string",
                     "description": "e.g. Authorization_Token.php"}},
            "required": ["path"]}}},
    {"type": "function", "function": {
        "name": "get_count",
        "description": ("How many of ONE thing exist in the system as of the "
                        "export. Choose exactly what is being asked about. If "
                        "the question is about people or users, do not use this "
                        "tool — use explain_missing_data."),
        "parameters": {"type": "object", "properties": {
            "what": {"type": "string",
                     "enum": ["courses", "content", "channels", "resources",
                              "certificates", "departments", "positions",
                              "skills", "companies", "quizzes", "surveys",
                              "blogs", "enrolments", "learner_ids"],
                     "description": ("courses = legacy courses; content = "
                                     "content/curriculum items; enrolments = "
                                     "enrolment records; learner_ids = distinct "
                                     "learner identifiers appearing in "
                                     "enrolments, which is NOT a user count")}},
            "required": ["what"]}}},
    {"type": "function", "function": {
        "name": "explain_missing_data",
        "description": ("Call this for ANY question about the CONTENTS of the "
                        "system rather than its structure: how many users or "
                        "learners there are, who is enrolled, what a course "
                        "teaches, what is stored in a record, anything about a "
                        "named person. Returns the list of tables whose rows "
                        "are deliberately not indexed and why. The answer to "
                        "such a question is always that this assistant cannot "
                        "tell you — never substitute a count of tables or "
                        "columns for a count of people."),
        "parameters": {"type": "object", "properties": {}, "required": []}}},
    {"type": "function", "function": {
        "name": "get_relationships",
        "description": ("Every foreign key declared in the database, with a "
                        "total. Use for questions about how tables join."),
        "parameters": {"type": "object", "properties": {}, "required": []}}},
]

NAMES = {d["function"]["name"] for d in DEFINITIONS}

# Tools that cannot be right for a question about a business quantity, and are
# therefore not offered for one.
#
# Warning the model off them was tried and did not work. Asked "มีหน่วยงานกี่
# หน่วยงาน", it called list_tables, answered 192 — the number of database
# tables — and then added that 192 is the number of tables and not the number
# of departments. It knew, said so, and still led with the wrong figure,
# because a model that is told a tool is inappropriate has still been handed
# the tool.
#
# So the tool is withdrawn instead. app.lexicon already decides that a question
# about departments, courses, skills or enrolments is a counting question; that
# decision now also shapes what the agent may reach for.
_NOT_FOR_BUSINESS_COUNTS = frozenset({"list_tables", "list_routes"})


def for_question(assessment) -> list[dict]:
    """The tools this particular question may use.

    Narrowing what is reachable rather than instructing what to avoid is the
    move that has worked every time in this codebase — the URL that was never
    shown, the list that was never handed over, and now the tool that is never
    offered.
    """
    if "digest_counts" not in (assessment.anchors or []):
        return DEFINITIONS
    return [d for d in DEFINITIONS
            if d["function"]["name"] not in _NOT_FOR_BUSINESS_COUNTS]

_DIGESTS = {
    "list_sensitive_tables": "digest_sensitive_columns",
    "list_tables": "digest_tables",
    "list_routes": "digest_routes",
    "get_relationships": "foreign_keys",
    "explain_missing_data": "digest_unindexed_tables",
}


class Result:
    """What a tool returned, and which chunks it came from.

    The chunks travel with the text because the guards need them. An agent that
    reported only its prose would leave the checker with nothing to check
    against, and "the model said it, so it must have read it" is the assumption
    this whole service exists to avoid making.
    """

    def __init__(self, text: str, chunks: list[dict]):
        self.text = text
        self.chunks = chunks


def _by_ref(store, ref: str) -> list[dict]:
    return store.named([ref])


def _missing(what: str, hint: str = "") -> Result:
    """A tool that found nothing says so in words the model can use.

    Returning an empty string invites it to fill the silence from somewhere
    else. Saying "there is no such table" is a fact it can report.
    """
    return Result(f"NOT FOUND: {what}." + (f" {hint}" if hint else ""), [])


# How many entries of a complete list the model is actually shown.
#
# Asking it not to transcribe a long list does not stop it transcribing a long
# list. Handed all twenty-six rows of the sensitive-table digest, qwen2.5 typed
# them out and wrote ``tbl_log_password`` — the real name is ``tbl_logPassword``,
# one underscore away, and the guard refused the whole answer for it. Correct,
# and the user still lost the answer to the most important question in the set.
#
# So the temptation is removed instead of forbidden. The model sees the total
# and a handful of examples; the caller still receives the complete list, whole
# and untouched, through the "lists" field. Same reasoning that stopped URLs
# being shown to the navigation assistant in kai-proctor: do not hand a model
# something it can only get wrong by retyping.
_DIGEST_EXAMPLES = 5


def _trimmed(chunk: dict) -> str:
    """A digest as the model should see it: the totals, and no names at all.

    The first version showed five entries as examples, which set the model an
    unwinnable task. Every entry in every digest is a table name, and the
    answers are required to contain no table names — so asked how many users
    there are, the model took the only material it had, wrote "ci_sessions,
    email_queue, tbl_activity_log", and the guard threw the whole answer away.
    It could not have done anything else with what it was handed.

    Names were the wrong thing to hand it, not the wrong thing to say. The
    header carries the totals, which is what the prose is for; the complete
    list travels to the caller in the chunk and is rendered beside the answer,
    where the exact spelling can be read and copied.
    """
    lines = chunk["text"].splitlines()
    entries = [n for n, line in enumerate(lines) if line.startswith("  - ")]
    if not entries:
        return chunk["text"]

    head = lines[:entries[0]]
    tail = [line for line in lines[entries[-1] + 1:]
            if line.strip() and not line.startswith("  - ")]
    return "\n".join(head + [
        f"(รายการทั้ง {len(entries)} รายการถูกแสดงให้ผู้ใช้เห็นข้างคำตอบของคุณแล้ว "
        f"ชื่อจึงไม่ถูกส่งมาที่นี่)",
        "",
        "ให้บอกยอดรวมและอธิบายเป็นภาษาคนว่ารายการนี้ครอบคลุมอะไร "
        "ห้ามเดาชื่อรายการ",
    ] + tail)


def _one_count(store, what: str, assessment=None) -> Result:
    """A single figure, named, with the caveat that belongs to it.

    One question, one number. The first version of this returned all fourteen
    at once and let the model choose: asked how many enrolments there are it
    answered 16,431 — the course count — and asked how many departments, 192,
    which is the number of tables in the database. Both figures were real, both
    came from a tool, and every guard passed, because the guards check where a
    number came from and not whether it answers the question in front of it.
    That is not a gap a guard can close; it is a gap in what the tool offered.
    """
    import json

    # The question's own reading of itself, checked against the model's choice.
    #
    # Asked "มีแบบทดสอบกี่ชุด" the model chose ``certificates`` and answered
    # 58; the true figure is 6. Every guard passed, because they check where a
    # number came from and not whether it answers the question in front of it —
    # and no guard can close that, because the service does not know what the
    # right answer is. It does know what the question said, which is enough to
    # refuse the wrong drawer.
    wanted = getattr(assessment, "count_keys", None) or set()
    if wanted and what not in wanted:
        return _missing(
            f"{what!r} is not what this question asked about",
            "The question is about: " + ", ".join(sorted(wanted))
            + ". Call get_count again with one of those.")

    # A subset that does not exist.
    #
    # Every figure is a plain row count of a whole table; none of them break
    # down by status or by date. Asked for the courses "ที่เปิดอยู่", the model
    # returned the total with the word still in the sentence, which reads as a
    # filtered figure and is not one.
    if getattr(assessment, "filtered", False):
        return _missing(
            "there is no count broken down that way",
            "The figures are whole-table totals with no breakdown by status, "
            "date or category. Say that only the overall total exists, give it "
            "if it helps, and do not describe it as a subset.")

    counts = json.loads(store.meta().get("counts") or "{}")
    if not counts:
        return _missing("this index has no counts; it was built before counting "
                        "was added, or from an export with no data sections")
    entry = counts.get(what)
    if entry is None:
        return _missing(f"there is no count called {what!r}",
                        "Available: " + ", ".join(sorted(counts)))

    lines = [f"{entry['label']}: {entry['value']:,} {entry['unit']}",
             "ตัวเลขนี้นับ ณ วันที่ export ไม่ใช่ข้อมูลสด",
             "และเป็นยอดรวมทั้งหมด ไม่ได้แยกตามสถานะ วันที่ หรือหมวดหมู่"]
    if entry.get("caveat"):
        lines.append("ข้อควรระวัง: " + entry["caveat"])
    chunks = _by_ref(store, "digest_counts")
    return Result("\n".join(lines), chunks)


def _shape(chunk: dict) -> str:
    """One table described by its shape rather than by its column names.

    The answers are required to read as ordinary Thai with no technical names
    in them. Handing the model thirty-five column names and instructing it not
    to mention any is the same losing arrangement that produced
    ``tbl_log_password`` from the sensitive-tables digest: asked what
    tbl_company holds, it wrote out ``allow_send_email`` and
    ``default_user_password``, the guard refused the whole answer, and the
    reader got nothing.

    So the names are not shown. The counts are, because counts are what the
    prose is supposed to carry, and the chunk still travels intact for the
    interface to render underneath — which is where somebody who wants the
    exact spelling should be reading it from anyway.
    """
    text = chunk["text"]
    # Read the count off the header rather than counting bullet lines. The
    # chunk uses "  - " for columns, foreign keys and indexes alike, so
    # counting them gave tbl_company 36 columns where it has 35 — one index
    # line, silently folded into the figure the answer would have quoted.
    header = text.splitlines()[0]
    columns = header.rsplit("—", 1)[-1].strip().split()[0] if "—" in header else "?"
    sensitive = 0
    if "sensitive" in text:
        marker = text.split("(sensitive) ตาม data dictionary:")[-1]
        sensitive = len([c for c in marker.splitlines()[1].split(",") if c.strip()])

    out = [f"ตาราง {chunk['ref']}",
           f"จำนวนคอลัมน์: {columns}"]
    if sensitive:
        out.append(f"คอลัมน์ที่จัดชั้นเป็นข้อมูลอ่อนไหว: {sensitive}")
    if "Primary key:" in text:
        out.append("มี primary key")
    for label, needle in (("foreign key ที่ชี้ออกไป", "Foreign key ที่ชี้ออกไป:"),
                          ("ตารางอื่นที่ชี้เข้ามา", "ตารางอื่นที่ชี้เข้ามา:"),
                          ("index", "Index:")):
        if needle in text:
            count = text.split(needle)[1].split("\n\n")[0].count("\n  - ")
            out.append(f"{label}: {count}")
    if "หมายเหตุ:" in text:
        out.append("หมายเหตุ: เป็นตาราง log หรือมีข้อมูลส่วนบุคคล "
                   "ข้อมูลในแถวไม่ถูกนำเข้าดัชนี")

    out += ["",
            "ชื่อคอลัมน์และรายละเอียดทั้งหมดถูกแสดงให้ผู้ใช้เห็นข้างคำตอบแล้ว "
            "ให้อธิบายเป็นภาษาคนว่าตารางนี้เก็บอะไรและมีอะไรที่ต้องระวัง "
            "ห้ามพิมพ์ชื่อคอลัมน์"]
    return "\n".join(out)


def run(store, name: str, arguments: dict, assessment=None) -> Result:
    """Dispatch one tool call. Unknown names are refused, not guessed at."""
    if name == "get_count":
        return _one_count(store, (arguments.get("what") or "").strip(), assessment)

    if name in _DIGESTS:
        chunks = _by_ref(store, _DIGESTS[name])
        if not chunks:
            return _missing(f"the {name} list is not in this index")
        # The chunk travels whole even though the text is trimmed: the guards
        # check against the chunk, so every real name stays permitted, and the
        # caller renders the complete list from it.
        return Result(_trimmed(chunks[0]), chunks)

    if name == "get_table":
        wanted = (arguments.get("name") or "").strip()
        chunks = [c for c in store.named([wanted]) if c["kind"] == "table"]
        if not chunks:
            return _missing(
                f"there is no table called {wanted!r} in this database",
                "Use search to find what it might be called, or say it does "
                "not exist. Do not answer from a similar name.")
        return Result(_shape(chunks[0]), chunks)

    if name == "get_source_file":
        wanted = (arguments.get("path") or "").strip()
        chunks = [c for c in store.db.execute(
            "SELECT chunk_id, kind, ref, title, text FROM chunk "
            "WHERE kind = 'source' AND (lower(ref) = lower(?) "
            "OR lower(ref) LIKE lower(?))", (wanted, f"%/{wanted}"))]
        chunks = [dict(c) for c in chunks]
        if not chunks:
            return _missing(
                f"there is no indexed source file called {wanted!r}",
                "Third-party libraries (PHPExcel, FPDF) are deliberately not "
                "indexed, so a file from those will not be here.")
        return Result(chunks[0]["text"], chunks)

    if name == "search":
        query = (arguments.get("query") or "").strip()
        assessment = scope.assess(query, store.vocabulary())
        # The same scope rule as a direct question. A tool is not a way around
        # it: an agent that searched for "the weather" would otherwise pull
        # whatever ranked least badly into its own context and answer from it.
        if not assessment.in_scope:
            return _missing(
                f"nothing in this system matches {query!r}",
                "This index covers only the Indorama LMS database schema, its "
                "routes and its source files.")
        chunks = store.search(query, limit=3, assessment=assessment)
        if not chunks:
            return _missing(f"nothing matches {query!r}")
        text = "\n\n".join(f"--- {c['title']} ---\n{c['text']}" for c in chunks)
        return Result(text, chunks)

    return _missing(f"there is no tool called {name!r}",
                    "Available: " + ", ".join(sorted(NAMES)))
