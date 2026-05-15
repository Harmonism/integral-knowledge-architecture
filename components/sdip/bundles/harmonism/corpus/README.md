# Harmonism Corpus

The Harmonism canonical corpus is distributed via the **Sovereignty Bundle** at:

  https://harmonism.io/sovereignty-bundle.zip

The bundle contains ~270 publishable Harmonist articles in English plus translations
in nine additional languages (fr, es, ar, pt, de, zh, ja, hi, ru). The corpus is
licensed CC-BY-4.0 by Harmonia.

## Populating the corpus

```bash
# From the harmonia-protocol repository root
bash scripts/populate-corpus-from-sovereignty-bundle.sh bundles/harmonism
```

The script downloads the current Sovereignty Bundle and copies the corpus content
into this directory. After population, regenerate the corpus_sha256 in manifest.json:

```bash
sdip build bundles/harmonism --output harmonism-2026.05.zip
```

## Why the corpus ships separately

The reference SDIP bundle in this repository ships the doctrinal backbone, the
glossary, the calibration schema, the conformance test suite, and the manifest —
the SDIP-specific layer. The corpus itself is large (~50MB after translation
to ten languages) and lives in the Sovereignty Bundle distribution, which has
its own existing publication infrastructure on harmonism.io.

For a tradition forking the protocol with their own canonical texts (a Sufi
*ṭarīqa*, a Theravāda *saṅgha*, etc.), the corpus would naturally live inside
the bundle directly — the separation here is a Harmonia-specific operational
convenience, not a protocol-level convention.
