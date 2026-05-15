# Pattern IX — Instruction Architecture

*The persistent orientation document. Institutional memory for an amnesiac operator.*

Full treatment: [`METHODOLOGY.md` § IX](../../METHODOLOGY.md). Reference template: [`components/instruction/`](../../components/instruction/).

## Problem It Solves

AI-mediated knowledge work is inherently amnesiac. Each session begins with a blank context. The operator must re-orient the AI to the system's conventions, terminology, architectural decisions, deployment procedures, known traps, and current priorities — or accept that the AI will operate without this context, making decisions that conflict with settled conventions and repeating mistakes that were solved in previous sessions.

The problem compounds with system complexity. A knowledge system with hundreds of files, five classification axes, multiple languages, an AI companion with three-tier context engineering, a translation pipeline with dual validation, and a fleet of scheduled sensor tasks cannot be re-explained from memory at the start of each session. The operator's memory is the bottleneck — and the operator's memory is lossy.

## The Solution in Brief

A single document — maintained as a living artifact, updated at the end of every session — serves as the AI's persistent memory across sessions. The document encodes not the system's *content* but its *operating conventions*: what the system is and how it is structured, where everything lives, what decisions have been made and why, what traps have been encountered, and what the current priorities are. It is structured by concern, not by chronology — recording the *current state of knowledge about how to operate the system* rather than the history of how that knowledge accumulated.

## The Critical Design Principle

When a trap is discovered — a silent failure in a deployment pipeline, a CSS specificity conflict, an SVG rendering behaviour that contradicts documentation — the trap is recorded in the orientation document with enough context that any future session can avoid it without rediscovering it. The document functions as institutional memory for an amnesiac operator: each session begins by reading it, and each session ends by updating it with whatever was learned.

The orientation document is the crystallised operational knowledge that survives session boundaries.

## Adoption Path

1. Author the orientation document at the location your AI agent reads at session start (for Cowork / Claude Code, this is `CLAUDE.md` at the workspace root).
2. Structure by concern: who-the-user-is, what-the-system-is, where-files-live, what-decisions-have-been-made, what-traps-have-been-encountered, what-the-priorities-are.
3. Use the template at [`components/instruction/PERSISTENT_ORIENTATION_TEMPLATE.md`](../../components/instruction/PERSISTENT_ORIENTATION_TEMPLATE.md) as a starting scaffold.
4. Adopt the session-end update discipline: every session that discovers a new trap, makes a new decision, or revises a convention ends with an update to the orientation document.
5. Pair with Pattern VIII (QA sensors) — run a sensor that verifies the orientation document accurately reflects vault state (the `claude-md-integrity-check` task in Harmonia's sensor fleet does this biweekly).

## The Document's Length Discipline

The Harmonism `CLAUDE.md` runs ~25,000 words across many sections. This is at the upper boundary of what context windows comfortably hold and is the result of dense concentrated growth across the Harmonia project's active build cycle. A new tradition's orientation document should start at ~2,000–4,000 words and grow as the system grows. The template at `components/instruction/` provides the structural skeleton; sections expand as decisions accumulate.

The discipline against bloat: a section that hasn't been referenced in three months is a candidate for archival. Move it to an archive document, leave a one-line pointer in the orientation document. The active orientation document is the *current state*; historical context lives elsewhere.

## Status

Pattern-grade. The architecture is articulated; the template provides a starting scaffold. The pattern is editorial, not algorithmic — its value is in the discipline of maintenance, not in any particular formatting.
