"""Translation file manifest — frontmatter parsing and metadata extraction.

A translation file is a Markdown document whose YAML frontmatter carries the
pipeline's integrity and provenance metadata:
    source_hash     — REQUIRED. SHA-256 of source body at translation time.
    para_hashes     — RECOMMENDED. List of per-block hashes for smart-translate.
    language        — language code of the translation.
    translated_by   — provider identifier.
    translated_at   — ISO 8601 timestamp.

This module loads translation files, parses frontmatter, and exposes a typed
view. Schema validation against `translation-manifest.schema.json` is available
via `validate_against_schema()`.
"""
from __future__ import annotations

from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

import yaml


class ManifestError(Exception):
    """Raised when a translation file's frontmatter is malformed."""


_FRONTMATTER_DELIM = "---\n"


def parse_frontmatter(text: str) -> tuple[dict[str, Any], str]:
    """Parse YAML frontmatter from a Markdown document.

    Returns (frontmatter_dict, body_text). If no frontmatter is present,
    returns ({}, text).
    """
    if not text.startswith(_FRONTMATTER_DELIM):
        return ({}, text)
    end = text.find("\n" + _FRONTMATTER_DELIM[:-1] + "\n", len(_FRONTMATTER_DELIM))
    if end == -1:
        return ({}, text)
    fm_yaml = text[len(_FRONTMATTER_DELIM):end]
    body = text[end + len(_FRONTMATTER_DELIM) + 1:]
    try:
        fm = yaml.safe_load(fm_yaml)
    except yaml.YAMLError as e:
        raise ManifestError(f"frontmatter is not valid YAML: {e}") from e
    if fm is None:
        return ({}, body)
    if not isinstance(fm, dict):
        raise ManifestError(f"frontmatter is not a YAML mapping (got {type(fm).__name__})")
    return (fm, body)


@dataclass
class TranslationFile:
    """A loaded translation file with typed access to its manifest fields."""

    path: Path
    frontmatter: dict[str, Any]
    body: str

    @classmethod
    def from_path(cls, path: str | Path) -> TranslationFile:
        p = Path(path)
        try:
            text = p.read_text(encoding="utf-8")
        except FileNotFoundError as e:
            raise ManifestError(f"translation file not found at {p}") from e
        fm, body = parse_frontmatter(text)
        return cls(path=p, frontmatter=fm, body=body)

    @property
    def source_hash(self) -> str | None:
        """The source_hash declared in frontmatter, or None if absent."""
        v = self.frontmatter.get("source_hash")
        return str(v) if v is not None else None

    @property
    def para_hashes(self) -> list[str]:
        """The para_hashes list from frontmatter. Empty if absent."""
        v = self.frontmatter.get("para_hashes", [])
        if not isinstance(v, list):
            return []
        return [str(h) for h in v]

    @property
    def language(self) -> str | None:
        v = self.frontmatter.get("language")
        return str(v) if v is not None else None

    @property
    def translated_by(self) -> str | None:
        v = self.frontmatter.get("translated_by")
        return str(v) if v is not None else None

    @property
    def translated_at(self) -> str | None:
        v = self.frontmatter.get("translated_at")
        return str(v) if v is not None else None

    @property
    def title(self) -> str | None:
        v = self.frontmatter.get("title")
        return str(v) if v is not None else None

    def __repr__(self) -> str:
        return (
            f"TranslationFile(path={self.path}, "
            f"language={self.language!r}, "
            f"source_hash={self.source_hash!r})"
        )
