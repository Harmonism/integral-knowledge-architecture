# Regeneration — Pattern XII Reference

Hash-manifest incremental regeneration. Implements Pattern XII of the [Integral Knowledge Architecture methodology](../../METHODOLOGY.md).

## What This Is

The schema, examples, and (forthcoming) Python library for the Living X family of canonical-regeneration pipelines — Living Book, Living Podcast, Living Video, Living Papers, Living System. The production implementations live in the Harmonia website repository (AGPL-3.0) and run continuously against the Harmonist corpus; this directory carries the architectural primitives extracted for tradition-neutral adoption.

## The Hash Manifest

The core technical primitive. A JSON file stored alongside each output artifact recording the SHA-256 of each source section at the time of last regeneration. Schema at [`schema/manifest.schema.json`](./schema/manifest.schema.json).

On regeneration:

1. Compute current hashes for each source section
2. Compare against the manifest's recorded hashes
3. Regenerate only sections whose hashes have drifted
4. Splice regenerated sections into the existing artifact
5. Update the manifest with new hashes and timestamps

The unchanged sections of an MP3 stay byte-identical. The unchanged HTML chapters serve from cache. The unchanged video frames are not re-rendered. The economic case for continuous regeneration depends entirely on this discipline.

## Quick Reference

- **[`schema/manifest.schema.json`](./schema/manifest.schema.json)** — the hash-manifest JSON Schema
- **[`examples/`](./examples/)** — sample manifests for audio (Living Podcast), HTML book chapters (Living Book), and video segments (Living Video)

## Reference Production Implementations

These run in the Harmonia website repository. Their architectures generalize cleanly to other traditions; the code itself is tightly coupled to the Harmonia stack and is being extracted for standalone use over coming quarters.

- **Living Podcast** — Python script at `harmonia-astro/scripts/tts-smart-update.py`. Provider: xAI Grok TTS (Leo/Ara/Sal voices). Manifest at `~/Documents/Audio/Harmonia/{slug}_{lang}/{slug}_{lang}_manifest.json`. Pronunciation overrides per language. Voice-change behavior keyed to content-only hash (changing voice does not trigger regen; use `--force` or rm manifest for that).
- **Living Book** — Python script at `harmonia-astro/scripts/generate-book.py`. Six volumes × ten languages = 60 books regenerable in ~30 seconds via `scripts/generate-all-books.sh`. Single source-of-truth `BOOKS` array maps slug → part structure → chapter file paths.
- **Living Video** — Phase B build deferred; the architectural commitment is recorded with the audio source layer (sourced from Living Podcast), the visual asset palette layer (hand-curated SVG library), and the orchestration layer (TBD, likely Remotion or FFmpeg-based) named explicitly.

## Adoption Path

1. Identify your output format and how its source maps to sections (chapters, paragraphs, named segments, audio breaks).
2. Author the manifest format following [`schema/manifest.schema.json`](./schema/manifest.schema.json) or extend it for your format-specific needs.
3. Implement the four-step regeneration discipline: compute, compare, regenerate-only-drift, splice.
4. Make the manifest the audit trail. Operators can see which sections were last regenerated when, which is the visibility that makes the system trustable.
5. Pair with Pattern III (Epistemic Metadata) — the manifest is itself a kind of metadata, recording the technical state of each output section against its source.

## Status

Spec-grade at v0.1. The schema is specified. Examples are documented. The production code lives at the Harmonia website repository pending standalone extraction.

## License

Schema, examples, documentation: CC-BY-4.0. Reference implementation code, when ported into this directory, will be AGPL-3.0.
