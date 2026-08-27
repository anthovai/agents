"""Are the files shared with face-service still the same code?

face_engine.py and liveness.py are copied rather than imported, so a fix
applied to one and not the other leaves a customer running code we have
already corrected — and nothing would notice, because both copies pass their
own tests either way.

Module docstrings are excluded from the comparison on purpose. The customer's
copy describes a service that stands alone; face-service's describes one that
sits behind Moodle. Both are accurate where they are, and neither is code.

    python deliverables/check-drift.py

Exit status is 1 if anything drifted, so it can gate a release.
"""
from __future__ import annotations

import ast
import pathlib
import sys

HERE = pathlib.Path(__file__).resolve().parent
MINE = HERE.parent / "face-service"
THEIRS = HERE / "face-recognition"

SHARED = [
    "app/face_engine.py",
    "app/liveness.py",
    "app/__init__.py",
    "tests/test_face_engine.py",
    "tests/test_calibration.py",
    "tests/test_thresholds.py",
]


def body(path: pathlib.Path) -> str:
    """The file's source with its module docstring removed.

    Parsed rather than pattern-matched: a docstring can be single- or
    triple-quoted and can contain anything, and a regex that mostly works here
    would fail on the one file somebody had just edited.
    """
    source = path.read_text(encoding="utf-8")
    tree = ast.parse(source)
    if (tree.body and isinstance(tree.body[0], ast.Expr)
            and isinstance(tree.body[0].value, ast.Constant)
            and isinstance(tree.body[0].value.value, str)):
        lines = source.splitlines()
        return "\n".join(lines[tree.body[0].end_lineno:]).strip()
    return source.strip()


def main() -> int:
    drifted = False
    for name in SHARED:
        mine, theirs = MINE / name, THEIRS / name
        if not mine.exists() or not theirs.exists():
            print(f"MISSING  {name}")
            drifted = True
        elif body(mine) == body(theirs):
            print(f"same     {name}")
        else:
            print(f"DRIFTED  {name}")
            drifted = True

    if drifted:
        print("\nThe two copies disagree on code, not just wording.")
        print("Copy the corrected file across before shipping.")
        return 1
    print("\nEvery shared file matches. Safe to ship.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
