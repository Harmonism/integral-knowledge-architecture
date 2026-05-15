# Pattern VIII — QA Sensor Architecture

*Automated tasks that detect and report but never modify. Living knowledge systems accumulate entropy invisibly; automated repair masks the failure modes.*

Full treatment: [`METHODOLOGY.md` § VIII](../../METHODOLOGY.md). Reference implementation: [`components/sensors/`](../../components/sensors/).

## Problem It Solves

A living knowledge system — continuously edited, extended, translated, deployed — accumulates entropy invisibly. A wikilink breaks because a file was renamed. A translation becomes stale because the English source was updated. The AI companion's index falls behind the vault by thirty articles. A deploy script overwrites a server-side configuration. A scheduled task stops running. None of these failures announce themselves. They are silent degradation — the kind that accumulates until a reader encounters a broken link, a companion gives outdated guidance, or a page returns 404.

## The Solution in Brief

Deploy a fleet of automated tasks that function as **sensors**: they detect and report but never modify. This constraint is critical. A sensor that also repairs creates a system that degrades silently and heals silently — the operator never learns where the weak points are. A sensor that only reports forces the operator to understand each failure and decide on the repair, building institutional knowledge of the system's failure modes.

The sensor fleet covers the full surface area of the system:

- **Website health** — fetches representative URLs to catch silent deploy breakage
- **Companion knowledge drift** — detects when the AI's index has fallen behind the vault
- **Translation staleness** — runs the dual-validation pipeline across all languages
- **Vault state** — surfaces classification gaps, broken cross-references, and high-leverage writing targets
- **Task reconciliation** — catches contradictions between the task list and the decision log
- **Instruction integrity** — verifies that the persistent orientation document (Pattern IX) accurately reflects vault state

All sensor reports are tagged with developer-audience metadata, ensuring they are excluded from the AI companion's index — readers and practitioners never see system diagnostics — while remaining available for operator review.

## The Sensor Discipline

Three structural commitments make sensors useful rather than noisy:

- **No silent repair.** When a sensor detects a problem, the operator decides on the response. Automated repair tools degrade institutional knowledge of where the system is fragile.
- **Cadence-appropriate to the failure rate.** Website health → daily. Translation staleness → weekly. Vault state → weekly. Instruction integrity → biweekly. The cadence matches how often each surface actually changes.
- **Report only deltas.** A sensor that reports "everything is fine" trains the operator to ignore it. A sensor that only fires when something has changed earns attention when it fires.

## Adoption Path

1. Identify the surfaces of your system that can degrade silently. List the failure modes per surface.
2. Author one sensor task per failure-mode class. See [`components/sensors/schema/sensor.schema.json`](../../components/sensors/schema/sensor.schema.json) for the sensor descriptor format.
3. Schedule each sensor at the cadence that matches the surface's change rate.
4. Route reports to a single inbox the operator reviews. Tag reports `audience: developer` so the AI companion doesn't index them.
5. Build the institutional habit: a sensor firing is a request for editorial judgment, not a task to silence.

## Example Sensors

Eight sensors live in production for the Harmonism vault and are documented at [`components/sensors/examples/`](../../components/sensors/examples/) as YAML descriptors. They are transferable to any vault with frontmatter classification and translation infrastructure.

## Status

Pattern-grade. The architecture is articulated, the descriptor schema is specified at v0.1, and example sensors are documented as YAML. Adoption is per-sensor — each tradition's failure-mode surface is specific, so a fully generic sensor fleet is less useful than per-tradition sensors implemented against the descriptor schema.
