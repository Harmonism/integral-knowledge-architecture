"""Tests for translate.glossary."""
from __future__ import annotations

import pytest

from translate.glossary import Glossary, GlossaryEntry, GlossaryError


SAMPLE_GLOSSARY = {
    "version": "2026.05",
    "language_primary": "en",
    "entries": [
        {
            "term": "Logos",
            "definition": "The inherent harmonic intelligence of the cosmos.",
            "adoption": "native",
            "translations": {"fr": "Logos", "es": "Logos", "ar": "اللوغوس"},
        },
        {
            "term": "Ṛta",
            "definition": "Vedic cognate of Logos.",
            "adoption": "tradition-specific",
            "source_tradition": "indian",
            "deprecated": True,
        },
        {
            "term": "Harmonism",
            "definition": "The philosophical system.",
            "adoption": "untranslatable",
        },
    ],
}


def test_glossary_loads_from_dict() -> None:
    g = Glossary.from_dict(SAMPLE_GLOSSARY)
    assert len(g) == 3
    assert g.version == "2026.05"
    assert g.language_primary == "en"


def test_glossary_lookup_case_insensitive() -> None:
    g = Glossary.from_dict(SAMPLE_GLOSSARY)
    assert g.lookup("Logos") is not None
    assert g.lookup("logos") is not None
    assert g.lookup("LOGOS") is not None
    assert g.lookup("Harmonism") is not None
    assert g.lookup("nonexistent") is None


def test_glossary_sanctioned_translation() -> None:
    g = Glossary.from_dict(SAMPLE_GLOSSARY)
    entry = g.lookup("Logos")
    assert entry is not None
    assert entry.sanctioned("fr") == "Logos"
    assert entry.sanctioned("ar") == "اللوغوس"
    assert entry.sanctioned("ja") is None


def test_glossary_untranslatable_terms() -> None:
    g = Glossary.from_dict(SAMPLE_GLOSSARY)
    untranslatable = g.untranslatable_terms()
    assert "Harmonism" in untranslatable
    assert "Logos" not in untranslatable


def test_glossary_deprecated_terms() -> None:
    g = Glossary.from_dict(SAMPLE_GLOSSARY)
    deprecated = g.deprecated_terms()
    assert "Ṛta" in deprecated
    assert "Logos" not in deprecated


def test_glossary_sanctioned_terms_excludes_untranslatable() -> None:
    g = Glossary.from_dict(SAMPLE_GLOSSARY)
    fr_terms = g.sanctioned_terms("fr")
    assert "Logos" in fr_terms
    assert fr_terms["Logos"] == "Logos"
    assert "Harmonism" not in fr_terms  # untranslatable terms are not in sanctioned set


def test_glossary_languages() -> None:
    g = Glossary.from_dict(SAMPLE_GLOSSARY)
    langs = g.languages()
    assert "fr" in langs
    assert "es" in langs
    assert "ar" in langs


def test_glossary_invalid_adoption_raises() -> None:
    bad = {
        "version": "2026.05",
        "entries": [{"term": "X", "definition": "Y", "adoption": "invalid-value"}],
    }
    with pytest.raises(GlossaryError):
        Glossary.from_dict(bad)


def test_glossary_missing_term_raises() -> None:
    bad = {
        "version": "2026.05",
        "entries": [{"definition": "no term"}],
    }
    with pytest.raises(GlossaryError):
        Glossary.from_dict(bad)


def test_glossary_missing_definition_raises() -> None:
    bad = {
        "version": "2026.05",
        "entries": [{"term": "X"}],
    }
    with pytest.raises(GlossaryError):
        Glossary.from_dict(bad)


def test_glossary_lookup_by_alias() -> None:
    data = {
        "version": "2026.05",
        "entries": [
            {
                "term": "Logos",
                "definition": "x",
                "aliases": ["the Word", "logos-principle"],
            }
        ],
    }
    g = Glossary.from_dict(data)
    assert g.lookup("the Word") is not None
    assert g.lookup("logos-principle") is not None


def test_harmonism_reference_glossary_loads() -> None:
    """The bundled Harmonism reference glossary must load."""
    from pathlib import Path
    repo_root = Path(__file__).parent.parent.parent.parent
    glossary_path = repo_root / "components" / "sdip" / "bundles" / "harmonism" / "glossary.yaml"
    if not glossary_path.exists():
        pytest.skip("Harmonism glossary not present in this checkout")
    g = Glossary.from_path(glossary_path)
    assert len(g) > 0
    # The Harmonism glossary should have Logos as native
    logos = g.lookup("Logos")
    assert logos is not None
    assert logos.adoption == "native"
