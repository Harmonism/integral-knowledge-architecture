# Extraction — Pattern XIII Reference

The six-step extraction protocol. Implements Pattern XIII of the [Integral Knowledge Architecture methodology](../../METHODOLOGY.md).

## What This Is

The schema and reference templates for routing external content (PDFs, audio, video, web articles) into a tradition's doctrinal architecture. Extraction tools (Marker, Docling, Whisper, defuddle) handle the technical conversion; this directory carries the architectural primitives that make the result *integrate* rather than *accumulate*.

## The Six Steps

| Step | Output | Automatable? | Tool Family |
|------|--------|---------------|-------------|
| 1. Extract | Cleaned Markdown with metadata | Yes | Marker, Docling, MinerU, Whisper, defuddle |
| 2. Assess | Per-kernel notes against doctrine | No (requires doctrine-fluent intelligence) | Operator + AI companion with backbone in context |
| 3. Identify kernels | List of discrete movable units | Partial (AI can propose; operator confirms) | LLM + assessment notes |
| 4. Reframe in native language | Kernels in tradition's terminology | Partial (glossary + LLM + operator) | Glossary (Pattern VII) + LLM |
| 5. Route to vault location | Markdown additions / new bridge articles | Partial (rule-based + judgment) | Routing rules (Pattern III) + operator |
| 6. Verify | Confirmed integration or rollback | No (requires reading the result) | Operator |

## Quick Reference

- **[`schema/step-descriptors.schema.json`](./schema/step-descriptors.schema.json)** — JSON Schema for per-step descriptors (what each step produces, what tool families it uses)
- **[`examples/`](./examples/)** — sample extraction sessions documenting the six steps applied to a podcast, a PDF, and a YouTube lecture

## The Two Pipelines

The protocol generalizes; only step 1 differs by source type.

**Audio/video pipeline.** Source (mp3 / mp4 / YouTube URL) → Whisper transcription with timestamps → cleaned Markdown → steps 2–6. Timestamp preservation through step 5 means the routed kernel carries a back-reference to the source audio at the specific moment.

**Web/PDF pipeline.** URL or PDF path → defuddle (web) or Marker (PDF) → cleaned Markdown → steps 2–6. Metadata preservation through step 5 means the routed kernel carries source attribution.

## What This Is NOT

- **Not an extraction tool.** Step 1 alone is what produces highlights collections.
- **Not a reading-notes app.** Reading-notes apps stop at step 1 (with optional annotations); the protocol's value is in steps 2 through 6.
- **Not automatable end-to-end.** Steps 2 and 6 require doctrine-fluent intelligence; steps 3, 4, and 5 are partially automatable but the operator owns the judgment.

## Adoption Path

1. Pattern I (Topology) and Pattern III (Classification) must exist first. Routing presupposes a routable architecture.
2. Pick an extraction tool per source type. The technical layer is a solved problem in open-source.
3. Author the doctrinal backbone (Pattern VI) — the assessment frame the operator (or AI companion) reads against.
4. Define routing rules. Where do new health kernels go? Civilizational-diagnosis kernels? Practice protocols? The rules are tradition-specific.
5. Run the protocol. Resist running step 1 alone — folder graveyards await.

## The Production Reference

The Harmonia Knowledge Extraction Pipeline (six-step protocol documented in the workspace `CLAUDE.md`) runs as editorial discipline in production, executed manually by Tahir in Cowork sessions with AI assistance at the assessment, kernel-identification, and reframing layers. A standalone CLI implementation — `extract <source> --bundle <tradition> --target <vault-root>` — is a next-phase contribution. The architectural commitment is recorded here; the CLI will land when sufficiently general across source types.

## Status

Spec-grade at v0.1. The methodology and schema are articulated. Examples demonstrate the protocol applied to three source types. The standalone CLI port is deferred.

## License

Schema, examples, documentation: CC-BY-4.0. Reference implementation code, when added, will be AGPL-3.0.
