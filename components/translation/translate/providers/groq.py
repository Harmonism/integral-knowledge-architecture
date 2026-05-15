"""Groq provider — for languages DeepL does not cover (primarily Arabic).

v0.1 STATUS: Interface and recovery-layer scaffolding in place. Full
implementation is being ported from the Harmonia website repository.

Production failure modes and their canonical recoveries (from PIPELINE.md § 5.2):

    Cross-script hallucinations (Greek/Cyrillic chars in Arabic prose,
    empirically observed with Llama 3.3 70B)
        → ScriptPurityValidator (advisory; see translate.validators)

    Cloudflare 1010 block on urllib default User-Agent
        → Explicit `User-Agent: GROQ_USER_AGENT` header on every request

    Sanctioned terms in wikilink displays escaping \\b regex
        → `sanctioned` pre-pass before display strings go to the API
"""
from __future__ import annotations

from translate.providers.base import (
    Provider,
    ProviderError,
    TranslationRequest,
    TranslationResult,
)


GROQ_ENDPOINT = "https://api.groq.com/openai/v1/chat/completions"
GROQ_DEFAULT_MODEL = "llama-3.3-70b-versatile"
GROQ_USER_AGENT = "harmonia-translate/0.1.0"


class GroqProvider(Provider):
    """Groq translation provider (Llama 3.3 70B by default).

    Args:
        api_key: Groq API key.
        model: Groq model identifier. Defaults to llama-3.3-70b-versatile.
        timeout: HTTP timeout in seconds.
    """

    provider_id = "groq-llama"

    def __init__(
        self,
        api_key: str,
        *,
        model: str = GROQ_DEFAULT_MODEL,
        timeout: float = 120.0,
    ):
        if not api_key:
            raise ProviderError("Groq API key is required")
        self.api_key = api_key
        self.model = model
        self.timeout = timeout

    def supported_languages(self) -> list[str]:
        # Groq via Llama supports any language the model knows; production
        # validation has focused on Arabic where DeepL has no coverage.
        return ["ar"]

    def translate(self, request: TranslationRequest) -> TranslationResult:
        """Translate via Groq's OpenAI-compatible API.

        v0.1 STATUS: scaffold only. Production code being ported from
        translate-sync.py.
        """
        raise NotImplementedError(
            "GroqProvider.translate is v0.1 scaffold. Production code being "
            "ported from i18n/scripts/translate-sync.py. See PIPELINE.md § 5.2 "
            "for the recovery-layer architecture."
        )
