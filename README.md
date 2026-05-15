# Integral Knowledge Architecture

**A tradition-neutral methodology for organizing, maintaining, and transmitting integral knowledge — and the reference implementations of its operational patterns.**

This is the public framework Harmonia has developed through the construction of [Harmonism](https://harmonism.io). It articulates thirteen architectural patterns that any integral knowledge tradition — traditional medicine, indigenous wisdom, contemplative lineages, integral educational curricula, religious teaching systems navigating the transition to AI-mediated learning — can adopt to organize itself for the age that has arrived.

The methodology is in [`METHODOLOGY.md`](./METHODOLOGY.md). The reference implementations are in [`components/`](./components/). The pattern-by-pattern adoption guidance is in [`patterns/`](./patterns/).

---

## Why This Exists

Every serious wisdom tradition faces the same structural crisis. The knowledge exists — scattered across lineages, texts, oral transmissions, lived practice — but it has no architecture. AI has arrived with the capacity to organize, retrieve, teach, and converse, but no methodology for doing so in service of integral knowledge. The default architecture — a chatbot over a base-trained model with shallow RAG — produces a tool that can summarise any tradition and embody none.

What is missing is not content. What is missing is architecture: a methodology for organising integral knowledge so that it can be navigated by human practitioners, taught by AI companions, maintained across languages, validated against its own standards, and extended without losing coherence.

This repository is that methodology, plus the operational tooling that implements it.

## The Thirteen Patterns

Each pattern is a problem class plus a solution pattern, validated against the production Harmonism deployment. Full treatment in [`METHODOLOGY.md`](./METHODOLOGY.md); per-pattern adoption guidance in `patterns/`.

| # | Pattern | Problem It Solves | Component |
|---|---------|-------------------|-----------|
| I | [Fractal Topology](./patterns/i-fractal-topology/) | Taxonomies murder integration; tag clouds provide no structure. | [`topology/`](./components/topology/) |
| II | [Centre-Spoke Topology](./patterns/ii-centre-spoke/) | Every choice of centre privileges one domain and subordinates others. | (specification only) |
| III | [Epistemic Metadata Framework](./patterns/iii-epistemic-metadata/) | Articles in a large vault have different epistemic standings; binary draft/published toggles cannot represent this. | [`classification/`](./components/classification/) |
| IV | [Content Priority Architecture](./patterns/iv-content-priority/) | Equal investment across domains produces mediocrity everywhere; author-driven investment produces unprincipled asymmetry. | (specification only) |
| V | [Companion as Transmission Architecture](./patterns/v-companion/) | Personalised integral transmission has never scaled beyond the one-to-one relationship. | [`sdip/`](./components/sdip/) |
| VI | [Three-Tier Context Engineering](./patterns/vi-context-engineering/) | RLHF-trained models hedge stable doctrinal positions structurally, not editorially. | [`sdip/`](./components/sdip/) |
| VII | [Translation Pipeline Architecture](./patterns/vii-translation/) | AI translation silently violates doctrinal terminology; staleness and translation-error are non-overlapping failure modes. | [`translation/`](./components/translation/) |
| VIII | [QA Sensor Architecture](./patterns/viii-qa-sensors/) | Living knowledge systems accumulate entropy invisibly; automated repair masks failure modes. | [`sensors/`](./components/sensors/) |
| IX | [Instruction Architecture](./patterns/ix-instruction-architecture/) | AI-mediated knowledge work is amnesiac; operator memory is the weakest link. | [`instruction/`](./components/instruction/) |
| X | [Cross-Domain Integration Principle](./patterns/x-cross-domain-integration/) | Parenthetical gestures toward integration without achieving it. | (specification only) |
| XI | [The Methodology as Living Document](./METHODOLOGY.md#xi-the-methodology-as-living-document) | The methodology itself must grow as new patterns surface. | (meta-pattern) |
| XII | [Continuous Canonical Regeneration](./patterns/xii-canonical-regeneration/) | Knowledge artifacts freeze at publication; doctrine continues to evolve; the gap widens with every publishing cycle. | [`regeneration/`](./components/regeneration/) |
| XIII | [External Content Integration](./patterns/xiii-content-integration/) | Folders accumulate captured content faster than it gets integrated into doctrinal architecture. | [`extraction/`](./components/extraction/) |

## Repository Layout

```
harmonia-architecture/
├── README.md                          # This file
├── METHODOLOGY.md                     # The thirteen patterns at full articulation
├── LICENSE                            # AGPL-3.0 for code
├── LICENSE-METHODOLOGY                # CC-BY-4.0 for methodology and schemas
├── CHANGELOG.md
│
├── patterns/                          # Per-pattern adoption notes
│   ├── i-fractal-topology/
│   ├── ii-centre-spoke/
│   ├── iii-epistemic-metadata/
│   ├── iv-content-priority/
│   ├── v-companion/
│   ├── vi-context-engineering/
│   ├── vii-translation/
│   ├── viii-qa-sensors/
│   ├── ix-instruction-architecture/
│   ├── x-cross-domain-integration/
│   ├── xii-canonical-regeneration/
│   └── xiii-content-integration/
│
└── components/                        # Reference implementations
    ├── sdip/                          # Patterns V + VI: Sovereign Doctrinal Inference Protocol
    ├── topology/                      # Pattern I: fractal heptagram builder + validator
    ├── classification/                # Pattern III: 5-axis classification schema + linter
    ├── translation/                   # Pattern VII: dual-validation translation pipeline
    ├── regeneration/                  # Pattern XII: hash-manifest incremental regeneration
    ├── extraction/                    # Pattern XIII: six-step extraction protocol
    ├── sensors/                       # Pattern VIII: scheduled sensor fleet
    └── instruction/                   # Pattern IX: persistent orientation template
```

## Adoption Paths

Three honest tiers, ordered by leverage.

**Methodology adoption.** Read [`METHODOLOGY.md`](./METHODOLOGY.md) and apply the patterns to a knowledge system you maintain. Most patterns can be applied without any code from this repository — they are architectural decisions, not software. The Fractal Topology, the Centre-Spoke Topology, the Epistemic Metadata Framework, the Content Priority Architecture, the Instruction Architecture, and the Cross-Domain Integration Principle are all pure-methodology patterns that any vault, wiki, or knowledge management system can adopt by editorial decision.

**Schema adoption.** The schemas under `components/*/schema/` are JSON Schemas and YAML conventions that any vault can adopt to interoperate with the methodology's reference tooling. Classification metadata, glossary governance, translation manifest, sensor task descriptors — all are tradition-neutral.

**Component adoption.** The full reference implementations in `components/` are open-source code (AGPL-3.0). Adopt them directly, fork them, or implement against their interfaces in your own language. SDIP is the most mature component (v0.1 ships today); translation, classification, topology, sensors, and instruction are scaffolded with schemas plus stubs at v0.1.

## Maturity Status by Pattern

| Pattern | Methodology Doc | Schema | Reference Impl | Status |
|---------|-----------------|--------|----------------|--------|
| I — Fractal Topology | ✓ | ✓ v0.1 | ✓ v0.1 | Schema-grade |
| II — Centre-Spoke | ✓ | n/a | (architectural pattern, no code) | Doctrine-grade |
| III — Epistemic Metadata | ✓ | ✓ v0.1 (5-axis) | ✓ v0.1 linter stub | Schema-grade |
| IV — Content Priority | ✓ | n/a | (editorial pattern) | Doctrine-grade |
| V — Companion | ✓ | ✓ (SDIP manifest) | ✓ v0.1 (SDIP) | Implementation-grade |
| VI — Context Engineering | ✓ | ✓ (SDIP calibrations) | ✓ v0.1 (SDIP) | Implementation-grade |
| VII — Translation Pipeline | ✓ | ✓ v0.1 | v0.1 library (validators working) | Library v0.1 |
| VIII — QA Sensors | ✓ | ✓ v0.1 | examples | Pattern-grade |
| IX — Instruction Architecture | ✓ | ✓ v0.1 (template) | template | Pattern-grade |
| X — Cross-Domain Integration | ✓ | n/a | (writing pattern) | Doctrine-grade |
| XI — Methodology as Living Document | ✓ | n/a | (meta-pattern) | Meta-pattern |
| XII — Continuous Canonical Regeneration | ✓ | ✓ v0.1 (manifest) | scaffold | Spec-grade |
| XIII — External Content Integration | ✓ | ✓ v0.1 (step descriptors) | scaffold | Spec-grade |

## Licensing

- **Methodology document, JSON schemas, YAML conventions, per-pattern docs:** [CC-BY-4.0](./LICENSE-METHODOLOGY). Adopt and adapt freely with attribution.
- **Code in `components/*/` (Python implementations, scripts, linters):** [AGPL-3.0](./LICENSE). Preserve the open-source invariant downstream.

The asymmetric license is structural: the methodology's value is in its adoption, so the spec layer is permissive. The harness code carries the architectural invariants (no telemetry, sovereign substrate, open source) that AGPL-3.0 keeps preserved through network-deployed forks.

## Contributing

Pattern additions, schema refinements, reference-implementation contributions, case studies of methodology adoption in other traditions — all welcome. The methodology itself grows as new patterns are forged against new problems; the contribution model is documented in `METHODOLOGY.md` § XI.

The doctrinal layer of the reference Harmonism instantiation (its specific corpus, glossary, calibration columns) is Harmonia's editorial domain. The architecture layer (the methodology, schemas, harness code, sensor patterns) is community.

## Acknowledgments

The methodology emerged through the construction of [Harmonism](https://harmonism.io). The transferable patterns were discovered by building, not by theorising — every solution was forged against a real problem encountered while constructing the Harmonist knowledge system. The references to specific decisions throughout `METHODOLOGY.md` (Decision #283, #517, #535, etc.) point at the Harmonia Decision Log entries where each pattern crystallized.

## See Also

- [Harmonism](https://harmonism.io) — the reference instantiation
- [`METHODOLOGY.md`](./METHODOLOGY.md) — the thirteen patterns at full articulation
- [`components/sdip/SPEC.md`](./components/sdip/SPEC.md) — the Sovereign Doctrinal Inference Protocol specification (Patterns V + VI)
- [Doctrinal Fidelity in Aligned AI](https://harmonism.io/the-living-papers/doctrinal-fidelity-in-aligned-ai--a-knowledge-architecture-response-to-the-problem-of-sovereign-transmission) — academic-paper articulation of the context-engineering pattern (Living Paper)
