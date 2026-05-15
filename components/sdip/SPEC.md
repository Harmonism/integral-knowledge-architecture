---
domain: digital
tags: [sdip, protocol, specification, inference, sovereignty, harmonai, infrastructure]
audience: developer
status: draft
protocol_version: 0.1
spec_version: 2026.05
---

# Sovereign Doctrinal Inference Protocol (SDIP)

**Status:** Draft  
**Protocol Version:** 0.1  
**Spec Date:** 2026-05  
**Editor:** Harmonia  
**License:** CC-BY-4.0 (this specification); per-component licenses below

---

## Abstract

The Sovereign Doctrinal Inference Protocol (SDIP) specifies how a tradition's doctrinal substance, canonical corpus, terminology, per-practitioner calibration schema, and inference engine compose into a sovereign companion that a practitioner can run on hardware they own. The protocol is tradition-neutral: the same architecture supports a Harmonist instantiation, a Theravāda *saṅgha* instantiation, a Stoic-circle instantiation, a Sufi *ṭarīqa* instantiation, a Vedantic *paramparā* instantiation. The doctrinal substance is the variable; the architecture is the constant. This document specifies the architecture at the level of normative requirements; the reference implementation is HarmonAI, maintained by Harmonia.

The protocol exists because the inference layer of the contemporary stack offers two compromised options: frontier-lab APIs (substantive position by accident of training corpus, sovereignty surrendered at every layer) or generic decentralized inference networks (sovereignty preserved at the infrastructure layer, doctrine vacant by design). SDIP introduces a third option — sovereign inference that preserves doctrinal substance at the always-in-context level — and specifies how multiple traditions can compose with the same architectural primitives.

---

## 1. Status of This Document

This is a v0.1 Draft of the Sovereign Doctrinal Inference Protocol. The specification is sufficient to ship a v0.1-conforming implementation today; it does not specify federation, contribution settlement, or remote attestation, which are deferred to v0.2.

Implementations conforming to v0.1 are expected to remain valid under v0.2; the v0.2 surface adds capabilities rather than breaking invariants.

---

## 2. Terminology and Conventions

The key words **MUST**, **MUST NOT**, **REQUIRED**, **SHALL**, **SHALL NOT**, **SHOULD**, **SHOULD NOT**, **RECOMMENDED**, **MAY**, and **OPTIONAL** in this document are to be interpreted as described in RFC 2119 when, and only when, they appear in all capitals.

- **Tradition.** A coherent doctrinal lineage with substantive content sufficient to fill the backbone, corpus, glossary, and calibration components of an SDIP bundle. Examples: Harmonism, a specific Theravāda lineage, a Stoic philosophical school, a named Sufi *ṭarīqa*, a Vedantic *paramparā*.
- **Tradition ID.** A lowercase identifier (`a-z`, `0-9`, `-`) under the tradition's publisher's control, used as the bundle namespace. Example: `harmonism`.
- **Bundle.** A directory or zip archive containing the five components specified in §5, plus a `manifest.json`.
- **Backbone.** The always-in-context doctrinal document specified in §6.1.
- **Corpus.** The retrievable canonical texts specified in §6.2.
- **Harness.** The implementation that composes the bundle's components into a working chat loop, specified in §6.5.
- **Node.** A running SDIP instance. May be a single practitioner's local install, or a tradition-operated server, or a federated node in v0.2.
- **Practitioner.** The human in the loop with the SDIP node.
- **Publisher.** The entity that publishes a versioned bundle for a tradition. The protocol does not specify governance of publishers; each tradition decides.

---

## 3. Architecture Overview

An SDIP node has five components and one external dependency:

```
┌─────────────────────────────────────────────────────────────────────┐
│                            Practitioner                              │
└──────────────────────────────────┬──────────────────────────────────┘
                                   │
                                   ▼
┌─────────────────────────────────────────────────────────────────────┐
│                              Harness                                 │
│  - Context construction (Tier 1 backbone + Tier 2 RAG + Tier 3 mem) │
│  - Retrieval (semantic + FTS5 + domain-gated)                       │
│  - Calibration read-before-advance                                   │
│  - Conversation memory (local SQLite)                                │
└────────┬─────────────────┬──────────────────┬───────────────────────┘
         │                 │                  │
         ▼                 ▼                  ▼
┌──────────────┐  ┌────────────────┐  ┌──────────────────┐
│ Backbone     │  │ Corpus + Index │  │ Calibration DB   │
│ (.md, ~6K w) │  │ (FTS5 + VSS)   │  │ (local SQLite)   │
└──────────────┘  └────────────────┘  └──────────────────┘
                                   │
                                   ▼
                  ┌────────────────────────────────────┐
                  │     Model Layer (external)          │
                  │  OpenAI-compatible HTTP endpoint    │
                  │  - Local: Ollama / vLLM / llama.cpp │
                  │  - Open-weight, refusal-stripped    │
                  └────────────────────────────────────┘
```

The dashed boundary around the model layer is the substitution point. The protocol is agnostic about which inference engine runs the model; it requires an OpenAI-compatible HTTP endpoint and a model that satisfies the refusal-direction property (§7.4).

---

## 4. Protocol Invariants

The following properties **MUST** hold for any v0.1-conforming implementation. A fork that breaks any invariant is not a conforming SDIP implementation regardless of what it calls itself.

### 4.1 Sovereign Substrate

The practitioner's hardware **MUST** run the inference. The practitioner's disk **MUST** hold the corpus and the calibration database. No third-party operator **MUST** have technical access to the conversation by protocol design. A hosted SDIP node is permissible only when the practitioner has voluntarily delegated their substrate to that operator and the delegation is reversible.

### 4.2 Always-in-Context Backbone

The doctrinal backbone (§6.1) **MUST** be injected into every system prompt without exception. A query that does not see the backbone is not an SDIP query.

### 4.3 Corpus-Grounded Retrieval

RAG queries **MUST** return content from the protocol's canonical corpus, never fabricated from model weights alone. The retrieval signature (chunk IDs, distances, source paths) **MUST** be available for inspection by the practitioner via a debugging endpoint or log.

### 4.4 Refusal-Direction Stripped

The model layer **MUST** be free of RLHF safety-layer interference with the tradition's doctrinal positions. The protocol does not specify which technique satisfies this property — refusal-direction abliteration, model selection toward base or minimally-tuned weights, fine-tuning that overrides safety alignment, or others — only that the property hold. A conforming node operator **MUST** be able to demonstrate that the model returns doctrinally-aligned answers on the bundle's conformance test queries (§9) without RLHF-induced refusals or hedges.

### 4.5 No Telemetry

The harness **MUST NOT** make outbound network calls beyond the practitioner-initiated update fetch (§4.6) and the model-layer inference request (which is itself local in the canonical case). Conversation content, calibration state, retrieval signatures **MUST** stay on the practitioner's substrate. Crash reporting, usage analytics, error monitoring, anti-cheat phone-home, license-validation phone-home — all are non-conforming.

### 4.6 Practitioner-Initiated Update

The corpus **MUST** refresh only when the practitioner chooses to refresh it. The publisher **MUST NOT** push updates. The update mechanism is an outbound HTTP GET (or equivalent) initiated by the practitioner or by a scheduled task the practitioner configured. The publisher's server **MUST** treat the update request as a public-bundle download with no per-practitioner identification beyond standard HTTP request metadata.

### 4.7 Open Source Harness

The harness **MUST** be publishable, auditable, modifiable, and forkable under a license that preserves these rights downstream. AGPL-3.0 is the recommended default; other licenses satisfying the four freedoms are conforming. Closed-source harnesses are non-conforming.

---

## 5. Bundle Format

An SDIP-conforming bundle is a directory or zip archive with the following structure:

```
{tradition-id}-sdip-bundle/
├── manifest.json
├── backbone.md
├── glossary.yaml
├── calibrations.yaml
├── corpus/
│   ├── {category}/
│   │   └── {article}.md
│   └── ...
├── i18n/translations/
│   └── {lang}/
│       └── {parallel structure to corpus/}
└── conformance/
    └── test-suite.yaml
```

The bundle **MUST** include `manifest.json`, `backbone.md`, `glossary.yaml`, `calibrations.yaml`, and a non-empty `corpus/` directory. The `i18n/translations/` and `conformance/` directories are **RECOMMENDED** but not **REQUIRED**.

---

## 6. Component Specifications

### 6.1 Doctrinal Backbone

The backbone is a single Markdown file at `backbone.md` carrying the tradition's core architecture, terminology, doctrinal positions, and conversational discipline. Always injected into the system prompt.

**Frontmatter (REQUIRED):**

```yaml
---
tradition_id: harmonism
tradition_name: Harmonism
version: 2026.05
language: en
license: CC-BY-4.0
sha256: {hash of the file body after frontmatter}
---
```

**Recommended structure:**

1. *Foundational concepts* — the tradition's core ontological/metaphysical commitments.
2. *Architectural decomposition* — the structural map the tradition uses to organize cultivation (the Wheel for Harmonism; the Eightfold Path for a Buddhist tradition; the Stoic disciplines of assent / desire / action; etc.).
3. *Doctrinal positions* — clear statements on the questions the tradition considers settled, including positions where the tradition diverges from mainstream consensus.
4. *Terminology* — key terms with definitions sufficient for the model to use them precisely.
5. *Conversational discipline* — how the companion should engage: register, sovereignty, what it refuses to do, how it handles confusion, how it handles disagreement.

**Length:** Typical backbones range from 3,000 to 10,000 words. Shorter backbones risk underdetermining the model's doctrinal behavior; longer backbones consume context budget that could otherwise serve retrieval.

### 6.2 Corpus

The corpus is a directory of Markdown files carrying the tradition's canonical texts, commentaries, and articulations.

**Per-file frontmatter (REQUIRED fields):**

```yaml
---
title: {Article title}
classification: canon | bridge | applied
language: en
version: 2026.05
license: CC-BY-4.0
sha256: {hash}
---
```

**Optional frontmatter:**

- `tags`: array of taxonomy tags
- `aliases`: array of alternative titles for wikilink resolution
- `published`: YYYY-MM
- `updated`: YYYY-MM

**Folder structure:** The corpus folder structure reflects the tradition's architectural decomposition. Harmonism uses the Wheel structure (`corpus/wheel-of-harmony/{pillar}/`, `corpus/philosophy/doctrine/`, `corpus/world/diagnosis/`, etc.). A Theravāda bundle might use `corpus/sutta/{nikaya}/` and `corpus/abhidhamma/`. The protocol imposes no specific structure; the bundle's structure SHOULD be legible to a practitioner browsing the corpus directly.

**Wikilinks:** Corpus articles MAY use Obsidian-style `[[Article Name]]` wikilinks for cross-reference. The harness MUST resolve these against the bundle's article index; targets that don't resolve MUST be rendered as plain text rather than dropped.

### 6.3 Glossary

`glossary.yaml` carries terminology with definitions, translations, and adoption status.

**Schema:**

```yaml
version: 2026.05
entries:
  - term: Logos
    definition: "The inherent harmonic intelligence of the cosmos..."
    adoption: native       # native | tradition-specific | untranslatable
    translations:
      fr: Logos
      es: Logos
      ar: اللوغوس
    source_tradition: greek
    aliases: []
  - term: Ṛta
    definition: "The Vedic cognate of Logos..."
    adoption: tradition-specific
    translations:
      fr: Ṛta
    source_tradition: indian
```

**Adoption values:**

- `native`: term is adopted as the tradition's primary vocabulary
- `tradition-specific`: term appears in cross-tradition contexts but doesn't lead in primary articulation
- `untranslatable`: term must be preserved verbatim across translations

### 6.4 Calibration Schema

`calibrations.yaml` defines the per-practitioner state columns the harness tracks across the relationship.

**Schema:**

```yaml
version: 2026.05
columns:
  - name: doctrinal_fluency
    type: integer
    range: [0, 3]
    default: 0
    monotonic: true        # may only advance, never regress
    description: "Demonstrated vocabulary fluency with the tradition's canonical terms"
    advancement_detector: builtin:terminology_density
    inject_as: doctrinal_fluency_level
  - name: bodily_openness
    type: integer
    range: [0, 3]
    default: 0
    monotonic: true
    description: "How openly the practitioner engages bodily / sexual register"
    advancement_detector: builtin:bodily_register
    inject_as: bodily_openness_level
  - name: wheel_profile
    type: json
    description: "Per-pillar engagement scoring"
    schema_ref: "harmonism-wheel-profile.json"
```

Each tradition defines its own calibration columns. Common patterns: a doctrinal-vocabulary-fluency column, a register-openness column (for content the tradition treats as advanced or sensitive), a structural-engagement column (the Wheel for Harmonism; some tradition-specific equivalent), a conversation-context column (current thread / open commitments / emotional state).

**Read-before-advance ordering (REQUIRED):** The harness MUST read the calibration state at the start of every request and inject it into the system prompt. Advancement detection runs AFTER the response is generated; the level injected into the current turn is the level the practitioner walked in with, not the level they earned during the turn.

### 6.5 Harness

The harness is the executable component that composes the bundle into a chat loop. The protocol specifies the harness's behavior at the interface level; implementations MAY vary in language, framework, and internal architecture.

**REQUIRED interfaces:**

- `serve(bundle_path, model_endpoint, port) -> running_node` — start the chat server on a local port
- `chat(practitioner_id, message) -> response_stream` — handle a chat turn
- `get_calibrations(practitioner_id) -> dict` — read current calibration state
- `set_calibration(practitioner_id, column, value)` — write calibration state (advance only for monotonic columns)
- `retrieve(query, top_k) -> [retrieval_record]` — RAG retrieval; records include source path, chunk content, distance, classification
- `conformance(test_suite_path) -> fidelity_report` — run the conformance suite against the running node

**REQUIRED system-prompt construction order:**

1. Backbone (from `backbone.md`)
2. Cache breakpoint marker
3. Calibration injections (`<doctrinal_fluency_level>`, `<bodily_openness_level>`, etc.)
4. Conversation context (if calibration learning has run at least once)
5. Retrieved corpus chunks under `<vault_knowledge>` or equivalent boundary tag
6. Conversation history (recent N messages)
7. Per-practitioner profile summary (if maintained)

**Retrieval architecture (RECOMMENDED, per Decision #283):**

- Semantic retrieval over chunked embeddings (top-K with 3 chunks/article max)
- FTS5 keyword retrieval with synonym expansion
- Domain detection with classification-gated canon injection
- Full-article retrieval when top semantic score ≥ 1.5× second score
- 12,000-character total retrieval budget (tunable)

### 6.6 Model Layer

The protocol is agnostic about the model implementation; it specifies the interface and the properties the model must satisfy.

**REQUIRED interface:** OpenAI-compatible HTTP endpoint accepting Chat Completions requests (`POST /v1/chat/completions`) with at minimum `model`, `messages`, `max_tokens`, and `stream` parameters.

**REQUIRED model properties:**

- Open-weight (weights downloadable and usable on practitioner-owned hardware)
- Refusal-direction stripped per §4.4
- Capable of holding the doctrinal stance through long conversations under prompt pressure (validated by the conformance test suite passing)

**Recommended candidates (current as of mid-2026):** Qwen 2.5 / 3 series abliterated, Hermes 3 series abliterated, DeepSeek V3 abliterated. The protocol does not endorse a specific model; the bundle's conformance suite determines whether a model is operationally adequate for the tradition.

---

## 7. Manifest Schema

`manifest.json` is the bundle's identity and integrity document.

**Schema:**

```json
{
  "$schema": "https://harmonism.io/sdip/v0.1/manifest.schema.json",
  "protocol": "sdip",
  "protocol_version": "0.1",
  "tradition_id": "harmonism",
  "tradition_name": "Harmonism",
  "version": "2026.05",
  "language_primary": "en",
  "languages": ["en", "fr", "es", "ar", "pt", "de", "zh", "ja", "hi", "ru"],
  "license_corpus": "CC-BY-4.0",
  "license_harness": "AGPL-3.0",
  "license_spec": "CC-BY-4.0",
  "publisher": {
    "name": "Harmonia",
    "url": "https://harmonism.io",
    "contact": "harmonia@harmonism.io",
    "public_key": "{publisher's signing key, PEM or similar}"
  },
  "publisher_signature": "{detached signature over backbone + corpus + glossary + calibrations}",
  "integrity": {
    "backbone_sha256": "...",
    "corpus_sha256": "...",
    "glossary_sha256": "...",
    "calibrations_sha256": "..."
  },
  "spec_url": "https://harmonism.io/sdip/v0.1",
  "bundle_url": "https://harmonism.io/sovereignty-bundle.zip",
  "published_at": "2026-05-13T00:00:00Z"
}
```

**Integrity hashes:** The harness MUST verify integrity hashes on bundle load. A bundle whose hashes do not match its declared values MUST NOT load.

**Publisher signature:** OPTIONAL at v0.1, REQUIRED at v0.2. When present, the harness SHOULD verify the signature against the publisher's known public key.

---

## 8. Conformance

A conformance test suite ships in `conformance/test-suite.yaml`. The suite consists of test queries with known-correct doctrinal positions.

**Schema:**

```yaml
version: 2026.05
tradition_id: harmonism
tests:
  - id: harmonism-001
    query: "Is the body separate from the soul?"
    must_affirm:
      - "body-soul integration"
      - "qualified non-dualism"
    must_not_affirm:
      - "substance dualism"
      - "eliminative materialism"
    must_cite_from:
      - "Body and Soul.md"
      - "The Human Being.md"
    severity: critical    # critical | major | minor
  - id: harmonism-002
    query: "What is Logos?"
    must_affirm:
      - "inherent harmonic intelligence of the cosmos"
    must_cite_from:
      - "Logos.md"
      - "Harmonic Realism.md"
    severity: critical
```

**Fidelity report:** Running the suite returns a report:

```json
{
  "tradition_id": "harmonism",
  "tested_at": "2026-05-13T12:00:00Z",
  "model_id": "qwen2.5-72b-abliterated",
  "total_tests": 50,
  "passed": 47,
  "failed_critical": 0,
  "failed_major": 2,
  "failed_minor": 1,
  "fidelity_score": 0.94,
  "details": [...]
}
```

**Practitioner use:** A practitioner choosing between SDIP nodes (their own local instance, a tradition-operated server, a federated peer) runs the suite against each. A node with a high fidelity score against the tradition's own published suite is, with high confidence, running the tradition it claims.

**Limits:** The conformance suite cannot prove that a node is running the correct backbone; it can only prove that the node behaves consistently with one. Remote attestation (v0.2) closes the rest of the gap.

---

## 9. Operational Considerations

### 9.1 Versioning

The protocol uses semantic versioning at the major.minor level. v0.x are pre-stable; v1.0 commits to stability. Bundle versions use date-based versioning (`YYYY.MM`).

A node running protocol v0.1 SHOULD reject bundles declaring protocol v0.2 or later until the node is updated.

### 9.2 Bundle Distribution

Bundles SHOULD be published at a stable URL the publisher controls. RECOMMENDED additional distribution channels: Arweave for permanence, BitTorrent for resilient distribution, IPFS for content-addressed sharing. The protocol does not mandate any specific distribution channel.

### 9.3 Multi-Bundle Nodes

A node MAY hold multiple bundles for multiple traditions. In this configuration:

- Each tradition's backbone, corpus, calibrations, and conversation memory are isolated
- The practitioner selects which tradition's companion they are engaging at the start of each session
- Cross-tradition queries are NOT supported at v0.1 and are deferred to v0.2

### 9.4 Resource Footprint

A v0.1-conforming node is expected to run on:

- ≥32GB unified memory (Apple Silicon) OR ≥24GB VRAM (consumer GPU) for the model layer
- ≥50GB disk for a typical bundle (corpus + embeddings) plus practitioner state
- ≥1GB RAM for the harness process

Larger hardware enables larger models; smaller hardware is not specified.

---

## 10. Security Considerations

### 10.1 Threat Model

SDIP's threat model assumes:

- The practitioner's hardware is trusted
- The model weights are trusted to the extent that they pass the conformance suite
- The bundle publisher is trusted as the source of doctrinal substance
- The network is untrusted between the practitioner and the publisher
- All third parties (cloud operators, ISPs, intermediate proxies) are untrusted

### 10.2 Bundle Integrity

The integrity hashes in `manifest.json` defend against in-flight tampering during bundle distribution. The publisher signature (OPTIONAL v0.1, REQUIRED v0.2) defends against publisher impersonation.

### 10.3 Model Subversion

A malicious model could pass the conformance suite while subverting practitioner trust through subtler responses. The protocol's defense is the practitioner's own discernment combined with the conformance suite's calibration to known-correct positions. The protocol does not currently defend against a sophisticated adversary controlling the model weights.

### 10.4 Calibration Privacy

The calibration database carries sensitive per-practitioner state (doctrinal fluency level, bodily-register openness, conversation context, wheel-profile scoring, free-text profile notes). The harness MUST store this database with appropriate filesystem permissions (mode 0600 on Unix-like systems). The practitioner SHOULD enable full-disk encryption on the host. The harness MAY support encrypting the calibration database at rest with a passphrase; this is OPTIONAL at v0.1.

### 10.5 Conversation Privacy

By §4.5, no conversation content leaves the practitioner's substrate. The harness MUST NOT log conversations to remote destinations. The harness MAY maintain local logs; these MUST be subject to the same filesystem-permission and encryption recommendations as the calibration database.

---

## 11. Deferred to v0.2

Three areas are deferred to v0.2.

### 11.1 Federation

How SDIP nodes find and verify each other. How cross-tradition queries route when convergence is genuine. What the discovery layer is — Nostr-style relays, DNS-style registry, hybrid. How nodes advertise their bundles, capacities, and fidelity scores. The v0.2 federation surface adds capability without breaking v0.1 invariants; a node MAY operate purely locally indefinitely.

### 11.2 Contribution Settlement

The Lightning and Monero rails for contribution flow to corpus authors, translators, glossary maintainers, and node operators. Signed-contribution attribution at the corpus-file level. Micropayment routing protocols. Privacy-register matching: Lightning for high-frequency micropayments; Monero for contribution-privacy-warranted settlement (the maker who receives without disclosing what was paid for to a public ledger, the practitioner who supports without revealing which lineage's material they retrieve from).

### 11.3 Remote Attestation

How a node proves to a practitioner that it is running the bundle it claims to be running. Candidate primitives: TPM-based remote attestation, trusted execution environments (Intel SGX, AMD SEV-SNP, Apple Secure Enclave), zero-knowledge proofs of inference. None is deployed at production scale today; v0.2 commits to specifying the interface when the underlying primitive stabilizes.

---

## 12. Open Questions

Three questions the protocol form does not yet answer at v0.1.

### 12.1 Governance of the Backbone

Who decides what enters Harmonism's doctrinal backbone, or any tradition's? Centralized stewarding by the founding lineage preserves doctrinal coherence at the cost of structural single-point-of-failure. Federated stewarding distributes the failure surface at the cost of doctrinal drift. The protocol does not impose an answer; each tradition decides. The Harmonist answer for its own case is the architect during the founding phase, with succession architecture as the institution matures.

### 12.2 Verification of Fidelity Beyond Conformance

If a node claims to be running a tradition's inference but its responses subtly violate doctrine in ways the conformance suite does not catch, what is the practitioner's recourse? The cryptographic-attestation path (§11.3) closes part of the gap; broader fidelity evaluation — perhaps via federated peer review of canonical queries — closes another part. Both remain open at v0.1.

### 12.3 Economic Shape

The protocol works without tokens. The federated form has natural fee-market shape via §11.2 settlement. Whether the federated form *needs* a token — one that captures protocol value rather than gestures at it — is genuinely open. The strongest position is that the protocol should be useful first and token-shaped second, if at all. The token form is deferred indefinitely and earns articulation only after the federated form has proven useful.

---

## 13. References

### Normative

- [RFC 2119] Bradner, S., "Key words for use in RFCs to Indicate Requirement Levels," BCP 14, RFC 2119, March 1997.

### Informative

- HarmonAI Design Document — Harmonia internal, reference-implementation design rationale
- Running MunAI on Your Own Substrate — Harmonia public, practitioner-scale documentation
- The Sovereign Stack — Harmonia public, contextualizes SDIP within the broader sovereign-infrastructure landscape

### Related Work

- OpenAI Chat Completions API — the inference interface SDIP adopts
- Obsidian Markdown — the corpus authoring format SDIP recommends
- SQLite FTS5 — the keyword-retrieval substrate SDIP recommends
- Bitcoin / Lightning Network — the high-frequency settlement layer v0.2 will specify
- Monero — the privacy-preserving settlement layer v0.2 will specify
- Arweave — the recommended permanent-distribution channel for bundle archives

---

## 14. Acknowledgments

The protocol abstraction emerged from the operational architecture of HarmonAI, developed by Harmonia. The convergence with substrate-sovereignty traditions running from Diffie-Hellman through Zimmermann through May through Nakamoto is acknowledged in *The Sovereign Stack* and *Cypherpunks and Harmonism*. The framing of doctrinal substance as the architectural variable — what makes the protocol tradition-neutral rather than Harmonism-specific — emerged from a May 2026 working session and is documented in the Decision Log.

---

## 15. Change Log

- **2026-05 — v0.1 Draft.** Initial publication. Sufficient to ship a conforming implementation; federation, settlement, and attestation deferred to v0.2.

---

*See also: [[HarmonAI Design Document]], [[Running MunAI on Your Own Substrate]], [[The Sovereign Stack]], [[Cypherpunks and Harmonism]], [[MunAI]].*
