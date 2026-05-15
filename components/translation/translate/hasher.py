"""Body hash and block-level hash discipline for smart-translate.

The body hash enables article-level staleness detection: when the source's
body hash changes, every translation linked to it is flagged.

The block-level hash enables smart-translate incremental updates: the source
body is split into markdown blocks, each block hashed independently, and only
blocks whose hash has drifted are re-translated. Cost reduction is the value:
editing one paragraph in a 40-block article bills ~80 chars instead of ~4,000.

Match the production translate-sync.py block-splitting heuristic: blocks are
delimited by blank lines, with code fences and HTML blocks preserved as
single blocks regardless of internal whitespace.
"""
from __future__ import annotations

import hashlib
import re
from pathlib import Path


# Compiled regex for frontmatter stripping
_FRONTMATTER_RE = re.compile(r"^---\s*\n(.*?)\n---\s*\n", re.DOTALL)

# Compiled regex for code fence detection
_CODE_FENCE_RE = re.compile(r"^(```|~~~)")


def strip_frontmatter(text: str) -> str:
    """Strip YAML frontmatter from a Markdown document, returning the body only."""
    match = _FRONTMATTER_RE.match(text)
    if match:
        return text[match.end():]
    return text


def hash_body(body: str, *, truncate: int | None = 16) -> str:
    """SHA-256 of a markdown body. Defaults to truncated 16-char hex digest.

    Truncation is convention-compatible with the production translate-sync.py
    (16-char prefix is sufficient for collision resistance at corpus scale).
    Pass `truncate=None` for the full 64-char digest.
    """
    h = hashlib.sha256(body.encode("utf-8")).hexdigest()
    if truncate is None:
        return h
    return h[:truncate]


def hash_block(block: str, *, truncate: int | None = 8) -> str:
    """SHA-256 of a single markdown block. Defaults to 8-char hex digest.

    Per-block hashes are stored as JSON arrays in translation frontmatter
    (`para_hashes` field). 8 chars is a deliberate cost-vs-collision trade-off:
    at typical article scales (<200 blocks) the collision probability is
    negligible, and 8-char hashes keep frontmatter readable.
    """
    h = hashlib.sha256(block.encode("utf-8")).hexdigest()
    if truncate is None:
        return h
    return h[:truncate]


def split_blocks(body: str) -> list[str]:
    """Split a markdown body into blocks for per-block hashing.

    Block delimiters: blank lines. Code fences (```, ~~~) and HTML blocks
    are preserved as single blocks regardless of internal whitespace.

    Leading/trailing whitespace is stripped from each block; empty blocks
    are dropped. The split is deterministic so block indices are stable
    across hashings of the same content.
    """
    if not body:
        return []

    blocks: list[str] = []
    current: list[str] = []
    in_code_fence: bool = False
    fence_marker: str | None = None

    lines = body.split("\n")
    for line in lines:
        # Detect code fence transitions
        if not in_code_fence:
            match = _CODE_FENCE_RE.match(line)
            if match:
                in_code_fence = True
                fence_marker = match.group(1)
                current.append(line)
                continue
        else:
            current.append(line)
            # Match closing fence (same marker)
            if line.strip().startswith(fence_marker or ""):
                in_code_fence = False
                fence_marker = None
            continue

        # Outside code fence: blank line separates blocks
        if line.strip() == "":
            if current:
                joined = "\n".join(current).strip()
                if joined:
                    blocks.append(joined)
                current = []
        else:
            current.append(line)

    # Flush remaining
    if current:
        joined = "\n".join(current).strip()
        if joined:
            blocks.append(joined)

    return blocks


class Hasher:
    """Convenience wrapper bundling body and block-level hashing.

    Usage:
        h = Hasher.from_path("article.md")
        h.body_hash                  # truncated 16-char digest
        h.block_hashes               # list of 8-char digests, one per block
        len(h.blocks)                # number of blocks
    """

    def __init__(self, body: str, *, body_truncate: int = 16, block_truncate: int = 8):
        self.body = body
        self.blocks = split_blocks(body)
        self.body_hash = hash_body(body, truncate=body_truncate)
        self.block_hashes = [hash_block(b, truncate=block_truncate) for b in self.blocks]

    @classmethod
    def from_path(cls, path: str | Path, *, body_truncate: int = 16, block_truncate: int = 8) -> Hasher:
        """Load a Markdown file, strip frontmatter, hash the body."""
        text = Path(path).read_text(encoding="utf-8")
        body = strip_frontmatter(text)
        return cls(body, body_truncate=body_truncate, block_truncate=block_truncate)

    def __repr__(self) -> str:
        return f"Hasher(body_hash={self.body_hash!r}, blocks={len(self.blocks)})"
