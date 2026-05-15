# Pattern VI — Three-Tier Context Engineering

*The minimum viable context architecture for AI that must embody — not merely describe — a philosophical system. Closes the doctrinal fidelity gap that pure RAG cannot.*

Full treatment: [`METHODOLOGY.md` § VI](../../METHODOLOGY.md). Reference implementation: [`components/sdip/`](../../components/sdip/). Academic articulation: [Doctrinal Fidelity in Aligned AI](https://harmonism.io/the-living-papers/doctrinal-fidelity-in-aligned-ai--a-knowledge-architecture-response-to-the-problem-of-sovereign-transmission) (Living Paper).

## Problem It Solves

The most consequential problem in AI-mediated knowledge transmission is not retrieval accuracy — it is **doctrinal fidelity**. A language model trained on the internet's full entropy will, by default, hedge every philosophical claim, soften every sovereign stance, and present every tradition's positions as one perspective among many. This is the correct default for a general-purpose intelligence serving all users. It is catastrophic for a knowledge system that needs its AI companion to *embody* a specific philosophical architecture rather than survey it from outside.

Retrieval-Augmented Generation alone does not solve this. RAG retrieves relevant passages and injects them, but the model still processes those passages through its base posterior — which includes a trained disposition toward epistemic humility that translates, in practice, to doctrinal dilution. The retrieved chunks enter the context as data; the data is processed by the same model that has been trained to hedge contested claims. The filter is invisible because it is the medium itself.

## The Solution in Brief

Three tiers, each addressing a different category of failure.

- **Tier 1 — Doctrinal Backbone.** A permanent reference document (~6,000 words for Harmonism) injected into every interaction regardless of query. Establishes doctrinal ground *before* any retrieval occurs. Always in context. The model cannot soften what it sees as the fixed reference frame for the entire interaction.
- **Tier 2 — Hybrid Retrieval with Domain-Gated Canon Injection.** Three retrieval layers operating in parallel: dense semantic similarity over chunked content, sparse keyword retrieval (FTS5) with synonym expansion, and Wheel-domain detection that auto-injects canon-tier articles for the detected domain regardless of raw similarity score. The retrieval boundary is marked by explicit XML tags so the model knows what is tradition-speaking and what is the user.
- **Tier 3 — Structured Per-Practitioner Memory.** Three temporal layers: recent messages in context directly, periodic Claude-generated conversation summaries, and a tradition-structured profile (engagement per pillar on a developmental scale, concerns, strengths, growth edges, resistance flags). Raw messages archived permanently.

Plus five reinforcement layers between the assembled context and the delivered response — explicit anti-hedging instructions for stable positions, per-practitioner doctrinal-fluency conditioning, pre-classification witness-mode gate for acute contexts, anti-confabulation rule for personal claims, async response queue. See METHODOLOGY.md § V for full treatment of each.

## Why Three Tiers, Not One

Each tier solves a problem the others cannot. The backbone ensures doctrinal consistency regardless of retrieval quality — it is the floor that never drops. The retrieval system provides depth and specificity no fixed document can cover — the corpus contains hundreds of articles, the backbone can only summarise. The user memory enables developmental sensitivity — the same question from a newcomer and an adept warrants different responses, and only persistent profiling makes that distinction possible. A system relying on any single tier inherits its limitations. The three compose into something none can achieve independently: a doctrinally grounded, knowledge-rich, developmentally sensitive AI companion.

## Adoption Path

1. Author the doctrinal backbone — the ~3,000–10,000-word document that carries your tradition's complete architectural commitments stated as held. This is the highest-leverage editorial work of the entire architecture.
2. Index your corpus for hybrid retrieval. SQLite FTS5 + OpenAI-API-compatible embeddings is the production-tested default; alternatives are interchangeable.
3. Define the per-practitioner memory schema — what does your tradition track? See [`components/sdip/bundles/harmonism/calibrations.yaml`](../../components/sdip/bundles/harmonism/calibrations.yaml) for Harmonism's schema as a model.
4. Implement against the SDIP protocol (see [`components/sdip/SPEC.md`](../../components/sdip/SPEC.md)) or fork the reference harness.
5. Author a conformance test suite — canonical queries with known-correct doctrinal positions — and run it before every deploy.

## Status

Implementation-grade. The three-tier architecture is in production across Harmonism's web, Telegram, and mobile surfaces since early 2026. The academic articulation lives in the *Doctrinal Fidelity in Aligned AI* Living Paper. The reference implementation is at [`components/sdip/`](../../components/sdip/).
