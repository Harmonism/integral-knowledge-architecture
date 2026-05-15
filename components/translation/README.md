# Translation — Pattern VII Reference

Dual-validation translation pipeline with glossary governance. Implements Pattern VII of the [Integral Knowledge Architecture methodology](../../METHODOLOGY.md).

## What This Is

The architecture for translating an integral knowledge corpus across languages while preserving terminological fidelity to doctrine. The production reference lives in the Harmonia website repository (`i18n/scripts/translate-sync.py`, ~2,000 lines of Python). This directory carries the schemas, the architecture document, and the v0.1 scaffold for the standalone-library port.

Production-validated against ten languages and ~270 articles for the Harmonist corpus, with three translation provider integrations (DeepL, Groq Llama 3.3 70B, Claude Haiku via agent dispatch) and five documented per-provider failure modes each with a recovery layer canonical inside the pipeline.

## Quick Reference

- **[`PIPELINE.md`](./PIPELINE.md)** — full architecture document; dual validation, glossary governance, provider-specific recovery layers
- **[`schema/glossary.schema.json`](./schema/glossary.schema.json)** — JSON Schema for the per-language glossary format
- **[`schema/translation-manifest.schema.json`](./schema/translation-manifest.schema.json)** — JSON Schema for the per-article translation metadata
- **[`examples/`](./examples/)** — sample glossaries and manifests

## The Two Validation Mechanisms

The pipeline runs both on every translation pass. The mechanisms detect **non-overlapping** failure modes; running only one leaves an entire class of errors undetected.

- **Staleness detection** compares source and translation using cryptographic hashing. When the source article changes, its hash changes, every translation linked to it is flagged stale. Catches *drift* — translation was correct when produced but the source has evolved.
- **Terminology linting** validates that translations use sanctioned terms, correct cross-references, and no deprecated concept names. Catches *translation errors* — mistakes introduced at generation time, not through subsequent source changes.

## Glossary Governance

Per-language glossaries map each system term to its sanctioned translation, with adoption status (`native` / `tradition-specific` / `untranslatable`), context-dependent variants, and a deprecated-terms registry tracking renamed concepts. The glossary is the doctrinal authority — not the AI model's linguistic intuition, not the translator's preference.

When a term is renamed in the system, the old name is immediately added to the deprecated registry, and the linter enforces the change across all languages.

## Provider-Specific Recovery Layers

Each translation provider has a fingerprint of failure modes. The architecture commits to extending the pipeline rather than spawning external post-processors:

- **DeepL** (FR/ES/PT/DE/ZH where covered) — `heal_deepl_body()` repairs leading-article injection, stray morphological residue, missing spaces after `]]` wikilink terminators, double-article elision conflicts. Smart-translate with per-block hashes for incremental updates.
- **Groq Llama** (AR, languages DeepL doesn't cover) — `validate_script_purity()` flags cross-script hallucinations (Greek `υπάρχ` injected into Arabic prose). Cloudflare-1010 fix via explicit User-Agent header.
- **Claude Haiku via agent dispatch** — five documented failure modes (hash fabrication, iframe stripping, concept-name drift, markdown-link-to-wikilink mis-conversion, target-language target invention), each with a recovery script. Confabulation detection on output truncation. File-level verification mandatory (char count vs source × 1.10–1.20 expansion, wikilink count match, frontmatter shape).

## Adoption Path

1. Author your glossary YAML following [`schema/glossary.schema.json`](./schema/glossary.schema.json).
2. Identify your provider mix per language — DeepL where supported, alternative where not, Claude/Anthropic for register-sensitive batches.
3. Implement the dual-validation pipeline. The architecture is fully specified at [`PIPELINE.md`](./PIPELINE.md); the Harmonia reference is ~2,000 lines of Python at `i18n/scripts/translate-sync.py` in the Harmonia website repo (AGPL-3.0).
4. Run staleness + terminology checks on every translation pass. Flag, don't auto-correct.

## Status

Spec-grade at v0.1. The methodology is articulated, the schemas are specified, the architecture document carries the full operational detail. The standalone Python library port is the next-quarter operational target; until then, the spec is sufficient for a fork to implement the pattern in their language of choice.

## License

The schemas and architecture document are CC-BY-4.0 (see [`../../LICENSE-METHODOLOGY`](../../LICENSE-METHODOLOGY)). Reference implementation code, when ported into this directory, will be AGPL-3.0 per the umbrella [`../../LICENSE`](../../LICENSE).
