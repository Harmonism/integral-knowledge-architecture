"""Provider interface.

A Provider takes a TranslationRequest (source text, target language, glossary
context) and returns a TranslationResult (translated text, provider metadata,
any per-provider recovery applied).

The interface is intentionally minimal. Provider-specific concerns (DeepL's
free-tier quota, Groq's UA header workaround, Claude's confabulation detection)
live inside each provider's implementation and surface to the caller only as
ProviderError when unrecoverable.
"""
from __future__ import annotations

from abc import ABC, abstractmethod
from dataclasses import dataclass, field
from typing import Any

from translate.glossary import Glossary


class ProviderError(Exception):
    """Raised on unrecoverable provider failure."""


@dataclass
class TranslationRequest:
    """A single translation request."""

    source_text: str
    source_language: str
    target_language: str
    glossary: Glossary | None = None
    # Optional: per-block hash list, enables smart-translate skipping
    block_hashes: list[str] = field(default_factory=list)
    # Optional: existing translation to update incrementally rather than redo from scratch
    existing_translation: str | None = None
    # Optional: hint to the provider about register (canon / bridge / applied / paper)
    register: str | None = None


@dataclass
class TranslationResult:
    """A completed translation with provider metadata."""

    translated_text: str
    provider_id: str
    target_language: str
    # Cost accounting in characters (for DeepL quota tracking) or tokens
    chars_billed: int = 0
    # Per-provider recovery layers that ran during this translation
    recoveries_applied: list[str] = field(default_factory=list)
    # Block-level results, if smart-translate ran:
    # list of (block_index, status) where status in {'translated', 'skipped_unchanged'}
    block_statuses: list[tuple[int, str]] = field(default_factory=list)
    # Provenance stamp for translation frontmatter
    provenance: dict[str, Any] = field(default_factory=dict)


class Provider(ABC):
    """Abstract base for translation providers.

    Subclasses implement `translate()` and `supported_languages()`. They MAY
    also implement provider-specific recovery layers as private methods, called
    inside `translate()` before returning the result.

    The protocol invariants any conforming provider preserves:

    1. **Glossary fidelity** — terms in the glossary's `untranslatable` set
       MUST be preserved verbatim. Terms with sanctioned translations MUST
       be rendered using the sanctioned translation.

    2. **Wikilink target preservation** — `[[Target|Display]]` constructs
       MUST preserve the target unchanged; only the display text may translate.
       Pure `[[Target]]` wikilinks have no display text and stay verbatim.

    3. **Frontmatter neutrality** — the provider MUST NOT touch frontmatter.
       The caller strips frontmatter before sending; the caller restamps it
       after the translation returns.

    4. **No telemetry** — providers MUST NOT log source text, glossary
       content, or any other content to remote endpoints beyond the
       provider's own API call.
    """

    provider_id: str = "abstract"

    @abstractmethod
    def translate(self, request: TranslationRequest) -> TranslationResult:
        """Translate the source text. MUST honour all invariants above."""

    @abstractmethod
    def supported_languages(self) -> list[str]:
        """List of target languages this provider supports."""

    def supports(self, target_language: str) -> bool:
        return target_language in self.supported_languages()
