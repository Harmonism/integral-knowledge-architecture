# Instruction — Pattern IX Reference

Persistent orientation document template. Implements Pattern IX of the [Integral Knowledge Architecture methodology](../../METHODOLOGY.md).

## What This Is

A starting scaffold for the document that serves as an AI agent's persistent memory across sessions. The document encodes the system's *operating conventions* — what the system is, where everything lives, what decisions have been made, what traps have been encountered, what the current priorities are. It is read at the start of every session; it is updated at the end of every session that discovers something new.

## The Critical Design Principle

When a trap is discovered, it is recorded in the orientation document with enough context that any future session can avoid it without rediscovering it. The document functions as institutional memory for an amnesiac operator — each session begins by reading it, each session ends by updating it. This crystallised operational knowledge is what survives session boundaries.

## Quick Reference

- **[`PERSISTENT_ORIENTATION_TEMPLATE.md`](./PERSISTENT_ORIENTATION_TEMPLATE.md)** — starting scaffold with section headings and prose stubs

## Adoption Path

1. Copy the template to the location your AI agent reads at session start. For Cowork and Claude Code, this is `CLAUDE.md` at the workspace root.
2. Fill in the sections with your tradition's specifics:
   - **Who You're Working With** — operator name, role, register
   - **The System** — what the knowledge system is, its top-level architecture
   - **The Vault** — folder structure, naming conventions, frontmatter conventions
   - **Pipelines and Operations** — how deploys work, how translation works, how the AI companion runs
   - **Traps and Lessons** — failures encountered and the discipline that survived
   - **Current Priorities** — what is being worked on now
3. Adopt the session-end update discipline: every session that discovers a new trap, makes a new decision, or revises a convention ends with an update to this document.
4. Pair with [Pattern VIII (QA Sensors)](../../patterns/viii-qa-sensors/) — a `claude-md-integrity-check` sensor verifies the orientation document still reflects vault state.

## The Length Discipline

The Harmonism `CLAUDE.md` runs ~25,000 words across many sections — at the upper boundary of what context windows comfortably hold, the result of dense concentrated growth across the Harmonia project's active build cycle. A new tradition's orientation document should start at ~2,000–4,000 words and grow as the system grows. Sections expand as decisions accumulate.

Anti-bloat discipline: a section unreferenced in three months is a candidate for archival. Move it to an archive document, leave a one-line pointer in the active orientation document. The active document is the *current state*; historical context lives elsewhere.

## Status

Pattern-grade. The architecture is articulated; the template provides a starting scaffold. The value is in the maintenance discipline, not in any particular formatting.

## License

Template and documentation: CC-BY-4.0.
