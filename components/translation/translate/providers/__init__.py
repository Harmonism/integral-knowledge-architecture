"""Translation provider abstraction.

The pipeline supports multiple translation providers via a common interface.
The reference implementations (production-validated against the Harmonist
corpus) are:

    DeepL  — FR, ES, PT, DE, ZH. Romance-language fluency, free tier.
    Groq   — AR (and any language). For languages DeepL does not cover.
    Claude — Any language. For register-sensitive paper/canon prose.

Each provider has a per-language failure-mode fingerprint and a corresponding
recovery layer. See PIPELINE.md § 5 for the full failure-mode catalogue.

v0.1 status: provider interfaces defined, stubs in place documenting the
failure modes and recovery primitives. The full production-grade provider
implementations are being ported from the Harmonia website repository
(`i18n/scripts/translate-sync.py`, AGPL-3.0) module-by-module.
"""
from translate.providers.base import (
    Provider,
    ProviderError,
    TranslationRequest,
    TranslationResult,
)

__all__ = [
    "Provider",
    "ProviderError",
    "TranslationRequest",
    "TranslationResult",
]
