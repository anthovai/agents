"""Keep personal data out of the index — by construction first, by filter second.

The archive we were given carries 864 real corporate email addresses despite
its own manifest declaring ``"anonymized": true``. An index built carelessly
from it would scatter those addresses across chunks that get pasted into
prompts and sent to a model, and no later filter can take that back.

The first draft of this module tried to solve that by filtering: drop the
columns the data dictionary marks ``sensitive``, refuse the log tables, sweep
the rest with regexes. It counted what it caught, ran against the real archive,
and caught nothing — which was not a bug in the filter but a fact about the
corpus. The assistant answers questions about how the system is *built*, so it
is built from schema, routes and source structure. It never reads a row. Every
address in the archive is in row data.

So the guarantee is stated the way it actually holds:

**Primary — by construction.** Only four sections of the archive may be opened,
and :class:`GuardedArchive` raises on any attempt to open another. The sections
holding personal data are not on the list, so no filter has to be trusted to
notice them. This is checkable by reading twenty lines rather than by auditing
what a regex happened to match.

**Secondary — a net.** :meth:`Sanitiser.scrub` still runs over every chunk. The
permitted sections are metadata, but metadata is written by people: a support
address in a config comment, an IP in a route, a token pasted into a library
docstring. The net costs nothing and covers the case where the archive contains
something its own structure did not lead us to expect.

The two other things this module knows — which columns are classified sensitive
and which tables are logs — turned out to be worth more as *content* than as
policy. "คอลัมน์ไหนเก็บข้อมูลส่วนบุคคล" is a question the assistant should
answer, so the classification is written into the chunks. See
:mod:`ingest.chunk`.
"""

import json
import re

# The only sections of the archive that may be read.
#
# Everything holding row data — ``learning/``, ``master/``, ``activities/``,
# ``integrations/``, ``assessments/`` — is absent, and that absence is the
# whole privacy argument. ``knowledge/`` is absent for a different reason: it
# is a derived restatement of ``source_code/`` and ``schema/``, so indexing it
# would duplicate the corpus rather than extend it.
PERMITTED_SECTIONS = frozenset({
    "manifest.json",
    "schema",
    "dictionary",
    "api",
    "source_code",
})

# Named so the chunk builder can say, in the chunk about a log table, that its
# rows are deliberately not indexed. Nothing here is a filter any more — no
# section that would contain these rows can be opened in the first place.
LOG_AND_PERSONAL_TABLES = frozenset({
    "ci_sessions",
    "tbl_lg",
    "tbl_activity_log",
    "tbl_log_learn_via_channel",
    "tbl_loglogin",
    "tbl_logpassword",
    "tbl_filelogs",
    "tbl_welcomelogin",
    "email_queue",
    "tbl_followup_email_queue",
    "tbl_notifications",
    "tbl_contact",
    "tbl_contactus_recipients",
    "tbl_users",
    "tbl_users_zoom",
    "tbl_certificate_log",
    "tbl_skillsoft_learning_activity_import",
})

# Deliberately blunt. A false positive costs one redacted string in a chunk
# about database columns; a false negative puts an address in a prompt.
_PATTERNS = [
    ("email", re.compile(r"[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}")),
    ("ipv4", re.compile(r"\b(?:\d{1,3}\.){3}\d{1,3}\b")),
    # Bearer/JWT-shaped material. The archive includes an SSO configuration and
    # a token library; a live secret in an index is a different incident from a
    # leaked address, and cheaper to prevent than to explain.
    ("token", re.compile(r"\beyJ[A-Za-z0-9_-]{10,}\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+")),
]


# Sections that may be *walked to count rows* but never read into a chunk.
#
# A deliberate second door, narrower than the first. The assistant was asked to
# answer with figures — "there are N of these" — which cannot come from schema
# alone. A count is not the rows: ingest/counts.py opens these, walks them, and
# returns integers, so nothing that could identify anybody survives the walk.
#
# GuardedArchive still refuses them, because it is what the chunk builder holds
# and the chunk builder must never see a row. The counter uses the raw archive
# and is trusted instead by being short enough to read — its every return value
# is an int.
COUNTABLE_SECTIONS = frozenset({"learning", "master", "assessments", "activities"})


class SectionRefused(Exception):
    """Raised when the builder asks for a section it is not allowed to read."""


class GuardedArchive:
    """A zip file that will only hand over the permitted sections.

    Wrapping the archive rather than checking at each call site is deliberate:
    a check that has to be remembered is a check that will eventually be
    forgotten, and the person who forgets it will be adding a chunk type under
    time pressure, months from now, without having read this file.
    """

    def __init__(self, archive):
        self._archive = archive
        self.sections_read: set[str] = set()

    def read(self, name: str) -> bytes:
        section = name.split("/")[0]
        if section not in PERMITTED_SECTIONS:
            raise SectionRefused(
                f"{name!r} is in section {section!r}, which is not indexed. "
                "It holds row data, and the row data in this archive contains "
                "personal information. See ingest/sanitize.py.")
        self.sections_read.add(section)
        return self._archive.read(name)


class Sanitiser:
    """The net, plus the classification the chunk builder writes into chunks."""

    def __init__(self, dictionary: list[dict]):
        self.sensitive: set[tuple[str, str]] = {
            (row["table"].lower(), row["column"].lower())
            for row in dictionary
            if row.get("classification") == "sensitive"
        }
        self.counts: dict[str, int] = {name: 0 for name, _ in _PATTERNS}

    @classmethod
    def from_archive(cls, archive: GuardedArchive) -> "Sanitiser":
        raw = archive.read("dictionary/data_dictionary.json").decode("utf-8")
        return cls(json.loads(raw))

    def scrub(self, text: str) -> str:
        """Replace anything that looks personal, wherever it turned up."""
        for name, pattern in _PATTERNS:
            text, hits = pattern.subn(f"[{name} redacted]", text)
            self.counts[name] += hits
        return text

    def report(self) -> dict:
        return dict(self.counts)
