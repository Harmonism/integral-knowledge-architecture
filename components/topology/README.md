# Topology — Pattern I Reference

Fractal heptagram (7+1) topology schema and example. Implements Pattern I of the [Integral Knowledge Architecture methodology](../../METHODOLOGY.md).

## What This Is

A YAML schema for declaring a tradition's fractal-heptagram knowledge architecture: seven peripheral pillars and one centre, repeating recursively at every sub-wheel. The schema is tradition-neutral; the Harmonism Wheel is provided as a reference instantiation.

## The 7+1 Structure

- **Seven peripherals.** Co-equal domains that together cover the tradition's territory completely (completeness test), distinctly (non-redundancy test), and necessarily (structural-necessity test).
- **One centre.** Not an eighth domain but the *mode of engagement* that animates all seven. Deepening the centre enriches every peripheral.
- **Fractal recursion.** Each peripheral expands into its own 7+1 sub-wheel. Each sub-wheel spoke can expand further. The structure is simultaneously finite at any level (seven things to hold in mind) and infinitely elaborable.

## Quick Reference

- **[`schema/topology.schema.json`](./schema/topology.schema.json)** — JSON Schema for a fractal-heptagram definition
- **[`examples/harmonism-wheel.yaml`](./examples/harmonism-wheel.yaml)** — the Harmonism Wheel as a conforming topology document

## Adoption Path

1. Survey candidate centres for your tradition's wheel. Apply the deepening-test (Pattern II).
2. Propose seven peripherals. Apply the three validation tests from Pattern I (completeness, non-redundancy, structural necessity).
3. For each peripheral, recursively propose its own 7+1 sub-wheel. The fractal terminates where the domain no longer warrants further resolution.
4. Encode the result as YAML conforming to [`schema/topology.schema.json`](./schema/topology.schema.json).
5. The conforming topology document becomes the structural metadata for the tradition's knowledge corpus — it tells the AI companion which articles belong where, drives the navigation UI, and seeds the content priority architecture (Pattern IV).

## Status

Schema-grade at v0.1. The methodology and schema are articulated. A validator that checks structural conformance (seven peripherals + one centre per wheel, fractal recursion well-formed) is straightforward to implement against the JSON Schema directly — `jsonschema validate -i my-wheel.yaml schema/topology.schema.json` covers most of it.

A *doctrinal* validator that applies the three tests (completeness, non-redundancy, structural necessity) is deferred — the tests require editorial judgment and cannot be fully automated. The structural validator catches well-formedness; doctrinal soundness remains the tradition's editorial responsibility.

## License

Schema and example: CC-BY-4.0.
