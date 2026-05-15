# Pattern XII — Continuous Canonical Regeneration

*Hash-manifest incremental regeneration. The canonical version of every artifact is whatever the source produces today, propagated across all output formats with only changed sections regenerating.*

Full treatment: [`METHODOLOGY.md` § XII](../../METHODOLOGY.md). Reference implementation scaffold: [`components/regeneration/`](../../components/regeneration/).

## Problem It Solves

Knowledge artifacts freeze at publication time while the tradition's doctrine continues to develop. The article in the book printed in 2018 says what it said in 2018; the podcast episode published last year carries last year's understanding; the video reflects the editorial state at upload. The gap between current doctrine and published artifacts widens with every cycle. Versioning works imperfectly for text and breaks entirely for audio and video where re-recording the entire artifact for a single correction is prohibitive.

## The Solution in Brief

A *hash manifest* — per-section content-hash record stored alongside each output artifact. On regeneration, the pipeline computes current hashes for each source section, compares against the manifest, and regenerates only sections whose hashes have drifted. Unchanged MP3 sections stay byte-identical; unchanged video frames are not re-rendered; unchanged HTML chapters serve from cache.

The architectural commitment: the *canonical* version of every artifact is the version the source produces today. Versioning exists for date-stamped academic snapshots (journal-submitted papers, dated conference talks) but not for the practitioner-facing canonical surface.

## The Five Reference Instantiations

- **The Living Book** — HTML book volumes regenerating from vault articles, smart-incremental at the chapter level
- **The Living Podcast** — single-voice TTS feed, SHA-256-per-section manifest, re-synthesis only of drifted sections
- **The Living Video** — TTS audio plus hand-curated SVG visual palette plus AI orchestration; regenerates per-section following the audio
- **The Living Papers** — academic articles maintained as living documents with date-stamped DOI snapshots for citation
- **The Living System** — the system's own meta-documentation regenerating from operational state

## Why No Talking Head in Video

The architectural commitment to *no talking head, no founder face* in video is **structurally entailed** by the regeneration discipline, not added as a stylistic preference. Footage of a specific person at a specific time cannot be regenerated when doctrine evolves; doctrinal currency requires the visual layer be assembled from regeneratable primitives. The asset-palette discipline (hand-curated SVG library plus AI orchestration over it, with no AI-generated assets) preserves authorial control over the visual identity while allowing the orchestration to track the audio's edits.

## Adoption Path

1. Identify your output artifact format and how its source corpus maps to sections (headings, paragraphs, named segments).
2. Define the manifest format — per-section SHA-256 of source content, output-section identifier, generation timestamp. See [`components/regeneration/schema/manifest.schema.json`](../../components/regeneration/schema/manifest.schema.json).
3. Implement the regeneration pipeline with the four-step discipline: compute current hashes, compare against manifest, regenerate drifted sections only, splice into the existing artifact.
4. Make the discipline observable — the manifest itself is the audit trail. A practitioner can see which sections were last regenerated when.
5. Resist *paranoid regeneration* — the failure mode where the pipeline regenerates everything on every edit because it cannot tell what changed. The manifest is the memory.

## Schema

See [`components/regeneration/schema/manifest.schema.json`](../../components/regeneration/schema/manifest.schema.json) for the hash-manifest JSON Schema. Tradition-neutral; applies to any sectional output format.

## Status

Spec-grade at v0.1. The methodology and schema are articulated; the production reference implementations (Living Book, Living Podcast, Living Video) live in the Harmonia website repository at AGPL-3.0. The standalone Python library extraction is the next-quarter target.
