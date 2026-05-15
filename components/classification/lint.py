#!/usr/bin/env python3
"""Classification linter for integral knowledge vaults.

Scans a vault directory for Markdown files with YAML frontmatter, validates
the five-axis classification (doctrinal_status, content_layer, breadth,
depth, craft) against the schema, and reports violations.

Usage:
    python lint.py /path/to/vault
    python lint.py /path/to/vault --census
    python lint.py /path/to/vault --query "doctrinal_status=clear breadth=partial"
"""
from __future__ import annotations

import argparse
import json
import sys
from collections import Counter, defaultdict
from pathlib import Path

try:
    import yaml
except ImportError:
    print("Error: PyYAML required. Install with: pip install PyYAML", file=sys.stderr)
    sys.exit(1)


VALID_VALUES = {
    "doctrinal_status": ["clouded", "clear", "luminous"],
    "content_layer": ["canon", "bridge", "applied", "paper"],
    "breadth": ["partial", "substantial", "full"],
    "depth": ["introductory", "developed", "comprehensive"],
    "craft": ["muddy", "clean", "pure"],
}

REQUIRED_FIELDS = list(VALID_VALUES.keys())


def parse_frontmatter(text: str) -> dict | None:
    """Parse YAML frontmatter from a Markdown document. Returns dict or None."""
    if not text.startswith("---\n"):
        return None
    end = text.find("\n---\n", 4)
    if end == -1:
        return None
    try:
        fm = yaml.safe_load(text[4:end])
    except yaml.YAMLError:
        return None
    return fm if isinstance(fm, dict) else None


def validate_classification(frontmatter: dict) -> list[str]:
    """Return a list of violation strings. Empty list means valid."""
    violations: list[str] = []
    for field in REQUIRED_FIELDS:
        value = frontmatter.get(field)
        if value is None:
            violations.append(f"missing field: {field}")
            continue
        if value not in VALID_VALUES[field]:
            allowed = ", ".join(VALID_VALUES[field])
            violations.append(f"invalid {field}={value!r} (allowed: {allowed})")
    return violations


def scan_vault(root: Path, ignore_dirs: list[str] | None = None) -> list[tuple[Path, dict | None, list[str]]]:
    """Walk a vault directory, returning (path, frontmatter, violations) for each .md file."""
    ignore = set(ignore_dirs or [])
    results: list[tuple[Path, dict | None, list[str]]] = []
    for md in sorted(root.rglob("*.md")):
        # Skip ignored directories
        if any(part in ignore for part in md.parts):
            continue
        try:
            text = md.read_text(encoding="utf-8")
        except (OSError, UnicodeDecodeError):
            continue
        fm = parse_frontmatter(text)
        if fm is None:
            results.append((md, None, ["no frontmatter"]))
            continue
        # Only lint files that have at least one classification field — skip
        # pure navigation files that don't carry classification by design
        if not any(field in fm for field in REQUIRED_FIELDS):
            continue
        violations = validate_classification(fm)
        results.append((md, fm, violations))
    return results


def cmd_lint(root: Path, ignore_dirs: list[str] | None) -> int:
    results = scan_vault(root, ignore_dirs)
    error_count = 0
    for path, _fm, violations in results:
        if not violations:
            continue
        rel = path.relative_to(root)
        print(f"{rel}")
        for v in violations:
            print(f"  ✗ {v}")
        error_count += 1
    if error_count == 0:
        print(f"✓ {len(results)} files scanned, no violations.")
    else:
        print(f"\n{error_count} of {len(results)} files have violations.")
    return 1 if error_count else 0


def cmd_census(root: Path, ignore_dirs: list[str] | None) -> int:
    results = scan_vault(root, ignore_dirs)
    counters: dict[str, Counter] = {field: Counter() for field in REQUIRED_FIELDS}
    for _path, fm, _v in results:
        if fm is None:
            continue
        for field in REQUIRED_FIELDS:
            value = fm.get(field)
            if value is None:
                counters[field][None] += 1
            else:
                counters[field][value] += 1
    print(f"Classification census — {len(results)} files\n")
    for field in REQUIRED_FIELDS:
        print(f"{field}:")
        for value in VALID_VALUES[field] + [None]:
            count = counters[field].get(value, 0)
            label = repr(value) if value is None else value
            print(f"  {label:20s} {count}")
        print()
    return 0


def cmd_query(root: Path, ignore_dirs: list[str] | None, query: str) -> int:
    constraints: dict[str, str] = {}
    for token in query.split():
        if "=" not in token:
            print(f"Invalid query token: {token!r} (expected field=value)", file=sys.stderr)
            return 2
        field, value = token.split("=", 1)
        if field not in REQUIRED_FIELDS:
            print(f"Unknown field: {field!r}", file=sys.stderr)
            return 2
        constraints[field] = value
    results = scan_vault(root, ignore_dirs)
    matches: list[Path] = []
    for path, fm, _v in results:
        if fm is None:
            continue
        if all(fm.get(f) == v for f, v in constraints.items()):
            matches.append(path)
    for path in matches:
        print(path.relative_to(root))
    print(f"\n{len(matches)} matches.", file=sys.stderr)
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="Lint classification metadata in a vault.")
    parser.add_argument("root", type=Path, help="Vault root directory to scan.")
    parser.add_argument(
        "--ignore", nargs="*", default=["Internal", "_Archive", ".obsidian"],
        help="Directory names to skip (default: Internal, _Archive, .obsidian)."
    )
    parser.add_argument("--census", action="store_true", help="Print classification distribution.")
    parser.add_argument("--query", type=str, default=None, help="Find files matching a query (e.g., \"doctrinal_status=clear breadth=partial\").")
    args = parser.parse_args(argv)

    if not args.root.is_dir():
        print(f"Error: {args.root} is not a directory.", file=sys.stderr)
        return 2

    if args.census:
        return cmd_census(args.root, args.ignore)
    if args.query:
        return cmd_query(args.root, args.ignore, args.query)
    return cmd_lint(args.root, args.ignore)


if __name__ == "__main__":
    sys.exit(main())
