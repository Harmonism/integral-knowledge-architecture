# Sensors — Pattern VIII Reference

Scheduled-sensor task descriptors. Implements Pattern VIII of the [Integral Knowledge Architecture methodology](../../METHODOLOGY.md).

## What This Is

A YAML descriptor schema for the kind of automated tasks that detect and report on a living knowledge system's surfaces — website health, AI-companion knowledge drift, translation staleness, classification gaps, broken cross-references, deploy-pipeline failures, scheduled-task failures. The descriptors document what each sensor monitors, at what cadence, and what kind of report it emits.

The Harmonism production deployment runs eight sensors against its vault and surrounding infrastructure. Examples below.

## The Sensor Discipline

Three architectural commitments:

- **Detect and report. Never modify.** A sensor that also repairs creates a system that degrades silently and heals silently — the operator never learns where the weak points are.
- **Cadence-appropriate to the failure rate.** Match each sensor's cadence to how often its monitored surface changes.
- **Report only deltas.** A sensor that reports "everything is fine" trains the operator to ignore it. A sensor that fires only when something has changed earns attention.

## Quick Reference

- **[`schema/sensor.schema.json`](./schema/sensor.schema.json)** — JSON Schema for a sensor task descriptor
- **[`examples/`](./examples/)** — descriptors for the eight sensors in Harmonism's production fleet

## The Eight Reference Sensors

| Sensor | Cadence | Surface | Failure Modes Caught |
|--------|---------|---------|----------------------|
| `website-health` | daily 7am | Web URLs | Silent deploy breakage, 404s, certificate failures |
| `sync-companion-profiles` | daily 8am | Companion DB ↔ vault | Profile drift between Telegram/web/mobile |
| `companion-beta-checkin` | daily 9am | Beta tester engagement | Practitioners silent >48h during beta phase |
| `companion-knowledge-drift` | twice-weekly | Vault ↔ RAG index | Index stale after vault edits |
| `weekly-vault-state-report` | weekly Sunday 8pm | Vault classification | Stable+partial articles, classification gaps |
| `todo-reconciliation` | weekly Friday 8pm | TODO ↔ Decision Log | Phantom completions, unblocked items, contradictions |
| `twitter-scouting` | daily 10am | X bookmarks + Following | Vault-relevant external content |
| `claude-md-integrity-check` | biweekly | Persistent orientation doc | Path drift, decision references, count mismatches |

Each is documented as a YAML descriptor under [`examples/`](./examples/). The descriptors are operational documentation; the implementations are tradition-specific (each tradition's failure-mode surface differs).

## Adoption Path

1. List the surfaces of your system that can degrade silently. For each, enumerate the failure modes.
2. Author one sensor descriptor per failure-mode class, conforming to [`schema/sensor.schema.json`](./schema/sensor.schema.json).
3. Set each sensor's cadence to match how often its surface changes.
4. Implement each sensor as a scheduled task (cron, Cowork scheduled tasks, Kubernetes CronJob, whatever your scheduler is) — the descriptor is the spec; the implementation is per-tradition.
5. Route reports to a single inbox the operator reviews. Tag reports `audience: developer` so the AI companion does not index them.
6. Build the institutional habit: a sensor firing is a request for editorial judgment, not a task to silence.

## Status

Pattern-grade at v0.1. The methodology is articulated, the descriptor schema is specified, eight reference sensors are documented as YAML. Implementation is per-tradition.

## License

Schema and examples: CC-BY-4.0.
