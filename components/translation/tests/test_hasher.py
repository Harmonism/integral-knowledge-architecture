"""Tests for translate.hasher."""
from __future__ import annotations

from translate.hasher import Hasher, hash_block, hash_body, split_blocks, strip_frontmatter


def test_hash_body_deterministic() -> None:
    body = "# Title\n\nFirst paragraph.\n\nSecond paragraph.\n"
    h1 = hash_body(body)
    h2 = hash_body(body)
    assert h1 == h2


def test_hash_body_default_truncate_16() -> None:
    body = "anything"
    h = hash_body(body)
    assert len(h) == 16
    # Full digest should also work
    full = hash_body(body, truncate=None)
    assert len(full) == 64
    assert full.startswith(h)


def test_hash_block_default_truncate_8() -> None:
    block = "A block of text"
    h = hash_block(block)
    assert len(h) == 8


def test_split_blocks_basic() -> None:
    body = "First block.\n\nSecond block.\n\nThird block."
    blocks = split_blocks(body)
    assert len(blocks) == 3
    assert blocks[0] == "First block."
    assert blocks[1] == "Second block."
    assert blocks[2] == "Third block."


def test_split_blocks_empty_input() -> None:
    assert split_blocks("") == []


def test_split_blocks_handles_extra_blank_lines() -> None:
    body = "First.\n\n\n\nSecond."
    blocks = split_blocks(body)
    assert blocks == ["First.", "Second."]


def test_split_blocks_preserves_code_fences() -> None:
    body = (
        "Intro paragraph.\n"
        "\n"
        "```python\n"
        "def foo():\n"
        "\n"
        "    return 42\n"
        "```\n"
        "\n"
        "Outro paragraph.\n"
    )
    blocks = split_blocks(body)
    # Code fence stays as one block despite internal blank line
    assert len(blocks) == 3
    assert "def foo():" in blocks[1]
    assert "return 42" in blocks[1]


def test_strip_frontmatter_removes_yaml() -> None:
    text = "---\ntitle: Hello\n---\nBody content\n"
    body = strip_frontmatter(text)
    assert body == "Body content\n"


def test_strip_frontmatter_no_frontmatter() -> None:
    text = "Just a body, no frontmatter.\n"
    assert strip_frontmatter(text) == text


def test_strip_frontmatter_malformed_left_alone() -> None:
    text = "---\nno close\nbody\n"
    # No matching `---` close means the function returns the text unchanged
    assert strip_frontmatter(text) == text


def test_hasher_wraps_body_and_blocks() -> None:
    body = "# Article\n\nPara one.\n\nPara two.\n"
    h = Hasher(body)
    assert h.body_hash
    assert len(h.blocks) == 3
    assert len(h.block_hashes) == 3
    assert h.block_hashes[0] != h.block_hashes[1]


def test_hash_changes_when_block_edited() -> None:
    body1 = "# Title\n\nFirst.\n\nSecond.\n"
    body2 = "# Title\n\nFirst edited.\n\nSecond.\n"
    h1 = Hasher(body1)
    h2 = Hasher(body2)
    # Body hash differs
    assert h1.body_hash != h2.body_hash
    # Only block 1 (the edited one) should differ
    assert h1.block_hashes[0] == h2.block_hashes[0]  # title unchanged
    assert h1.block_hashes[1] != h2.block_hashes[1]  # edited
    assert h1.block_hashes[2] == h2.block_hashes[2]  # unchanged
