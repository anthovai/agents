"""Conversations, stored as Markdown the person who owns them can read.

One file per conversation under a per-user directory, plus a SQLite index of
metadata. The split is not redundancy: listing somebody's conversations, or
totalling what they are using, has to work without opening and parsing every
file they own — but the file is what the format promises, so the file is the
copy that has to survive. If the index is deleted it can be rebuilt from the
directory; if a file is deleted, nothing can bring it back.

**Markdown was asked for, and it is a real constraint rather than a detail.** A
format people can read is a format people can edit, so nothing here assumes the
file is exactly as it was written. Turn boundaries are HTML comments, which
render as nothing at all in every Markdown viewer, and the parser tolerates
whatever sits between them — including text a person added by hand.

The quota is per user and counts bytes on disk.
"""

import os
import re
import sqlite3
import threading
import uuid
from datetime import datetime, timezone

from . import config

# Anything that is not one of these cannot become part of a path. Checked
# rather than escaped: an identifier that needs escaping is an identifier
# somebody constructed from user input, and this is the layer where that
# becomes a directory traversal.
#
# The leading character must be alphanumeric, which is the whole point of
# splitting the pattern in two. The first version allowed a dot anywhere, so
# ``..`` matched it — a perfectly well-formed user id that resolves to the
# parent directory. Every other traversal attempt was blocked and that one
# walked through, because it never needed a slash.
_SAFE_ID = re.compile(r"^[A-Za-z0-9][A-Za-z0-9._-]{0,63}$")

# Turn markers. HTML comments because Markdown renders them as nothing, so the
# file reads as a conversation rather than as a file format.
_TURN = re.compile(r"^<!--turn:(user|assistant):([^:]*):(.*?)-->$", re.MULTILINE)


class MemoryError_(Exception):
    """Refused, with a code the caller can branch on."""

    def __init__(self, code: str, message: str):
        self.code = code
        self.message = message
        super().__init__(f"{code}: {message}")


def _now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def _check_id(value: str, what: str) -> str:
    if not _SAFE_ID.match(value or ""):
        raise MemoryError_(
            "bad_identifier",
            f"{what} must be 1-64 characters of letters, digits, dot, dash or "
            f"underscore; got {value!r}")
    return value


def _escape(text: str) -> str:
    """Stop a turn's content from being read back as a turn boundary.

    Somebody pasting the contents of one of these files into a question is not
    a hypothetical — it is the obvious thing to do when reporting a problem
    with one. Without this, that paste would split their conversation in half
    on the way back out.
    """
    return text.replace("<!--turn:", "<!-- turn:")


class Store:
    """Metadata index beside the files it describes."""

    def __init__(self, root: str):
        self.root = root
        os.makedirs(root, exist_ok=True)
        self._local = threading.local()
        self._ensure()

    @property
    def db(self) -> sqlite3.Connection:
        conn = getattr(self._local, "conn", None)
        if conn is None:
            conn = sqlite3.connect(os.path.join(self.root, "index.sqlite"))
            conn.row_factory = sqlite3.Row
            self._local.conn = conn
        return conn

    def _ensure(self) -> None:
        self.db.executescript("""
            CREATE TABLE IF NOT EXISTS conversation (
                id         TEXT PRIMARY KEY,
                user_id    TEXT NOT NULL,
                title      TEXT NOT NULL,
                created_at TEXT NOT NULL,
                updated_at TEXT NOT NULL,
                bytes      INTEGER NOT NULL DEFAULT 0,
                turns      INTEGER NOT NULL DEFAULT 0
            );
            CREATE INDEX IF NOT EXISTS conversation_user
                ON conversation (user_id, updated_at DESC);
        """)
        self.db.commit()

    # ---------- paths ----------

    def user_dir(self, user_id: str) -> str:
        return os.path.join(self.root, _check_id(user_id, "user id"))

    def path(self, user_id: str, conversation_id: str) -> str:
        return os.path.join(self.user_dir(user_id),
                            _check_id(conversation_id, "conversation id") + ".md")

    # ---------- quota ----------

    def usage(self, user_id: str) -> dict:
        row = self.db.execute(
            "SELECT count(*) AS n, coalesce(sum(bytes), 0) AS b "
            "FROM conversation WHERE user_id = ?",
            (_check_id(user_id, "user id"),)).fetchone()
        return {
            "conversations": row["n"],
            "bytes": row["b"],
            "quota_bytes": config.USER_QUOTA_BYTES,
            "bytes_remaining": max(0, config.USER_QUOTA_BYTES - row["b"]),
        }

    def _check_quota(self, user_id: str, adding: int) -> None:
        used = self.usage(user_id)
        if used["bytes"] + adding > config.USER_QUOTA_BYTES:
            raise MemoryError_(
                "quota_exceeded",
                f"this would put the account over its {config.USER_QUOTA_BYTES} "
                f"byte limit ({used['bytes']} already stored). Delete a "
                f"conversation to make room.")

    # ---------- writing ----------

    def start(self, user_id: str, title: str) -> str:
        _check_id(user_id, "user id")
        conversation_id = uuid.uuid4().hex[:16]
        os.makedirs(self.user_dir(user_id), exist_ok=True)
        stamp = _now()
        header = (f"# {title}\n\n"
                  f"<!--conversation:{conversation_id} user:{user_id} "
                  f"created:{stamp}-->\n")
        self._check_quota(user_id, len(header.encode("utf-8")))
        with open(self.path(user_id, conversation_id), "w", encoding="utf-8") as fh:
            fh.write(header)
        self.db.execute(
            "INSERT INTO conversation (id, user_id, title, created_at, updated_at,"
            " bytes, turns) VALUES (?,?,?,?,?,?,0)",
            (conversation_id, user_id, title, stamp, stamp,
             len(header.encode("utf-8"))))
        self.db.commit()
        return conversation_id

    def append(self, user_id: str, conversation_id: str, role: str,
               text: str, note: str = "") -> None:
        """Add one turn. ``note`` records the sources, for the reader."""
        path = self.path(user_id, conversation_id)
        if not os.path.exists(path):
            raise MemoryError_("no_such_conversation",
                               f"no conversation {conversation_id!r} for this account")

        stamp = _now()
        heading = "ผู้ใช้" if role == "user" else "ผู้ช่วย"
        block = (f"\n<!--turn:{role}:{stamp}:-->\n"
                 f"## {heading} · {stamp}\n\n{_escape(text).strip()}\n")
        if note:
            block += f"\n<!--sources:{_escape(note)}-->\n_{note}_\n"

        encoded = block.encode("utf-8")
        self._check_quota(user_id, len(encoded))
        with open(path, "a", encoding="utf-8") as fh:
            fh.write(block)

        self.db.execute(
            "UPDATE conversation SET updated_at = ?, bytes = ?, turns = turns + 1 "
            "WHERE id = ? AND user_id = ?",
            (stamp, os.path.getsize(path), conversation_id, user_id))
        self.db.commit()

    # ---------- reading ----------

    def turns(self, user_id: str, conversation_id: str) -> list[dict]:
        """The conversation, back as roles and text.

        Parsed from the file rather than kept in the database, because the file
        is the copy that is promised to the person who owns it. If they edit it,
        the assistant should see what they left behind — anything else means the
        Markdown is decoration over a hidden real store.
        """
        path = self.path(user_id, conversation_id)
        if not os.path.exists(path):
            raise MemoryError_("no_such_conversation",
                               f"no conversation {conversation_id!r} for this account")
        with open(path, encoding="utf-8") as fh:
            body = fh.read()

        out = []
        marks = list(_TURN.finditer(body))
        for n, mark in enumerate(marks):
            end = marks[n + 1].start() if n + 1 < len(marks) else len(body)
            chunk = body[mark.end():end]
            # Drop the human-facing heading and the sources line; what is left
            # is what was said.
            chunk = re.sub(r"^##[^\n]*\n", "", chunk.strip())
            chunk = re.sub(r"<!--sources:[^>]*-->\n?_[^\n]*_\n?", "", chunk)
            out.append({"role": mark.group(1), "at": mark.group(2),
                        "text": chunk.strip()})
        return out

    def listing(self, user_id: str) -> list[dict]:
        rows = self.db.execute(
            "SELECT id, title, created_at, updated_at, bytes, turns "
            "FROM conversation WHERE user_id = ? ORDER BY updated_at DESC",
            (_check_id(user_id, "user id"),)).fetchall()
        return [dict(r) for r in rows]

    def raw(self, user_id: str, conversation_id: str) -> str:
        """The Markdown itself, for the owner to download."""
        path = self.path(user_id, conversation_id)
        if not os.path.exists(path):
            raise MemoryError_("no_such_conversation",
                               f"no conversation {conversation_id!r} for this account")
        with open(path, encoding="utf-8") as fh:
            return fh.read()

    # ---------- the owner's controls ----------

    def rename(self, user_id: str, conversation_id: str, title: str) -> None:
        path = self.path(user_id, conversation_id)
        if not os.path.exists(path):
            raise MemoryError_("no_such_conversation",
                               f"no conversation {conversation_id!r} for this account")
        with open(path, encoding="utf-8") as fh:
            body = fh.read()
        body = re.sub(r"^# [^\n]*", f"# {title}", body, count=1)
        with open(path, "w", encoding="utf-8") as fh:
            fh.write(body)
        self.db.execute(
            "UPDATE conversation SET title = ?, bytes = ? WHERE id = ? AND user_id = ?",
            (title, os.path.getsize(path), conversation_id, user_id))
        self.db.commit()

    def delete(self, user_id: str, conversation_id: str) -> None:
        path = self.path(user_id, conversation_id)
        if os.path.exists(path):
            os.remove(path)
        self.db.execute("DELETE FROM conversation WHERE id = ? AND user_id = ?",
                        (conversation_id, user_id))
        self.db.commit()

    def delete_all(self, user_id: str) -> int:
        """Everything this account owns, file and record together.

        The file goes first. A record without its file is a listing entry that
        opens to nothing; a file without its record is storage the owner has
        been told they deleted, which is the worse of the two to leave behind.
        """
        removed = 0
        for row in self.listing(user_id):
            self.delete(user_id, row["id"])
            removed += 1
        return removed

    def close(self) -> None:
        conn = getattr(self._local, "conn", None)
        if conn is not None:
            conn.close()
            self._local.conn = None
