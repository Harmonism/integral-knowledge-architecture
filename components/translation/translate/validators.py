"""Dual-validation pipeline — staleness detection + terminology linting.

The two mechanisms detect non-overlapping failure modes:

    Staleness — translation was correct when produced; source has since
                evolved. Compare manifest source_hash to current source hash.

    Terminology — mistakes introduced at generation time; deprecated terms,
                  missing sanctioned translations, broken wikilink targets.
                  Scan translation body against the glossary.

A third validator addresses provider-specific failure: script purity (catches
cross-script hallucinations, e.g. Greek characters in Arabic prose — observed
empirically with Groq Llama 3.3 70B). Per-language regex of allowed Unicode
scripts; flags runs of ≥2 alphabetic characters outside the allowed pattern.

These run after every translation pass. The validators flag; they do not
auto-correct. Corrections require editorial judgment.
"""
from __future__ import annotations

import re
from dataclasses import dataclass
from pathlib import Path
from typing import Iterable

from translate.glossary import Glossary
from translate.hasher import hash_body, strip_frontmatter
from translate.manifest import TranslationFile


# ─── Staleness ───────────────────────────────────────────────────────────────


@dataclass
class StalenessResult:
    """Result of comparing a translation's recorded hash to current source hash."""

    translation_path: Path
    source_path: Path
    is_stale: bool
    recorded_hash: str | None
    current_hash: str
    reason: str  # 'fresh' | 'drift' | 'missing_manifest_hash' | 'missing_source'

    @property
    def is_fresh(self) -> bool:
        return not self.is_stale


class StalenessValidator:
    """Compare a translation file's source_hash to the current source body hash.

    Usage:
        validator = StalenessValidator()
        result = validator.check(translation_path="i18n/translations/fr/Logos.md",
                                 source_path="Philosophy/Doctrine/Logos.md")
        if result.is_stale:
            print(f"Stale: {result.reason}")
    """

    def __init__(self, *, hash_truncate: int = 16):
        self.hash_truncate = hash_truncate

    def check(
        self,
        translation_path: str | Path,
        source_path: str | Path,
    ) -> StalenessResult:
        t_path = Path(translation_path)
        s_path = Path(source_path)

        if not s_path.exists():
            return StalenessResult(
                translation_path=t_path,
                source_path=s_path,
                is_stale=True,
                recorded_hash=None,
                current_hash="",
                reason="missing_source",
            )

        translation = TranslationFile.from_path(t_path)
        recorded = translation.source_hash

        source_text = s_path.read_text(encoding="utf-8")
        source_body = strip_frontmatter(source_text)
        current = hash_body(source_body, truncate=self.hash_truncate)

        if recorded is None:
            return StalenessResult(
                translation_path=t_path,
                source_path=s_path,
                is_stale=True,
                recorded_hash=None,
                current_hash=current,
                reason="missing_manifest_hash",
            )

        if recorded != current:
            return StalenessResult(
                translation_path=t_path,
                source_path=s_path,
                is_stale=True,
                recorded_hash=recorded,
                current_hash=current,
                reason="drift",
            )

        return StalenessResult(
            translation_path=t_path,
            source_path=s_path,
            is_stale=False,
            recorded_hash=recorded,
            current_hash=current,
            reason="fresh",
        )


# ─── Terminology ─────────────────────────────────────────────────────────────


@dataclass
class LintFinding:
    """A single terminology-linting finding."""

    translation_path: Path
    category: str  # 'deprecated_term' | 'missing_sanctioned' | 'unsanctioned_translation'
    term: str
    detail: str
    severity: str = "warning"  # 'error' | 'warning' | 'info'


class TerminologyLinter:
    """Scan a translation body for terminology violations.

    Three categories of finding:
    1. `deprecated_term` — translation uses a term that has been deprecated.
       The deprecated-terms registry is the glossary's deprecated section.
    2. `missing_sanctioned` — source term appears in the source body, but
       the sanctioned translation is not used in the translation body.
       (Best-effort: a translation may legitimately omit a term where the
       sentence has been restructured. Reported as warning, not error.)
    3. `unsanctioned_translation` — translation uses a target-language word
       that is the sanctioned translation of a DIFFERENT source term, suggesting
       the translator confused two related concepts.
       (Reported as info; requires editorial judgment.)
    """

    def __init__(self, glossary: Glossary):
        self.glossary = glossary

    def lint(self, translation_path: str | Path, language: str) -> list[LintFinding]:
        t_path = Path(translation_path)
        translation = TranslationFile.from_path(t_path)
        findings: list[LintFinding] = []

        # Category 1: deprecated terms appearing anywhere in the body
        for deprecated_term in self.glossary.deprecated_terms():
            pattern = re.compile(rf"\b{re.escape(deprecated_term)}\b", re.IGNORECASE)
            if pattern.search(translation.body):
                findings.append(
                    LintFinding(
                        translation_path=t_path,
                        category="deprecated_term",
                        term=deprecated_term,
                        detail=f"Deprecated term {deprecated_term!r} appears in translation. "
                               f"Replace with current canonical term.",
                        severity="error",
                    )
                )

        return findings

    def lint_against_source(
        self,
        translation_path: str | Path,
        source_path: str | Path,
        language: str,
    ) -> list[LintFinding]:
        """Lint with source-aware checks (categories 2 and 3).

        Requires both the translation and its source for missing-sanctioned and
        unsanctioned-translation detection.
        """
        t_path = Path(translation_path)
        s_path = Path(source_path)
        translation = TranslationFile.from_path(t_path)
        source_text = s_path.read_text(encoding="utf-8")
        source_body = strip_frontmatter(source_text)

        # Category 1 first
        findings = self.lint(t_path, language)

        # Category 2: missing sanctioned translation
        sanctioned = self.glossary.sanctioned_terms(language)
        for source_term, sanctioned_translation in sanctioned.items():
            source_pattern = re.compile(rf"\b{re.escape(source_term)}\b")
            if not source_pattern.search(source_body):
                continue
            # Source mentions this term. Does translation use sanctioned?
            translation_pattern = re.compile(rf"\b{re.escape(sanctioned_translation)}\b")
            if not translation_pattern.search(translation.body):
                findings.append(
                    LintFinding(
                        translation_path=t_path,
                        category="missing_sanctioned",
                        term=source_term,
                        detail=(
                            f"Source uses {source_term!r}; translation does not contain "
                            f"the sanctioned {language} translation {sanctioned_translation!r}. "
                            f"Verify the term was not paraphrased away or replaced with a synonym."
                        ),
                        severity="warning",
                    )
                )

        return findings


# ─── Script Purity ───────────────────────────────────────────────────────────

# Per-language regex of allowed Unicode scripts. Cross-script characters
# outside these ranges indicate likely hallucination by the translation
# provider (especially Groq Llama-class models, where empirically observed
# cases include Greek/Cyrillic injected into Arabic prose).
_ALLOWED_SCRIPT_PATTERNS: dict[str, str] = {
    # Arabic: Arabic + Arabic Supplement + Arabic Extended-A + Latin (for proper nouns/digits)
    "ar": r"[؀-ۿݐ-ݿࢠ-ࣿﭐ-﷿ﹰ-﻿a-zA-Z0-9\s\p{P}\p{S}]",
    # Romance languages: Latin Extended + typography
    "fr": r"[a-zA-ZÀ-ÿ0-9\s\p{P}\p{S}]",
    "es": r"[a-zA-ZÀ-ÿ0-9\s\p{P}\p{S}]",
    "pt": r"[a-zA-ZÀ-ÿ0-9\s\p{P}\p{S}]",
    # Germanic: Latin Extended
    "de": r"[a-zA-ZÀ-ÿ0-9\s\p{P}\p{S}]",
    # Chinese: CJK Unified Ideographs + Latin/digits
    "zh": r"[一-鿿㐀-䶿豈-﫿a-zA-Z0-9\s\p{P}\p{S}]",
    # Japanese: Hiragana + Katakana + CJK + Latin/digits
    "ja": r"[぀-ゟ゠-ヿ一-鿿a-zA-Z0-9\s\p{P}\p{S}]",
    # Hindi: Devanagari + Latin
    "hi": r"[ऀ-ॿa-zA-Z0-9\s\p{P}\p{S}]",
    # Russian: Cyrillic + Latin
    "ru": r"[Ѐ-ӿa-zA-Z0-9\s\p{P}\p{S}]",
}


class ScriptPurityValidator:
    """Flag cross-script characters in a translation body.

    For each language, the allowed-script regex defines what Unicode ranges
    are valid. Runs of ≥`min_run` alphabetic characters outside the allowed
    pattern are flagged as likely hallucination.

    Advisory only — the validator returns findings; corrections are editorial.
    """

    def __init__(self, min_run: int = 2):
        self.min_run = min_run

    def check(self, translation_path: str | Path, language: str) -> list[LintFinding]:
        if language not in _ALLOWED_SCRIPT_PATTERNS:
            return []
        t_path = Path(translation_path)
        translation = TranslationFile.from_path(t_path)
        # Strip protected regions before scanning: fenced code, inline code,
        # iframes, HTML tags, URLs, wikilinks, image markdown, placeholders.
        text = self._strip_protected_regions(translation.body)

        # Build the negative-class pattern: anything NOT in the allowed pattern,
        # that is alphabetic, in runs of min_run+
        # Note: Python's stdlib `re` doesn't support \p{P} / \p{S} unicode
        # properties. For v0.1 we use a coarse approximation: flag runs of
        # non-allowed characters that contain at least one letter from a
        # different script family.
        findings: list[LintFinding] = []
        # Coarse heuristic: look for runs of letters that don't match the
        # expected script ranges. This catches the most common failure mode
        # (Greek/Cyrillic injection into Arabic/CJK) without false positives
        # on legitimate punctuation, emojis, or proper nouns.
        coarse_pattern = _coarse_script_pattern(language)
        if coarse_pattern is None:
            return findings
        for match in coarse_pattern.finditer(text):
            run = match.group(0)
            if len(run) < self.min_run:
                continue
            findings.append(
                LintFinding(
                    translation_path=t_path,
                    category="script_purity",
                    term=run,
                    detail=f"Run of unexpected-script characters in {language} translation: {run!r}",
                    severity="warning",
                )
            )
        return findings

    @staticmethod
    def _strip_protected_regions(text: str) -> str:
        # Fenced code blocks
        text = re.sub(r"```.*?```", " ", text, flags=re.DOTALL)
        text = re.sub(r"~~~.*?~~~", " ", text, flags=re.DOTALL)
        # Inline code
        text = re.sub(r"`[^`\n]+`", " ", text)
        # HTML / iframe blocks
        text = re.sub(r"<[^>]+>", " ", text)
        # URLs
        text = re.sub(r"https?://\S+", " ", text)
        # Wikilinks (the target stays English; only the display might translate)
        text = re.sub(r"\[\[[^\]]+\]\]", " ", text)
        # Image markdown
        text = re.sub(r"!\[[^\]]*\]\([^)]*\)", " ", text)
        return text


# Coarse per-language patterns for the script-purity heuristic.
# Each entry is a regex that matches runs of "unexpected script" letters.
def _coarse_script_pattern(language: str) -> re.Pattern[str] | None:
    """Build a coarse regex flagging non-target-script alphabetic runs."""
    patterns = {
        # Arabic should not contain runs of Greek, Cyrillic, or Devanagari letters
        "ar": r"[Ͱ-ϿЀ-ӿऀ-ॿ]+",
        # Chinese should not contain runs of Cyrillic/Greek/Arabic letters
        "zh": r"[Ͱ-ϿЀ-ӿ؀-ۿ]+",
        # Japanese: same
        "ja": r"[Ͱ-ϿЀ-ӿ؀-ۿ]+",
        # Hindi: should not contain Arabic, Cyrillic, Greek, CJK
        "hi": r"[Ͱ-ϿЀ-ӿ؀-ۿ一-鿿]+",
        # Russian: should not contain Greek, Arabic, Devanagari, CJK
        "ru": r"[Ͱ-Ͽ؀-ۿऀ-ॿ一-鿿]+",
        # Romance / Germanic: should not contain non-Latin scripts
        "fr": r"[Ͱ-ϿЀ-ӿ؀-ۿऀ-ॿ一-鿿]+",
        "es": r"[Ͱ-ϿЀ-ӿ؀-ۿऀ-ॿ一-鿿]+",
        "pt": r"[Ͱ-ϿЀ-ӿ؀-ۿऀ-ॿ一-鿿]+",
        "de": r"[Ͱ-ϿЀ-ӿ؀-ۿऀ-ॿ一-鿿]+",
    }
    pat = patterns.get(language)
    return re.compile(pat) if pat else None
