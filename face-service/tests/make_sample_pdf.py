"""Build a sample Thai licence-exam PDF for testing the importer.

A real customer pack cannot go in the repository — it is somebody's copyright
and it names a real training provider. This generates one in the same shape:
numbered questions, "( ) ก." choices, and an answer key headed "คำตอบ : วิชา".

It is a fixture, not a substitute for a real pack. Real packs carry broken font
encodings that the parser has specific repairs for (see _THAI_FIXES), and a
cleanly generated file cannot reproduce those. Those repairs are covered by the
text-level tests instead, which is where they belong.

Needs reportlab (BSD) and a Thai font. Sarabun is used because it is under the SIL
Open Font License, covers both Thai and Latin, and is the font these packs are
usually set in; a system font could not be redistributed.

    python tests/make_sample_pdf.py tests/sample-exam.pdf
"""
from __future__ import annotations

import sys
import urllib.request
from pathlib import Path

# Sarabun rather than Noto Sans Thai: Noto Sans Thai has no Latin glyphs, so
# every digit and full stop came out as .notdef and the extracted text had
# no question numbers left to parse. Sarabun covers both scripts and is the
# font these packs are usually set in anyway.
FONT_URL = "https://raw.githubusercontent.com/google/fonts/main/ofl/sarabun/Sarabun-Regular.ttf"
FONT_FILE = Path(__file__).parent / "Sarabun-Regular.ttf"

QUESTIONS = [
    ("ระบบคุมสอบยืนยันตัวตนของผู้เรียนด้วยวิธีใด",
     ["ใบหน้า", "ลายนิ้วมือ", "รหัสผ่านอย่างเดียว", "เบอร์โทรศัพท์"], 0),
    ("ถ้าผู้เรียนออกจากหน้าต่างเรียนระหว่างสอบ ระบบจะทำอย่างไร",
     ["บันทึกเหตุการณ์ไว้เป็นหลักฐาน", "ไม่ทำอะไรเลย", "ลบคำตอบทั้งหมด", "เพิ่มคะแนนให้"], 0),
    ("การตรวจ liveness มีไว้เพื่อวัตถุประสงค์ใด",
     ["กันการใช้ภาพถ่ายแทนคนจริง", "วัดความเร็วอินเทอร์เน็ต",
      "ตรวจสอบไวยากรณ์", "นับจำนวนข้อสอบ"], 1),
    ("ข้อมูลชีวมิติได้รับความคุ้มครองตามกฎหมายฉบับใด",
     ["พ.ร.บ. จราจรทางบก", "พ.ร.บ. คุ้มครองข้อมูลส่วนบุคคล",
      "พ.ร.บ. ลิขสิทธิ์", "พ.ร.บ. ศุลกากร"], 1),
    ("หลักฐานการคุมสอบควรถูกลบเมื่อใด",
     ["ไม่ต้องลบเลย", "ทุกวันจันทร์",
      "เมื่อพ้นระยะเก็บรักษาที่กำหนด", "เมื่อผู้เรียนร้องขอเท่านั้น"], 2),
    ("ผู้เรียนมีสิทธิใดตามกฎหมายคุ้มครองข้อมูลส่วนบุคคล",
     ["ขอแก้คะแนนสอบ", "ขอดูข้อมูลของผู้เรียนคนอื่น",
      "ขอปิดระบบคุมสอบ", "ขอเข้าถึงและขอให้ลบข้อมูลของตน"], 3),
]

LETTERS = ["ก", "ข", "ค", "ง"]


def ensure_font() -> Path:
    if FONT_FILE.is_file():
        return FONT_FILE
    print(f"fetching {FONT_URL}")
    with urllib.request.urlopen(FONT_URL, timeout=120) as response:
        FONT_FILE.write_bytes(response.read())
    return FONT_FILE


def build_lines() -> list[str]:
    lines = ["แนวข้อสอบระบบคุมสอบ ชุดทดสอบ", ""]

    for index, (stem, choices, _) in enumerate(QUESTIONS, start=1):
        lines.append(f"{index}. {stem}")
        for letter, choice in zip(LETTERS, choices):
            # The "( ) ก." marker these packs use for an unticked choice.
            lines.append(f"( ) {letter}. {choice}")
        lines.append("")

    lines.append("คำตอบ : วิชา ระบบคุมสอบ")
    for index, (_, _, answer) in enumerate(QUESTIONS, start=1):
        lines.append(f"{index} {LETTERS[answer]}")

    return lines


def build_pdf(target: Path, lines: list[str]) -> None:
    from reportlab.lib.pagesizes import A4
    from reportlab.pdfbase import pdfmetrics
    from reportlab.pdfbase.ttfonts import TTFont
    from reportlab.pdfgen import canvas

    pdfmetrics.registerFont(TTFont("Sarabun", str(ensure_font())))

    width, height = A4
    pdf = canvas.Canvas(str(target), pagesize=A4)
    pdf.setFont("Sarabun", 11)

    y = height - 50
    for line in lines:
        if y < 60:
            pdf.showPage()
            pdf.setFont("Sarabun", 11)
            y = height - 50
        pdf.drawString(40, y, line)
        y -= 16

    pdf.save()


def main() -> int:
    target = Path(sys.argv[1] if len(sys.argv) > 1 else "tests/sample-exam.pdf")
    target.parent.mkdir(parents=True, exist_ok=True)
    build_pdf(target, build_lines())
    print(f"wrote {target} ({target.stat().st_size} bytes, {len(QUESTIONS)} questions)")
    return 0


if __name__ == "__main__":
    sys.exit(main())
