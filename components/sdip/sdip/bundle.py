"""Bundle loading and integrity verification.

A Bundle is the on-disk (or in-zip) form of a tradition's SDIP package.
This module loads bundles, validates structure, and verifies integrity hashes.
"""
from __future__ import annotations

import hashlib
import zipfile
from dataclasses import dataclass
from pathlib import Path
from typing import Iterator

import yaml

from sdip.manifest import Manifest, ManifestError


class BundleError(Exception):
    """Raised when a bundle is malformed, missing required components, or fails integrity verification."""


@dataclass
class CorpusArticle:
    """A single article in the corpus."""

    relative_path: str
    frontmatter: dict
    body: str

    @property
    def title(self) -> str:
        return str(self.frontmatter.get("title") or self.relative_path)

    @property
    def classification(self) -> str:
        return str(self.frontmatter.get("classification", "applied"))

    @property
    def language(self) -> str:
        return str(self.frontmatter.get("language", "en"))


class Bundle:
    """A loaded SDIP bundle, accessed via component-named properties.

    Usage:
        bundle = Bundle.from_path("/path/to/harmonism-sdip-bundle")
        bundle.verify_integrity()
        backbone_text = bundle.backbone
        for article in bundle.corpus_iter():
            ...
    """

    def __init__(self, root: Path, manifest: Manifest):
        self.root = root
        self.manifest = manifest

    @classmethod
    def from_path(cls, path: str | Path) -> Bundle:
        """Load a bundle from a directory or zip file.

        Zip files are extracted to a temporary directory on load. For
        production use, prefer directory-form bundles.
        """
        p = Path(path)
        if p.is_file() and p.suffix == ".zip":
            return cls._from_zip(p)
        if not p.is_dir():
            raise BundleError(f"bundle path is neither a directory nor a zip: {p}")

        manifest_path = p / "manifest.json"
        if not manifest_path.exists():
            raise BundleError(f"bundle missing manifest.json at {p}")

        try:
            manifest = Manifest.from_path(manifest_path)
        except ManifestError as e:
            raise BundleError(f"bundle has invalid manifest: {e}") from e

        # Required components
        for required in ("backbone.md", "glossary.yaml", "calibrations.yaml"):
            if not (p / required).exists():
                raise BundleError(f"bundle missing required component: {required}")
        if not (p / "corpus").is_dir():
            raise BundleError("bundle missing required directory: corpus/")

        return cls(root=p, manifest=manifest)

    @classmethod
    def _from_zip(cls, zip_path: Path) -> Bundle:
        import tempfile

        tmp = Path(tempfile.mkdtemp(prefix="sdip-bundle-"))
        with zipfile.ZipFile(zip_path) as zf:
            zf.extractall(tmp)

        # If the zip contains a single top-level directory, descend into it.
        entries = list(tmp.iterdir())
        if len(entries) == 1 and entries[0].is_dir():
            return cls.from_path(entries[0])
        return cls.from_path(tmp)

    # ── Component accessors ──────────────────────────────────────────────────

    @property
    def backbone(self) -> str:
        """The full text of backbone.md."""
        return (self.root / "backbone.md").read_text(encoding="utf-8")

    @property
    def backbone_body(self) -> str:
        """The backbone body with frontmatter stripped."""
        text = self.backbone
        if text.startswith("---\n"):
            end = text.find("\n---\n", 4)
            if end != -1:
                return text[end + 5 :]
        return text

    @property
    def glossary(self) -> dict:
        """The parsed glossary.yaml."""
        return yaml.safe_load((self.root / "glossary.yaml").read_text(encoding="utf-8"))

    @property
    def calibrations(self) -> dict:
        """The parsed calibrations.yaml."""
        return yaml.safe_load((self.root / "calibrations.yaml").read_text(encoding="utf-8"))

    def corpus_iter(self, language: str | None = None) -> Iterator[CorpusArticle]:
        """Iterate over corpus articles.

        Yields CorpusArticle instances. If `language` is provided and is not
        the bundle's primary language, iterates over the corresponding
        translation directory instead.
        """
        if language and language != self.manifest.language_primary:
            corpus_root = self.root / "i18n" / "translations" / language
            if not corpus_root.exists():
                return
        else:
            corpus_root = self.root / "corpus"

        for md_file in sorted(corpus_root.rglob("*.md")):
            rel = md_file.relative_to(corpus_root).as_posix()
            text = md_file.read_text(encoding="utf-8")
            frontmatter, body = _parse_frontmatter(text)
            yield CorpusArticle(relative_path=rel, frontmatter=frontmatter, body=body)

    @property
    def conformance_suite_path(self) -> Path | None:
        """Path to the conformance test suite if present."""
        p = self.root / "conformance" / "test-suite.yaml"
        return p if p.exists() else None

    # ── Integrity verification ───────────────────────────────────────────────

    def verify_integrity(self) -> None:
        """Verify backbone, glossary, and calibrations hashes against the manifest.

        Raises BundleError on any hash mismatch.
        """
        actual_backbone = _sha256_text(self.backbone_body)
        if actual_backbone != self.manifest.integrity.backbone_sha256:
            raise BundleError(
                f"backbone integrity check failed: "
                f"expected {self.manifest.integrity.backbone_sha256}, got {actual_backbone}"
            )

        actual_glossary = _sha256_path(self.root / "glossary.yaml")
        if actual_glossary != self.manifest.integrity.glossary_sha256:
            raise BundleError(
                f"glossary integrity check failed: "
                f"expected {self.manifest.integrity.glossary_sha256}, got {actual_glossary}"
            )

        actual_calibrations = _sha256_path(self.root / "calibrations.yaml")
        if actual_calibrations != self.manifest.integrity.calibrations_sha256:
            raise BundleError(
                f"calibrations integrity check failed: "
                f"expected {self.manifest.integrity.calibrations_sha256}, got {actual_calibrations}"
            )

        # corpus_sha256 is optional in v0.1 (Merkle root computation deferred)
        if self.manifest.integrity.corpus_sha256:
            actual_corpus = self._compute_corpus_merkle()
            if actual_corpus != self.manifest.integrity.corpus_sha256:
                raise BundleError(
                    f"corpus integrity check failed: "
                    f"expected {self.manifest.integrity.corpus_sha256}, got {actual_corpus}"
                )

    def _compute_corpus_merkle(self) -> str:
        """Compute a deterministic Merkle root over the corpus directory.

        v0.1: simple sorted-path concatenation of per-file hashes. v0.2 may
        upgrade to a binary Merkle tree for incremental verification.
        """
        corpus_root = self.root / "corpus"
        hashes: list[str] = []
        for md_file in sorted(corpus_root.rglob("*.md")):
            rel = md_file.relative_to(corpus_root).as_posix()
            file_hash = _sha256_path(md_file)
            hashes.append(f"{rel}:{file_hash}")
        combined = "\n".join(hashes).encode("utf-8")
        return hashlib.sha256(combined).hexdigest()

    def compute_integrity_hashes(self) -> dict[str, str]:
        """Compute all integrity hashes from current bundle contents.

        Used by build-bundle.py to stamp the manifest with current values.
        """
        return {
            "backbone_sha256": _sha256_text(self.backbone_body),
            "glossary_sha256": _sha256_path(self.root / "glossary.yaml"),
            "calibrations_sha256": _sha256_path(self.root / "calibrations.yaml"),
            "corpus_sha256": self._compute_corpus_merkle(),
        }


# ── Helpers ──────────────────────────────────────────────────────────────────


def _parse_frontmatter(text: str) -> tuple[dict, str]:
    """Parse YAML frontmatter from a Markdown document.

    Returns (frontmatter_dict, body_text). If no frontmatter is present,
    returns ({}, text).
    """
    if not text.startswith("---\n"):
        return ({}, text)
    end = text.find("\n---\n", 4)
    if end == -1:
        return ({}, text)
    fm_yaml = text[4:end]
    body = text[end + 5 :]
    try:
        fm = yaml.safe_load(fm_yaml) or {}
    except yaml.YAMLError:
        fm = {}
    if not isinstance(fm, dict):
        fm = {}
    return (fm, body)


def _sha256_text(text: str) -> str:
    return hashlib.sha256(text.encode("utf-8")).hexdigest()


def _sha256_path(path: Path) -> str:
    h = hashlib.sha256()
    with path.open("rb") as f:
        for chunk in iter(lambda: f.read(65536), b""):
            h.update(chunk)
    return h.hexdigest()
