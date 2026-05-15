"""DeepL provider — production-validated for FR, ES, PT, DE, ZH.

v0.1 STATUS: Interface and recovery-layer scaffolding in place. Full
implementation is being ported from the Harmonia website repository
(`i18n/scripts/translate-sync.py`, AGPL-3.0). The functions stubbed below
have working production code at that location; the port is a multi-day
mechanical extraction.

Production failure modes and their canonical recoveries (from PIPELINE.md § 5.1):

    Leading-article injection at wikilink display source
        → `_strip_leading_article(display, lang)` — language-keyed regex
          table for elided ("l'", "d'") and non-elided ("le/la/les") articles
        → Applied INSIDE the display translator before returns

    Stray morphological letters outside </keep> tags (e.g., ]]s, ]]e)
        → `heal_deepl_body(body, lang)` — fixed-point loop up to 3 iterations

    Missing spaces after ]] before next word
        → `heal_deepl_body(body, lang)`

    Outer-prose + inner-display double articles (L'[[X|La Y]] elision mismatch)
        → `heal_deepl_body(body, lang)` with language-keyed
          _DEEPL_INNER_DOUBLE_ARTICLES map

    Hallucinated displays for proper nouns (Sanskrit chakra names, etc.)
        → Glossary `untranslatable` adoption → <keep> protection upstream

All recovery layers MUST be canonical inside this provider, not external
post-processing. The architectural commitment: when a new DeepL failure mode
surfaces, extend `heal_deepl_body` here rather than spawning a new fixer.
"""
from __future__ import annotations

from translate.providers.base import (
    Provider,
    ProviderError,
    TranslationRequest,
    TranslationResult,
)


# Endpoint routing: free-tier keys carry a `:fx` suffix and use
# api-free.deepl.com; paid-tier keys have no suffix and use api.deepl.com.
DEEPL_FREE_ENDPOINT = "https://api-free.deepl.com/v2"
DEEPL_PAID_ENDPOINT = "https://api.deepl.com/v2"


# Language code mapping: DeepL uses uppercase ISO codes with some quirks
# (PT-PT vs PT-BR, EN-US vs EN-GB). The mapping below uses default variants;
# override per-deployment if needed.
DEEPL_LANG_CODES = {
    "fr": "FR",
    "es": "ES",
    "pt": "PT-PT",  # European Portuguese; switch to PT-BR for Brazilian
    "de": "DE",
    "zh": "ZH",
}


class DeepLProvider(Provider):
    """DeepL translation provider.

    Args:
        api_key: DeepL API key. Keys with `:fx` suffix route to free tier;
                 keys without suffix route to paid tier (Growth+).
        default_register: Default register hint (canon/bridge/applied/paper).
                          May be overridden per-request.
        timeout: HTTP timeout in seconds.
    """

    provider_id = "deepl"

    def __init__(
        self,
        api_key: str,
        *,
        default_register: str | None = None,
        timeout: float = 60.0,
    ):
        if not api_key:
            raise ProviderError("DeepL API key is required")
        self.api_key = api_key
        self.endpoint = (
            DEEPL_FREE_ENDPOINT if api_key.endswith(":fx") else DEEPL_PAID_ENDPOINT
        )
        self.default_register = default_register
        self.timeout = timeout

    def supported_languages(self) -> list[str]:
        return list(DEEPL_LANG_CODES.keys())

    def translate(self, request: TranslationRequest) -> TranslationResult:
        """Translate via DeepL with the full recovery-layer chain.

        v0.1 STATUS: scaffold only. The production implementation is
        ~800 lines of Python in translate-sync.py; the port is mechanical
        and scheduled for the next quarter.
        """
        if not self.supports(request.target_language):
            raise ProviderError(
                f"DeepL does not support target language {request.target_language!r}. "
                f"Supported: {', '.join(self.supported_languages())}"
            )

        raise NotImplementedError(
            "DeepLProvider.translate is v0.1 scaffold. Production code being "
            "ported from i18n/scripts/translate-sync.py. See PIPELINE.md § 5.1 "
            "for the recovery-layer architecture; see the module docstring for "
            "the failure-mode catalogue."
        )

    # ── Recovery layer interfaces (stubs documenting the ports) ──────────────

    @staticmethod
    def _strip_leading_article(display: str, lang: str) -> str:
        """Strip a translator-injected leading article from a wikilink display.

        Production reference: translate-sync.py:1842 — _strip_leading_article.
        Language-keyed regex table covers French elided (l'/d'), French
        non-elided (le/la/les/un/une/des/du), Spanish (el/la/los/las), and
        Portuguese (o/a/os/as) article forms.
        """
        raise NotImplementedError("Port from translate-sync.py pending")

    @staticmethod
    def _heal_body(body: str, lang: str) -> str:
        """Fixed-point body-healing pass.

        Production reference: translate-sync.py:1923 — heal_deepl_body.
        Fixed-point loop up to 3 iterations applying:
        - Stray morphological-letter pattern: \]\]([a-zà-ÿ]+) → ]]\1 with
          space if appropriate, dropped otherwise
        - Missing-space-after-]]: \]\](\w) → ]] \1
        - Outer-prose + inner-display double-article: L'\[\[X\|La Y\]\] →
          L'\[\[X\|Y\]\]
        """
        raise NotImplementedError("Port from translate-sync.py pending")
