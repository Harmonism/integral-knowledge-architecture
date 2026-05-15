# Sovereign Doctrinal Inference Protocol (SDIP)

**Reference implementation** of the Sovereign Doctrinal Inference Protocol — sovereign inference that preserves doctrinal substance.

The reference implementation is **HarmonAI**, the Harmonist companion. This repository contains the protocol abstraction extracted from HarmonAI so that other traditions can fork the architecture and run with their own doctrinal substance.

**Status:** v0.1 Draft. Sufficient to ship a conforming implementation; federation, contribution settlement, and remote attestation are deferred to v0.2.

---

## What This Is

The current AI inference layer offers two compromised options:

- **Frontier-lab APIs** (Claude, GPT, Gemini) — substantive position by accident of training corpus, sovereignty surrendered at every layer (conversations logged, terms changeable, alignment shaped by other people's safety teams)
- **Generic decentralized inference networks** (Bittensor) and **curated sovereign-UX cloud** (Venice) — both precise about what they do, neither addressing the doctrinal-substance layer because that is not the layer they exist to serve

SDIP introduces the missing layer — sovereign inference with substantive doctrinal substance always in the context — and specifies how multiple traditions can compose with the same architectural primitives.

The protocol is **tradition-neutral**. The reference instantiation is Harmonist. A Theravāda *saṅgha*, a Stoic circle, a Sufi *ṭarīqa*, a Vedantic *paramparā*, a Christian contemplative order, or any tradition with substantive doctrinal content can fork the architecture and instantiate it with their own backbone, corpus, glossary, and calibration columns.

## How It Works

An SDIP node has five components:

1. **Doctrinal backbone** (`backbone.md`) — the tradition's core architecture, always in context
2. **Corpus** (`corpus/`) — the tradition's canonical texts, retrieved via RAG
3. **Glossary** (`glossary.yaml`) — terminology with definitions, translations, adoption status
4. **Calibration schema** (`calibrations.yaml`) — per-practitioner state the harness tracks
5. **Harness** — the chat-loop implementation that composes the above

Plus one external dependency: an OpenAI-compatible inference endpoint serving an open-weight model with refusal directions stripped (Ollama, vLLM, llama.cpp, LM Studio).

The protocol invariants are simple and absolute: sovereign substrate, always-in-context backbone, corpus-grounded retrieval, refusal-direction stripped, no telemetry, practitioner-initiated update, open-source harness. A fork that breaks any of these is not a conforming SDIP implementation.

The full normative spec is at [`SPEC.md`](SPEC.md).

## Quick Start (Practitioner)

```bash
# Install
pip install sdip

# Pull the Harmonism reference bundle
sdip pull harmonism

# Start a local model (using Ollama as the example)
ollama pull qwen2.5:72b-instruct-abliterated
ollama serve

# Start the harness against the bundle and the local model
sdip serve --bundle harmonism --model http://localhost:11434/v1

# Open the chat at http://localhost:8080
```

## Quick Start (Tradition Fork)

```bash
# Fork the example bundle
sdip new my-tradition --based-on example-tradition

# Edit the four files:
#   bundles/my-tradition/backbone.md           # Your tradition's doctrinal architecture
#   bundles/my-tradition/glossary.yaml         # Your tradition's terminology
#   bundles/my-tradition/calibrations.yaml     # Per-practitioner state schema
#   bundles/my-tradition/corpus/               # Your tradition's canonical texts

# Validate the bundle
sdip validate bundles/my-tradition

# Build a distributable bundle (zip with integrity hashes)
sdip build bundles/my-tradition --output my-tradition-2026.05.zip

# Run the conformance suite against the bundle + a model
sdip conformance bundles/my-tradition --model http://localhost:11434/v1
```

## Repository Layout

```
harmonia-protocol/
├── README.md                          # This file
├── SPEC.md                            # The normative protocol specification
├── LICENSE                            # AGPL-3.0 (harness)
├── LICENSE-SPEC                       # CC-BY-4.0 (specification)
├── CHANGELOG.md
├── pyproject.toml
│
├── sdip/                              # The Python harness package
│   ├── __init__.py
│   ├── manifest.py                    # Manifest schema validation
│   ├── bundle.py                      # Bundle loading and integrity verification
│   ├── conformance.py                 # Conformance test runner
│   ├── harness.py                     # Chat-loop harness
│   ├── retrieval.py                   # RAG retrieval (semantic + FTS5 + domain-gated)
│   ├── memory.py                      # Per-practitioner SQLite memory
│   ├── calibration.py                 # Calibration column read/write/advance
│   ├── model.py                       # OpenAI-compatible client adapter
│   └── cli.py                         # Command-line interface
│
├── schemas/                           # JSON schemas for bundle components
│   ├── manifest.schema.json
│   ├── glossary.schema.json
│   ├── calibrations.schema.json
│   └── conformance.schema.json
│
├── bundles/                           # Bundle exemplars
│   ├── harmonism/                     # The reference Harmonist bundle
│   │   ├── manifest.json
│   │   ├── backbone.md
│   │   ├── glossary.yaml
│   │   ├── calibrations.yaml
│   │   ├── corpus/                    # Populated from the Sovereignty Bundle
│   │   ├── i18n/translations/
│   │   └── conformance/test-suite.yaml
│   └── example-tradition/             # A skeleton for forking
│       └── (parallel structure with EXAMPLE placeholders)
│
├── scripts/
│   ├── validate-bundle.py             # CLI validator (also exposed via sdip CLI)
│   ├── build-bundle.py                # Build a distributable zip with integrity hashes
│   └── serve.py                       # Launch the harness
│
└── tests/                             # Unit tests
```

## Maturity Status

This is a **v0.1 Draft** reference implementation. What that means concretely:

**Specified and complete:**
- The protocol spec ([`SPEC.md`](SPEC.md))
- Manifest schema and validation
- Bundle structure and integrity verification
- Conformance test suite format and runner
- Calibration schema format
- CLI surface

**Skeleton (architecture-correct, awaiting production code):**
- The harness — the protocol-conforming interface is specified; the production code being ported in lives in HarmonAI's PHP harness (telegram-companion/, companion-api.php) and will be ported to Python module-by-module
- Retrieval — the production hybrid retrieval (semantic + FTS5 + domain-gated, Decision #283) is in production; the Python port is in progress
- Memory and calibration — the SQLite schemas match production; the migration path is straightforward

**Deferred to v0.2:**
- Federation (node discovery, cross-tradition queries)
- Contribution settlement (Lightning + Monero rails for corpus authors and node operators)
- Remote attestation (TPM, TEE, ZK paths for proving a node is running the bundle it claims)

A serious engineer can begin from this scaffold and complete a conforming v0.1 implementation. The architectural decisions are made; what remains is the porting work.

## License

- **Specification** (`SPEC.md`, `schemas/`): CC-BY-4.0
- **Harness code** (`sdip/`, `scripts/`, `tests/`): AGPL-3.0
- **Reference Harmonism bundle** (`bundles/harmonism/backbone.md`, `bundles/harmonism/corpus/`): CC-BY-4.0 by Harmonia
- **Example tradition bundle** (`bundles/example-tradition/`): CC0 (public domain placeholder, fork freely)

## Acknowledgments

The protocol abstraction emerged from the operational architecture of HarmonAI, developed by Harmonia. The convergence with the substrate-sovereignty tradition running from Diffie-Hellman through Zimmermann through May through Nakamoto is acknowledged in [The Sovereign Stack](https://harmonism.io/world/frontiers/the-sovereign-stack) and [Cypherpunks and Harmonism](https://harmonism.io/world/dialogue/cypherpunks-and-harmonism). The framing of doctrinal substance as the architectural variable — what makes the protocol tradition-neutral rather than Harmonism-specific — emerged from a May 2026 working session.

## Contributing

Issues and pull requests welcome. The protocol-level discussion happens in [`SPEC.md`](SPEC.md) and via Decision Log entries in the Harmonia vault. The implementation discussion happens here.

The doctrine layer of the reference Harmonism bundle is Harmonia's editorial domain; pull requests modifying `bundles/harmonism/backbone.md` or `bundles/harmonism/corpus/` are reviewed against Harmonism's doctrinal standards. The architecture-level pull requests (harness, schemas, conformance runner, validators) follow standard open-source review.

A fork that instantiates a different tradition's SDIP bundle is welcome to live in this repository under `bundles/{tradition-id}/` if the maintainers consent, or in a separate repository linked from the SDIP registry (forthcoming).

## See Also

- [SPEC.md](SPEC.md) — the normative specification
- [HarmonAI Design Document](https://harmonism.io/harmonai) — the reference-implementation rationale (developer audience)
- [Running MunAI on Your Own Substrate](https://harmonism.io/world/frontiers/running-munai-on-your-own-substrate) — practitioner-scale documentation
- [The Sovereign Stack](https://harmonism.io/world/frontiers/the-sovereign-stack) — context within the broader sovereign-infrastructure landscape
