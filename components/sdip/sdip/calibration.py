"""Calibration column read/write/advance/inject.

Each tradition defines its own calibration columns in calibrations.yaml.
This module wires the declarations to the memory store and to the
system-prompt injection block.

The protocol invariant: read-before-advance. The level injected into the
current turn is the level the practitioner walked in with — advancement
detection runs AFTER the response is generated and surfaces on the next turn.
"""
from __future__ import annotations

from dataclasses import dataclass
from typing import Any, Callable, Protocol

from sdip.memory import Memory


class CalibrationError(Exception):
    """Raised on calibration schema or operation errors."""


@dataclass
class CalibrationColumn:
    """A single calibration column declaration."""

    name: str
    type: str
    description: str
    range: tuple[float, float] | None = None
    default: Any = None
    monotonic: bool = False
    advancement_detector: str | None = None
    inject_as: str | None = None
    inject_block_builder: str | None = None
    schema_ref: str | None = None

    @classmethod
    def from_dict(cls, d: dict[str, Any]) -> CalibrationColumn:
        rng = d.get("range")
        return cls(
            name=d["name"],
            type=d["type"],
            description=d["description"],
            range=(rng[0], rng[1]) if rng else None,
            default=d.get("default"),
            monotonic=d.get("monotonic", False),
            advancement_detector=d.get("advancement_detector"),
            inject_as=d.get("inject_as"),
            inject_block_builder=d.get("inject_block_builder"),
            schema_ref=d.get("schema_ref"),
        )


class AdvancementDetector(Protocol):
    """Interface for advancement detectors.

    A detector is called after the practitioner's message is received and
    returns the highest level the message warrants for this column.
    The harness then applies the monotonic-MAX (or non-monotonic set) via
    the Memory layer.
    """

    def __call__(self, message: str, current_level: Any) -> Any: ...


class Calibration:
    """Calibration column manager bound to a Memory instance and a calibrations doc."""

    def __init__(
        self,
        memory: Memory,
        calibrations_doc: dict,
        detectors: dict[str, AdvancementDetector] | None = None,
        block_builders: dict[str, Callable[[Any], str]] | None = None,
    ):
        self.memory = memory
        self.columns = [CalibrationColumn.from_dict(c) for c in calibrations_doc.get("columns", [])]
        self.detectors = detectors or {}
        self.block_builders = block_builders or {}
        self._by_name = {c.name: c for c in self.columns}

    # ── Read ────────────────────────────────────────────────────────────────

    def read_all(self, practitioner_id: str) -> dict[str, Any]:
        """Read every calibration column's current value."""
        return {c.name: self.memory.get_calibration(practitioner_id, c.name) for c in self.columns}

    def read(self, practitioner_id: str, column_name: str) -> Any:
        return self.memory.get_calibration(practitioner_id, column_name)

    # ── Advance ─────────────────────────────────────────────────────────────

    def advance_from_message(self, practitioner_id: str, message: str) -> dict[str, Any]:
        """Run every column's advancement detector against a message.

        Returns the new values written. Called AFTER response generation per
        the read-before-advance invariant.
        """
        updates: dict[str, Any] = {}
        for col in self.columns:
            if not col.advancement_detector:
                continue
            detector = self.detectors.get(col.advancement_detector)
            if detector is None:
                continue
            current = self.read(practitioner_id, col.name)
            new_value = detector(message=message, current_level=current)
            if new_value is None:
                continue
            self.memory.set_calibration(
                practitioner_id, col.name, new_value, monotonic=col.monotonic
            )
            updates[col.name] = new_value
        return updates

    # ── Inject ──────────────────────────────────────────────────────────────

    def build_injection_blocks(self, practitioner_id: str) -> str:
        """Construct the system-prompt injection blocks for this practitioner.

        Returns a string of XML-style blocks, one per column with an inject_as
        tag defined. Empty string if no columns inject.
        """
        parts: list[str] = []
        current = self.read_all(practitioner_id)
        for col in self.columns:
            if not col.inject_as:
                continue
            value = current.get(col.name)
            if value is None:
                continue
            builder = self.block_builders.get(col.inject_block_builder or "")
            if builder is not None:
                block_text = builder(value)
            else:
                block_text = _default_block_builder(col, value)
            parts.append(f"<{col.inject_as}>\n{block_text}\n</{col.inject_as}>")
        return "\n\n".join(parts)


def _default_block_builder(col: CalibrationColumn, value: Any) -> str:
    """Default injection text for a calibration column.

    Tradition bundles SHOULD override this with custom block_builders for
    columns where the level needs to translate to specific instructions
    (e.g., HarmonAI's doctrinal_fluency_level → "Plain English with lived-
    experience framing" at level 0, "Full vocabulary as shared language" at
    level 3).
    """
    return f"value: {value}\ndescription: {col.description}"
