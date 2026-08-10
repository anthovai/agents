"""Extract text from a PDF in a way that survives Thai.

pdfminer's own line grouping breaks Thai. Vowels and tone marks are drawn as
separate glyphs above or below the consonant they belong to, at a different
baseline, so pdfminer puts them on their own line — and often orders that line
before the one it belongs to. "ระบบคุมสอบยืนยันตัวตนของผู้เรียน" comes back as
"ระบบคุมสอบยืนยันตัวตนของผู" with "้เรียน" stranded on a line of its own.

No combination of LAParams fixes it; the grouping happens before those apply.
So the characters are regrouped here: cluster the base characters into lines by
baseline, attach each mark to the line it visually sits on, and read every line
left to right.
"""
from __future__ import annotations

import io

# Thai marks that do not advance the pen: they are drawn over or under the
# preceding consonant and therefore land on their own pdfminer "line".
_COMBINING = {"ั"}                                             # MAI HAN AKAT
_COMBINING |= {chr(code) for code in range(0x0E34, 0x0E3B)}    # SARA I .. PHINTHU
_COMBINING |= {chr(code) for code in range(0x0E47, 0x0E4F)}    # MAITAIKHU .. YAMAKKAN


def _is_combining(text: str) -> bool:
    return len(text) == 1 and text in _COMBINING


def extract_text(data: bytes) -> str:
    """Return the document's text, one visual line per line."""
    from pdfminer.high_level import extract_pages
    from pdfminer.layout import LTChar

    pages = []
    # laparams=None disables layout analysis completely. That matters: with it
    # on, pdfminer has already grouped the marks onto lines of their own before
    # we see them, and the order characters arrive in is that grouping rather
    # than the order they were drawn. Off, they arrive in content-stream order,
    # which is the order the document actually writes them — and that is the
    # only reliable signal for which consonant a mark belongs to.
    for layout in extract_pages(io.BytesIO(data), laparams=None):
        chars = [item for item in _walk(layout) if isinstance(item, LTChar)]
        if chars:
            pages.append(_rebuild(list(enumerate(chars))))

    return "\n".join(pages)


def _walk(element):
    yield element
    for child in getattr(element, "_objs", []) or []:
        yield from _walk(child)


def _rebuild(indexed: list) -> str:
    """Group characters into visual lines and read each one left to right.

    Ties on x are broken by the order the characters were drawn. That is not a
    detail: a Thai tone mark has zero advance width, so it shares its x with
    the character that follows it, and sorting on x alone turns "ข้อ" into
    "ขอ้".
    """
    bases = [(i, c) for i, c in indexed if not _is_combining(c.get_text())]
    marks = [(i, c) for i, c in indexed if _is_combining(c.get_text())]

    if not bases:
        return ""

    # Tolerance scaled to the text: a fixed number of points would split lines
    # in a large font and merge them in a small one.
    heights = sorted(c.height for _, c in bases)
    tolerance = max(1.0, heights[len(heights) // 2] * 0.4)

    lines: list[dict] = []
    for index, char in sorted(bases, key=lambda pair: (-pair[1].y0, pair[1].x0)):
        for line in lines:
            if abs(line["y"] - char.y0) <= tolerance:
                line["chars"].append((index, char))
                break
        else:
            lines.append({"y": char.y0, "chars": [(index, char)]})

    # Each mark joins whichever line it visually sits on — the nearest by
    # vertical distance, which for an above-mark is the line below it and for a
    # below-mark the line above.
    #
    # Attaching each mark to "the character it was drawn after" was tried and is
    # worse: after layout analysis the order characters arrive in is not the
    # order they were drawn, so marks landed on the wrong consonant or at the
    # end of the line entirely.
    for index, mark in marks:
        centre = (mark.y0 + mark.y1) / 2
        nearest = min(lines, key=lambda line: abs(line["y"] - centre))
        nearest["chars"].append((index, mark))

    out = []
    for line in sorted(lines, key=lambda line: -line["y"]):
        ordered = sorted(line["chars"], key=lambda pair: (round(pair[1].x0, 1), pair[0]))
        out.append("".join(char.get_text() for _, char in ordered).rstrip())

    return "\n".join(out)
