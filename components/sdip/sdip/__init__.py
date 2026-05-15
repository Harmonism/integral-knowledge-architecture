"""Sovereign Doctrinal Inference Protocol — reference implementation.

This package implements the Python reference for the SDIP v0.1 protocol.
The canonical specification lives at SPEC.md in the repository root.

Public surface:
    sdip.Bundle       — load and validate a bundle directory or zip
    sdip.Manifest     — manifest model and validation
    sdip.Conformance  — conformance test runner
    sdip.Harness      — chat-loop harness (skeleton at v0.1)
    sdip.Memory       — per-practitioner SQLite store
    sdip.Model        — OpenAI-compatible inference client
"""

from sdip.bundle import Bundle, BundleError
from sdip.calibration import Calibration, CalibrationColumn, CalibrationError
from sdip.conformance import Conformance, ConformanceResult
from sdip.harness import Harness
from sdip.manifest import Manifest, ManifestError
from sdip.memory import Memory
from sdip.model import Model, ModelError

__version__ = "0.1.0"
__protocol_version__ = "0.1"

__all__ = [
    "Bundle",
    "BundleError",
    "Calibration",
    "CalibrationColumn",
    "CalibrationError",
    "Conformance",
    "ConformanceResult",
    "Harness",
    "Manifest",
    "ManifestError",
    "Memory",
    "Model",
    "ModelError",
    "__version__",
    "__protocol_version__",
]
