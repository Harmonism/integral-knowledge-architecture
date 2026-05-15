"""Per-tradition glossary — the doctrinal authority for terminology.

A glossary maps each canonical source-language term to its sanctioned
translation in each target language, with an adoption-status tag controlling
how the term is handled by the translation pipeline.

Three adoption statuses:
    native           — Tradition's primary vocabulary. Translates per glossary.
    tradition-specific — Used in cross-tradition contexts but doesn't lead.
                         May translate or stay verbatim depending on context.
    untranslatable   — Preserved verbatim across all languages.
                       Protected upstream so providers never see the term.

A glossary may also carry a `deprecated` section listing renamed concepts.
When a term is renamed, the old name moves to `deprecated`, and the linter
flags any translation still using the old name.
"""
from __future__ import annotations

from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

import yaml


class GlossaryError(Exception):
    """Raised on glossary loading or validation errors."""


@dataclass
class GlossaryEntry:
    """A single term in the glossary."""

    term: str
    definition: str
    adoption: str = "native"
    translations: dict[str, str] = field(default_factory=dict)
    source_tradition: str | None = None
    aliases: list[str] = field(default_factory=list)
    deprecated: bool = False

    @classmethod
    def from_dict(cls, d: dict[str, Any]) -> GlossaryEntry:
        if "term" not in d:
            raise GlossaryError(f"glossary entry missing 'term': {d}")
        if "definition" not in d:
            raise GlossaryError(f"glossary entry missing 'definition' for term {d['term']!r}")
        adoption = d.get("adoption", "native")
        if adoption not in ("native", "tradition-specific", "untranslatable"):
            raise GlossaryError(
                f"glossary entry {d['term']!r} has invalid adoption {adoption!r} "
                f"(must be native | tradition-specific | untranslatable)"
            )
        return cls(
            term=d["term"],
            definition=d["definition"],
            adoption=adoption,
            translations=d.get("translations", {}) or {},
            source_tradition=d.get("source_tradition"),
            aliases=d.get("aliases", []) or [],
            deprecated=bool(d.get("deprecated", False)),
        )

    def sanctioned(self, lang: str) -> str | None:
        """Return the sanctioned translation for `lang`, or None if not defined."""
        return self.translations.get(lang)


class Glossary:
    """Loaded glossary indexed for fast lookup.

    Usage:
        glossary = Glossary.from_path("glossary.yaml")
        entry = glossary.lookup("Logos")
        if entry:
            fr_translation = entry.sanctioned("fr")  # "Logos"
        for term in glossary.untranslatable_terms():
            ...  # protect these from any translation provider
    """

    def __init__(
        self,
        entries: list[GlossaryEntry],
        version: str,
        language_primary: str = "en",
    ):
        self.entries = entries
        self.version = version
        self.language_primary = language_primary
        self._by_term: dict[str, GlossaryEntry] = {}
        self._by_alias: dict[str, GlossaryEntry] = {}
        for entry in entries:
            self._by_term[entry.term.lower()] = entry
            for alias in entry.aliases:
                self._by_alias[alias.lower()] = entry

    @classmethod
    def from_dict(cls, data: dict[str, Any]) -> Glossary:
        if "entries" not in data:
            raise GlossaryError("glossary missing 'entries' key")
        if not isinstance(data["entries"], list):
            raise GlossaryError("glossary 'entries' must be a list")
        entries = [GlossaryEntry.from_dict(e) for e in data["entries"]]
        version = str(data.get("version", "unknown"))
        language_primary = data.get("language_primary", "en")
        return cls(entries=entries, version=version, language_primary=language_primary)

    @classmethod
    def from_path(cls, path: str | Path) -> Glossary:
        p = Path(path)
        try:
            data = yaml.safe_load(p.read_text(encoding="utf-8"))
        except FileNotFoundError as e:
            raise GlossaryError(f"glossary not found at {p}") from e
        except yaml.YAMLError as e:
            raise GlossaryError(f"glossary at {p} is not valid YAML: {e}") from e
        if not isinstance(data, dict):
            raise GlossaryError(f"glossary at {p} is not a YAML mapping")
        return cls.from_dict(data)

    # ── Lookups ──────────────────────────────────────────────────────────────

    def lookup(self, term: str) -> GlossaryEntry | None:
        """Find a glossary entry by term (case-insensitive) or alias."""
        key = term.lower()
        entry = self._by_term.get(key)
        if entry is None:
            entry = self._by_alias.get(key)
        return entry

    def untranslatable_terms(self) -> list[str]:
        """All terms with adoption=untranslatable. Protect these upstream."""
        return [e.term for e in self.entries if e.adoption == "untranslatable"]

    def deprecated_terms(self) -> list[str]:
        """All terms flagged deprecated. The linter scans translations for these."""
        return [e.term for e in self.entries if e.deprecated]

    def sanctioned_terms(self, lang: str) -> dict[str, str]:
        """All source-language terms that have a sanctioned translation in `lang`.

        Returns a dict mapping source term → sanctioned translation. Excludes
        untranslatable terms (those should stay verbatim, not be substituted).
        """
        out: dict[str, str] = {}
        for entry in self.entries:
            if entry.adoption == "untranslatable":
                continue
            translation = entry.sanctioned(lang)
            if translation is not None:
                out[entry.term] = translation
        return out

    def languages(self) -> set[str]:
        """All languages that appear in any entry's translations dict."""
        out: set[str] = set()
        for entry in self.entries:
            out.update(entry.translations.keys())
        return out

    def __len__(self) -> int:
        return len(self.entries)

    def __repr__(self) -> str:
        return f"Glossary(version={self.version!r}, entries={len(self.entries)})"
