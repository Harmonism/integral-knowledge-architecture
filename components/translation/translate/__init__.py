"""harmonia-translate — dual-validation translation pipeline with glossary governance.

Reference implementation of Pattern VII of the Integral Knowledge Architecture
methodology. Pairs a per-language glossary (the doctrinal authority for
terminology) with two independent validators (staleness detection and
terminology linting) that detect non-overlapping failure modes.

Public surface:
    translate.Glossary           — load and query per-tradition glossaries
    translate.TranslationFile    — parse translation frontmatter and body
    translate.Hasher             — body hash + block-split + per-block hash
    translate.StalenessValidator — source-vs-translation hash comparison
    translate.TerminologyLinter  — sanctioned-term and deprecated-term scan
    translate.ScriptPurityValidator — per-language Unicode-script validator
    translate.providers          — Provider abstraction + DeepL/Groq/Claude stubs
"""
from translate.glossary import Glossary, GlossaryEntry, GlossaryError
from translate.hasher import Hasher, hash_body, hash_block, split_blocks
from translate.manifest import TranslationFile, ManifestError
from translate.validators import (
    LintFinding,
    ScriptPurityValidator,
    StalenessResult,
    StalenessValidator,
    TerminologyLinter,
)

__version__ = "0.1.0"

__all__ = [
    "Glossary",
    "GlossaryEntry",
    "GlossaryError",
    "Hasher",
    "hash_body",
    "hash_block",
    "split_blocks",
    "TranslationFile",
    "ManifestError",
    "StalenessValidator",
    "StalenessResult",
    "TerminologyLinter",
    "LintFinding",
    "ScriptPurityValidator",
    "__version__",
]
