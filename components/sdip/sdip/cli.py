"""Command-line interface for SDIP.

    sdip validate <bundle-path>
        Validate a bundle against the protocol schemas and integrity hashes.

    sdip build <bundle-path> [--output <zip>]
        Build a distributable bundle: compute integrity hashes, stamp manifest,
        emit a zip archive.

    sdip conformance <bundle-path> --model <endpoint>
        Run the bundle's conformance suite against a model and report fidelity.

    sdip serve <bundle-path> --model <endpoint> [--port 8080]
        Start a local SDIP chat server (skeleton — full server in v0.2).

    sdip new <tradition-id> [--based-on example-tradition]
        Scaffold a new tradition bundle from a template.

    sdip pull <tradition-id> [--source <url>]
        Download a tradition's bundle (practitioner-initiated update).
"""
from __future__ import annotations

import json
import shutil
import sys
import time
from pathlib import Path

import click
from rich.console import Console
from rich.table import Table

from sdip import __protocol_version__, __version__
from sdip.bundle import Bundle, BundleError
from sdip.conformance import Conformance, ConformanceError
from sdip.harness import Harness, HarnessConfig
from sdip.manifest import Manifest, ManifestError

console = Console()


@click.group()
@click.version_option(version=__version__, message=f"sdip {__version__} (protocol v{__protocol_version__})")
def main() -> None:
    """SDIP — Sovereign Doctrinal Inference Protocol reference implementation."""


# ── validate ─────────────────────────────────────────────────────────────────


@main.command()
@click.argument("bundle_path", type=click.Path(exists=True))
@click.option("--skip-integrity", is_flag=True, help="Skip integrity hash verification.")
def validate(bundle_path: str, skip_integrity: bool) -> None:
    """Validate a bundle against the protocol schemas and integrity hashes."""
    try:
        bundle = Bundle.from_path(bundle_path)
    except (BundleError, ManifestError) as e:
        console.print(f"[red]Bundle validation failed:[/red] {e}")
        sys.exit(1)

    console.print(f"[green]✓[/green] Manifest valid")
    console.print(f"  protocol:        sdip {bundle.manifest.protocol_version}")
    console.print(f"  tradition:       {bundle.manifest.tradition_name} ({bundle.manifest.tradition_id})")
    console.print(f"  version:         {bundle.manifest.version}")
    console.print(f"  language_primary: {bundle.manifest.language_primary}")
    if bundle.manifest.languages:
        console.print(f"  translations:    {', '.join(bundle.manifest.languages)}")
    console.print(f"  license_corpus:  {bundle.manifest.license_corpus}")
    console.print(f"  license_harness: {bundle.manifest.license_harness}")

    if skip_integrity:
        console.print("[yellow]⚠[/yellow]  integrity verification skipped (--skip-integrity)")
    else:
        try:
            bundle.verify_integrity()
            console.print("[green]✓[/green] Integrity hashes verified")
        except BundleError as e:
            console.print(f"[red]✗[/red] Integrity verification failed: {e}")
            sys.exit(1)

    # Component summary
    n_articles = sum(1 for _ in bundle.corpus_iter())
    glossary_entries = len(bundle.glossary.get("entries", []))
    calibration_columns = len(bundle.calibrations.get("columns", []))

    console.print(f"  backbone:        {len(bundle.backbone_body)} chars")
    console.print(f"  corpus:          {n_articles} articles")
    console.print(f"  glossary:        {glossary_entries} entries")
    console.print(f"  calibrations:    {calibration_columns} columns")
    if bundle.conformance_suite_path:
        suite_path = bundle.conformance_suite_path
        import yaml as _yaml

        suite_doc = _yaml.safe_load(suite_path.read_text(encoding="utf-8"))
        n_tests = len(suite_doc.get("tests", []))
        console.print(f"  conformance:     {n_tests} tests")
    else:
        console.print(f"  conformance:     [yellow](no test suite)[/yellow]")


# ── build ────────────────────────────────────────────────────────────────────


@main.command()
@click.argument("bundle_path", type=click.Path(exists=True))
@click.option("--output", "-o", default=None, help="Output zip path (default: {tradition-id}-{version}.zip)")
def build(bundle_path: str, output: str | None) -> None:
    """Compute integrity hashes, stamp manifest, emit a distributable zip."""
    import zipfile

    try:
        bundle = Bundle.from_path(bundle_path)
    except (BundleError, ManifestError) as e:
        console.print(f"[red]Bundle load failed:[/red] {e}")
        sys.exit(1)

    # Compute and stamp hashes
    hashes = bundle.compute_integrity_hashes()
    manifest_dict = bundle.manifest.to_dict()
    manifest_dict["integrity"] = hashes

    # Write updated manifest back to the bundle
    manifest_path = bundle.root / "manifest.json"
    manifest_path.write_text(json.dumps(manifest_dict, indent=2) + "\n", encoding="utf-8")
    console.print(f"[green]✓[/green] Integrity hashes computed and stamped")
    for k, v in hashes.items():
        console.print(f"  {k}: {v[:16]}...")

    # Build the zip
    if output is None:
        output = f"{bundle.manifest.tradition_id}-{bundle.manifest.version}.zip"
    output_path = Path(output)

    with zipfile.ZipFile(output_path, "w", zipfile.ZIP_DEFLATED) as zf:
        for file in bundle.root.rglob("*"):
            if file.is_file():
                arcname = file.relative_to(bundle.root.parent).as_posix()
                zf.write(file, arcname)

    console.print(f"[green]✓[/green] Bundle built: {output_path} ({output_path.stat().st_size} bytes)")


# ── conformance ──────────────────────────────────────────────────────────────


@main.command()
@click.argument("bundle_path", type=click.Path(exists=True))
@click.option("--model", required=True, help="OpenAI-compatible endpoint, e.g., http://localhost:11434/v1")
@click.option("--model-name", required=True, help="Model identifier, e.g., qwen2.5:72b-instruct-abliterated")
@click.option("--memory", default=None, help="Memory database path (default: temporary).")
@click.option("--index", default=None, help="Index database path (default: temporary).")
@click.option("--output", default=None, help="Write the fidelity report to this JSON file.")
def conformance(
    bundle_path: str,
    model: str,
    model_name: str,
    memory: str | None,
    index: str | None,
    output: str | None,
) -> None:
    """Run the bundle's conformance suite against a model and report fidelity."""
    import tempfile

    tmpdir = None
    if memory is None or index is None:
        tmpdir = Path(tempfile.mkdtemp(prefix="sdip-conformance-"))
    memory_path = Path(memory) if memory else tmpdir / "memory.db"  # type: ignore[union-attr]
    index_path = Path(index) if index else tmpdir / "index.db"  # type: ignore[union-attr]

    config = HarnessConfig(
        bundle_path=bundle_path,
        memory_path=memory_path,
        index_path=index_path,
        model_endpoint=model,
        model_name=model_name,
    )

    try:
        harness = Harness(config)
        harness.initialize()
    except (BundleError, ManifestError) as e:
        console.print(f"[red]Harness initialization failed:[/red] {e}")
        sys.exit(1)

    try:
        conf = Conformance.from_bundle(harness.bundle)
    except ConformanceError as e:
        console.print(f"[red]Conformance suite load failed:[/red] {e}")
        sys.exit(1)

    console.print(f"Running {len(conf.tests)} tests against {model_name}...")
    result = conf.run(harness)

    # Render summary
    table = Table(title=f"Conformance Report — {result.tradition_id}")
    table.add_column("Metric", style="cyan")
    table.add_column("Value", style="white")
    table.add_row("Total tests", str(result.total_tests))
    table.add_row("Passed", f"[green]{result.passed}[/green]")
    table.add_row("Failed (critical)", f"[red]{result.failed_critical}[/red]")
    table.add_row("Failed (major)", f"[yellow]{result.failed_major}[/yellow]")
    table.add_row("Failed (minor)", f"{result.failed_minor}")
    table.add_row("Fidelity score", f"[bold]{result.fidelity_score:.2%}[/bold]")
    console.print(table)

    if output:
        Conformance.write_report(result, output)
        console.print(f"[green]✓[/green] Report written to {output}")

    # Exit code: nonzero if any critical failure
    sys.exit(0 if result.failed_critical == 0 else 1)


# ── serve ────────────────────────────────────────────────────────────────────


@main.command()
@click.argument("bundle_path", type=click.Path(exists=True))
@click.option("--model", required=True, help="OpenAI-compatible endpoint")
@click.option("--model-name", required=True, help="Model identifier")
@click.option("--memory", default="./practitioner.db", help="Memory database path")
@click.option("--index", default="./vault-index.db", help="Index database path")
@click.option("--port", default=8080, type=int, help="Local server port")
def serve(
    bundle_path: str,
    model: str,
    model_name: str,
    memory: str,
    index: str,
    port: int,
) -> None:
    """Start a local SDIP chat server.

    v0.1: REPL only. v0.2: FastAPI server with WebSocket streaming.
    """
    config = HarnessConfig(
        bundle_path=bundle_path,
        memory_path=memory,
        index_path=index,
        model_endpoint=model,
        model_name=model_name,
    )
    harness = Harness(config)
    harness.initialize()

    console.print(f"[green]✓[/green] Harness initialized for {harness.bundle.manifest.tradition_name}")
    console.print(f"  bundle:  {bundle_path}")
    console.print(f"  model:   {model_name} @ {model}")
    console.print(f"  memory:  {memory}")
    console.print(f"  index:   {index}")
    console.print("")
    console.print("REPL mode — type your message, blank line to exit.")
    console.print("[dim]A FastAPI/WebSocket server is the v0.2 surface; the REPL is the v0.1 reference.[/dim]")
    console.print("")

    practitioner_id = f"local-{int(time.time())}"

    while True:
        try:
            console.print("[bold cyan]>[/bold cyan] ", end="")
            user_input = input().strip()
        except (EOFError, KeyboardInterrupt):
            console.print("\n[dim](exiting)[/dim]")
            break

        if not user_input:
            break

        console.print("[bold magenta]MunAI:[/bold magenta] ", end="")
        for token in harness.chat(practitioner_id, user_input):
            console.print(token, end="")
        console.print("")
        console.print("")


# ── new ──────────────────────────────────────────────────────────────────────


@main.command()
@click.argument("tradition_id")
@click.option(
    "--based-on",
    default="example-tradition",
    help="Template bundle to fork. Default: example-tradition.",
)
@click.option("--output", default=None, help="Output directory (default: ./bundles/{tradition-id})")
def new(tradition_id: str, based_on: str, output: str | None) -> None:
    """Scaffold a new tradition bundle from a template."""
    pkg_root = Path(__file__).parent.parent
    template_path = pkg_root / "bundles" / based_on
    if not template_path.is_dir():
        console.print(f"[red]Template not found:[/red] {template_path}")
        sys.exit(1)

    output_path = Path(output) if output else Path("./bundles") / tradition_id
    if output_path.exists():
        console.print(f"[red]Output path already exists:[/red] {output_path}")
        sys.exit(1)

    shutil.copytree(template_path, output_path)

    # Update the manifest with the new tradition_id
    manifest_path = output_path / "manifest.json"
    manifest = json.loads(manifest_path.read_text(encoding="utf-8"))
    manifest["tradition_id"] = tradition_id
    manifest["tradition_name"] = tradition_id.replace("-", " ").title()
    manifest_path.write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")

    console.print(f"[green]✓[/green] Scaffolded {tradition_id} at {output_path}")
    console.print("")
    console.print("Next steps:")
    console.print(f"  1. Edit {output_path}/backbone.md           — your tradition's doctrinal architecture")
    console.print(f"  2. Edit {output_path}/glossary.yaml         — your tradition's terminology")
    console.print(f"  3. Edit {output_path}/calibrations.yaml     — per-practitioner state schema")
    console.print(f"  4. Populate {output_path}/corpus/           — your tradition's canonical texts")
    console.print(f"  5. Define {output_path}/conformance/test-suite.yaml — canonical positions to verify")
    console.print(f"  6. sdip validate {output_path}")
    console.print(f"  7. sdip build {output_path}")


# ── pull ─────────────────────────────────────────────────────────────────────


@main.command()
@click.argument("tradition_id")
@click.option("--source", default=None, help="Bundle URL (default: tradition's published URL).")
@click.option("--output", default=None, help="Output path (default: ./bundles/{tradition-id}).")
def pull(tradition_id: str, source: str | None, output: str | None) -> None:
    """Download a tradition's bundle (practitioner-initiated update).

    The practitioner explicitly chooses to fetch. No background sync, no
    push from the publisher — per protocol §4.6.
    """
    # Default registry: per-tradition URL conventions
    DEFAULT_REGISTRY = {
        "harmonism": "https://harmonism.io/sovereignty-bundle.zip",
    }
    url = source or DEFAULT_REGISTRY.get(tradition_id)
    if url is None:
        console.print(f"[red]No source URL known for tradition_id={tradition_id}.[/red]")
        console.print("Provide --source <url> to fetch from a specific location.")
        sys.exit(1)

    output_path = Path(output) if output else Path("./bundles") / tradition_id
    output_path.parent.mkdir(parents=True, exist_ok=True)

    # Skeleton — production version fetches via httpx with progress, verifies
    # publisher signature, validates manifest, atomically swaps the directory.
    console.print(f"[yellow]pull is v0.2 deferred[/yellow]")
    console.print(f"  intended: download {url} → {output_path}")
    console.print(f"  current workaround: download the bundle manually and unzip to {output_path}")
    sys.exit(0)


if __name__ == "__main__":
    main()
