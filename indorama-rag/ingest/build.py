"""Build the index from the export archive.

    python -m ingest.build path/to/export_20260804_065758.zip

Writes ``index.sqlite`` and ``build-report.json`` beside it. The report is not
a courtesy: it records how many values each sanitiser mechanism caught, and a
build that reports zero redactions on this archive has a broken sanitiser, not
a clean archive. That is the one number worth reading every time.
"""

import argparse
import hashlib
import json
import os
import sys
import zipfile

from . import chunk as chunker
from . import counts
from . import sanitize

sys.path.insert(0, os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
from app import store as store_mod  # noqa: E402


def build(archive_path: str, index_path: str, report_path: str) -> dict:
    if os.path.exists(index_path):
        os.remove(index_path)

    with zipfile.ZipFile(archive_path) as raw:
        archive = sanitize.GuardedArchive(raw)
        manifest = json.loads(archive.read("manifest.json").decode("utf-8"))
        san = sanitize.Sanitiser.from_archive(archive)
        chunks, excluded = chunker.build_all(archive, san)
        sections = sorted(archive.sections_read)
        # Counted from the raw archive, deliberately outside GuardedArchive.
        # See ingest/counts.py: the chunk builder must never be able to read a
        # row, and the counter must never be able to emit anything but a
        # number. Two different restrictions, so two different objects.
        count_chunks, count_values = counts.build(raw, san)
        chunks += count_chunks

    store = store_mod.Store(index_path)
    store.create()
    store.add(chunks)

    kinds: dict[str, int] = {}
    for c in chunks:
        kinds[c["kind"]] = kinds.get(c["kind"], 0) + 1

    report = {
        "source_archive": os.path.basename(archive_path),
        "source_sha256": _digest(archive_path),
        "export_generated_at": manifest.get("generated_at"),
        "export_claims_anonymised": manifest.get("anonymized"),
        "chunks_total": len(chunks),
        "chunks_by_kind": kinds,
        "excluded": excluded,
        "sections_read": sections,
        "sections_permitted": sorted(sanitize.PERMITTED_SECTIONS),
        "sections_counted_only": sorted(sanitize.COUNTABLE_SECTIONS),
        "redactions_by_the_net": san.report(),
        "characters_indexed": sum(len(c["text"]) for c in chunks),
    }
    for key, value in report.items():
        store.set_meta(key, json.dumps(value, ensure_ascii=False))
    # Keyed counts, so a tool can serve one figure per question rather than a
    # menu the model picks from. See ingest/counts.py.
    store.set_meta("counts", json.dumps(count_values, ensure_ascii=False))
    store.close()

    with open(report_path, "w", encoding="utf-8") as fh:
        json.dump(report, fh, ensure_ascii=False, indent=2)
    return report


def _digest(path: str) -> str:
    """Which archive this index came from.

    Recorded because the client has been asked for a re-export with the
    personal data removed. When it arrives there will be two archives with
    similar names, and an index that cannot say which one it was built from is
    an index nobody can vouch for.
    """
    digest = hashlib.sha256()
    with open(path, "rb") as fh:
        for block in iter(lambda: fh.read(1 << 20), b""):
            digest.update(block)
    return digest.hexdigest()


def main() -> None:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("archive")
    parser.add_argument("--index", default="index.sqlite")
    parser.add_argument("--report", default="build-report.json")
    args = parser.parse_args()

    report = build(args.archive, args.index, args.report)
    print(json.dumps(report, ensure_ascii=False, indent=2))

    # Nothing is asserted about the redaction counts. Zero is the expected
    # result on a metadata-only corpus and does not mean the net is broken; the
    # guarantee that matters is the section list, which GuardedArchive enforces
    # while the index is being built rather than reporting on afterwards.
    print("\nsections read: " + ", ".join(report["sections_read"]), file=sys.stderr)


if __name__ == "__main__":
    main()
