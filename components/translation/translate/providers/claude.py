"""Claude provider — register-sensitive paper/canon prose, any language.

v0.1 STATUS: Interface and recovery-layer scaffolding in place. The
production Harmonia deployment uses two Claude dispatch paths:

    Claude Haiku via Cowork agent dispatch — for individual articles,
    paper/canon register, languages where DeepL doesn't earn the voice.
    Cost-economics inversion under Claude Max: effectively zero marginal
    cost per article.

    Claude Sonnet via CLI (`claude -p --model sonnet`) — fallback for very
    large articles (>85K chars) where Haiku output cap forces confabulation.
    Requires CLAUDE_CODE_MAX_OUTPUT_TOKENS=64000 env var.

Production failure modes and their canonical recoveries (from PIPELINE.md § 5.3):

    Hash fabrication (Haiku invents source_hash values)
        → Post-translation file verification: frontmatter must contain
          source_hash matching computed value. fix-hashes repair script.

    External markdown link → wikilink mis-conversion ([text](url) → [[text]])
        → recover-{lang}-md-links scanner. Restores [display](URL) from
          EN markdown-link dictionary.

    Target-language wikilink target invention ([[cartésianisme]])
        → unwrap-{lang}-text-wikilinks scanner. Unwraps any wikilink whose
          target carries target-language diacritics.

    Missing grammatical articles before adopted terms (le Logos, le Dharma)
        → add-articles-{lang} script. Restores articles in flowing prose;
          preserves bare instances in titles, italics, term enumerations.

    Triple-pipe wikilink variant ([[X|YYY|URL]])
        → fix-md-links scanner. Language-agnostic; keys on URL.

    Output truncation on very large articles WITH confabulated success report
        → Mandatory file-level verification before trusting output:
          actual char count vs source × 1.10-1.20 expansion,
          wikilink count match,
          frontmatter starts with --- AND contains source_hash field.
        → Re-dispatch via Sonnet CLI on detection.
"""
from __future__ import annotations

from translate.providers.base import (
    Provider,
    ProviderError,
    TranslationRequest,
    TranslationResult,
)


class ClaudeProvider(Provider):
    """Claude translation provider.

    Two dispatch modes:
        - 'haiku' (default): direct API or Cowork agent dispatch
        - 'sonnet-cli': spawn subprocess `claude -p --model sonnet` for
          very large articles (>85K chars source)
    """

    provider_id = "claude"

    def __init__(
        self,
        api_key: str | None = None,
        *,
        mode: str = "haiku",
        timeout: float = 600.0,
    ):
        if mode == "haiku" and not api_key:
            raise ProviderError(
                "Claude Haiku mode requires an Anthropic API key. "
                "For 'sonnet-cli' mode, ensure `claude` CLI is installed and authenticated."
            )
        if mode not in ("haiku", "sonnet-cli"):
            raise ProviderError(
                f"Unknown Claude dispatch mode {mode!r}. Use 'haiku' or 'sonnet-cli'."
            )
        self.api_key = api_key
        self.mode = mode
        self.timeout = timeout

    def supported_languages(self) -> list[str]:
        # Claude supports any language the model knows; production uses it
        # for paper/canon register-sensitive batches.
        return ["fr", "es", "ar", "pt", "de", "zh", "ja", "hi", "ru"]

    def translate(self, request: TranslationRequest) -> TranslationResult:
        """Translate via Claude API (haiku) or CLI (sonnet-cli).

        v0.1 STATUS: scaffold only. Production code being ported from
        translate-sync.py and the recover-haiku-*.py recovery scripts.
        """
        raise NotImplementedError(
            "ClaudeProvider.translate is v0.1 scaffold. Production code "
            "being ported from i18n/scripts/translate-sync.py and the "
            "five recover-haiku-*.py recovery scripts. See PIPELINE.md § 5.3 "
            "for the failure-mode catalogue."
        )

    # ── Recovery interfaces (stubs documenting the ports) ────────────────────

    @staticmethod
    def _verify_output_not_confabulated(
        translated_path: str,
        source_path: str,
    ) -> tuple[bool, str]:
        """Verify a Haiku-produced translation is real, not confabulated.

        Returns (is_valid, reason). Mandatory check before trusting any
        Haiku output: confabulation pattern is a truncated summary with
        wrong frontmatter format and FALSE success metrics in the
        completion line.

        Production verification (translate-sync.py):
            - File starts with `---` (proper frontmatter open)
            - Frontmatter contains `source_hash` field
            - char(translation) is within source_chars * 1.10–1.20
              for the language family (Romance expansion factor)
            - wikilink count(translation) == wikilink count(source)
        """
        raise NotImplementedError("Port from translate-sync.py pending")

    @staticmethod
    def _recover_md_links(translated_body: str, source_body: str, language: str) -> str:
        """Recover external markdown links Haiku converted to wikilinks.

        Production reference: i18n/scripts/recover-haiku-fr-md-links.py.
        Parses source to extract markdown-link dictionary, scans translation
        for wikilinks whose target isn't a legitimate vault wikilink,
        restores as [target_lang_display](URL).
        """
        raise NotImplementedError("Port from recover-haiku-*.py pending")

    @staticmethod
    def _unwrap_text_wikilinks(translated_body: str, language: str) -> str:
        """Unwrap wikilinks whose targets carry target-language diacritics.

        Production reference: i18n/scripts/unwrap-fr-text-wikilinks.py.
        Detects target-language characters in [[Target]] targets; unwraps
        to plain text.
        """
        raise NotImplementedError("Port from unwrap-*-text-wikilinks.py pending")
