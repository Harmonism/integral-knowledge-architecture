"""Conformance test suite runner.

A bundle ships a conformance/test-suite.yaml of canonical queries with
known-correct doctrinal positions. Running the suite against a bundle and
a model returns a fidelity report — the score a practitioner uses to
verify that a node is actually running the tradition it claims.
"""
from __future__ import annotations

import json
import time
from dataclasses import dataclass, field
from pathlib import Path
from typing import Any

import yaml

from sdip.bundle import Bundle
from sdip.harness import Harness, HarnessConfig
from sdip.model import ChatMessage, Model


class ConformanceError(Exception):
    """Raised on conformance suite or runner errors."""


@dataclass
class TestCase:
    id: str
    query: str
    must_affirm: list[str] = field(default_factory=list)
    must_not_affirm: list[str] = field(default_factory=list)
    must_cite_from: list[str] = field(default_factory=list)
    must_use_terminology: list[str] = field(default_factory=list)
    must_not_use_terminology: list[str] = field(default_factory=list)
    severity: str = "major"
    category: str | None = None

    @classmethod
    def from_dict(cls, d: dict[str, Any]) -> TestCase:
        return cls(
            id=d["id"],
            query=d["query"],
            must_affirm=d.get("must_affirm", []),
            must_not_affirm=d.get("must_not_affirm", []),
            must_cite_from=d.get("must_cite_from", []),
            must_use_terminology=d.get("must_use_terminology", []),
            must_not_use_terminology=d.get("must_not_use_terminology", []),
            severity=d.get("severity", "major"),
            category=d.get("category"),
        )


@dataclass
class TestResult:
    test_id: str
    passed: bool
    severity: str
    response: str
    citations: list[str]
    affirmation_misses: list[str] = field(default_factory=list)
    affirmation_violations: list[str] = field(default_factory=list)
    citation_misses: list[str] = field(default_factory=list)
    terminology_misses: list[str] = field(default_factory=list)
    terminology_violations: list[str] = field(default_factory=list)
    elapsed_ms: int = 0


@dataclass
class ConformanceResult:
    tradition_id: str
    tested_at: str
    model_id: str
    total_tests: int
    passed: int
    failed_critical: int
    failed_major: int
    failed_minor: int
    fidelity_score: float
    results: list[TestResult] = field(default_factory=list)

    def to_dict(self) -> dict[str, Any]:
        return {
            "tradition_id": self.tradition_id,
            "tested_at": self.tested_at,
            "model_id": self.model_id,
            "total_tests": self.total_tests,
            "passed": self.passed,
            "failed_critical": self.failed_critical,
            "failed_major": self.failed_major,
            "failed_minor": self.failed_minor,
            "fidelity_score": self.fidelity_score,
            "details": [
                {
                    "test_id": r.test_id,
                    "passed": r.passed,
                    "severity": r.severity,
                    "elapsed_ms": r.elapsed_ms,
                    "affirmation_misses": r.affirmation_misses,
                    "affirmation_violations": r.affirmation_violations,
                    "citation_misses": r.citation_misses,
                    "terminology_misses": r.terminology_misses,
                    "terminology_violations": r.terminology_violations,
                }
                for r in self.results
            ],
        }


class Conformance:
    """Conformance test runner.

    Two modes:
    - Bundle-only: load the test suite and report what it asserts (no model)
    - Bundle + harness: actually run the tests through a harness against a model

    Usage:
        # Just inspect the suite
        conf = Conformance.from_bundle(bundle)
        print(f"{len(conf.tests)} tests defined")

        # Run against a harness
        result = conf.run(harness, practitioner_id="conformance-test")
        print(f"Fidelity: {result.fidelity_score:.2f}")
    """

    def __init__(self, bundle: Bundle, tests: list[TestCase], judge_model: str | None = None):
        self.bundle = bundle
        self.tests = tests
        self.judge_model = judge_model

    @classmethod
    def from_bundle(cls, bundle: Bundle) -> Conformance:
        """Load the conformance suite from a bundle."""
        suite_path = bundle.conformance_suite_path
        if suite_path is None:
            raise ConformanceError("bundle has no conformance/test-suite.yaml")
        return cls.from_path(suite_path, bundle=bundle)

    @classmethod
    def from_path(cls, path: str | Path, bundle: Bundle | None = None) -> Conformance:
        """Load a conformance suite from a YAML file."""
        p = Path(path)
        doc = yaml.safe_load(p.read_text(encoding="utf-8"))
        if not isinstance(doc, dict):
            raise ConformanceError(f"conformance suite at {p} is not a YAML mapping")
        if doc.get("tradition_id") and bundle and doc["tradition_id"] != bundle.manifest.tradition_id:
            raise ConformanceError(
                f"conformance suite tradition_id ({doc['tradition_id']}) does not match "
                f"bundle tradition_id ({bundle.manifest.tradition_id})"
            )
        tests = [TestCase.from_dict(t) for t in doc.get("tests", [])]
        if bundle is None:
            # Allow standalone loading; some operations only need the test list
            raise ConformanceError("conformance suite loaded without bundle context")
        return cls(bundle=bundle, tests=tests, judge_model=doc.get("judge_model"))

    # ── Running ─────────────────────────────────────────────────────────────

    def run(self, harness: Harness, practitioner_id: str = "conformance-test") -> ConformanceResult:
        """Run every test through the harness and produce a fidelity report."""
        from datetime import datetime, timezone

        results: list[TestResult] = []
        for test in self.tests:
            result = self._run_test(harness, test, practitioner_id)
            results.append(result)

        passed = sum(1 for r in results if r.passed)
        failed_critical = sum(1 for r in results if not r.passed and r.severity == "critical")
        failed_major = sum(1 for r in results if not r.passed and r.severity == "major")
        failed_minor = sum(1 for r in results if not r.passed and r.severity == "minor")
        total = len(results)
        fidelity = passed / total if total > 0 else 0.0

        return ConformanceResult(
            tradition_id=harness.bundle.manifest.tradition_id,
            tested_at=datetime.now(timezone.utc).isoformat(),
            model_id=harness.model.model_name,
            total_tests=total,
            passed=passed,
            failed_critical=failed_critical,
            failed_major=failed_major,
            failed_minor=failed_minor,
            fidelity_score=fidelity,
            results=results,
        )

    def _run_test(self, harness: Harness, test: TestCase, practitioner_id: str) -> TestResult:
        start = time.monotonic()

        # Build a clean system prompt (no calibrations) and send the query
        records = harness.retrieval.retrieve(
            query=test.query,
            top_k=harness.config.retrieval_top_k,
            max_chars=harness.config.retrieval_max_chars,
        )
        citations = [r.source_path for r in records]

        system_prompt = harness.build_system_prompt(
            practitioner_id=practitioner_id,
            retrieval_records=records,
        )
        messages = [
            ChatMessage(role="system", content=system_prompt),
            ChatMessage(role="user", content=test.query),
        ]
        response = harness.model.chat(messages, max_tokens=1024).choices[0].message.content

        elapsed_ms = int((time.monotonic() - start) * 1000)

        # Check assertions
        lower_response = response.lower()

        affirmation_misses = [
            a for a in test.must_affirm if a.lower() not in lower_response
        ]
        affirmation_violations = [
            a for a in test.must_not_affirm if a.lower() in lower_response
        ]
        citation_misses: list[str] = []
        if test.must_cite_from:
            cited = [c for c in citations]
            if not any(any(must in c for c in cited) for must in test.must_cite_from):
                citation_misses = list(test.must_cite_from)
        terminology_misses = [
            t for t in test.must_use_terminology if t.lower() not in lower_response
        ]
        terminology_violations = [
            t for t in test.must_not_use_terminology if t.lower() in lower_response
        ]

        passed = (
            not affirmation_misses
            and not affirmation_violations
            and not citation_misses
            and not terminology_misses
            and not terminology_violations
        )

        return TestResult(
            test_id=test.id,
            passed=passed,
            severity=test.severity,
            response=response,
            citations=citations,
            affirmation_misses=affirmation_misses,
            affirmation_violations=affirmation_violations,
            citation_misses=citation_misses,
            terminology_misses=terminology_misses,
            terminology_violations=terminology_violations,
            elapsed_ms=elapsed_ms,
        )

    # ── Reporting ───────────────────────────────────────────────────────────

    @staticmethod
    def write_report(result: ConformanceResult, output_path: str | Path) -> None:
        """Write a conformance result to a JSON file."""
        Path(output_path).write_text(json.dumps(result.to_dict(), indent=2), encoding="utf-8")
