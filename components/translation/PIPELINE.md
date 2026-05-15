# Translation Pipeline — Architecture Document

This document specifies the full architecture of the dual-validation translation pipeline that operationalizes Pattern VII of the Integral Knowledge Architecture methodology.

**Status:** Architecture-grade. The pipeline runs in production at Harmonia's website repository against ~270 articles across ten languages (EN/FR/ES/AR/PT/DE/ZH/JA/HI/RU).

---

## 1. The Problem Space

Translation of integral knowledge differs categorically from ordinary translation because *the system's terminology is doctrine*. A translation that renders a tradition's technical term with a colloquial equivalent has not made a linguistic error — it has corrupted doctrine. The integrity of the translated corpus depends on the pipeline catching such failures before they reach readers.

Three structural failure modes recur across providers:

- **Drift** — translation was correct when produced; source has since evolved
- **Translation errors** — mistakes introduced at generation time (wrong term, stripped element, deprecated concept name)
- **Provider artifacts** — provider-specific failure fingerprints (leading-article injection, script hallucinations, output truncation, target-language target invention)

The pipeline addresses each at the appropriate layer.

## 2. The Architecture

```
┌──────────────────────────────────────────────────────────────────┐
│                        Source Vault (EN)                          │
│              ~270 articles with YAML frontmatter                  │
└──────────────────────────────┬───────────────────────────────────┘
                               │
                               ▼
┌──────────────────────────────────────────────────────────────────┐
│                    Pre-translation Pipeline                       │
│  - Compute source SHA-256 hash                                    │
│  - Strip frontmatter (translate body only)                        │
│  - Protect untranslatable terms (<keep>...</keep>)                │
│  - Apply sanctioned-translation pre-pass to wikilink displays     │
│  - Apply pronunciation overrides (provider-specific)              │
└──────────────────────────────┬───────────────────────────────────┘
                               │
                  ┌────────────┼────────────┐
                  ▼            ▼            ▼
            ┌─────────┐  ┌──────────┐  ┌──────────────┐
            │  DeepL  │  │   Groq   │  │ Claude Haiku │
            │ FR/ES/  │  │  Llama   │  │  agent       │
            │ PT/DE/  │  │  3.3 70B │  │  dispatch    │
            │ ZH      │  │   (AR)   │  │  (any/all)   │
            └────┬────┘  └────┬─────┘  └──────┬───────┘
                 │            │               │
                 ▼            ▼               ▼
┌──────────────────────────────────────────────────────────────────┐
│                  Provider-Specific Recovery Layers                │
│  DeepL:  heal_deepl_body, _strip_leading_article,                 │
│          smart-translate per-block hashes                         │
│  Groq:   validate_script_purity, UA header fix                    │
│  Haiku:  recover-md-links, unwrap-text-wikilinks,                 │
│          add-articles, hash fabrication detection                 │
└──────────────────────────────┬───────────────────────────────────┘
                               │
                               ▼
┌──────────────────────────────────────────────────────────────────┐
│                       Post-translation Pipeline                   │
│  - Unprotect <keep> regions                                       │
│  - Apply sanctioned-translation enforcement (final pass)          │
│  - Stamp source_hash + para_hashes into frontmatter               │
│  - Write to i18n/translations/{lang}/{path}                       │
└──────────────────────────────┬───────────────────────────────────┘
                               │
                               ▼
┌──────────────────────────────────────────────────────────────────┐
│                   Dual Validation Layer                           │
│  1. Staleness:  compare source_hash to current source SHA-256     │
│  2. Terminology lint:  scan for deprecated terms, broken wikilinks│
│  3. Script purity:  flag cross-script characters (Groq AR)        │
└──────────────────────────────┬───────────────────────────────────┘
                               │
                               ▼
                       Report (flagged, not repaired)
```

## 3. The Glossary Layer

A YAML file per source language carries the doctrinal authority for translation. Schema at [`schema/glossary.schema.json`](./schema/glossary.schema.json).

Each entry has:

- `term` — the canonical source-language form
- `definition` — concise definition, ≤200 chars for tooltip rendering
- `adoption` — one of `native` / `tradition-specific` / `untranslatable`
- `translations` — map of language code to sanctioned translation
- `source_tradition` — for tradition-specific terms, the originating tradition
- `aliases` — alternative spellings or related terms that should resolve to this entry
- `deprecated` — boolean flag for retired terms preserved for backward compatibility

The glossary also carries a `deprecated` section listing renamed concepts — when a term is renamed in the source, the old name moves here, and the linter flags any translation still using the old name.

### Three Adoption Statuses

- **`native`** — the source tradition has adopted this term as its primary vocabulary (e.g., for Harmonism: Logos, Dharma, Presence, the Wheel). Translates per the `translations` map.
- **`tradition-specific`** — appears in cross-tradition contexts but does not lead in primary articulation (e.g., for Harmonism: Ṛta, Ayni, Munay). May translate or may stay verbatim depending on context.
- **`untranslatable`** — preserved verbatim across all languages (e.g., proper nouns of the institution, the system itself). Protected by `<keep>` wrappers before any translation provider sees the text.

## 4. Source-Hash and Block-Hash Discipline

Every translation file carries two integrity hashes in frontmatter:

```yaml
---
title: Article Title
source_hash: a1b2c3d4e5f6g7h8        # SHA-256 of source body
para_hashes:                          # SHA-256 per markdown block
  - "8a8c1f3b"
  - "4d2e7a91"
  - "...one per block..."
---
```

- **`source_hash`** enables staleness detection at the article level. When source body changes, hash changes, translation is flagged.
- **`para_hashes`** enables *smart-translate* incremental updates. The pipeline compares block-level hashes; only changed blocks go through the translation provider. Cost reduction: editing one paragraph in a 40-block article bills ~80 chars instead of ~4,000.

## 5. Provider-Specific Recovery Layers

### 5.1 DeepL

Production failure fingerprint and remediation:

| Failure Mode | Location | Recovery |
|--------------|----------|----------|
| Leading-article injection at wikilink display source | `translate_wikilink_displays_deepl()` | `_strip_leading_article(raw, lang)` — language-keyed regex table for elided (`l'`, `d'`) and non-elided (`le/la/les`) articles |
| Stray morphological letters outside `</keep>` (`]]s`, `]]e`) | post-translation body | `heal_deepl_body()` — fixed-point loop up to 3 iterations |
| Missing spaces after `]]` before next word | post-translation body | `heal_deepl_body()` |
| Outer-prose + inner-display double articles (`L'[[X\|La Y]]` elision mismatch) | post-translation body | `heal_deepl_body()` with language-keyed `_DEEPL_INNER_DOUBLE_ARTICLES` map |
| Hallucinated displays for proper nouns (chakra names, Sanskrit terms) | display translator | `untranslatable` adoption in glossary → `<keep>` protection upstream |

### 5.2 Groq Llama 3.3 70B

| Failure Mode | Location | Recovery |
|--------------|----------|----------|
| Cross-script hallucinations (Greek chars in Arabic) | post-translation body | `validate_script_purity()` — per-language regex of allowed Unicode ranges; advisory warnings to stderr |
| Cloudflare 1010 block on urllib default UA | network layer | explicit `User-Agent: GROQ_USER_AGENT` header |
| Sanctioned terms in wikilink displays escaping `\b` regex | display translator | `sanctioned` pre-pass in `translate_wikilink_displays_groq()` |

### 5.3 Claude Haiku via Agent Dispatch

| Failure Mode | Detection | Recovery |
|--------------|-----------|----------|
| Hash fabrication (Haiku invents source_hash values) | Post-translation: file frontmatter must start with `---` and contain `source_hash` matching computed value | `fix-hashes` repair script |
| External markdown link → wikilink mis-conversion (`[text](url)` becomes `[[text]]`) | Standalone scanner | `recover-haiku-{lang}-md-links.py` — restores `[FR_display](URL)` from EN markdown-link dict |
| French-text wikilink target invention (`[[cartésianisme]]`) | Scanner detects diacritic targets | `unwrap-fr-text-wikilinks.py` — unwraps any wikilink whose target carries a French diacritic |
| Missing grammatical articles before adopted terms (*le Logos*, *le Dharma*) | Scanner detects bare instances in flowing prose | `add-articles-fr.py [terms ...]` |
| Triple-pipe wikilink variant (`[[X\|YYY\|URL]]`) — Haiku invents third pipe | Standalone scanner | `fix-haiku-md-links.py` — language-agnostic, keys on URL |
| Output truncation on very large articles with confabulated success report | Mandatory file-level verification: actual char count vs source × 1.10–1.20 expansion, wikilink count match, frontmatter shape | Re-dispatch via Sonnet CLI with `CLAUDE_CODE_MAX_OUTPUT_TOKENS=64000` |

The recovery layers are **canonical inside the pipeline**, not external post-processing. When a new failure mode surfaces, extend `heal_*` / `validate_*` / `recover_*` in the pipeline itself rather than spawning a new one-shot fixer.

## 6. Cost Profile

Empirical costs from production Harmonia deployment, full-vault batches (~270 articles, ~1.2M source characters), May 2026:

| Provider | Language Coverage | Cost per Full Vault Batch | Best For |
|----------|-------------------|---------------------------|----------|
| DeepL Free | FR/ES/PT/DE/ZH | $0 (500K char/month quota) | Routine maintenance, smart-translate incremental |
| DeepL Pro | FR/ES/PT/DE/ZH/RU | ~$25 | Initial full-vault batches |
| Groq Llama 3.3 70B | AR (and any language) | ~$0.20–0.50 | Languages DeepL doesn't cover |
| Claude Haiku (Cowork dispatch) | Any | ~$0.50 (Max plan: $0) | Paper/canon register-sensitive batches |
| Claude Sonnet (CLI) | Any | ~$30–60 | Very large articles (>85K chars) requiring high register fidelity |

The asymmetry: DeepL is essentially free for ongoing maintenance under the free tier; Claude is essentially free for tradition operators on a Max plan; Groq fills the language gaps DeepL doesn't cover; Sonnet is the high-cost high-fidelity fallback for the largest pieces.

## 7. Adoption Recipe (Minimum Viable Implementation)

1. Author `glossary.yaml` per [`schema/glossary.schema.json`](./schema/glossary.schema.json).
2. Implement the staleness detector — SHA-256 of source body, comparison against `source_hash` in translation frontmatter.
3. Implement the terminology linter — scan for `deprecated` terms, scan for sanctioned-translation conformance, scan for wikilink target validity.
4. Implement at least one provider integration. DeepL is the lowest-friction starting point (HTTP API, free tier).
5. Run both validators after every translation pass. Flag, don't auto-correct.

A v0.1-conforming implementation can be ~500 lines of Python. The Harmonia production reference is ~2,000 lines and adds the smart-translate per-block diffing, the provider-specific recovery layers, the multi-provider routing, and 30 propagation-site audit lists for adding new languages.

## 8. The Standalone Library Port

The next-quarter operational target for this component directory: port `translate-sync.py` from the Harmonia website repository into a standalone Python library (`pip install harmonia-translate`) with provider plugins, schema-driven configuration, and the full recovery layer set. Until that port lands, the spec above is sufficient for a fork to implement the pattern in the language of their choice.

## 9. References

- Harmonia website repository: `harmonia-astro/i18n/scripts/translate-sync.py` (production reference, AGPL-3.0)
- DeepL API documentation: https://developers.deepl.com/docs
- Groq API documentation: https://console.groq.com/docs
- The Harmonism `glossary.yaml` instance: [`../sdip/bundles/harmonism/glossary.yaml`](../sdip/bundles/harmonism/glossary.yaml)
