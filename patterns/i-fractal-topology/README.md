# Pattern I — Fractal Topology

*The 7+1 recursive heptagram. The first topology that scales without losing either comprehensibility or integration.*

Full treatment: [`METHODOLOGY.md` § I](../../METHODOLOGY.md). Reference implementation: [`components/topology/`](../../components/topology/).

## Problem It Solves

How to organize integral knowledge — where every domain connects to every other — without either flattening into a taxonomy that murders the connections, or leaving it as an undifferentiated tag cloud that overwhelms the navigator. Hierarchical trees impose false subordination. Library classification systems sever connections. Zettelkasten preserves connections but provides no architecture.

## The Solution in Brief

Seven co-equal domains organized around a unifying centre (the *+1*), with the entire structure repeating fractally at every level of magnification. The number seven sits at the intersection of three independent constraints: cognitive capacity (Miller's Law), cross-traditional convergence (seven recurs across cultures with no diffusion pathway), and structural sufficiency (fewer than seven collapses distinct domains; more exceeds cognitive grasp without adding structural necessity). The centre is not an eighth domain but the principle that animates all seven — *Presence* in Harmonism, *diagnostic awareness* in a traditional medicine system, *relational reciprocity* in an indigenous wisdom tradition.

## Validation Tests (apply to any proposed element)

- **Completeness.** Can you name something essential that falls outside the existing structure? If yes, the architecture is incomplete.
- **Non-redundancy.** Can you subsume one pillar under another without remainder? If the absorption is clean, the collapsed pillar was redundant.
- **Structural necessity.** Does each element account for genuine variance — does its absence create a specific form of impoverishment no other element compensates for?

These three tests are transferable to any integral classification system.

## Adoption Path

1. Identify the candidate domain (the field your tradition organizes).
2. Propose 7+1 — seven peripheral elements and a centre.
3. Apply the three validation tests to each proposed element.
4. If any element fails, revise. If all pass, the architecture is structurally sound.
5. For each peripheral pillar, repeat the exercise at the sub-wheel level (the pillar's own 7+1).
6. Continue fractally as long as the domain warrants resolution.

The pattern is doctrinal, not algorithmic — the work is editorial judgment guided by the three tests, not automated.

## Schema

See [`components/topology/schema/topology.schema.json`](../../components/topology/schema/topology.schema.json) for the JSON schema that encodes a fractal-heptagram definition. The schema is tradition-neutral; any tradition's wheel can be expressed as a conforming document. An example instantiation for Harmonism's Wheel is at [`components/topology/examples/harmonism-wheel.yaml`](../../components/topology/examples/harmonism-wheel.yaml).

## Status

Pattern-grade. The methodology is articulated; the schema is specified at v0.1; an example exists. A validator that checks a proposed topology against the three validation tests is deferred — the tests require editorial judgment and cannot be fully automated; the current schema validates structural conformance (seven peripherals + one centre, fractal recursion well-formed) rather than doctrinal soundness.
