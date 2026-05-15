"""The SDIP chat-loop harness.

Composes Bundle + Memory + Retrieval + Calibration + Model into a
protocol-conforming chat loop.

The harness enforces the protocol invariants from §4 of the SPEC:
- Sovereign substrate (constructor takes local paths; never opens a
  network connection except to the configured local model endpoint and
  the practitioner-initiated update fetch in update())
- Always-in-context backbone (build_system_prompt always includes it)
- Corpus-grounded retrieval (retrieval signature is logged with every turn)
- Refusal-direction stripped (validated by the model selection)
- No telemetry (no outbound calls beyond model and update)
- Practitioner-initiated update (update() must be called explicitly)
- Open source (this file is AGPL-3.0)
"""
from __future__ import annotations

from dataclasses import dataclass
from pathlib import Path
from typing import Iterator

from sdip.bundle import Bundle
from sdip.calibration import Calibration
from sdip.memory import Memory
from sdip.model import ChatMessage, Model
from sdip.retrieval import Retrieval, RetrievalRecord


# Cache breakpoint marker — placed between persona+backbone and dynamic context.
# Inference servers that support prompt caching can split here.
CACHE_BREAKPOINT_MARKER = "<!-- CACHE_BREAKPOINT -->"


@dataclass
class HarnessConfig:
    """Configuration for a Harness instance."""

    bundle_path: str | Path
    memory_path: str | Path
    index_path: str | Path
    model_endpoint: str
    model_name: str
    api_key: str | None = None
    embedder_endpoint: str | None = None
    embedder_model: str = "text-embedding-3-small"
    retrieval_top_k: int = 8
    retrieval_max_chars: int = 12000
    recent_messages_window: int = 20


class Harness:
    """The chat-loop harness.

    Usage:
        config = HarnessConfig(
            bundle_path="bundles/harmonism",
            memory_path="./practitioner.db",
            index_path="./vault-index.db",
            model_endpoint="http://localhost:11434/v1",
            model_name="qwen2.5:72b-instruct-abliterated",
        )
        harness = Harness(config)
        harness.initialize()

        for token in harness.chat("p-12345", "What is Logos?"):
            print(token, end="", flush=True)
    """

    def __init__(self, config: HarnessConfig):
        self.config = config
        self.bundle = Bundle.from_path(config.bundle_path)
        self.memory = Memory.open(config.memory_path)
        self.model = Model(
            endpoint=config.model_endpoint,
            model_name=config.model_name,
            api_key=config.api_key,
        )

        embedder = None
        if config.embedder_endpoint:
            from sdip.retrieval import OpenAIEmbedder

            embedder = OpenAIEmbedder(
                endpoint=config.embedder_endpoint,
                model_name=config.embedder_model,
                api_key=config.api_key,
            )

        self.retrieval = Retrieval(
            bundle=self.bundle,
            index_path=config.index_path,
            embedder=embedder,
        )
        self.calibration = Calibration(
            memory=self.memory,
            calibrations_doc=self.bundle.calibrations,
        )

    def initialize(self, force_reindex: bool = False) -> None:
        """One-time setup: verify bundle integrity, build indexes, ensure schema."""
        self.bundle.verify_integrity()
        self.memory.ensure_calibration_columns(self.bundle.calibrations)
        index_exists = Path(self.config.index_path).exists()
        if not index_exists or force_reindex:
            self.retrieval.build_indexes()

    # ── Chat loop ───────────────────────────────────────────────────────────

    def chat(self, practitioner_id: str, message: str) -> Iterator[str]:
        """Handle a chat turn and yield response tokens.

        Implements the protocol-required ordering:
        1. Ensure practitioner exists
        2. READ calibrations (level the practitioner walks in with)
        3. Record user message
        4. Run retrieval
        5. Build system prompt: backbone + breakpoint + calibration + retrieval
        6. Build message history with recent N messages
        7. Stream model response, recording it as it accumulates
        8. After response complete: ADVANCE calibrations
        """
        self.memory.ensure_practitioner(practitioner_id)

        # Build retrieval signature for this turn
        records = self.retrieval.retrieve(
            query=message,
            top_k=self.config.retrieval_top_k,
            max_chars=self.config.retrieval_max_chars,
        )

        # Record user message with retrieval signature
        retrieval_sig_for_log = [
            {
                "source_path": r.source_path,
                "chunk_index": r.chunk_index,
                "score": r.score,
                "retriever": r.retriever,
            }
            for r in records
        ]
        self.memory.record_message(
            practitioner_id=practitioner_id,
            role="user",
            content=message,
            retrieval_signature=retrieval_sig_for_log,
        )

        # Build system prompt
        system_prompt = self.build_system_prompt(
            practitioner_id=practitioner_id,
            retrieval_records=records,
        )

        # Recent message history
        recent = self.memory.recent_messages(
            practitioner_id=practitioner_id, n=self.config.recent_messages_window
        )
        history = [
            ChatMessage(role=r["role"], content=r["content"]) for r in recent
        ]

        messages = [ChatMessage(role="system", content=system_prompt), *history]

        # Stream the response
        accumulated: list[str] = []
        for token in self.model.chat_stream(messages):
            accumulated.append(token)
            yield token

        full_response = "".join(accumulated)

        # Record assistant response
        self.memory.record_message(
            practitioner_id=practitioner_id,
            role="assistant",
            content=full_response,
        )

        # ADVANCE calibrations (read-before-advance discipline: this runs AFTER
        # the level injected into the prompt was determined)
        self.calibration.advance_from_message(practitioner_id, message)

    # ── System prompt construction ──────────────────────────────────────────

    def build_system_prompt(
        self,
        practitioner_id: str,
        retrieval_records: list[RetrievalRecord],
    ) -> str:
        """Construct the system prompt per the protocol-required ordering.

        Order (per §6.5 of the SPEC):
        1. Backbone (always in context)
        2. Cache breakpoint
        3. Calibration injections
        4. Conversation context (if maintained)
        5. Retrieved corpus chunks under <vault_knowledge>
        6. (Conversation history is added at the message-list level, not here)
        """
        parts: list[str] = []

        # 1. Backbone
        parts.append(self.bundle.backbone_body)

        # 2. Cache breakpoint marker
        parts.append(CACHE_BREAKPOINT_MARKER)

        # 3. Calibration injections
        cal_blocks = self.calibration.build_injection_blocks(practitioner_id)
        if cal_blocks:
            parts.append(cal_blocks)

        # 4. Conversation context — placeholder; full implementation in v0.2
        # parts.append(self._build_conversation_context(practitioner_id))

        # 5. Retrieved corpus chunks
        if retrieval_records:
            chunks_text = self._format_retrieval_block(retrieval_records)
            parts.append(chunks_text)

        return "\n\n".join(parts)

    def _format_retrieval_block(self, records: list[RetrievalRecord]) -> str:
        """Wrap retrieved chunks in <vault_knowledge> with provenance."""
        lines: list[str] = ["<vault_knowledge>"]
        for r in records:
            lines.append(f"<chunk source=\"{r.source_path}\" classification=\"{r.classification}\">")
            lines.append(r.chunk_text)
            lines.append("</chunk>")
        lines.append("</vault_knowledge>")
        return "\n".join(lines)

    # ── Updates ─────────────────────────────────────────────────────────────

    def update(self, bundle_url: str | None = None) -> bool:
        """Practitioner-initiated bundle update.

        Fetches the current bundle from the publisher's distribution URL,
        compares hashes, and if different, replaces the local bundle and
        triggers reindex.

        Returns True if an update was applied, False if already current.
        """
        # Skeleton — production implementation fetches via httpx, validates
        # the new bundle's manifest signature, atomically swaps the bundle
        # directory, and triggers reindex. v0.1 of this reference defers the
        # secure-update mechanism to v0.2.
        raise NotImplementedError("update() will be implemented in v0.2")
