"""Tests for translate.validators."""
from __future__ import annotations

from pathlib import Path

import pytest

from translate.glossary import Glossary
from translate.hasher import hash_body
from translate.validators import (
    ScriptPurityValidator,
    StalenessValidator,
    TerminologyLinter,
)


SAMPLE_GLOSSARY_DATA = {
    "version": "2026.05",
    "language_primary": "en",
    "entries": [
        {
            "term": "Logos",
            "definition": "Inherent harmonic intelligence.",
            "adoption": "native",
            "translations": {"fr": "Logos", "ar": "اللوغوس"},
        },
        {
            "term": "Companion",
            "definition": "Deprecated MunAI display name.",
            "deprecated": True,
        },
    ],
}


def _write(tmp_path: Path, name: str, content: str) -> Path:
    p = tmp_path / name
    p.write_text(content, encoding="utf-8")
    return p


# ─── Staleness ──────────────────────────────────────────────────────────────


def test_staleness_fresh_when_hashes_match(tmp_path: Path) -> None:
    source_body = "# Logos\n\nLogos is the inherent harmonic intelligence.\n"
    source_path = _write(tmp_path, "source.md", source_body)
    h = hash_body(source_body)

    translation_body = f"---\nsource_hash: {h}\nlanguage: fr\n---\n# Logos\n\nLogos est...\n"
    translation_path = _write(tmp_path, "translation.md", translation_body)

    validator = StalenessValidator()
    result = validator.check(translation_path=translation_path, source_path=source_path)
    assert result.is_fresh
    assert result.reason == "fresh"


def test_staleness_drift_when_hashes_differ(tmp_path: Path) -> None:
    source_body = "# Logos\n\nUpdated content.\n"
    source_path = _write(tmp_path, "source.md", source_body)

    # Translation has stale hash
    translation_body = "---\nsource_hash: 0000000000000000\nlanguage: fr\n---\nLogos est...\n"
    translation_path = _write(tmp_path, "translation.md", translation_body)

    validator = StalenessValidator()
    result = validator.check(translation_path=translation_path, source_path=source_path)
    assert result.is_stale
    assert result.reason == "drift"


def test_staleness_missing_manifest_hash(tmp_path: Path) -> None:
    source_path = _write(tmp_path, "source.md", "body")
    translation_body = "---\nlanguage: fr\n---\nLogos est...\n"  # no source_hash
    translation_path = _write(tmp_path, "translation.md", translation_body)

    validator = StalenessValidator()
    result = validator.check(translation_path=translation_path, source_path=source_path)
    assert result.is_stale
    assert result.reason == "missing_manifest_hash"


def test_staleness_missing_source(tmp_path: Path) -> None:
    translation_path = _write(tmp_path, "translation.md", "---\nsource_hash: x\n---\n")
    missing = tmp_path / "does-not-exist.md"

    validator = StalenessValidator()
    result = validator.check(translation_path=translation_path, source_path=missing)
    assert result.is_stale
    assert result.reason == "missing_source"


# ─── Terminology ────────────────────────────────────────────────────────────


def test_terminology_linter_flags_deprecated_term(tmp_path: Path) -> None:
    glossary = Glossary.from_dict(SAMPLE_GLOSSARY_DATA)
    translation_body = (
        "---\nlanguage: fr\nsource_hash: abc\n---\n"
        "# Translation\n\nThe Companion guides you...\n"
    )
    translation_path = _write(tmp_path, "translation.md", translation_body)

    linter = TerminologyLinter(glossary)
    findings = linter.lint(translation_path, language="fr")
    assert len(findings) == 1
    assert findings[0].category == "deprecated_term"
    assert findings[0].term == "Companion"
    assert findings[0].severity == "error"


def test_terminology_linter_no_findings_when_clean(tmp_path: Path) -> None:
    glossary = Glossary.from_dict(SAMPLE_GLOSSARY_DATA)
    translation_body = "---\nlanguage: fr\nsource_hash: abc\n---\nLogos est l'intelligence harmonique.\n"
    translation_path = _write(tmp_path, "translation.md", translation_body)

    linter = TerminologyLinter(glossary)
    findings = linter.lint(translation_path, language="fr")
    assert findings == []


def test_terminology_linter_missing_sanctioned_against_source(tmp_path: Path) -> None:
    glossary = Glossary.from_dict(SAMPLE_GLOSSARY_DATA)
    source_body = "Logos is the principle of cosmic order.\n"
    source_path = _write(tmp_path, "source.md", source_body)

    # Translation paraphrases away "Logos"
    translation_body = (
        "---\nlanguage: fr\nsource_hash: abc\n---\n"
        "Le principe de l'ordre cosmique est central.\n"
    )
    translation_path = _write(tmp_path, "translation.md", translation_body)

    linter = TerminologyLinter(glossary)
    findings = linter.lint_against_source(translation_path, source_path, language="fr")
    # Should flag missing sanctioned "Logos" translation
    missing = [f for f in findings if f.category == "missing_sanctioned"]
    assert len(missing) == 1
    assert missing[0].term == "Logos"


# ─── Script Purity ──────────────────────────────────────────────────────────


def test_script_purity_clean_arabic(tmp_path: Path) -> None:
    translation_body = "---\nlanguage: ar\n---\nهذا نص عربي.\n"
    translation_path = _write(tmp_path, "translation.md", translation_body)

    validator = ScriptPurityValidator()
    findings = validator.check(translation_path, language="ar")
    assert findings == []


def test_script_purity_flags_cyrillic_in_arabic(tmp_path: Path) -> None:
    # Empirically-observed Groq Llama failure: injects Cyrillic chars into Arabic
    translation_body = "---\nlanguage: ar\n---\nهذا نص объявление عربي.\n"
    translation_path = _write(tmp_path, "translation.md", translation_body)

    validator = ScriptPurityValidator()
    findings = validator.check(translation_path, language="ar")
    assert len(findings) >= 1
    assert all(f.category == "script_purity" for f in findings)


def test_script_purity_unsupported_language_returns_empty(tmp_path: Path) -> None:
    translation_body = "---\nlanguage: ko\n---\nText\n"
    translation_path = _write(tmp_path, "translation.md", translation_body)

    validator = ScriptPurityValidator()
    findings = validator.check(translation_path, language="ko")  # no pattern for Korean
    assert findings == []
