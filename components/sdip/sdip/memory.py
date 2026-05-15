"""Per-practitioner SQLite memory store.

SDIP requires each practitioner's conversation history, profile, and
calibration state to live on the practitioner's substrate. This module
defines the SQLite schema and provides typed access. The schema matches
the production HarmonAI memory schema (Decisions #181, #538, #536, #775)
adapted to be tradition-neutral via the calibrations column extension.
"""
from __future__ import annotations

import json
import sqlite3
import time
from contextlib import contextmanager
from pathlib import Path
from typing import Any, Iterator


class MemoryError(Exception):
    """Raised on memory database errors."""


# Base schema. Calibration columns from calibrations.yaml are added via ALTER
# TABLE in Memory.ensure_calibration_columns().
SCHEMA = """
CREATE TABLE IF NOT EXISTS practitioners (
    id TEXT PRIMARY KEY,
    created_at INTEGER NOT NULL,
    last_active_at INTEGER NOT NULL,
    message_count INTEGER NOT NULL DEFAULT 0,
    profile_summary TEXT
);

CREATE TABLE IF NOT EXISTS messages (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    practitioner_id TEXT NOT NULL,
    role TEXT NOT NULL,                    -- 'user' or 'assistant'
    content TEXT NOT NULL,
    created_at INTEGER NOT NULL,
    retrieval_signature TEXT,              -- JSON: list of chunks retrieved for this turn
    FOREIGN KEY (practitioner_id) REFERENCES practitioners(id)
);

CREATE INDEX IF NOT EXISTS idx_messages_practitioner_time
    ON messages(practitioner_id, created_at);

CREATE TABLE IF NOT EXISTS conversation_summaries (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    practitioner_id TEXT NOT NULL,
    period_start INTEGER NOT NULL,
    period_end INTEGER NOT NULL,
    summary TEXT NOT NULL,
    created_at INTEGER NOT NULL,
    FOREIGN KEY (practitioner_id) REFERENCES practitioners(id)
);

CREATE INDEX IF NOT EXISTS idx_summaries_practitioner_period
    ON conversation_summaries(practitioner_id, period_end);

CREATE TABLE IF NOT EXISTS profile_notes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    practitioner_id TEXT NOT NULL,
    category TEXT NOT NULL,
    content TEXT NOT NULL,
    updated_at INTEGER NOT NULL,
    FOREIGN KEY (practitioner_id) REFERENCES practitioners(id)
);

CREATE INDEX IF NOT EXISTS idx_profile_practitioner_category
    ON profile_notes(practitioner_id, category);
"""


class Memory:
    """Per-practitioner SQLite store.

    Usage:
        mem = Memory.open("/path/to/practitioner.db")
        mem.ensure_calibration_columns(bundle.calibrations)
        mem.record_message(practitioner_id, role="user", content="...")
    """

    def __init__(self, db_path: Path):
        self.db_path = db_path
        self._calibration_columns: list[str] = []

    @classmethod
    def open(cls, path: str | Path) -> Memory:
        """Open or create the memory database, applying the base schema."""
        db_path = Path(path)
        db_path.parent.mkdir(parents=True, exist_ok=True)
        # Set restrictive permissions on the database file (mode 0600 equivalent)
        mem = cls(db_path)
        with mem._connect() as conn:
            conn.executescript(SCHEMA)
            conn.commit()
        try:
            db_path.chmod(0o600)
        except OSError:
            pass
        return mem

    @contextmanager
    def _connect(self) -> Iterator[sqlite3.Connection]:
        conn = sqlite3.connect(self.db_path)
        conn.row_factory = sqlite3.Row
        try:
            yield conn
        finally:
            conn.close()

    # ── Schema management ───────────────────────────────────────────────────

    def ensure_calibration_columns(self, calibrations_doc: dict) -> None:
        """Ensure the practitioners table has columns for each declared calibration.

        Reads the parsed calibrations.yaml and adds any missing columns via
        ALTER TABLE. Idempotent.
        """
        columns = calibrations_doc.get("columns", [])
        with self._connect() as conn:
            existing = {row["name"] for row in conn.execute("PRAGMA table_info(practitioners)")}
            for col in columns:
                name = col["name"]
                if name in existing:
                    continue
                sql_type = _python_to_sqlite_type(col["type"])
                default = col.get("default")
                default_clause = ""
                if default is not None:
                    default_clause = f" DEFAULT {_format_default(default, col['type'])}"
                try:
                    conn.execute(f"ALTER TABLE practitioners ADD COLUMN {name} {sql_type}{default_clause}")
                except sqlite3.OperationalError as e:
                    raise MemoryError(f"failed to add calibration column {name}: {e}") from e
            conn.commit()
        self._calibration_columns = [c["name"] for c in columns]

    # ── Practitioner operations ─────────────────────────────────────────────

    def ensure_practitioner(self, practitioner_id: str) -> None:
        """Ensure a practitioner row exists. Idempotent."""
        now = int(time.time())
        with self._connect() as conn:
            conn.execute(
                "INSERT OR IGNORE INTO practitioners (id, created_at, last_active_at) "
                "VALUES (?, ?, ?)",
                (practitioner_id, now, now),
            )
            conn.commit()

    def get_practitioner(self, practitioner_id: str) -> dict[str, Any] | None:
        """Fetch a practitioner row as a dict, or None if not found."""
        with self._connect() as conn:
            row = conn.execute(
                "SELECT * FROM practitioners WHERE id = ?", (practitioner_id,)
            ).fetchone()
        return dict(row) if row else None

    # ── Calibration access ──────────────────────────────────────────────────

    def get_calibration(self, practitioner_id: str, column: str) -> Any:
        """Get the current value of a calibration column."""
        with self._connect() as conn:
            row = conn.execute(
                f"SELECT {column} FROM practitioners WHERE id = ?", (practitioner_id,)
            ).fetchone()
        return row[column] if row else None

    def set_calibration(
        self,
        practitioner_id: str,
        column: str,
        value: Any,
        monotonic: bool = False,
    ) -> None:
        """Set a calibration column value.

        If monotonic=True, applies MAX(current, value) at the SQL level so the
        value cannot regress.
        """
        if monotonic:
            sql = f"UPDATE practitioners SET {column} = MAX(COALESCE({column}, 0), ?) WHERE id = ?"
        else:
            sql = f"UPDATE practitioners SET {column} = ? WHERE id = ?"
        with self._connect() as conn:
            conn.execute(sql, (value, practitioner_id))
            conn.commit()

    # ── Messages ────────────────────────────────────────────────────────────

    def record_message(
        self,
        practitioner_id: str,
        role: str,
        content: str,
        retrieval_signature: list[dict] | None = None,
    ) -> int:
        """Record a message. Returns the new message ID."""
        now = int(time.time())
        sig_json = json.dumps(retrieval_signature) if retrieval_signature else None
        with self._connect() as conn:
            cur = conn.execute(
                "INSERT INTO messages (practitioner_id, role, content, created_at, retrieval_signature) "
                "VALUES (?, ?, ?, ?, ?)",
                (practitioner_id, role, content, now, sig_json),
            )
            conn.execute(
                "UPDATE practitioners SET last_active_at = ?, message_count = message_count + 1 "
                "WHERE id = ?",
                (now, practitioner_id),
            )
            conn.commit()
            return cur.lastrowid or 0

    def recent_messages(self, practitioner_id: str, n: int = 20) -> list[dict[str, Any]]:
        """Fetch the most recent N messages in chronological order."""
        with self._connect() as conn:
            rows = conn.execute(
                "SELECT * FROM messages WHERE practitioner_id = ? "
                "ORDER BY created_at DESC LIMIT ?",
                (practitioner_id, n),
            ).fetchall()
        return [dict(r) for r in reversed(rows)]

    # ── Summaries and profile notes ─────────────────────────────────────────

    def record_summary(
        self,
        practitioner_id: str,
        period_start: int,
        period_end: int,
        summary: str,
    ) -> None:
        now = int(time.time())
        with self._connect() as conn:
            conn.execute(
                "INSERT INTO conversation_summaries "
                "(practitioner_id, period_start, period_end, summary, created_at) "
                "VALUES (?, ?, ?, ?, ?)",
                (practitioner_id, period_start, period_end, summary, now),
            )
            conn.commit()

    def upsert_profile_note(
        self,
        practitioner_id: str,
        category: str,
        content: str,
    ) -> None:
        now = int(time.time())
        with self._connect() as conn:
            conn.execute(
                "INSERT INTO profile_notes (practitioner_id, category, content, updated_at) "
                "VALUES (?, ?, ?, ?) "
                "ON CONFLICT(practitioner_id, category) DO UPDATE SET "
                "content = excluded.content, updated_at = excluded.updated_at",
                (practitioner_id, category, content, now),
            )
            conn.commit()


# ── Helpers ──────────────────────────────────────────────────────────────────

_SQLITE_TYPE_MAP = {
    "integer": "INTEGER",
    "real": "REAL",
    "boolean": "INTEGER",
    "text": "TEXT",
    "json": "TEXT",
}


def _python_to_sqlite_type(t: str) -> str:
    return _SQLITE_TYPE_MAP.get(t, "TEXT")


def _format_default(value: Any, declared_type: str) -> str:
    if declared_type in ("integer", "real", "boolean"):
        return str(int(value)) if declared_type == "boolean" else str(value)
    return "'" + str(value).replace("'", "''") + "'"
