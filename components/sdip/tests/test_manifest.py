"""Tests for sdip.manifest."""
from __future__ import annotations

import json
from pathlib import Path

import pytest

from sdip.manifest import Manifest, ManifestError


VALID_MANIFEST = {
    "protocol": "sdip",
    "protocol_version": "0.1",
    "tradition_id": "test-tradition",
    "tradition_name": "Test Tradition",
    "version": "2026.05",
    "language_primary": "en",
    "license_corpus": "CC-BY-4.0",
    "license_harness": "AGPL-3.0-or-later",
    "publisher": {"name": "Test Publisher"},
    "integrity": {
        "backbone_sha256": "0" * 64,
        "glossary_sha256": "1" * 64,
        "calibrations_sha256": "2" * 64,
    },
}


def test_valid_manifest_parses() -> None:
    m = Manifest.from_dict(VALID_MANIFEST)
    assert m.protocol == "sdip"
    assert m.protocol_version == "0.1"
    assert m.tradition_id == "test-tradition"
    assert m.publisher.name == "Test Publisher"
    assert m.integrity.backbone_sha256 == "0" * 64


def test_missing_required_field_raises() -> None:
    incomplete = {k: v for k, v in VALID_MANIFEST.items() if k != "tradition_id"}
    with pytest.raises(ManifestError):
        Manifest.from_dict(incomplete)


def test_wrong_protocol_value_raises() -> None:
    bad = dict(VALID_MANIFEST, protocol="not-sdip")
    with pytest.raises(ManifestError):
        Manifest.from_dict(bad)


def test_invalid_version_format_raises() -> None:
    bad = dict(VALID_MANIFEST, version="not-a-date")
    with pytest.raises(ManifestError):
        Manifest.from_dict(bad)


def test_invalid_tradition_id_raises() -> None:
    bad = dict(VALID_MANIFEST, tradition_id="Invalid With Spaces")
    with pytest.raises(ManifestError):
        Manifest.from_dict(bad)


def test_invalid_hash_format_raises() -> None:
    bad = dict(VALID_MANIFEST)
    bad["integrity"] = dict(bad["integrity"], backbone_sha256="not-a-hash")
    with pytest.raises(ManifestError):
        Manifest.from_dict(bad)


def test_roundtrip_through_path(tmp_path: Path) -> None:
    p = tmp_path / "manifest.json"
    p.write_text(json.dumps(VALID_MANIFEST), encoding="utf-8")
    m = Manifest.from_path(p)
    assert m.tradition_id == "test-tradition"


def test_to_dict_preserves_raw() -> None:
    m = Manifest.from_dict(VALID_MANIFEST)
    d = m.to_dict()
    assert d["protocol"] == "sdip"
    assert d["tradition_id"] == "test-tradition"


def test_harmonism_reference_manifest_validates() -> None:
    """The bundled Harmonism reference manifest must be valid."""
    repo_root = Path(__file__).parent.parent
    manifest_path = repo_root / "bundles" / "harmonism" / "manifest.json"
    if not manifest_path.exists():
        pytest.skip("Harmonism reference manifest not present in this checkout")
    m = Manifest.from_path(manifest_path)
    assert m.tradition_id == "harmonism"
    assert m.protocol_version == "0.1"
