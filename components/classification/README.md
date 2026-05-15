# Classification — Pattern III Reference

Five-axis epistemic metadata framework. Implements Pattern III of the [Integral Knowledge Architecture methodology](../../METHODOLOGY.md).

## What This Is

A frontmatter convention plus a linter that classifies every article in a knowledge corpus along five orthogonal axes, producing a 243-cell classification space (3⁵) that any agent — human or AI — can use to know exactly how to engage with any article.

## The Five Axes

| Axis | Question | Values (low → high) |
|------|----------|---------------------|
| `doctrinal_status` | How clearly does the system see what this article claims? | `clouded` → `clear` → `luminous` |
| `content_layer` | What is this article's relationship to external sources? | `canon` / `bridge` / `applied` / `paper` |
| `breadth` | What proportion of intended territory has been claimed? | `partial` → `substantial` → `full` |
| `depth` | How thoroughly does the article penetrate territory it has claimed? | `introductory` → `developed` → `comprehensive` |
| `craft` | How well is the article made? | `muddy` → `clean` → `pure` |

Two axes route through their native chakras (Decision #517): `doctrinal_status` through Ajna (light vocabulary — the faculty of seeing applied to the claim), `craft` through Vishuddha (water vocabulary — the faculty of expression applied to the vessel). Light upstream, water at the channel — the two semantic registers stay distinct because the two faculties they measure are distinct.

## The Routing Rule

When external content enters the system, route to `bridge` or `applied`, **never** to `canon`. If no appropriate bridge article exists, seed one rather than contaminating the canonical layer. This single rule, rigorously applied, protects timeless architecture from the entropy of dated references.

## Frontmatter Convention

```yaml
---
doctrinal_status: clear
content_layer: bridge
breadth: substantial
depth: developed
craft: clean
---
```

Default for new articles: `clear` (or `clouded` for genuinely exploratory topics), `bridge` (or `applied` for protocol/analysis pieces), `partial`, `introductory`, `muddy`. Maturity moves along the four cultivation axes (status, breadth, depth, craft) through editorial work; `content_layer` is a register choice, not a trajectory.

## Highest-Leverage Queries

The classification space yields work queues:

- `doctrinal_status: clear` + `breadth: partial` — settled architecture with structural gaps. **Top priority for new writing.**
- `doctrinal_status: clear` + `breadth: substantial` — near-complete articles ready to be closed out.
- `doctrinal_status: clear` + `breadth: full` + `depth: introductory` — structurally complete, ripe for deeper treatment.
- `doctrinal_status: clear` + `breadth: full` + `craft: muddy` — channel-not-yet-cut. Clearest polishing target.
- `craft: pure` — the vault's reference set, the standard against which everything else is measured.

## Quick Reference

- **[`schema/classification.schema.json`](./schema/classification.schema.json)** — JSON Schema for the five-axis frontmatter
- **[`lint.py`](./lint.py)** — Python frontmatter linter that scans a vault directory and reports missing or invalid classification
- **[`examples/`](./examples/)** — sample frontmatter for each combination class

## Usage

```bash
# Lint a vault directory for missing or invalid classification
python lint.py /path/to/vault

# Report counts by classification combination
python lint.py /path/to/vault --census

# Find articles matching a query
python lint.py /path/to/vault --query "doctrinal_status=clear breadth=partial"
```

## Status

Schema-grade at v0.1. The five-axis classification is articulated in the methodology; the JSON schema is specified; a frontmatter linter stub exists at [`lint.py`](./lint.py). The Harmonism vault uses this classification on ~270 publishable articles in production; the discipline transfers cleanly to any Markdown-with-frontmatter knowledge base.

## License

Schema and documentation: CC-BY-4.0. Linter code: AGPL-3.0 (umbrella `../../LICENSE`).
