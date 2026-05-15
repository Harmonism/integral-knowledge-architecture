# Pattern III — Epistemic Metadata Framework

*Five orthogonal axes classifying every article. The minimum metadata for a knowledge system to become self-aware about its own epistemic state.*

Full treatment: [`METHODOLOGY.md` § III](../../METHODOLOGY.md). Reference implementation: [`components/classification/`](../../components/classification/).

## Problem It Solves

A knowledge system that grows to hundreds of articles faces a crisis no table of contents can solve: not all articles have the same *epistemic standing*. Some articulate settled doctrine; some explore crystallising ideas; some are placeholders. Some engage external sources and need updating; some are timeless and should read identically in fifty years. An article can cover its full intended territory at orientation depth, or penetrate deeply into only a fragment. Without metadata that tracks these distinctions, AI companions treat provisional explorations with the same confidence as settled doctrine, translators waste effort on skeletons, and readers cannot distinguish what the system holds from what it is considering.

## The Solution in Brief

Every article is classified along five independent dimensions, producing a 3⁵ = 243-cell classification space.

| Axis | Question It Answers | Values |
|------|---------------------|--------|
| **doctrinal_status** | How clearly does the system see what this article claims? | `clouded` → `clear` → `luminous` |
| **content_layer** | What is this article's relationship to external sources? | `canon` / `bridge` / `applied` / `paper` |
| **breadth** | What proportion of intended territory has been claimed? | `partial` → `substantial` → `full` |
| **depth** | How thoroughly does the article penetrate the territory it has claimed? | `introductory` → `developed` → `comprehensive` |
| **craft** | How well is the article made? | `muddy` → `clean` → `pure` |

The five axes are genuinely orthogonal — each combination tells you something the others cannot. A `clear-canon-full-comprehensive-muddy` is doctrinally settled, intemporally voiced, structurally complete, deeply treated, but sentence-level slack. A `clouded-bridge-substantial-developed-clean` is still refining doctrine, engaging external sources, with some territory unclaimed, but cleanly made within what it currently is. The strategic response to each is entirely different.

## The Routing Rule

When external content enters the system, route it to `bridge` or `applied`, never to `canon`. If no appropriate bridge article exists, seed one rather than contaminating the canonical layer. This single rule, rigorously applied, protects timeless architecture from the entropy of dated references while still engaging fully with contemporary knowledge.

## Adoption Path

1. Add the five fields to your vault's article frontmatter (YAML or equivalent).
2. Classify existing articles. Start with `doctrinal_status` and `content_layer` — these are the highest-leverage axes for AI engagement.
3. Apply the routing rule to all incoming external content.
4. Run a frontmatter linter (Pattern III's component, [`components/classification/`](../../components/classification/)) to catch missing or invalid values.
5. Build queries against the classification space — *show all `clear + breadth: partial`* surfaces the highest-leverage writing targets.

## Schema

See [`components/classification/schema/classification.schema.json`](../../components/classification/schema/classification.schema.json) for the JSON schema. The schema is tradition-neutral; the values are fixed enumerations applicable to any knowledge corpus.

## Status

Schema-grade. The five-axis classification is articulated, the JSON schema is specified at v0.1, and a frontmatter linter stub exists. The Harmonism vault uses this classification on ~270 publishable articles; the discipline transfers cleanly to any Markdown-with-frontmatter knowledge base.
