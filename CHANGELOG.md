# Changelog

All notable changes to the Integral Knowledge Architecture framework — and the Sovereign Doctrinal Inference Protocol within it — are documented in this file.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/). Methodology and schemas version on date (`YYYY.MM`); component implementations version on semver.

## [Unreleased]

## [0.3.0] — 2026-08-15 — The Licence Exits

The AGPL-3.0 / CC-BY-4.0 split is retired. The whole repository — methodology, specification,
schemas, per-pattern documentation, reference-implementation code, and both SDIP bundles — is
dedicated to the public domain under CC0-1.0. No rights reserved, no attribution required.

The reasoning is in `NOTICE.md` and is doctrinal rather than strategic. Copyleft enforces the
four freedoms through copyright, and Harmonia has publicly renounced pursuing copyright remedy
(harmonism.io/transmission). A copyleft licence its holder will not invoke is a threat already
disarmed; keeping it was theatre. Protection relocates to where the doctrine says it belongs —
the depth of the work, the conformance suite, and the canonical surfaces.

The cost is accepted openly: CC0 permits a fork to close its copy and serve it. AGPL forbade
that. This is the same trade the Harmonist corpus already accepts.

### Changed

- **`LICENSE`** — AGPL-3.0 replaced with the CC0-1.0 legalcode, at the root and at `components/sdip/`.
- **`LICENSE-METHODOLOGY`, `components/sdip/LICENSE-SPEC`** — reduced to tombstones pointing at
  `LICENSE`. They grant nothing and restrict nothing; delete once inbound links are updated.
- **SPEC § 4.7 (Open Source Harness)** — the invariant is preserved and its enforcement relocated.
  The harness must still be publishable, auditable, modifiable and forkable *by the practitioner
  who runs it*, and source must ship with it or be fetchable at a stable public location named in
  the manifest. What changes: conformance is asserted by the conformance suite and the lineage of
  implementations that pass it, not by licence propagation. A fork may close its copy and thereby
  forfeit conformance.
- **Licence metadata swept repository-wide** — both `pyproject.toml` files (`license` field and
  trove classifier), both SDIP bundle manifests, `manifest.schema.json` field descriptions,
  `harness.py`'s invariant comment, `test_manifest.py` fixtures, the topology and translation
  examples, and the licence sections of every component README.

### Added

- **`NOTICE.md`** — why there is no licence; what replaces it; the CC0 patent caveat stated as
  legal fact rather than posture; and the standing exclusion of credentials and practitioner data
  from the commons.

### Supersedes

Decisions #836 and #839 on the licensing question, and closes the follow-through opened by
Decision #902.


## [0.2.0] — 2026-06-18 — Vault-Alignment Pass

Brings the published framework back into alignment with the doctrinal evolution of the source vault since the initial release. No new engineering — the change is to the methodology layer, tracking how Harmonia's own articulation of these patterns has matured.

### Added

- **`METHODOLOGY.md` § VI — "The Substrate Beneath the Context Layer."** New subsection articulating the two-layer composition the context-engineering pattern was always implicitly resting on: the context layer (substrate-agnostic, corrects the model's hand at the prompt, what this pattern transfers) and the model layer (substrate-specific alignment on a *fully-open* model — weights, corpus, training code, checkpoints, of which Ai2's OLMo family is the leading instance — reachable only with training capacity). The context layer is primary and ships now; substrate-specific alignment is the trajectory a system grows into, not a prerequisite. Cross-references the *Inference Sovereignty* doctrine and the *Doctrinal Fidelity in Aligned AI* Living Paper.
- **`patterns/vi-context-engineering/README.md` — substrate-layer section** mirroring the methodology addition, with pointers to the *Inference Sovereignty* article and to SDIP's existing OpenAI-compatible local-endpoint routing as the built-in substitution point for either layer.

### Changed

- **Pattern V renamed "Companion as Transmission Architecture" → "AI Companion as Transmission Architecture,"** and "the Companion" → "the AI companion" throughout, aligning with the source vault's lowercase-companion naming discipline ("companion" is the functional category, not a proper noun). Propagated across `METHODOLOGY.md`, `README.md` (pattern table + status table), and `patterns/v-companion/README.md`.
- **Harmonized the `METHODOLOGY.md` knowledge-system size figure** — the intro (`~470-file`) and body (`430-file`) disagreed; both now read `~470-file`, an approximate figure that avoids false precision on a count that drifts continuously.

## [0.1.0] — 2026-05-15 — Initial Public Release

The first public release of the Integral Knowledge Architecture framework. Tradition-neutral methodology for organizing, maintaining, and transmitting integral knowledge through AI, paired with the reference implementations of the patterns where code is the natural artifact.

### Methodology

- `METHODOLOGY.md` at root — thirteen substantive architectural patterns plus one meta-pattern, each forged through the construction of Harmonism: I (Fractal Topology), II (Centre-Spoke Topology), III (Epistemic Metadata Framework), IV (Content Priority Architecture), V (Companion as Transmission Architecture), VI (AI Context Engineering Architecture), VII (Translation Pipeline Architecture), VIII (Quality Assurance Architecture), IX (Instruction Architecture), X (Cross-Domain Integration Principle), XI (The Methodology as Living Document — meta-pattern), XII (Continuous Canonical Regeneration), XIII (External Content Integration)
- `patterns/` — per-pattern adoption guidance for each of the twelve substantive patterns plus the meta-pattern
- `LICENSE-METHODOLOGY` — CC-BY-4.0 for methodology, schemas, and per-pattern documentation

### Reference Components

- `components/sdip/` — **Sovereign Doctrinal Inference Protocol** v0.1, implementing Patterns V and VI. Full protocol specification (`SPEC.md`), 10-module Python harness package with manifest validator, bundle loader, conformance runner, OpenAI-compatible model client, per-practitioner SQLite memory, calibration manager, hybrid retrieval skeleton, harness, CLI. Reference Harmonism bundle carrying the ~36 KB production doctrinal backbone, the glossary, the calibration schema, and a 12-test conformance suite. Example-tradition bundle skeleton demonstrating the fork pattern. 9 tests passing.
- `components/topology/` — Pattern I reference: fractal-heptagram JSON schema, Harmonism Wheel as a worked example.
- `components/classification/` — Pattern III reference: five-axis classification JSON schema, frontmatter linter with `validate`, `census`, and `query` modes.
- `components/translation/` — Pattern VII reference: working Python library with dual validators (staleness detection, terminology linting, script purity), glossary loader and query engine, body and per-block hasher with markdown-aware splitting, provider abstraction with DeepL/Groq/Claude scaffolds documenting their failure-mode recovery interfaces, Click-based CLI with five working commands. 34 tests passing. Full architecture documentation at `PIPELINE.md`.
- `components/regeneration/` — Pattern XII reference: hash-manifest JSON schema, Living Podcast example manifest.
- `components/extraction/` — Pattern XIII reference: six-step protocol descriptor JSON schema.
- `components/sensors/` — Pattern VIII reference: sensor task descriptor JSON schema, four example sensor descriptors (`website-health`, `companion-knowledge-drift`, `weekly-vault-state-report`, `translation-staleness`).
- `components/instruction/` — Pattern IX reference: `PERSISTENT_ORIENTATION_TEMPLATE.md` scaffold for the document that gives an amnesiac AI agent persistent operational memory across sessions.

### Repository Infrastructure

- Top-level `README.md` — umbrella entry with the thirteen patterns table, repository layout, adoption paths, maturity status per pattern, license rationale.
- `LICENSE` — AGPL-3.0 for reference-implementation code under `components/`.
- `LICENSE-METHODOLOGY` — CC-BY-4.0 for the methodology document, schemas, and per-pattern documentation.
- 43 tests total across SDIP (9) and translation library (34); all passing.

### Status by Pattern

| Pattern | Maturity |
|---------|----------|
| I — Fractal Topology | Schema-grade (v0.1 schema + Harmonism Wheel example) |
| II — Centre-Spoke Topology | Doctrine-grade (architectural pattern, no code) |
| III — Epistemic Metadata | Schema-grade (v0.1 schema + working linter) |
| IV — Content Priority | Doctrine-grade (editorial pattern, no code) |
| V — Companion | Implementation-grade (SDIP v0.1) |
| VI — Context Engineering | Implementation-grade (SDIP v0.1) |
| VII — Translation Pipeline | Library v0.1 (validators working; provider ports pending) |
| VIII — QA Sensors | Pattern-grade (descriptor schema + four examples) |
| IX — Instruction Architecture | Pattern-grade (template + adoption guidance) |
| X — Cross-Domain Integration | Doctrine-grade (writing pattern, no code) |
| XI — Methodology as Living Document | Meta-pattern |
| XII — Continuous Canonical Regeneration | Spec-grade (schema + example; production code at Harmonia website repo) |
| XIII — External Content Integration | Spec-grade (schema + protocol descriptor) |

### Deferred to Future Releases

- **Translation library — DeepL, Groq, Claude provider implementations.** The architecture is documented in `PIPELINE.md` § 5 with all per-provider failure-mode recovery layers catalogued. The production code (~2,000 lines of Python) lives at the Harmonia website repository and ports module-by-module across subsequent releases.
- **SDIP — federation, contribution settlement, remote attestation.** Specified in `components/sdip/SPEC.md` § 11; the v0.1 protocol works without them.
- **Regeneration and extraction — standalone Python implementations.** The schemas, architectural documents, and example manifests are sufficient for any fork to implement in the language of their choice. The production reference implementations live in Harmonia's operational deployment.
