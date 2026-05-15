"""RAG retrieval — semantic + FTS5 + domain-gated canon injection.

The production retrieval architecture is documented in HarmonAI Design
Document Decision #283. This module exposes the protocol-conforming
interface; the production-grade implementation is being ported from the
PHP harness module-by-module.

For v0.1, the implementation provides:
- A working FTS5 keyword retriever over the corpus
- The interface for semantic retrieval (the embeddings backend is pluggable)
- The retrieval signature shape expected by §4.3 of the SPEC
"""
from __future__ import annotations

import re
import sqlite3
from dataclasses import dataclass
from pathlib import Path

from sdip.bundle import Bundle, CorpusArticle


@dataclass
class RetrievalRecord:
    """A single retrieved chunk with provenance.

    The retrieval signature returned to the harness is a list of these.
    """

    source_path: str
    classification: str
    chunk_index: int
    chunk_text: str
    score: float
    retriever: str  # "fts5" | "semantic" | "domain-canon"


class Retrieval:
    """Hybrid retrieval over a bundle's corpus.

    Construction performs FTS5 indexing of the corpus. Semantic indexing is
    optional and requires an embedding model — pass it via the `embedder`
    parameter.

    Usage:
        retrieval = Retrieval(bundle, index_path=Path("./vault-index.db"))
        retrieval.build_indexes()
        records = retrieval.retrieve(query="What is Logos?", top_k=8)
    """

    def __init__(
        self,
        bundle: Bundle,
        index_path: str | Path,
        embedder: "Embedder | None" = None,
        chunk_size: int = 3000,
        chunk_overlap: int = 200,
    ):
        self.bundle = bundle
        self.index_path = Path(index_path)
        self.embedder = embedder
        self.chunk_size = chunk_size
        self.chunk_overlap = chunk_overlap

    # ── Index construction ──────────────────────────────────────────────────

    def build_indexes(self) -> None:
        """(Re)build FTS5 and (if embedder provided) semantic indexes."""
        self.index_path.parent.mkdir(parents=True, exist_ok=True)
        conn = sqlite3.connect(self.index_path)
        try:
            conn.executescript(
                """
                CREATE VIRTUAL TABLE IF NOT EXISTS chunks_fts USING fts5(
                    source_path, classification, chunk_index, chunk_text,
                    tokenize = 'porter unicode61'
                );
                CREATE TABLE IF NOT EXISTS chunks (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    source_path TEXT NOT NULL,
                    classification TEXT NOT NULL,
                    chunk_index INTEGER NOT NULL,
                    chunk_text TEXT NOT NULL,
                    embedding BLOB
                );
                CREATE INDEX IF NOT EXISTS idx_chunks_source
                    ON chunks(source_path);
                """
            )
            conn.execute("DELETE FROM chunks_fts")
            conn.execute("DELETE FROM chunks")

            for article in self.bundle.corpus_iter():
                for i, chunk_text in enumerate(self._chunk(article.body)):
                    conn.execute(
                        "INSERT INTO chunks (source_path, classification, chunk_index, chunk_text) "
                        "VALUES (?, ?, ?, ?)",
                        (article.relative_path, article.classification, i, chunk_text),
                    )
                    conn.execute(
                        "INSERT INTO chunks_fts (source_path, classification, chunk_index, chunk_text) "
                        "VALUES (?, ?, ?, ?)",
                        (article.relative_path, article.classification, i, chunk_text),
                    )

            conn.commit()

            if self.embedder is not None:
                self._build_semantic_index(conn)
        finally:
            conn.close()

    def _build_semantic_index(self, conn: sqlite3.Connection) -> None:
        """Compute embeddings for every chunk. Stored as BLOBs (float32 packed)."""
        # Skeleton — the production version batches embedding requests and
        # handles failures with retries. v0.1 reference computes per-chunk.
        import struct

        rows = conn.execute("SELECT id, chunk_text FROM chunks").fetchall()
        for row_id, text in rows:
            assert self.embedder is not None
            vec = self.embedder.embed(text)
            blob = struct.pack(f"{len(vec)}f", *vec)
            conn.execute("UPDATE chunks SET embedding = ? WHERE id = ?", (blob, row_id))
        conn.commit()

    def _chunk(self, body: str) -> list[str]:
        """Simple character-window chunking with overlap.

        Production HarmonAI uses paragraph-aware chunking; v0.1 reference
        uses character-window as a conservative baseline.
        """
        if len(body) <= self.chunk_size:
            return [body]
        chunks: list[str] = []
        start = 0
        while start < len(body):
            end = min(start + self.chunk_size, len(body))
            chunks.append(body[start:end])
            if end == len(body):
                break
            start = end - self.chunk_overlap
        return chunks

    # ── Retrieval ───────────────────────────────────────────────────────────

    def retrieve(
        self,
        query: str,
        top_k: int = 8,
        max_chars: int = 12000,
    ) -> list[RetrievalRecord]:
        """Retrieve chunks for a query.

        Returns a budget-bounded list of RetrievalRecord. The harness logs
        this list as the retrieval signature (§4.3 of the SPEC).
        """
        records: list[RetrievalRecord] = []
        conn = sqlite3.connect(self.index_path)
        try:
            # FTS5 layer
            fts_records = self._retrieve_fts(conn, query, top_k=top_k)
            records.extend(fts_records)

            # Semantic layer (if embedder configured)
            if self.embedder is not None:
                sem_records = self._retrieve_semantic(conn, query, top_k=top_k)
                records.extend(sem_records)
        finally:
            conn.close()

        # Deduplicate by (source_path, chunk_index), keeping highest score
        seen: dict[tuple[str, int], RetrievalRecord] = {}
        for r in records:
            key = (r.source_path, r.chunk_index)
            if key not in seen or r.score > seen[key].score:
                seen[key] = r
        deduped = sorted(seen.values(), key=lambda r: -r.score)

        # Apply character budget
        out: list[RetrievalRecord] = []
        running = 0
        for r in deduped:
            if running + len(r.chunk_text) > max_chars:
                break
            out.append(r)
            running += len(r.chunk_text)
        return out

    def _retrieve_fts(
        self, conn: sqlite3.Connection, query: str, top_k: int
    ) -> list[RetrievalRecord]:
        # Sanitize for FTS5: keep alphanumerics and quoted phrases
        sanitized = re.sub(r"[^\w\s\"']", " ", query).strip()
        if not sanitized:
            return []
        # bm25() returns lower=better; invert to higher=better
        try:
            rows = conn.execute(
                "SELECT source_path, classification, chunk_index, chunk_text, "
                "-bm25(chunks_fts) AS score "
                "FROM chunks_fts WHERE chunks_fts MATCH ? ORDER BY score DESC LIMIT ?",
                (sanitized, top_k),
            ).fetchall()
        except sqlite3.OperationalError:
            return []
        return [
            RetrievalRecord(
                source_path=r[0],
                classification=r[1],
                chunk_index=r[2],
                chunk_text=r[3],
                score=float(r[4]),
                retriever="fts5",
            )
            for r in rows
        ]

    def _retrieve_semantic(
        self, conn: sqlite3.Connection, query: str, top_k: int
    ) -> list[RetrievalRecord]:
        """Cosine-similarity over chunk embeddings."""
        import math
        import struct

        assert self.embedder is not None
        q_vec = self.embedder.embed(query)
        q_norm = math.sqrt(sum(v * v for v in q_vec))
        if q_norm == 0:
            return []

        records: list[RetrievalRecord] = []
        for row in conn.execute(
            "SELECT source_path, classification, chunk_index, chunk_text, embedding "
            "FROM chunks WHERE embedding IS NOT NULL"
        ):
            source_path, classification, chunk_index, chunk_text, blob = row
            dim = len(blob) // 4
            vec = struct.unpack(f"{dim}f", blob)
            d_norm = math.sqrt(sum(v * v for v in vec))
            if d_norm == 0:
                continue
            dot = sum(a * b for a, b in zip(q_vec, vec))
            score = dot / (q_norm * d_norm)
            records.append(
                RetrievalRecord(
                    source_path=source_path,
                    classification=classification,
                    chunk_index=chunk_index,
                    chunk_text=chunk_text,
                    score=float(score),
                    retriever="semantic",
                )
            )
        records.sort(key=lambda r: -r.score)
        return records[:top_k]


class Embedder:
    """Embedder interface. Implementations: OpenAIEmbedder, local sentence-transformers, etc."""

    def embed(self, text: str) -> list[float]:
        raise NotImplementedError


class OpenAIEmbedder(Embedder):
    """OpenAI-compatible embedder.

    Works against any OpenAI-compatible /embeddings endpoint (OpenAI itself,
    local Ollama with embeddings, llama.cpp embedding server, etc.).
    """

    def __init__(
        self,
        endpoint: str,
        model_name: str = "text-embedding-3-small",
        api_key: str | None = None,
        timeout: float = 30.0,
    ):
        self.endpoint = endpoint.rstrip("/")
        self.model_name = model_name
        self.api_key = api_key
        self.timeout = timeout

    def embed(self, text: str) -> list[float]:
        import httpx

        headers = {"Content-Type": "application/json"}
        if self.api_key:
            headers["Authorization"] = f"Bearer {self.api_key}"
        r = httpx.post(
            f"{self.endpoint}/embeddings",
            json={"model": self.model_name, "input": text},
            headers=headers,
            timeout=self.timeout,
        )
        r.raise_for_status()
        return r.json()["data"][0]["embedding"]
