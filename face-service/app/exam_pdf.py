"""Parse multiple-choice exam PDFs into a question bank.

Ported from face-re/app/exam_pdf.py. The parsing is unchanged — it is the part
that took real work to get right against actual Thai licence-exam packs, and it
has no reason to be rewritten in PHP.

What did change: the PDF text extractor. The original used PyMuPDF, which is
AGPL-3.0 and would put the same licence problem on this project that
InsightFace already did once. pdfminer.six is MIT and layout-aware, which is
what this parser needs — it works off line structure, not raw glyph order.

Supports Thai broker/license-style packs where:
  - questions are numbered ``1. …`` with choices ``ก. / ข. / ค. / ง.`` (or A–D)
  - an answer key at the end is headed ``คำตอบ : วิชา …`` and lists
    ``<number> <letter>`` pairs (often one token per line)

Subject sections usually restart numbering at 1; answer-key subjects are matched
to question groups in order. Difficulty is not present in these PDFs, so it is
assigned by thirds (easy / medium / hard) so the default exam blueprint works.
"""
from __future__ import annotations

import re
# Thai letter choices → 0-based index (also accept Latin A–D)
_CHOICE_LETTER = {
    "ก": 0, "ข": 1, "ค": 2, "ง": 3, "จ": 4,
    "A": 0, "B": 1, "C": 2, "D": 3, "E": 4,
    "a": 0, "b": 1, "c": 2, "d": 3, "e": 4,
}

# Common Thai combining-mark corruption from older PDF encodings
_THAI_FIXES = (
    ("คํา", "คำ"),
    ("ที$", "ที่"),
    ("เพื$อ", "เพื่อ"),
    ("เพิ$ม", "เพิ่ม"),
    ("เพิ$ง", "เพิ่ง"),
    ("ซึ$ง", "ซึ่ง"),
    ("นั\"น", "นั้น"),
    ("นี\"", "นี้"),
    ("ขึ\"น", "ขึ้น"),
    ("ตั\"ง", "ตั้ง"),
    ("ทั\"ง", "ทั้ง"),
    ("เบี\"ย", "เบี้ย"),
    ("ชี\"", "ชี้"),
    ("ซื$อ", "ซื้อ"),
    ("ซื\"อ", "ซื้อ"),
    ("เรื$อง", "เรื่อง"),
    ("เงื$อน", "เงื่อน"),
    ("หมั$น", "หมั่น"),
    ("สมํ$า", "สม่ำ"),
    ("เปลี$ยน", "เปลี่ยน"),
    ("อื$น", "อื่น"),
    ("สิ$ง", "สิ่ง"),
    ("หนึ$ง", "หนึ่ง"),
    ("เกี$ยว", "เกี่ยว"),
    ("เมื$อ", "เมื่อ"),
    ("ชื$อ", "ชื่อ"),
    ("เนื$อง", "เนื่อง"),
    ("ขี$", "ขี่"),
    ("ทั$", "ทั่"),
    ("จํา", "จำ"),
    ("ทํา", "ทำ"),
    ("นํา", "นำ"),
    ("ตํา", "ตำ"),
)

_HEADER_RE = re.compile(
    r"(?m)^(?:แนวข้อสอบ.*|Page\s*\d+\s*|www\.\S+.*|"
    r".*srikrungbroker.*|Update\s+\d{1,2}/\d{1,2}/\d{2,4}\s*)$"
)
_SUBJECT_FOOTER_RE = re.compile(r"(?m)^คำถามวิชา\s*.+$")
_ANSWER_START_RE = re.compile(r"คำตอบ\s*:?\s*วิชา\s*(.+)")
_ANSWER_SUBJECT_RE = re.compile(r"คำตอบ\s*:?\s*วิชา\s*(.+)")
_Q_START_RE = re.compile(r"(?m)^(\d+)\.\s+")
_CHOICE_RE = re.compile(
    r"(?m)^\s*\(\s*\)\s*([กขคงจA-Ea-e])\.\s*"
)


class ExamPdfError(Exception):
    """Raised when a PDF cannot be turned into questions."""

    def __init__(self, code: str, message: str):
        super().__init__(message)
        self.code = code
        self.message = message


def _err(code: str, message: str, cause: BaseException | None = None):
    exc = ExamPdfError(code, message)
    if cause is not None:
        raise exc from cause
    raise exc


def _normalize_thai(text: str) -> str:
    for a, b in _THAI_FIXES:
        text = text.replace(a, b)
    # leftover control-ish glyph replacements from broken fonts
    text = text.replace("$", "").replace("\"", "").replace("", "").replace("!", "")
    return text


def _extract_text(data: bytes) -> str:
    try:
        from . import pdf_text
    except ImportError as exc:
        _err("pdf_unsupported", "ยังไม่ได้ติดตั้ง pdfminer.six", exc)

    try:
        # Not pdfminer's own extract_text: it splits Thai vowels and tone marks
        # onto lines of their own. See pdf_text.py.
        raw = pdf_text.extract_text(data)
    except Exception as exc:  # noqa: BLE001 — surfaced as ExamPdfError
        _err("bad_pdf", f"เปิดไฟล์ PDF ไม่ได้: {exc}", exc)

    text = _normalize_thai(raw or "")
    if not text.strip():
        _err("bad_pdf", "ดึงข้อความจาก PDF ไม่ได้ (อาจเป็นสแกนภาพ — ต้องมี text layer)")
    return text


def _guess_title(text: str) -> str | None:
    for line in text.splitlines():
        line = line.strip()
        if line.startswith("แนวข้อสอบ"):
            # drop trailing site / phone noise
            line = re.split(r"\s*:\s*www\.|\s*Page\s*\d+", line)[0].strip()
            return line[:160] or None
    return None


def _parse_answer_keys(answer_text: str) -> list[dict]:
    """Return [{subject, answers: {qnum: idx}}] in document order."""
    sections: list[dict] = []
    current: dict | None = None
    # Tokenize: either "12 ก" on one line, or alternating number/letter lines
    tokens: list[str] = []
    for raw in answer_text.splitlines():
        line = raw.strip()
        if not line:
            continue
        m_sub = _ANSWER_SUBJECT_RE.match(line)
        if m_sub:
            if current is not None:
                sections.append(current)
            current = {"subject": m_sub.group(1).strip(), "answers": {}, "_pending": None}
            continue
        if current is None:
            continue
        tokens = line.replace(",", " ").split()
        for tok in tokens:
            if re.fullmatch(r"\d+", tok):
                current["_pending"] = int(tok)
            elif tok in _CHOICE_LETTER and current.get("_pending") is not None:
                current["answers"][current["_pending"]] = _CHOICE_LETTER[tok]
                current["_pending"] = None
            elif re.fullmatch(r"\d+[กขคงจA-Ea-e]", tok):
                # compact "12ก"
                n, letter = int(tok[:-1]), tok[-1]
                current["answers"][n] = _CHOICE_LETTER[letter]
                current["_pending"] = None
    if current is not None:
        sections.append(current)
    for s in sections:
        s.pop("_pending", None)
    return sections


def _strip_noise(body: str) -> str:
    body = _HEADER_RE.sub("", body)
    body = _SUBJECT_FOOTER_RE.sub("", body)
    return body


def _split_choices(block: str) -> tuple[str, list[str]]:
    """Split a question block into stem + choice texts (ordered กขคง / A–D)."""
    matches = list(_CHOICE_RE.finditer(block))
    # Keep only a single contiguous cluster starting at the first ก/A.
    start_i = next(
        (i for i, m in enumerate(matches) if _CHOICE_LETTER.get(m.group(1), -1) == 0),
        None,
    )
    if start_i is None:
        return block.strip(), []
    cluster = [matches[start_i]]
    for m in matches[start_i + 1:]:
        idx = _CHOICE_LETTER.get(m.group(1), -1)
        if idx == 0:
            break
        if idx < 0:
            break
        cluster.append(m)
    if len(cluster) < 2:
        return block.strip(), []

    stem = block[: cluster[0].start()].strip()
    choices_by_idx: dict[int, str] = {}
    for i, m in enumerate(cluster):
        idx = _CHOICE_LETTER[m.group(1)]
        text_start = m.end()
        text_end = cluster[i + 1].start() if i + 1 < len(cluster) else len(block)
        # If trailing junk after ง contains another question, trim
        if i + 1 == len(cluster):
            stop = _Q_START_RE.search(block[text_start:])
            if stop:
                text_end = text_start + stop.start()
        text = block[text_start:text_end].strip()
        text = re.sub(r"\s+", " ", text)
        if text:
            choices_by_idx[idx] = text
    if len(choices_by_idx) < 2:
        return stem, []
    max_idx = max(choices_by_idx)
    choices = [choices_by_idx[i] for i in range(max_idx + 1) if i in choices_by_idx]
    return stem, choices


def _parse_questions_flat(body: str) -> list[dict]:
    """Parse MCQs; merge nested ``1. 2. 3.`` list markers into the parent stem."""
    body = _strip_noise(body)
    starts = list(_Q_START_RE.finditer(body))
    questions: list[dict] = []
    pending_num: int | None = None
    pending_stem = ""

    for i, m in enumerate(starts):
        num = int(m.group(1))
        end = starts[i + 1].start() if i + 1 < len(starts) else len(body)
        block = body[m.end():end]
        stem_part, choices = _split_choices(block)
        stem_part = re.sub(r"\s+", " ", stem_part).strip()

        if len(choices) < 2:
            # Nested list item (or header) — hold as stem prefix for the next real MCQ.
            if pending_num is None:
                pending_num = num
                pending_stem = stem_part
            else:
                piece = f"{num}. {stem_part}".strip()
                pending_stem = f"{pending_stem} {piece}".strip()
            continue

        qnum = pending_num if pending_num is not None else num
        stem = f"{pending_stem} {stem_part}".strip() if pending_stem else stem_part
        pending_num = None
        pending_stem = ""
        if len(stem) < 3:
            continue
        questions.append({"num": qnum, "text": stem, "choices": choices})

    return questions


def _match_to_answers(
    flat: list[dict], answer_sections: list[dict]
) -> tuple[list[dict], int]:
    """Pair questions to answer-key sections in document order.

    Handles subjects that continue numbering (e.g. 79–93 after 1–78) and
    subjects that restart at 1.
    """
    out: list[dict] = []
    missing = 0
    qi = 0
    for si, sec in enumerate(answer_sections):
        answers: dict[int, int] = sec["answers"]
        if not answers:
            continue
        wanted = sorted(answers)
        subject = sec.get("subject") or f"ส่วนที่ {si + 1}"
        slug = _slug_subject(subject, si)
        for num in wanted:
            found = None
            for j in range(qi, len(flat)):
                if flat[j]["num"] == num:
                    found = j
                    break
            if found is None:
                missing += 1
                continue
            q = flat[found]
            ans = answers[num]
            if not (0 <= ans < len(q["choices"])):
                missing += 1
                qi = found + 1
                continue
            out.append({
                "id": f"{slug}-{num:03d}",
                "difficulty": "medium",
                "text": q["text"],
                "choices": q["choices"],
                "answer": ans,
                "explanation": None,
            })
            qi = found + 1
    return out, missing


def _assign_difficulty(index: int, total: int) -> str:
    if total <= 0:
        return "medium"
    # Prefer a usable spread for the default 5/3/2 blueprint.
    third = max(1, total // 3)
    if index < third:
        return "easy"
    if index < 2 * third:
        return "medium"
    return "hard"


def _slug_subject(subject: str, index: int) -> str:
    # Keep Thai/ASCII letters and digits; fall back to S01.
    cleaned = re.sub(r"[^\wก-๙]+", "-", subject, flags=re.UNICODE).strip("-")
    if not cleaned:
        return f"S{index + 1:02d}"
    return cleaned[:40]


def parse_pdf_text(text: str) -> tuple[str | None, str | None, str | None, list[dict]]:
    """Parse already-extracted PDF text → (course_id, title, note, questions)."""
    text = _normalize_thai(text)
    title = _guess_title(text)

    ans_match = _ANSWER_START_RE.search(text)
    if not ans_match:
        _err("bad_pdf", "ไม่พบหัวข้อเฉลยใน PDF (คาดหวังบรรทัดแบบ 'คำตอบ : วิชา …')")
    body = text[: ans_match.start()]
    answer_text = text[ans_match.start():]
    answer_sections = _parse_answer_keys(answer_text)
    if not answer_sections or not any(s["answers"] for s in answer_sections):
        _err("bad_pdf", "อ่านตารางเฉลยจาก PDF ไม่ได้")

    flat = _parse_questions_flat(body)
    if not flat:
        _err("bad_pdf", "ไม่พบข้อสอบแบบเลือกตอบ (ก/ข/ค/ง) ใน PDF")

    questions, missing = _match_to_answers(flat, answer_sections)
    if not questions:
        _err("bad_pdf", "จับคู่ข้อสอบกับเฉลยไม่ได้")

    # Deduplicate ids if subjects slug-collide
    seen: set[str] = set()
    for i, q in enumerate(questions):
        base = q["id"]
        if base in seen:
            q["id"] = f"{base}-{i + 1}"
        seen.add(q["id"])

    total = len(questions)
    for i, q in enumerate(questions):
        q["difficulty"] = _assign_difficulty(i, total)

    note = (
        f"นำเข้าจาก PDF · {len(answer_sections)} วิชา · {total} ข้อ"
        + (f" · ข้าม {missing} ข้อที่ไม่มีเฉลยจับคู่" if missing else "")
    )
    return None, title, note, questions


def parse_pdf(data: bytes) -> tuple[str | None, str | None, str | None, list[dict]]:
    """Parse an uploaded PDF file into a question bank."""
    if not data:
        _err("bad_pdf", "ไฟล์ PDF ว่าง")
    if not data.startswith(b"%PDF"):
        _err("bad_pdf", "ไฟล์ไม่ใช่ PDF")
    return parse_pdf_text(_extract_text(data))
