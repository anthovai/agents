"""Where videos, answers and progress are kept.

SQLite, on purpose. This service holds a few thousand rows per video and is
read far more than it is written; a database server would be one more thing to
run, back up and get wrong for no gain anybody could measure. Swap the class
for a Postgres one if that stops being true — everything above it goes through
these methods and none of it knows what is underneath.
"""
from __future__ import annotations

import json
import sqlite3
import threading
import time
from pathlib import Path

from .models import Item, Video

SCHEMA = """
CREATE TABLE IF NOT EXISTS video (
    id          TEXT PRIMARY KEY,
    definition  TEXT NOT NULL,
    updated     INTEGER NOT NULL
);

CREATE TABLE IF NOT EXISTS answer (
    video_id    TEXT NOT NULL,
    user_id     TEXT NOT NULL,
    item_id     TEXT NOT NULL,
    response    TEXT NOT NULL,
    correct     INTEGER NOT NULL,
    attempts    INTEGER NOT NULL DEFAULT 1,
    answered    INTEGER NOT NULL,
    PRIMARY KEY (video_id, user_id, item_id)
);

CREATE TABLE IF NOT EXISTS progress (
    video_id    TEXT NOT NULL,
    user_id     TEXT NOT NULL,
    seconds     REAL NOT NULL,
    finished    INTEGER NOT NULL DEFAULT 0,
    updated     INTEGER NOT NULL,
    PRIMARY KEY (video_id, user_id)
);

CREATE INDEX IF NOT EXISTS answer_by_video ON answer (video_id, user_id);
"""


class Store:
    """Every read and write, behind one lock.

    SQLite allows one writer at a time and a web server will happily try for
    more. The lock makes the queue explicit rather than leaving it to surface
    as "database is locked" under exactly the load nobody tested at.
    """

    def __init__(self, path: str):
        self.path = path
        if path != ":memory:":
            Path(path).parent.mkdir(parents=True, exist_ok=True)
        self._lock = threading.Lock()
        # check_same_thread=False plus the lock above: one connection shared by
        # the worker threads, with serialisation we control.
        self._db = sqlite3.connect(path, check_same_thread=False)
        self._db.row_factory = sqlite3.Row
        # WAL so that a reader is never blocked by the writer. On :memory: it
        # is not supported and not needed.
        if path != ":memory:":
            self._db.execute("PRAGMA journal_mode=WAL")
        self._db.executescript(SCHEMA)
        self._db.commit()

    def close(self) -> None:
        with self._lock:
            self._db.close()

    # ---------------------------------------------------------------- videos

    def save_video(self, video: Video) -> None:
        """Create or replace a video and its whole timeline.

        Replaced as one document rather than diffed row by row. An author
        editing a timeline is rearranging a single thing, and a partial save
        that left half the old items behind would be far worse than a rewrite.
        Answers are keyed by item id and survive it.
        """
        payload = video.model_dump_json()
        with self._lock:
            self._db.execute(
                "INSERT INTO video (id, definition, updated) VALUES (?, ?, ?) "
                "ON CONFLICT(id) DO UPDATE SET definition=excluded.definition, "
                "updated=excluded.updated",
                (video.id, payload, int(time.time())))
            self._db.commit()

    def video(self, video_id: str) -> Video | None:
        with self._lock:
            row = self._db.execute(
                "SELECT definition FROM video WHERE id = ?", (video_id,)).fetchone()
        return Video.model_validate_json(row["definition"]) if row else None

    def videos(self) -> list[dict]:
        with self._lock:
            rows = self._db.execute(
                "SELECT id, definition, updated FROM video ORDER BY updated DESC"
            ).fetchall()
        out = []
        for row in rows:
            video = Video.model_validate_json(row["definition"])
            out.append({"id": video.id, "title": video.title,
                        "provider": video.provider.value,
                        "items": len(video.timeline), "updated": row["updated"]})
        return out

    def delete_video(self, video_id: str) -> bool:
        """Remove a video, its answers and its progress.

        The answers go with it deliberately: they are answers *to* this video
        and mean nothing once it is gone, and leaving them would quietly grow
        a table nobody can interpret.
        """
        with self._lock:
            cursor = self._db.execute("DELETE FROM video WHERE id = ?", (video_id,))
            self._db.execute("DELETE FROM answer WHERE video_id = ?", (video_id,))
            self._db.execute("DELETE FROM progress WHERE video_id = ?", (video_id,))
            self._db.commit()
            return cursor.rowcount > 0

    # --------------------------------------------------------------- answers

    def record_answer(self, video_id: str, user_id: str, item_id: str,
                      response: str, correct: bool) -> int:
        """Store one answer and return how many attempts this item has had.

        The row is replaced, so the record is the latest answer rather than
        every attempt — but `attempts` is carried forward, because "right on
        the third go" and "right first time" are different facts and a report
        that cannot tell them apart is a report somebody will dispute.
        """
        now = int(time.time())
        with self._lock:
            row = self._db.execute(
                "SELECT attempts FROM answer "
                "WHERE video_id = ? AND user_id = ? AND item_id = ?",
                (video_id, user_id, item_id)).fetchone()
            attempts = (row["attempts"] + 1) if row else 1
            self._db.execute(
                "INSERT INTO answer "
                "(video_id, user_id, item_id, response, correct, attempts, answered) "
                "VALUES (?, ?, ?, ?, ?, ?, ?) "
                "ON CONFLICT(video_id, user_id, item_id) DO UPDATE SET "
                "response=excluded.response, correct=excluded.correct, "
                "attempts=excluded.attempts, answered=excluded.answered",
                (video_id, user_id, item_id, response, int(correct), attempts, now))
            self._db.commit()
        return attempts

    def answers(self, video_id: str, user_id: str) -> dict[str, dict]:
        with self._lock:
            rows = self._db.execute(
                "SELECT item_id, response, correct, attempts, answered FROM answer "
                "WHERE video_id = ? AND user_id = ?", (video_id, user_id)).fetchall()
        return {r["item_id"]: {"item_id": r["item_id"], "response": r["response"],
                               "correct": bool(r["correct"]),
                               "attempts": r["attempts"], "answered": r["answered"]}
                for r in rows}

    # -------------------------------------------------------------- progress

    def record_progress(self, video_id: str, user_id: str,
                        seconds: float, finished: bool) -> None:
        """Keep the furthest point reached, not the latest one.

        Two reports arrive for the same departure — one from the page hiding,
        one from it unloading — and they race. Keeping the maximum means the
        order they land in does not decide where the learner resumes, and a
        learner who watched to 28s is never sent back to 15s to sit through it
        again.
        """
        now = int(time.time())
        with self._lock:
            self._db.execute(
                "INSERT INTO progress (video_id, user_id, seconds, finished, updated) "
                "VALUES (?, ?, ?, ?, ?) "
                "ON CONFLICT(video_id, user_id) DO UPDATE SET "
                "seconds=MAX(progress.seconds, excluded.seconds), "
                "finished=MAX(progress.finished, excluded.finished), "
                "updated=excluded.updated",
                (video_id, user_id, seconds, int(finished), now))
            self._db.commit()

    def progress(self, video_id: str, user_id: str) -> dict:
        with self._lock:
            row = self._db.execute(
                "SELECT seconds, finished, updated FROM progress "
                "WHERE video_id = ? AND user_id = ?", (video_id, user_id)).fetchone()
        if not row:
            return {"seconds": 0.0, "finished": False, "updated": 0}
        return {"seconds": row["seconds"], "finished": bool(row["finished"]),
                "updated": row["updated"]}

    def viewers(self, video_id: str) -> list[str]:
        """Everyone who has answered or watched anything of this video."""
        with self._lock:
            rows = self._db.execute(
                "SELECT user_id FROM progress WHERE video_id = ? "
                "UNION SELECT user_id FROM answer WHERE video_id = ? "
                "ORDER BY user_id", (video_id, video_id)).fetchall()
        return [r["user_id"] for r in rows]

    def forget_user(self, user_id: str) -> int:
        """Erase one person from every video.

        Here because a service that records what a named person answered needs
        an answer to "delete my data" that is one call rather than a project.

        :return: how many rows went
        """
        with self._lock:
            a = self._db.execute("DELETE FROM answer WHERE user_id = ?", (user_id,))
            p = self._db.execute("DELETE FROM progress WHERE user_id = ?", (user_id,))
            self._db.commit()
            return a.rowcount + p.rowcount


def summary(video: Video, answers: dict[str, dict], progress: dict) -> dict:
    """One person's standing on one video.

    Info cards are counted as reached but never as marks. They record as
    correct — there was nothing to get wrong — and including them would lift
    every score by however many messages the author happened to write, which
    is a number that has nothing to do with the learner.
    """
    graded = [item for item in video.timeline if item.type.value != "info"]
    right = sum(1 for item in graded
                if answers.get(item.id, {}).get("correct"))
    answered = sum(1 for item in graded if item.id in answers)

    return {
        "graded_items": len(graded),
        "answered": answered,
        "correct": right,
        "fraction": (right / len(graded)) if graded else None,
        "seconds": progress["seconds"],
        "finished": progress["finished"],
    }


def by_category(video: Video, answers: dict[str, dict]) -> list[dict]:
    """The same standing, split by the author's own categories, worst last.

    The point of this is to say *what* somebody has not understood rather than
    only how much — a 60% that is entirely one topic is a different
    conversation from a 60% spread evenly.
    """
    totals: dict[str, dict] = {}
    for item in video.timeline:
        if item.type.value == "info" or not item.category:
            continue
        row = totals.setdefault(item.category,
                                {"category": item.category, "correct": 0, "total": 0})
        row["total"] += 1
        row["correct"] += 1 if answers.get(item.id, {}).get("correct") else 0

    out = list(totals.values())
    for row in out:
        row["fraction"] = row["correct"] / row["total"] if row["total"] else None
    out.sort(key=lambda r: (r["fraction"] is None, r["fraction"]), reverse=True)
    return out
