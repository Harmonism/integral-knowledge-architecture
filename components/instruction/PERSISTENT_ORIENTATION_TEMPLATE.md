# [Tradition Name] — Operator Orientation

*Persistent orientation document. Read at the start of every AI-mediated session; updated at the end of every session that discovers something new. This is institutional memory for an amnesiac operator.*

*Pattern IX of the [Integral Knowledge Architecture](https://harmonism.io/integral-knowledge-architecture). Replace each section's placeholder content with your tradition's specifics. Sections expand as decisions accumulate.*

---

## Who You're Working With

[Operator name, role, working context. The AI agent's stance toward the operator — what register to take, what assumptions are warranted, what the operator's strengths and bottlenecks are.]

## The System

[What this knowledge system *is*. Top-level architecture in 2–4 paragraphs. The system's centre and peripherals. Cross-reference to the topology document if one exists.]

## The Vault

[Folder structure. Where canonical content lives, where internal/operational content lives, what gets indexed by the AI companion, what is excluded.]

### Folder Structure

```
[example tree of top-level folders]
```

### Frontmatter Conventions

[Per-classification axis: which values are used, what the defaults are. Cross-reference to Pattern III if classification metadata is in use.]

### Wikilink Conventions

[Display-text patterns, header anchoring, aliasing.]

## Pipelines and Operations

### Deploy

[How content reaches readers. Command(s) the operator runs. Where the operator has to be present vs. what runs automatically.]

### Translation

[Which languages are maintained. What pipeline handles translation. Where glossaries live. How sanctioned-translation governance works.]

### AI Companion

[What the companion is, where it runs, where its corpus and backbone live, what calibration columns it tracks per practitioner.]

### Sensors

[Which scheduled sensors run. Where their reports land. What the operator should do when a sensor fires.]

## Traps and Lessons

[Failure modes that have been encountered and the discipline that survived. Each entry: the symptom, the root cause, the fix. Future sessions read this section to avoid rediscovering traps.]

### Trap: [name]

**Symptom.** [What looked wrong.]

**Root cause.** [What was actually wrong.]

**Fix.** [The discipline that survived. Where in the codebase / vault it lives.]

### Trap: [name]

...

## Decision Log Pointers

[For non-trivial architectural decisions, the orientation document carries one-line pointers. The full Decision Log lives elsewhere.]

- **Decision #N — [title]**. [One-line summary.] See [Decision Log](path/to/decision-log.md#decision-n) for context.

## Current Priorities

[What is being worked on right now. The operator's current concentration. What is *not* being worked on right now and why.]

### Active Threads

1. [Thread name] — [one-line status]
2. ...

### Deferred / Backlog

[Items consciously deferred. Each with a one-line note on what would trigger picking it up.]

## Session-End Discipline

When a session discovers a new trap, makes a new decision, or revises a convention, the session ends by updating this document. The update is concrete:

- New trap → add to *Traps and Lessons*
- New decision → add to *Decision Log Pointers* with one-line summary
- Convention change → revise the affected section in *Pipelines and Operations* or *The Vault*
- New section warranted → add it, place it in the right register

The discipline is the document. Skip the discipline and the document becomes stale; the document stale and the next session has no memory of what was learned. This is not optional; it is the maintenance practice that makes the orientation document work.

---

*Template last updated: 2026-05. Source: [Integral Knowledge Architecture, Pattern IX](https://harmonism.io/integral-knowledge-architecture/patterns/ix-instruction-architecture).*
