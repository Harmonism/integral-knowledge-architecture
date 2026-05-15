#!/usr/bin/env python3
"""Validate a SDIP bundle. Standalone equivalent of `sdip validate`.

Usage:
    python scripts/validate-bundle.py <bundle-path>

This script is the direct invocation path; the same logic is also exposed
via the `sdip` CLI as `sdip validate`. Use this when the CLI is not
installed (e.g., when validating from CI or against a checked-out repo
without `pip install`).
"""
from __future__ import annotations

import sys
from pathlib import Path

# Allow running from the repo root without `pip install -e .`
REPO_ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(REPO_ROOT))

from sdip.bundle import Bundle, BundleError  # noqa: E402
from sdip.manifest import ManifestError  # noqa: E402


def main() -> int:
    if len(sys.argv) < 2:
        print("Usage: validate-bundle.py <bundle-path>", file=sys.stderr)
        return 2

    bundle_path = sys.argv[1]
    try:
        bundle = Bundle.from_path(bundle_path)
    except (BundleError, ManifestError) as e:
        print(f"✗ Bundle invalid: {e}", file=sys.stderr)
        return 1

    print(f"✓ Manifest valid")
    print(f"  protocol:        sdip {bundle.manifest.protocol_version}")
    print(f"  tradition:       {bundle.manifest.tradition_name} ({bundle.manifest.tradition_id})")
    print(f"  version:         {bundle.manifest.version}")

    try:
        bundle.verify_integrity()
        print("✓ Integrity hashes verified")
    except BundleError as e:
        print(f"✗ Integrity verification failed: {e}", file=sys.stderr)
        return 1

    n_articles = sum(1 for _ in bundle.corpus_iter())
    glossary_entries = len(bundle.glossary.get("entries", []))
    calibration_columns = len(bundle.calibrations.get("columns", []))

    print(f"  backbone:        {len(bundle.backbone_body)} chars")
    print(f"  corpus:          {n_articles} articles")
    print(f"  glossary:        {glossary_entries} entries")
    print(f"  calibrations:    {calibration_columns} columns")

    return 0


if __name__ == "__main__":
    sys.exit(main())
