# Pattern XIII — External Content Integration

*The six-step extraction protocol. Makes external-content integration a deterministic process rather than an editorial aspiration.*

Full treatment: [`METHODOLOGY.md` § XIII](../../METHODOLOGY.md). Reference implementation scaffold: [`components/extraction/`](../../components/extraction/).

## Problem It Solves

Knowledge systems acquire content from outside themselves — books, podcasts, papers, transcripts, video lectures, PDFs. Extraction tools are solved problems in open-source (Marker, Docling, MinerU for PDFs; Whisper for audio and video; defuddle for web). The hard part is *routing the extracted content into the tradition's doctrinal architecture* — placing it where it can be retrieved, classified, cross-linked to existing canon, distinguished from canon by content-layer.

The folder-based inbox model accumulates faster than it gets integrated. Reading-notes apps produce highlight collections that never become anything. The tag-soup model confuses *capture* with *integration*.

## The Six Steps

The protocol is deterministic. Each step has a defined output that becomes the input to the next.

1. **Extract.** Source → clean Markdown with preserved metadata. Marker / Docling / MinerU for PDFs; Whisper for audio/video; defuddle for web. The extraction layer is a solved problem.
2. **Assess.** Read against the tradition's doctrinal architecture. Convergences? Divergences? Novel kernels? Requires doctrine-fluent intelligence (operator + AI companion with backbone in context). *Not fully automatable.*
3. **Identify kernels.** Specific claims, frameworks, observations the tradition might integrate. A kernel is a discrete unit movable into the architecture without losing meaning. Most sources yield zero to three kernels; some yield more. *A kernel is not a quote.*
4. **Reframe in native language.** Translate kernels into the tradition's terminology. *Mindfulness* in the source becomes *Presence — sati in the Pāli* in the Harmonist register. The kernel's meaning must survive translation, or it was not what it appeared to be.
5. **Route to vault location.** Place reframed kernels in the architecture. Pattern III's routing rule applies absolutely — route to `bridge` or `applied`, **never** to `canon`. Seed new bridge articles when no destination exists.
6. **Verify.** Read the destination article(s) after integration. Does the kernel land coherently? Strengthen claims? Create contradiction? Verification prevents accumulation of kernels that were technically routed but functionally orphaned.

## Two Sibling Pipelines

- **Audio/video pipeline.** Source → Whisper transcription → Markdown with timestamps → steps 2–6. Timestamps make the output queryable (kernel routed into vault carries reference back to source audio at specific moment).
- **Web/PDF pipeline.** URL or path → defuddle / Marker → Markdown with metadata → steps 2–6. Metadata makes the output citable (routed kernel carries source attribution forward).

Both share the protocol; only step 1 differs.

## What the Protocol is NOT

- **Not extraction alone.** An operator running step 1 only is using an extraction tool, which solves a different problem.
- **Not a highlights collection.** Steps 3 and 4 distinguish integration from quotation.
- **Not a reading-notes app.** The protocol's value is in routing to a doctrinal architecture, not in producing annotated lists.

## Adoption Path

1. Define your tradition's doctrinal architecture first — Pattern I (Topology) and Pattern III (Classification) must exist before integration can route to it.
2. Pick an extraction tool per source type. The extraction layer is interchangeable.
3. Author the assessment discipline — the doctrinal frame the operator (or AI companion) reads against in step 2. This is the doctrinal backbone of Pattern VI applied at the extraction-assessment register.
4. Define routing rules for your tradition. Where do new health kernels go? New civilizational-diagnosis kernels? The routing schema lives at [`components/extraction/schema/step-descriptors.schema.json`](../../components/extraction/schema/step-descriptors.schema.json).
5. Run the protocol on incoming content. Resist running step 1 alone; the failure mode is folder graveyard.

## Schema

See [`components/extraction/schema/step-descriptors.schema.json`](../../components/extraction/schema/step-descriptors.schema.json) for the per-step descriptor format — what each step produces, what tool families it uses, what the destination shape is.

## Status

Spec-grade at v0.1. The methodology and schema are articulated. The production Knowledge Extraction Pipeline runs in Harmonia's operational practice (documented in the workspace `CLAUDE.md`) but lives as editorial discipline rather than as a standalone tool. The standalone implementation — a CLI that walks the six steps with appropriate AI assistance at the assessment and routing layers — is a next-phase contribution.
