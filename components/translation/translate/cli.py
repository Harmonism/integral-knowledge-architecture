"""harmonia-translate CLI.

Commands:
    harmonia-translate validate-glossary <glossary.yaml>
        Load and validate a glossary file. Reports entry count, language
        coverage, untranslatable terms, deprecated terms.

    harmonia-translate check-staleness <translation.md> --source <source.md>
        Check whether a translation is stale relative to its source.

    harmonia-translate lint <translation.md> --glossary <glossary.yaml> --lang <code>
        Run terminology linting against a translation.

    harmonia-translate lint-vault <vault-root> --glossary <glossary.yaml> --lang <code>
        Lint every translation in a vault directory.

    harmonia-translate hash <file.md>
        Compute body hash and per-block hashes for a Markdown file.
"""
from __future__ import annotations

import json
import sys
from pathlib import Path

import click
from rich.console import Console
from rich.table import Table

from translate import __version__
from translate.glossary import Glossary, GlossaryError
from translate.hasher import Hasher
from translate.manifest import ManifestError, TranslationFile
from translate.validators import (
    LintFinding,
    ScriptPurityValidator,
    StalenessValidator,
    TerminologyLinter,
)

console = Console()


@click.group()
@click.version_option(version=__version__)
def main() -> None:
    """harmonia-translate — Pattern VII reference implementation."""


# ── validate-glossary ───────────────────────────────────────────────────────


@main.command("validate-glossary")
@click.argument("glossary_path", type=click.Path(exists=True))
def cmd_validate_glossary(glossary_path: str) -> None:
    """Load and validate a glossary file. Report counts and coverage."""
    try:
        glossary = Glossary.from_path(glossary_path)
    except GlossaryError as e:
        console.print(f"[red]✗[/red] {e}")
        sys.exit(1)

    console.print(f"[green]✓[/green] Glossary valid")
    console.print(f"  version:           {glossary.version}")
    console.print(f"  primary language:  {glossary.language_primary}")
    console.print(f"  total entries:     {len(glossary)}")
    console.print(f"  untranslatable:    {len(glossary.untranslatable_terms())}")
    console.print(f"  deprecated:        {len(glossary.deprecated_terms())}")

    languages = sorted(glossary.languages())
    if languages:
        console.print(f"  translations in:   {', '.join(languages)}")

    # Adoption breakdown
    table = Table(title="Adoption breakdown")
    table.add_column("Adoption", style="cyan")
    table.add_column("Count", justify="right")
    counts = {"native": 0, "tradition-specific": 0, "untranslatable": 0}
    for e in glossary.entries:
        counts[e.adoption] += 1
    for adoption in ("native", "tradition-specific", "untranslatable"):
        table.add_row(adoption, str(counts[adoption]))
    console.print(table)


# ── check-staleness ─────────────────────────────────────────────────────────


@main.command("check-staleness")
@click.argument("translation_path", type=click.Path(exists=True))
@click.option("--source", "source_path", required=True, type=click.Path(exists=True),
              help="Path to the source-language article.")
def cmd_check_staleness(translation_path: str, source_path: str) -> None:
    """Compare translation's source_hash to current source body hash."""
    validator = StalenessValidator()
    result = validator.check(translation_path=translation_path, source_path=source_path)

    if result.is_fresh:
        console.print(f"[green]✓[/green] Fresh")
        console.print(f"  source_hash:   {result.recorded_hash}")
    else:
        console.print(f"[red]✗[/red] Stale — {result.reason}")
        console.print(f"  recorded hash: {result.recorded_hash}")
        console.print(f"  current hash:  {result.current_hash}")
        sys.exit(1)


# ── lint ────────────────────────────────────────────────────────────────────


@main.command("lint")
@click.argument("translation_path", type=click.Path(exists=True))
@click.option("--glossary", "glossary_path", required=True, type=click.Path(exists=True))
@click.option("--lang", required=True, help="Target language code (fr, es, ar, etc.)")
@click.option("--source", "source_path", type=click.Path(), default=None,
              help="Optional source path for missing-sanctioned checks.")
@click.option("--script-purity/--no-script-purity", default=True,
              help="Run the script-purity validator (default: on).")
def cmd_lint(
    translation_path: str,
    glossary_path: str,
    lang: str,
    source_path: str | None,
    script_purity: bool,
) -> None:
    """Lint a translation against the glossary."""
    try:
        glossary = Glossary.from_path(glossary_path)
    except GlossaryError as e:
        console.print(f"[red]✗[/red] Glossary error: {e}")
        sys.exit(2)

    linter = TerminologyLinter(glossary)
    if source_path:
        findings = linter.lint_against_source(translation_path, source_path, lang)
    else:
        findings = linter.lint(translation_path, lang)

    if script_purity:
        sp = ScriptPurityValidator()
        findings += sp.check(translation_path, lang)

    if not findings:
        console.print(f"[green]✓[/green] No terminology violations.")
        return

    _print_findings(findings)
    # Exit nonzero if any errors
    if any(f.severity == "error" for f in findings):
        sys.exit(1)


def _print_findings(findings: list[LintFinding]) -> None:
    by_severity: dict[str, int] = {"error": 0, "warning": 0, "info": 0}
    for f in findings:
        marker = {"error": "[red]✗[/red]", "warning": "[yellow]⚠[/yellow]", "info": "[blue]i[/blue]"}[f.severity]
        console.print(f"{marker} [{f.category}] {f.term}: {f.detail}")
        by_severity[f.severity] += 1
    console.print(
        f"\n  errors: {by_severity['error']}  warnings: {by_severity['warning']}  info: {by_severity['info']}"
    )


# ── lint-vault ──────────────────────────────────────────────────────────────


@main.command("lint-vault")
@click.argument("vault_root", type=click.Path(exists=True, file_okay=False))
@click.option("--glossary", "glossary_path", required=True, type=click.Path(exists=True))
@click.option("--lang", required=True)
def cmd_lint_vault(vault_root: str, glossary_path: str, lang: str) -> None:
    """Lint every Markdown file under vault_root against the glossary."""
    try:
        glossary = Glossary.from_path(glossary_path)
    except GlossaryError as e:
        console.print(f"[red]✗[/red] Glossary error: {e}")
        sys.exit(2)

    linter = TerminologyLinter(glossary)
    all_findings: list[LintFinding] = []
    file_count = 0
    for md in sorted(Path(vault_root).rglob("*.md")):
        file_count += 1
        try:
            findings = linter.lint(md, lang)
        except ManifestError:
            continue
        all_findings.extend(findings)

    console.print(f"Linted {file_count} files; {len(all_findings)} findings.\n")
    if all_findings:
        _print_findings(all_findings)
        if any(f.severity == "error" for f in all_findings):
            sys.exit(1)


# ── hash ────────────────────────────────────────────────────────────────────


@main.command("hash")
@click.argument("file_path", type=click.Path(exists=True))
@click.option("--json", "as_json", is_flag=True, help="Output as JSON.")
def cmd_hash(file_path: str, as_json: bool) -> None:
    """Compute body hash and per-block hashes for a Markdown file."""
    h = Hasher.from_path(file_path)
    if as_json:
        click.echo(json.dumps({
            "body_hash": h.body_hash,
            "block_count": len(h.blocks),
            "block_hashes": h.block_hashes,
        }, indent=2))
    else:
        console.print(f"  body_hash:   {h.body_hash}")
        console.print(f"  block count: {len(h.blocks)}")
        console.print(f"  block hashes:")
        for i, bh in enumerate(h.block_hashes):
            console.print(f"    [{i:3d}] {bh}")


if __name__ == "__main__":
    main()
