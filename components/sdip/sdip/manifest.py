"""Manifest model and validation for SDIP bundles.

The manifest is the bundle's identity-and-integrity document. This module
loads, validates, and round-trips manifest.json against the schema at
schemas/manifest.schema.json.
"""
from __future__ import annotations

import json
from dataclasses import dataclass, field
from importlib import resources
from pathlib import Path
from typing import Any

import jsonschema


class ManifestError(Exception):
    """Raised when a manifest is malformed or fails validation."""


@dataclass
class PublisherInfo:
    name: str
    url: str | None = None
    contact: str | None = None
    public_key: str | None = None


@dataclass
class IntegrityHashes:
    backbone_sha256: str
    glossary_sha256: str
    calibrations_sha256: str
    corpus_sha256: str | None = None


@dataclass
class Manifest:
    """A typed view of a SDIP bundle manifest."""

    protocol: str
    protocol_version: str
    tradition_id: str
    tradition_name: str
    version: str
    language_primary: str
    license_corpus: str
    license_harness: str
    publisher: PublisherInfo
    integrity: IntegrityHashes

    languages: list[str] = field(default_factory=list)
    license_spec: str | None = None
    publisher_signature: str | None = None
    spec_url: str | None = None
    bundle_url: str | None = None
    published_at: str | None = None
    previous_version: str | None = None

    raw: dict[str, Any] = field(default_factory=dict, repr=False)

    @classmethod
    def from_dict(cls, data: dict[str, Any]) -> Manifest:
        """Parse and validate a manifest dict.

        Raises ManifestError on any schema violation.
        """
        _validate_against_schema(data)
        pub = data.get("publisher", {})
        integ = data.get("integrity", {})

        return cls(
            protocol=data["protocol"],
            protocol_version=data["protocol_version"],
            tradition_id=data["tradition_id"],
            tradition_name=data["tradition_name"],
            version=data["version"],
            language_primary=data["language_primary"],
            languages=data.get("languages", []),
            license_corpus=data["license_corpus"],
            license_harness=data["license_harness"],
            license_spec=data.get("license_spec"),
            publisher=PublisherInfo(
                name=pub["name"],
                url=pub.get("url"),
                contact=pub.get("contact"),
                public_key=pub.get("public_key"),
            ),
            publisher_signature=data.get("publisher_signature"),
            integrity=IntegrityHashes(
                backbone_sha256=integ["backbone_sha256"],
                glossary_sha256=integ["glossary_sha256"],
                calibrations_sha256=integ["calibrations_sha256"],
                corpus_sha256=integ.get("corpus_sha256"),
            ),
            spec_url=data.get("spec_url"),
            bundle_url=data.get("bundle_url"),
            published_at=data.get("published_at"),
            previous_version=data.get("previous_version"),
            raw=data,
        )

    @classmethod
    def from_path(cls, path: str | Path) -> Manifest:
        """Load a manifest from a JSON file."""
        p = Path(path)
        try:
            data = json.loads(p.read_text(encoding="utf-8"))
        except FileNotFoundError as e:
            raise ManifestError(f"manifest.json not found at {p}") from e
        except json.JSONDecodeError as e:
            raise ManifestError(f"manifest.json at {p} is not valid JSON: {e}") from e
        return cls.from_dict(data)

    def to_dict(self) -> dict[str, Any]:
        """Return a serialisable dict representation, suitable for re-writing manifest.json."""
        if self.raw:
            return self.raw
        out: dict[str, Any] = {
            "protocol": self.protocol,
            "protocol_version": self.protocol_version,
            "tradition_id": self.tradition_id,
            "tradition_name": self.tradition_name,
            "version": self.version,
            "language_primary": self.language_primary,
            "license_corpus": self.license_corpus,
            "license_harness": self.license_harness,
            "publisher": {"name": self.publisher.name},
            "integrity": {
                "backbone_sha256": self.integrity.backbone_sha256,
                "glossary_sha256": self.integrity.glossary_sha256,
                "calibrations_sha256": self.integrity.calibrations_sha256,
            },
        }
        if self.languages:
            out["languages"] = self.languages
        if self.license_spec:
            out["license_spec"] = self.license_spec
        if self.publisher.url:
            out["publisher"]["url"] = self.publisher.url
        if self.publisher.contact:
            out["publisher"]["contact"] = self.publisher.contact
        if self.publisher.public_key:
            out["publisher"]["public_key"] = self.publisher.public_key
        if self.publisher_signature:
            out["publisher_signature"] = self.publisher_signature
        if self.integrity.corpus_sha256:
            out["integrity"]["corpus_sha256"] = self.integrity.corpus_sha256
        for opt_field in ("spec_url", "bundle_url", "published_at", "previous_version"):
            value = getattr(self, opt_field)
            if value is not None:
                out[opt_field] = value
        return out


_SCHEMA_CACHE: dict[str, Any] | None = None


def _load_schema() -> dict[str, Any]:
    """Load the bundled manifest schema from schemas/manifest.schema.json."""
    global _SCHEMA_CACHE
    if _SCHEMA_CACHE is not None:
        return _SCHEMA_CACHE

    # Locate schemas/ adjacent to the package root (development install)
    # or installed alongside the package (wheel install).
    pkg_root = Path(__file__).parent.parent
    schema_path = pkg_root / "schemas" / "manifest.schema.json"
    if not schema_path.exists():
        # Fallback: look inside package data (for wheel installs)
        try:
            with resources.files("sdip.schemas").joinpath("manifest.schema.json").open() as f:
                _SCHEMA_CACHE = json.load(f)
                return _SCHEMA_CACHE
        except (FileNotFoundError, ModuleNotFoundError):
            raise ManifestError(
                f"manifest schema not found at {schema_path} or in package data"
            ) from None

    _SCHEMA_CACHE = json.loads(schema_path.read_text(encoding="utf-8"))
    return _SCHEMA_CACHE


def _validate_against_schema(data: dict[str, Any]) -> None:
    """Validate a manifest dict against the SDIP manifest schema."""
    schema = _load_schema()
    try:
        jsonschema.validate(instance=data, schema=schema)
    except jsonschema.ValidationError as e:
        path = ".".join(str(p) for p in e.absolute_path)
        location = f" at {path}" if path else ""
        raise ManifestError(f"manifest validation failed{location}: {e.message}") from e
    except jsonschema.SchemaError as e:
        raise ManifestError(f"manifest schema is invalid: {e.message}") from e
