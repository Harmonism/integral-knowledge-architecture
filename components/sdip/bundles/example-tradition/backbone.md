---
tradition_id: example-tradition
tradition_name: Example Tradition
version: 2026.05
language: en
license: CC0-1.0
sha256: 0000000000000000000000000000000000000000000000000000000000000000
publisher: Example Publisher
---

# Example Tradition Doctrinal Backbone

> This file is a **skeleton**. Replace this entire content with your tradition's
> core doctrinal architecture, then run `sdip build` to compute and stamp the
> integrity hashes into manifest.json.

A tradition's doctrinal backbone establishes what the companion always sees,
regardless of what the practitioner asks. It carries:

1. **Foundational concepts.** The tradition's core ontological or metaphysical
   commitments — the substrate beneath every position the tradition holds.

2. **Architectural decomposition.** The structural map the tradition uses to
   organize cultivation. Harmonism uses the Wheel (Presence + 7 pillars).
   A Theravāda bundle might use the Eightfold Path. A Stoic bundle might use
   the three disciplines (assent / desire / action).

3. **Doctrinal positions.** Clear statements on the questions the tradition
   considers settled, including positions where the tradition diverges from
   mainstream consensus. The backbone is where the companion gets its courage
   to hold the tradition's position under prompt pressure.

4. **Terminology.** The technical vocabulary the tradition uses with precision.
   Cross-reference the glossary.yaml.

5. **Conversational discipline.** How the companion engages — register,
   sovereignty, what it refuses to do, how it handles disagreement.

## Length guidance

Typical backbones run 3,000 to 10,000 words. The Harmonism reference backbone
is ~6,000 words. Shorter risks underdetermining the model's behavior; longer
consumes context budget that could otherwise serve retrieval.

## After editing this file

```bash
sdip validate bundles/your-tradition       # check schema and structure
sdip build bundles/your-tradition          # compute hashes, build zip
```

The `sdip build` command will compute the actual SHA-256 of this file's body
(everything after the YAML frontmatter) and stamp it into `manifest.json`'s
`integrity.backbone_sha256` field. The placeholder zeros in the frontmatter
above will also be updated.

## Conformance

Edit `conformance/test-suite.yaml` to define canonical queries with known-
correct doctrinal positions. The test suite is how a practitioner verifies
that a node claiming to run your tradition is actually running your tradition.
A tradition with no conformance suite is a tradition that cannot be verified
to be itself.

---

*Replace this skeleton with your tradition's actual doctrinal architecture.*
