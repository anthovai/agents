"""Corpus-level roll-ups, so that a question about a *set* can be answered.

Every other chunk describes one thing: one table, one file, one group of
routes. That is the right shape for "what does tbl_company hold" and the wrong
shape for "which tables hold personal data", where the answer is a list of 26
tables and the retriever hands over four.

The four it hands over are all correct, which is what makes this the worse
failure. A wrong answer announces itself the first time somebody checks it. An
answer that names four of twenty-six tables reads as complete, gets used to
scope a migration, and the other twenty-two are found afterwards by whoever
inherits the consequences. Nothing in the reply says "these are some of them".

So the sets the corpus can enumerate exactly are enumerated once, at build
time, into chunks that say what they are and how many they contain. A digest is
not a summary — it is the whole list, and it says so, because a summary that
does not state its own completeness has the same problem as the four chunks.

Only sets that can be stated exactly get a digest. There is no digest for
"which files touch the database", because the export's extractor found tables
in 172 of 476 files and the true set is unknown — a digest claiming
completeness there would be the exact failure this module exists to prevent.
"""

import json

from . import sanitize


def _chunk(ref: str, title: str, lines: list[str], keywords: str = "") -> dict:
    """One digest, with an English keyword line so it is findable in both.

    Every label in this corpus is Thai and every identifier is English, which
    leaves a digest reachable from a Thai question and invisible to "list all
    tables" — the English words in that question appear nowhere in a chunk
    whose prose is Thai. The keyword line is the cheapest honest fix: it is
    part of the chunk, so it is searched like everything else, and it says only
    what the digest already contains.

    Keywords name what makes a digest *different*, never what every digest
    shares. The first version gave all five "complete list all every", so every
    set question matched all five equally and BM25 broke the tie on document
    length — handing "list all tables" to whichever list was shortest, which is
    never the full inventory. Words that do not discriminate do not belong
    here: the aggregate question has already been recognised by app.scope
    before this line is ever searched.
    """
    body = list(lines)
    if keywords:
        body += ["", f"keywords: {keywords}"]
    return {"kind": "digest", "ref": ref, "title": title, "text": "\n".join(body)}


def sensitive_columns(columns: list[dict], san) -> dict:
    """Every column the client's own data dictionary classifies ``sensitive``.

    The question a migration actually starts from, and the one where a partial
    answer does the most damage.
    """
    by_table: dict[str, list[str]] = {}
    for col in columns:
        if (col["table_name"].lower(), col["column_name"].lower()) in san.sensitive:
            by_table.setdefault(col["table_name"], []).append(col["column_name"])

    total = sum(len(v) for v in by_table.values())
    lines = [
        f"รายการครบถ้วน: ตารางที่มีคอลัมน์จัดชั้นเป็นข้อมูลอ่อนไหว (sensitive)",
        "",
        f"มีทั้งหมด {len(by_table)} ตาราง รวม {total} คอลัมน์ "
        "นี่คือรายการทั้งหมด ไม่ใช่ตัวอย่าง — จัดชั้นตาม data dictionary ที่มาพร้อม export",
        "",
    ]
    for table in sorted(by_table):
        lines.append(f"  - {table}: " + ", ".join(sorted(by_table[table])))
    return _chunk("digest_sensitive_columns",
                  "รายการครบ: คอลัมน์ที่เป็นข้อมูลอ่อนไหวทุกตาราง", lines,
                  "sensitive personal pii privacy confidential")


def table_inventory(tables: list[dict], columns: list[dict]) -> dict:
    """All 192 tables with their column counts."""
    counts: dict[str, int] = {}
    for col in columns:
        counts[col["table_name"]] = counts.get(col["table_name"], 0) + 1

    lines = [
        "รายการครบถ้วน: ตารางทั้งหมดในฐานข้อมูล",
        "",
        f"มีทั้งหมด {len(tables)} ตาราง รวม {len(columns)} คอลัมน์ "
        "นี่คือรายชื่อทั้งหมด ไม่ใช่ตัวอย่าง",
        "",
    ]
    for entry in sorted(tables, key=lambda t: t["table_name"].lower()):
        name = entry["table_name"]
        lines.append(f"  - {name} ({counts.get(name, 0)} คอลัมน์)")
    return _chunk("digest_tables", "รายการครบ: ตารางทั้งหมด", lines,
                  "tables table schema database inventory catalogue")


def unindexed_tables(tables: list[dict]) -> dict:
    """The tables whose rows are deliberately absent from the index.

    Worth its own digest because it answers a question somebody will ask when
    an answer disappoints them — "why does it not know how many learners there
    are" — and the honest answer is a policy decision, not a gap.
    """
    present = {t["table_name"].lower(): t["table_name"] for t in tables}
    named = sorted(present[n] for n in sanitize.LOG_AND_PERSONAL_TABLES if n in present)
    lines = [
        "รายการครบถ้วน: ตารางที่ข้อมูลในแถวไม่ถูกนำเข้าดัชนี",
        "",
        f"มี {len(named)} ตาราง เป็น log หรือมีข้อมูลส่วนบุคคล "
        "ดัชนีนี้เก็บเฉพาะโครงสร้างของมัน ไม่มีข้อมูลในแถวแม้แต่แถวเดียว "
        "จึงตอบคำถามเกี่ยวกับ*เนื้อหา*ในตารางเหล่านี้ไม่ได้เลย",
        "",
    ]
    lines += [f"  - {name}" for name in named]
    # Keywords say what makes this list *different*, not what every list
    # shares. Carrying the generic "tables list all" made it collide with the
    # full inventory on "list all tables", and BM25 handed the win to whichever
    # was shorter — which is this one, by a hundred and seventy-five rows.
    return _chunk("digest_unindexed_tables",
                  "รายการครบ: ตารางที่ไม่ได้นำข้อมูลเข้าดัชนี", lines,
                  "unindexed excluded skipped rows log missing")


def route_inventory(endpoint_chunks: list[dict]) -> dict:
    """Every route group and how many routes it holds."""
    counts = [(c["ref"], c["text"].count("\n  - ")) for c in endpoint_chunks]
    total = sum(n for _, n in counts)
    lines = [
        "รายการครบถ้วน: กลุ่ม route ทั้งหมด",
        "",
        f"มี {len(counts)} กลุ่ม รวม {total} route "
        "ประกาศไว้ใน application/config/routes.php ทั้งหมด นี่คือรายการทั้งหมด",
        "",
    ]
    for ref, n in sorted(counts):
        lines.append(f"  - {ref} ({n} route)")
    return _chunk("digest_routes", "รายการครบ: กลุ่ม route ทั้งหมด", lines,
                  "routes route endpoints endpoint api url path")


def source_inventory(source_chunks: list[dict], vendored: int) -> dict:
    """What source files the index holds, by section, and what it left out."""
    by_section: dict[str, list[str]] = {}
    for chunk in source_chunks:
        section = chunk["text"].split("(")[-1].split(")")[0]
        by_section.setdefault(section, []).append(chunk["ref"])

    lines = [
        "รายการครบถ้วน: ไฟล์ซอร์สโค้ดที่อยู่ในดัชนี",
        "",
        f"มี {len(source_chunks)} ไฟล์ และ **ตัดออก {vendored} ไฟล์** "
        "ที่เป็นโค้ดของบุคคลที่สาม (PHPExcel, FPDF) ซึ่งไม่ใช่ระบบนี้ "
        "ถ้าถามถึงไฟล์ในกลุ่มที่ถูกตัด จะไม่พบ",
        "",
    ]
    for section in sorted(by_section):
        lines.append(f"  {section}: {len(by_section[section])} ไฟล์")
    lines.append("")
    lines.append("controller ทั้งหมด:")
    for path in sorted(by_section.get("controllers", [])):
        lines.append(f"  - {path}")
    return _chunk("digest_source_files", "รายการครบ: ไฟล์ซอร์สโค้ดในดัชนี", lines,
                  "files file source code controllers models libraries "
                  "helpers classes methods")


def build(archive, san, endpoint_chunks: list[dict],
          source_chunks: list[dict], vendored: int) -> list[dict]:
    def read(name):
        return json.loads(archive.read(name).decode("utf-8"))

    tables = read("schema/tables.json")
    columns = read("schema/columns.json")

    chunks = [
        sensitive_columns(columns, san),
        table_inventory(tables, columns),
        unindexed_tables(tables),
        route_inventory(endpoint_chunks),
        source_inventory(source_chunks, vendored),
    ]
    for chunk in chunks:
        chunk["text"] = san.scrub(chunk["text"])
    return chunks
