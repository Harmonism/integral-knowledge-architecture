# Pattern VII — Translation Pipeline Architecture

*Dual validation with glossary governance. The minimum architecture for terminological fidelity across languages in AI-augmented translation.*

Full treatment: [`METHODOLOGY.md` § VII](../../METHODOLOGY.md). Reference implementation: [`components/translation/`](../../components/translation/).

## Problem It Solves

A knowledge system that aspires to civilisational relevance must operate across languages. But translation of integral knowledge is categorically different from ordinary translation, because the system's *terminology is doctrine*. When Harmonism uses *Presence*, it does not mean generic mindfulness — it means the centre of the Wheel, the mode of conscious awareness from which all domains are engaged, the fractal principle that recurs at the centre of every sub-wheel. A translator who renders this as the French equivalent of mindfulness has not made a linguistic error — they have made a doctrinal one.

AI translation compounds the problem. Language models translate fluently but without doctrinal awareness. They silently replace technical terms with common synonyms, strip HTML elements they do not understand (iframes, interactive components), and use deprecated concept names long after the system has renamed them — because the model's training data contains the old name and the new name has not yet entered its weights.

## The Solution in Brief

The pipeline requires two independent validation mechanisms operating on different failure modes, plus a glossary governance layer.

- **Staleness detection.** Cryptographic hashing of source and translation. When the source hash changes, every translation linked to it is flagged as stale. Catches *drift* — translation was correct when produced but the source has evolved.
- **Terminology linting.** Validates that translations use sanctioned terms, correct cross-references, and no deprecated concept names. Catches *translation errors* — mistakes introduced at generation time, not through subsequent source changes.
- **Glossary governance.** Per-language glossaries map each system term to its sanctioned translation, with a deprecated-terms registry tracking renamed concepts. The glossary is the doctrinal authority — not the AI model's linguistic intuition, not the translator's preference.

The critical insight: **the two validation mechanisms detect non-overlapping failure modes.** A translation can pass staleness while failing terminology (used a deprecated term that was also deprecated in the source before the translation was made). A translation can pass terminology while failing staleness (all terms current but the source has been expanded). Running only one mechanism leaves an entire class of errors undetected.

## Provider-Specific Failure Recovery

Production deployment surfaces provider-specific failure modes that each require recovery scaffolding. Harmonia's production pipeline handles:

- **DeepL** — Romance-language fluency; per-block-hash smart-translate for incremental updates; self-healing for leading-article injection, body-class morphological residue, hallucinated display translations for proper nouns. Cost: free tier 500K chars/month, paid tier scales linearly. Best for FR/ES/PT/DE/ZH (where DeepL covers the language).
- **Groq Llama-class models** — handles languages DeepL doesn't (AR); validator catches cross-script hallucinations (Greek characters in Arabic prose). Cost: cents per full vault batch.
- **Claude Haiku via agent dispatch** — paper/canon register-sensitive cases where machine translation doesn't earn the voice. Five documented failure modes (hash fabrication, iframe stripping, concept-name drift, markdown-link-to-wikilink mis-conversion, target-language target invention) each with a recovery script.

Each recovery layer is canonical inside the pipeline itself, not external post-processing. The architectural commitment: when a new failure mode surfaces, extend the pipeline rather than spawning a one-shot fixer.

## Adoption Path

1. Define your tradition's glossary as a YAML file. See [`components/translation/schema/glossary.schema.json`](../../components/translation/schema/glossary.schema.json) for the schema, and Harmonism's [`components/sdip/bundles/harmonism/glossary.yaml`](../../components/sdip/bundles/harmonism/glossary.yaml) for a model.
2. Mark each term's adoption status (`native` / `tradition-specific` / `untranslatable`) and provide canonical translations for each target language.
3. Identify your provider mix per language — DeepL where supported, Groq for unsupported languages, Claude for register-sensitive batches.
4. Implement the dual-validation pipeline. The architecture is documented at [`components/translation/PIPELINE.md`](../../components/translation/PIPELINE.md); the Harmonia reference (~2,000 lines of Python at `i18n/scripts/translate-sync.py` in the Harmonia website repo) is open-source.
5. Run staleness + terminology checks on every translation pass. Flag, don't auto-correct — corrections require editorial judgment.

## Status

Spec-grade. The methodology is articulated. The schemas are specified at v0.1. The architecture document at `components/translation/PIPELINE.md` carries the full operational detail. The reference implementation lives in production at the Harmonia website repository (`i18n/scripts/translate-sync.py`) and the extraction-to-this-repository port is the next-quarter operational target. Until then, the spec is sufficient for a fork to implement the pattern in the language of their choice.
