"""Turn the export into retrievable chunks.

What is worth indexing was decided by measurement, not by taking the export at
its word. The archive ships a ``knowledge/`` section — 903 ``rag_chunks``, 172
``business_rules``, 476 ``fine_tuning_samples`` — which looks like the job is
already done. It is not: all three are derived restatements of ``source_code/``
and ``schema/``, so indexing them alongside the originals would put the same
fact in the index three times under three wordings, and retrieval would return
three copies of one answer instead of three answers.

The originals are indexed. The derivations are not.

Four kinds of chunk, each built so that a question about the system can be
answered from one of them without needing a second lookup:

``table``       one per table: columns, keys, indexes, and which of its
                columns hold personal data
``endpoint``    one per controller: the routes that reach it
``source``      one per file: classes, methods, tables it touches
``rule``        the declared foreign keys, as prose
``digest``      corpus-level roll-ups: the sets the index can state exactly,
                so that a question about a set is not answered from a sample

Chunk text is written for a reader, not for a tokeniser. It is what gets pasted
into a prompt, so a chunk that reads as a table definition produces an answer
that reads as one.
"""

import json
import re

from . import digest, sanitize

_STRING_TYPES = ("nvarchar", "varchar", "char", "nchar")


def _by_table(rows: list[dict], key: str = "table_name") -> dict[str, list[dict]]:
    grouped: dict[str, list[dict]] = {}
    for row in rows:
        grouped.setdefault(row[key], []).append(row)
    return grouped


# A DEFAULT constraint that is a quoted string, rather than a number or a
# function call. ((1)) and (getdate()) are structure; ('Indorama2025') is a
# password.
_STRING_DEFAULT = re.compile(r"^\(+'(.*)'\)+$", re.DOTALL)


def _default_of(col: dict, sensitive: bool) -> str | None:
    """The DEFAULT clause, unless printing it would publish a secret.

    Found by reading a built chunk rather than by reasoning about the schema:
    ``tbl_company.default_user_password`` carries ``DEFAULT ('Indorama2025')``,
    which is the live initial password for every account the client creates.
    The data dictionary classifies that column ``sensitive`` and the classifier
    was right — but a default value is part of the column definition, so it
    travelled with the structure while the row data everyone was watching
    stayed behind.

    Numeric and function defaults on the same columns are kept. ``((1))`` on
    ``allow_send_email`` tells a reader how the system behaves out of the box
    and reveals nothing; suppressing it would cost information for no gain.
    """
    raw = col.get("default_value")
    if not raw:
        return None
    if sensitive and _STRING_DEFAULT.match(raw.strip()):
        return "DEFAULT [ค่าตั้งต้นถูกปกปิด — คอลัมน์นี้จัดชั้นเป็นข้อมูลอ่อนไหว]"
    return f"DEFAULT {raw}"


def _column_line(col: dict, sensitive: bool = False) -> str:
    """One column, as it would be written in a schema note."""
    parts = [col["column_name"], col["data_type"]]
    if col.get("max_length") and col["data_type"] in _STRING_TYPES:
        length = col["max_length"]
        parts[-1] += "(max)" if length == -1 else f"({length})"
    if col.get("is_nullable") == "NO":
        parts.append("NOT NULL")
    if col.get("is_identity"):
        parts.append("IDENTITY")
    if col.get("is_computed"):
        parts.append("COMPUTED")
    default = _default_of(col, sensitive)
    if default:
        parts.append(default)
    return "  - " + " ".join(parts)


def table_chunks(archive, san) -> list[dict]:
    """One chunk per table, carrying everything the schema knows about it."""
    def read(name):
        return json.loads(archive.read(name).decode("utf-8"))

    tables = read("schema/tables.json")
    columns = _by_table(read("schema/columns.json"))
    primary = _by_table(read("schema/primary_keys.json"))
    indexes = _by_table(read("schema/indexes.json"))

    outgoing: dict[str, list[dict]] = {}
    incoming: dict[str, list[dict]] = {}
    for fk in read("schema/foreign_keys.json"):
        outgoing.setdefault(fk["table_name"], []).append(fk)
        incoming.setdefault(fk["referenced_table"], []).append(fk)

    chunks = []
    for entry in tables:
        name = entry["table_name"]
        cols = columns.get(name, [])
        is_sensitive = {c["column_name"]:
                        (name.lower(), c["column_name"].lower()) in san.sensitive
                        for c in cols}
        lines = [f"ตาราง {name} (schema {entry['table_schema']}) — {len(cols)} คอลัมน์",
                 "", "คอลัมน์:"]
        lines += [_column_line(c, is_sensitive[c["column_name"]]) for c in cols]

        pk = [p["column_name"] for p in
              sorted(primary.get(name, []), key=lambda p: p["key_ordinal"])]
        if pk:
            lines += ["", "Primary key: " + ", ".join(pk)]

        if outgoing.get(name):
            lines += ["", "Foreign key ที่ชี้ออกไป:"]
            lines += [f"  - {fk['column_name']} -> {fk['referenced_table']}."
                      f"{fk['referenced_column']} ({fk['constraint_name']})"
                      for fk in outgoing[name]]
        if incoming.get(name):
            lines += ["", "ตารางอื่นที่ชี้เข้ามา:"]
            lines += [f"  - {fk['table_name']}.{fk['column_name']} -> {fk['referenced_column']}"
                      for fk in incoming[name]]

        by_index: dict[str, list[dict]] = {}
        for ix in indexes.get(name, []):
            by_index.setdefault(ix["index_name"], []).append(ix)
        if by_index:
            lines += ["", "Index:"]
            for ix_name, parts in by_index.items():
                inside = ", ".join(p["column_name"] for p in
                                   sorted(parts, key=lambda p: p["key_ordinal"]))
                unique = "UNIQUE " if parts[0]["is_unique"] else ""
                lines.append(f"  - {unique}{ix_name} ({inside})")

        # The classification is content here, not only policy. "คอลัมน์ไหนเก็บ
        # ข้อมูลส่วนบุคคลบ้าง" is one of the questions this assistant exists to
        # answer, and the answer is only worth trusting if it comes from the
        # dictionary the client's own export shipped.
        sensitive = [c["column_name"] for c in cols if is_sensitive[c["column_name"]]]
        if sensitive:
            lines += ["", "คอลัมน์ที่จัดชั้นเป็นข้อมูลอ่อนไหว (sensitive) ตาม data dictionary:",
                      "  " + ", ".join(sensitive)]

        if name.lower() in sanitize.LOG_AND_PERSONAL_TABLES:
            lines += ["", "หมายเหตุ: ตารางนี้เป็น log หรือมีข้อมูลส่วนบุคคล "
                          "ข้อมูลในแถวจึงไม่ถูกนำเข้าดัชนี มีเฉพาะโครงสร้างตามด้านบน"]

        chunks.append({
            "kind": "table",
            "ref": name,
            "title": f"ตาราง {name}",
            "text": san.scrub("\n".join(lines)),
        })
    return chunks


# Segments that name where a route lives rather than what it does. Grouping on
# these alone put 151 routes in one chunk called "api" and 90 in one called
# "backend" — so asking about the channel API retrieved a chunk containing
# every API route in the system, and the model had to find the answer inside
# it. Measured on the export: see the group sizes in build-report.json.
_ROUTE_PREFIXES = frozenset({"api", "backend", "admin", "setting", "learner"})


def _route_group(target: str) -> str:
    """The name a person would use for this family of routes."""
    parts = [p for p in target.split("/") if p]
    if not parts:
        return "(ไม่ระบุปลายทาง)"
    if parts[0] in _ROUTE_PREFIXES and len(parts) > 1:
        return "/".join(parts[:2])
    return parts[0]


def endpoint_chunks(archive, san) -> list[dict]:
    """Routes, grouped by the controller they reach.

    Every one of the 427 is declared in the same ``routes.php``, so grouping by
    source file would produce a single unreadable chunk. Grouped by target
    instead, which is also how the question arrives: somebody asks what the
    channel API exposes, not what line 200 of the routing table says.
    """
    rows = [json.loads(line) for line in
            archive.read("api/endpoints_0001.jsonl").decode("utf-8").splitlines()]

    grouped: dict[str, list[dict]] = {}
    for row in rows:
        target = row["data"]["target"] or "(ไม่ระบุปลายทาง)"
        grouped.setdefault(_route_group(target), []).append(row["data"])

    chunks = []
    for controller, routes in sorted(grouped.items()):
        lines = [f"Route ที่เข้าสู่ {controller} — {len(routes)} รายการ",
                 "ประกาศไว้ใน application/config/routes.php", ""]
        for route in sorted(routes, key=lambda r: r["path"]):
            lines.append(f"  - {route['http_method']:4} {route['path']} -> {route['target']}")
        chunks.append({
            "kind": "endpoint",
            "ref": controller,
            "title": f"Route ของ {controller}",
            "text": san.scrub("\n".join(lines)),
        })
    return chunks


# Third-party code that was vendored into the application tree. 253 of the 265
# files under libraries/ are PHPExcel and FPDF, checked in under
# application/libraries/FPDF/ rather than pulled from a package manager.
#
# Excluded because they are not this system. Asked "ไฟล์ไหนจัดการ token", the
# first version answered PHPExcel/Calculation/Token/Stack.php — a spreadsheet
# formula parser — instead of Authorization_Token.php, which is the file the
# question was about. Third-party code is also the part a maintainer can look
# up in its own upstream documentation, so indexing it costs precision and buys
# nothing.
_VENDORED = ("/FPDF/", "/PHPExcel/", "/PHPMailer/", "/Dompdf/", "/vendor/")


def _is_vendored(path: str) -> bool:
    return any(marker in path for marker in _VENDORED)


def source_chunks(archive, san) -> tuple[list[dict], int]:
    """One chunk per PHP file: what it defines and what it touches.

    Returns the chunks and how many files were skipped as third-party, so the
    build report can state the exclusion rather than leaving a reader to wonder
    why the file they were looking for is not in the index.
    """
    chunks = []
    skipped = 0
    for section in ("controllers", "models", "libraries", "helpers"):
        name = f"source_code/{section}_0001.jsonl"
        for line in archive.read(name).decode("utf-8").splitlines():
            row = json.loads(line)
            data, path = row["data"], row["source"]["file"]
            if _is_vendored(path):
                skipped += 1
                continue
            lines = [f"ไฟล์ {path} ({section})"]
            if data.get("classes"):
                lines.append("คลาส: " + ", ".join(data["classes"]))
            if data.get("methods"):
                lines.append("เมธอด: " + ", ".join(data["methods"]))
            if data.get("database_tables"):
                lines.append("ตารางฐานข้อมูลที่อ้างถึง: " + ", ".join(data["database_tables"]))
            else:
                # Stated rather than left blank. The extractor found tables in
                # 172 of 476 files; a silent absence would read as "this file
                # touches no tables", which is a claim the export cannot make.
                lines.append("ตารางฐานข้อมูลที่อ้างถึง: ตัวสกัดไม่พบ "
                             "(ไม่ได้แปลว่าไฟล์นี้ไม่แตะฐานข้อมูล)")
            chunks.append({
                "kind": "source",
                "ref": path,
                "title": path,
                "text": san.scrub("\n".join(lines)),
            })
    return chunks, skipped


def relationship_chunk(archive, san) -> list[dict]:
    """The declared foreign keys in one place.

    Twenty-eight of them across 192 tables, which is itself the finding: this
    database declares almost no referential integrity, and an assistant asked
    "how do these tables join" has to say so rather than imply the 28 are all
    there is to know.
    """
    rows = json.loads(archive.read("schema/relationships.json").decode("utf-8"))
    tables = json.loads(archive.read("schema/tables.json").decode("utf-8"))
    lines = [f"ความสัมพันธ์ระหว่างตารางที่ประกาศไว้ในฐานข้อมูล — {len(rows)} รายการ", "",
             f"จากทั้งหมด {len(tables)} ตาราง มีการประกาศ foreign key เพียงเท่านี้ "
             "ความสัมพันธ์อื่นถูกบังคับในโค้ดแอปพลิเคชัน ไม่ใช่ในฐานข้อมูล", ""]
    for row in sorted(rows, key=lambda r: r["from_table"]):
        lines.append(f"  - {row['from_table']}.{row['from_column']} -> "
                     f"{row['to_table']}.{row['to_column']} "
                     f"({row['relationship_type']}, confidence {row['confidence']})")
    return [{
        "kind": "rule",
        "ref": "foreign_keys",
        "title": "ความสัมพันธ์ระหว่างตารางที่ประกาศไว้",
        "text": san.scrub("\n".join(lines)),
    }]


def build_all(archive, san) -> tuple[list[dict], dict]:
    """Every chunk, plus what the builder chose to leave out.

    The second return value exists so that no exclusion is silent. A corpus
    that quietly dropped a quarter of its files reads, from the outside,
    exactly like a corpus that never had them.
    """
    sources, vendored = source_chunks(archive, san)
    endpoints = endpoint_chunks(archive, san)
    chunks = (table_chunks(archive, san)
              + endpoints
              + sources
              + relationship_chunk(archive, san)
              # Built from the other chunks, so they come last and can count
              # what is actually in the index rather than what the export
              # contained. See ingest/digest.py.
              + digest.build(archive, san, endpoints, sources, vendored))
    for n, chunk in enumerate(chunks):
        chunk["chunk_id"] = f"{chunk['kind']}_{n:04d}"
    return chunks, {"vendored_source_files_skipped": vendored}
