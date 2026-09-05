"""The index, and how a question is turned into a query against it.

SQLite FTS5, not a vector database, and the reason is the corpus rather than a
preference. Almost every question this assistant will be asked names something
exactly: ``tbl_contentEnroll``, ``Authorization_Token``, ``routes.php``. Dense
embeddings are built to put *different* words that mean the same thing near
each other, which is the opposite of what an identifier needs — they blur
``tbl_content`` into ``tbl_contentLang`` into ``tbl_contentEnroll``, and the
answer that comes back names the wrong table with total confidence. Lexical
matching cannot make that mistake: the token either appears or it does not.

The corpus is also about a quarter of a megabyte. A vector store would be
operational weight — a service to run, a model to pin, a dimension to migrate —
bought to solve a recall problem this corpus does not have.

**There is no Thai in the index.** An earlier version indexed Thai character
trigrams alongside the Latin tokens, on the reasoning that Thai has no spaces
and needs its own tokenisation. Counting what Thai the corpus actually contains
retired that: 28 distinct runs, every one a label written by this codebase, and
none from the export. The trigrams were matching our own scaffolding, which is
why an off-topic Thai question retrieved three tables. Thai now reaches the
index through :mod:`app.lexicon`, which maps a term to the Latin anchors and
chunk kinds it means — a question written in Thai searches for the English the
corpus is actually made of.
"""

import sqlite3
import threading

from . import lexicon, scope

# ``_`` joins identifiers; without it FTS5 splits every table name in the
# database into fragments that match each other.
_TOKENIZER = "unicode61 tokenchars '_'"

_SCHEMA = f"""
CREATE TABLE chunk (
    chunk_id TEXT PRIMARY KEY,
    kind     TEXT NOT NULL,
    ref      TEXT NOT NULL,
    title    TEXT NOT NULL,
    text     TEXT NOT NULL
);
CREATE VIRTUAL TABLE chunk_fts USING fts5(
    title, body,
    content = '',
    tokenize = "{_TOKENIZER}"
);
CREATE TABLE build_meta (
    key   TEXT PRIMARY KEY,
    value TEXT NOT NULL
);
"""


class Store:
    """One connection per thread, opened on first use.

    FastAPI runs a synchronous endpoint in a worker thread, and a thread is not
    guaranteed to be the one that opened the database — sqlite3 raises rather
    than risk it, which is how this surfaced: every request after startup
    failed with a thread-identity error while the tests, all single-threaded,
    passed.

    Thread-local connections rather than ``check_same_thread=False`` and a
    lock. The index is a read-only build artefact, so connections share nothing
    that needs coordinating, and serialising every read behind one lock would
    be a bottleneck invented to work around a problem that has a direct answer.
    """

    def __init__(self, path: str):
        self.path = path
        self._local = threading.local()

    @property
    def db(self) -> sqlite3.Connection:
        conn = getattr(self._local, "conn", None)
        if conn is None:
            conn = sqlite3.connect(self.path)
            conn.row_factory = sqlite3.Row
            self._local.conn = conn
        return conn

    def create(self, tokenizer: str = _TOKENIZER) -> None:
        """Build an empty index.

        The tokenizer is a parameter because the two corpora this code serves
        need different ones, and the difference is not a preference.

        ``unicode61`` — the default, and right for the developer corpus, which
        is identifiers: ``tbl_contentEnroll`` must be one token or a search for
        it matches every table whose name starts the same way.

        ``trigram`` — right for a corpus written in Thai. Thai is written
        without spaces, so unicode61 makes a whole sentence into a single
        token: measured on one course title, "การช่วยชีวิต" at the start could
        be found by prefix and "ฉุกเฉิน" in the middle could not be found at
        all, by any query. A learner asking about emergencies would be told
        the course does not exist. Trigrams match substrings, which is what a
        language with no word boundaries needs.
        """
        self.db.executescript(_SCHEMA.replace(_TOKENIZER, tokenizer))

    def add(self, chunks: list[dict]) -> None:
        for chunk in chunks:
            self.db.execute(
                "INSERT INTO chunk (chunk_id, kind, ref, title, text) VALUES (?,?,?,?,?)",
                (chunk["chunk_id"], chunk["kind"], chunk["ref"],
                 chunk["title"], chunk["text"]))
            # The reference goes into the title column alongside the title.
            # Several chunks have a Thai title and a Latin ref — the foreign
            # key chunk is titled "ความสัมพันธ์ระหว่างตารางที่ประกาศไว้" and
            # referenced ``foreign_keys`` — and with only the title indexed,
            # the anchor the lexicon points at matched nothing at all.
            self.db.execute(
                "INSERT INTO chunk_fts (rowid, title, body) VALUES (?,?,?)",
                (self._rowid(chunk["chunk_id"]),
                 f"{chunk['title']} {chunk['ref']}", chunk["text"]))
        self.db.commit()

    def _rowid(self, chunk_id: str) -> int:
        return self.db.execute(
            "SELECT rowid FROM chunk WHERE chunk_id = ?", (chunk_id,)).fetchone()[0]

    def set_meta(self, key: str, value: str) -> None:
        self.db.execute("INSERT OR REPLACE INTO build_meta VALUES (?,?)", (key, value))
        self.db.commit()

    def meta(self) -> dict:
        return {r["key"]: r["value"] for r in self.db.execute("SELECT * FROM build_meta")}

    def count(self) -> int:
        return self.db.execute("SELECT count(*) FROM chunk").fetchone()[0]

    def vocabulary(self) -> set[str]:
        """Every name the corpus actually contains.

        Built from the chunk references — table names, file paths, controller
        names — which is exactly the set an answer is allowed to draw on. Read
        once at startup: the index is a build artefact and does not change
        while the service is running.
        """
        return {r["ref"] for r in self.db.execute("SELECT ref FROM chunk")}

    def named(self, wanted: list[str]) -> list[dict]:
        """Chunks the question named outright.

        Ranking is the wrong instrument for this. Asked "โครงสร้างของ
        tbl_certificate", an earlier version scored ``tbl_lg`` above the table
        actually named. No weighting fixes that honestly, so the two signals
        are separated instead of balanced: an exact identifier is treated as
        the question naming its own answer, and it is answered before ranking
        is consulted at all.
        """
        if not wanted:
            return []
        lowered = {w.lower() for w in wanted}
        rows = self.db.execute(
            "SELECT chunk_id, kind, ref, title, text FROM chunk").fetchall()
        hits = [dict(r) for r in rows if r["ref"].lower() in lowered]
        # Longest name first: a question mentioning both a file and the table
        # it touches should lead with the more specific of the two.
        hits.sort(key=lambda h: -len(h["ref"]))
        return hits

    def ranked(self, exact: list[str], prefix: list[str], kinds: set[str],
               limit: int, digests: bool = True) -> list[dict]:
        """Whatever else the wording matches, best first.

        An OR of every term rather than an AND. A question is prose with one or
        two useful words buried in it, and requiring all of them to appear
        would return nothing.

        Domain words are matched as prefixes and identifiers are not, which is
        the difference between the two arguments. ``unicode61`` does no
        stemming, so a question asking about a "controller" found nothing while
        every chunk said ``controllers``; a prefix fixes that whole class of
        near-miss at once. Identifiers stay exact for the opposite reason —
        ``tbl_content*`` would match ``tbl_contentLang`` and
        ``tbl_contentEnroll``, which is the confusion this index exists to
        avoid.
        """
        clauses = [f'"{t}"' for t in exact if t]
        clauses += [f'"{t}"*' for t in prefix if t]
        if not clauses:
            return []
        query = " OR ".join(clauses)

        # A title hit outranks a body hit. These weights only order the results
        # that exact naming did not already decide.
        sql = """
            SELECT c.chunk_id, c.kind, c.ref, c.title, c.text,
                   bm25(chunk_fts, 10.0, 3.0) AS score
            FROM chunk_fts
            JOIN chunk c ON c.rowid = chunk_fts.rowid
            WHERE chunk_fts MATCH ?
        """
        params: list = [query]
        sql, params = self._filter(sql, params, kinds, digests)
        sql += " ORDER BY score LIMIT ?"
        params.append(limit)
        return [dict(r) for r in self.db.execute(sql, params)]

    @staticmethod
    def _filter(sql: str, params: list, kinds: set[str], digests: bool):
        """Restrict by chunk kind, and say whether digests may come through.

        Digests are not simply exempt from the kind filter. Letting them
        through unconditionally meant "tbl_company มีคอลัมน์อะไรบ้าง" — one
        table, named outright — came back with the table plus two corpus-wide
        lists filling the remaining slots, so the model answering about one
        table was also holding a list of all 192. A complete list is the right
        answer to a question about a set and clutter in a question about one
        thing; the caller knows which it has.
        """
        if not kinds:
            return sql, params
        if digests:
            sql += " AND (c.kind = 'digest' OR c.kind IN (%s))" % ",".join("?" * len(kinds))
        else:
            sql += " AND c.kind IN (%s)" % ",".join("?" * len(kinds))
        return sql, params + sorted(kinds)

    def total_matches(self, exact: list[str], prefix: list[str],
                      kinds: set[str]) -> int:
        """How many chunks the query matched, before the limit was applied.

        Needed so the service can say when what it showed is a sample. Counting
        is cheap here and the alternative is silence, which reads as "this was
        everything".
        """
        clauses = [f'"{t}"' for t in exact if t]
        clauses += [f'"{t}"*' for t in prefix if t]
        if not clauses:
            return 0
        sql = ("SELECT count(*) FROM chunk_fts JOIN chunk c "
               "ON c.rowid = chunk_fts.rowid WHERE chunk_fts MATCH ?")
        # Counted over the same population search() draws from, digests
        # included. Counting the narrower set produced a total *below* the
        # number of chunks actually shown, which would have made the "this is a
        # sample" note fire on the wrong questions and stay silent on the right
        # ones — a warning that is wrong about its own subject is worse than no
        # warning.
        sql, params = self._filter(sql, [" OR ".join(clauses)], kinds, True)
        return self.db.execute(sql, params).fetchone()[0]

    def search(self, question: str, limit: int = 6,
               assessment: scope.Assessment | None = None) -> list[dict]:
        """Named chunks first, then ranked ones, without repeats.

        Out-of-scope questions return nothing at all rather than whatever
        ranked least badly — see :mod:`app.scope` for why that is decided here
        and not left to a score threshold.
        """
        if assessment is None:
            assessment = scope.assess(question, self.vocabulary())
        if not assessment.in_scope:
            return []

        out = self.named(assessment.named)[:limit]
        seen = {h["chunk_id"] for h in out}

        exact = list(assessment.named)
        prefix = assessment.english + assessment.anchors

        # A question about a set gets the complete lists first, ahead of even
        # the best-ranked individual chunk. Ranking cannot make this decision:
        # a digest and a table chunk both match "sensitive" honestly, and the
        # one that answers "which tables" is the one holding all of them.
        #
        # One, not several. Handing over three complete lists produced the
        # failure the digests were built to end: asked "which tables hold
        # personal data", the model got the sensitive-column list, the
        # unindexed-table list and the full table inventory, and answered from
        # the wrong one — two tables out of twenty-six, stated plainly. A
        # complete list only helps if it is unmistakably *the* list, and
        # ranking already knows which one that is.
        if assessment.aggregate:
            for hit in self.ranked(exact, prefix, {"digest"}, 1):
                if hit["chunk_id"] not in seen and len(out) < limit:
                    out.append(hit)
                    seen.add(hit["chunk_id"])

        # Digests only compete for the remaining slots when the question did not
        # name one specific thing.
        # Digests are excluded from this pass entirely: the aggregate pass
        # above already picked the one that belongs, and letting more in here
        # would rebuild the pile it was narrowed down from.
        for hit in self.ranked(exact, prefix, assessment.kinds, limit,
                               digests=not assessment.named and not assessment.aggregate):
            if len(out) >= limit:
                break
            if hit["chunk_id"] not in seen:
                out.append(hit)
                seen.add(hit["chunk_id"])

        # Understood, and still empty. Two ways to get here and the same answer
        # serves both: a question whose every term maps to a kind rather than to
        # a searchable anchor ("มีตารางทั้งหมดกี่ตาราง" is entirely Thai), and a
        # question whose terms are searchable but match nothing ("list all
        # tables" searches for the English word "tables", which appears nowhere
        # in a corpus whose labels are Thai).
        #
        # Checking the result rather than the terms is what covers both. The
        # first version tested whether the query was empty and let the second
        # case fall through to a blank answer — in scope, understood, and
        # silently unanswered, which is the failure mode this whole design is
        # organised against.
        if not out:
            fallback = [lexicon.KIND_DIGEST[k] for k in sorted(assessment.kinds)
                        if k in lexicon.KIND_DIGEST]
            out = self.named(fallback)[:limit]
        return out

    def close(self) -> None:
        """Close this thread's connection.

        Only this thread's: another thread's connection is not ours to close,
        and the service never closes anyway — the index lives as long as the
        process. This exists for the build and for tests.
        """
        conn = getattr(self._local, "conn", None)
        if conn is not None:
            conn.close()
            self._local.conn = None
