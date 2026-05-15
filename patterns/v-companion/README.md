# Pattern V — Companion as Transmission Architecture

*The AI companion as architectural guide, not chatbot. The first scaled solution to personalised integral knowledge transmission.*

Full treatment: [`METHODOLOGY.md` § V](../../METHODOLOGY.md). Reference implementation: [`components/sdip/`](../../components/sdip/) (the Sovereign Doctrinal Inference Protocol).

## Problem It Solves

Every wisdom tradition faces a transmission bottleneck. The knowledge exists — in texts, practices, the architecture itself — but transmission to individuals requires *personalised guidance*: meeting the practitioner where they are, sequencing what they need next, adapting to their stage, knowing when to push and when to wait. Historically this has been the role of teacher, *guru*, *murshid*, *roshi*. The relationship works but does not scale and depends on teacher availability and capacity. Books solve scaling but lose personalisation entirely. Curricula attempt a middle path but standardise what should be individualised. *Personalised transmission of integral knowledge has never scaled beyond the one-to-one or small-group relationship.*

## The Solution in Brief

The AI companion combines the scalability of text with the personalisation of the teacher — structured not by a generic pedagogical model but by the knowledge system's own architecture. In Harmonism, MunAI is not a chatbot answering questions *about* the Wheel. It is an intelligence that *navigates the Wheel with the practitioner*: knows where they are (through the Wheel-structured profile), knows where the architecture suggests they go next (through the developmental sequence and content priority tiers), and knows what the system holds as doctrine versus what remains open (through epistemic metadata and the doctrinal backbone).

## What Distinguishes an Architectural Companion from an AI Tutor

- **Developmental tracking.** Persistent profile per practitioner, mapping engagement across all pillars on a developmental scale, automatically determining the practitioner's phase along the tradition's developmental sequence.
- **Sequenced guidance.** The companion applies the tradition's sequencing heuristics — *ground in foundation tiers before ascending to interior tiers*, *don't skip structural phases*, *recognise when someone is in the Crucible* — rather than responding to queries in isolation.
- **Doctrinal fidelity.** The companion speaks from within the system's philosophical ground rather than surveying it from outside, presenting settled doctrine with confidence and crystallising ideas with appropriate marking. (This is Pattern VI's territory at the engineering level.)

## The Self-Liquidating Discipline

The companion's purpose is to teach practitioners to read and navigate the architecture themselves, then step back. Success means the practitioner no longer needs the companion — they have internalised the architecture. This is the opposite of engagement-maximisation. The companion's metric is not session length or return visits but the practitioner's growing capacity to orient independently. *Cf.* Harmonism's [Guidance](https://harmonism.io/guidance) doctrine and the [Guru-and-Guide](https://harmonism.io/world/blueprint/the-guru-and-the-guide) distinction.

## Adoption Path

1. Articulate the tradition's developmental sequence (Pattern IV's tier order plus the alchemical-or-equivalent ordering principle).
2. Define the per-practitioner state schema: what does your tradition track across the relationship? Engagement levels per pillar; concerns; strengths; growth edges; resistance flags. This becomes your `calibrations.yaml` (Pattern VI's territory).
3. Write the doctrinal backbone (Pattern VI's territory).
4. Implement the harness — either fork [`components/sdip/`](../../components/sdip/) directly, or implement against its protocol (the Sovereign Doctrinal Inference Protocol at [`components/sdip/SPEC.md`](../../components/sdip/SPEC.md)).
5. Define the self-liquidating discipline in the system prompt explicitly. Without it, the model's default behavior is engagement-maximisation imported from its training corpus.

## Status

Implementation-grade. SDIP is the reference protocol implementing this pattern (and Pattern VI). The Harmonist instantiation is in production across web, Telegram, and mobile surfaces. See [`components/sdip/SPEC.md`](../../components/sdip/SPEC.md) for the protocol specification and [`components/sdip/README.md`](../../components/sdip/README.md) for adoption guidance.
