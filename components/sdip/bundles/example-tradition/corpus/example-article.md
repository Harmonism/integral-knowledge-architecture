---
title: Example Article
classification: canon
language: en
version: 2026.05
license: CC0-1.0
sha256: 0000000000000000000000000000000000000000000000000000000000000000
---

# Example Article

This is a placeholder article demonstrating corpus document shape.

Replace this directory with your tradition's canonical texts. Each article
is a Markdown file with YAML frontmatter declaring:

- `title` — human-readable title
- `classification` — one of `canon`, `bridge`, or `applied`
- `language` — BCP 47 language code
- `version` — typically matches the bundle version
- `license` — SPDX identifier
- `sha256` — hash of the body (computed by `sdip build`)

The folder structure under `corpus/` reflects your tradition's architectural
decomposition. Harmonism uses the Wheel structure
(`corpus/wheel-of-harmony/{pillar}/`, `corpus/philosophy/`, etc.).
A Buddhist tradition might use `corpus/sutta/{nikaya}/` and
`corpus/abhidhamma/`.

Articles MAY use Obsidian-style `[[wikilinks]]` for cross-reference. The
harness resolves these against the bundle's article index.
