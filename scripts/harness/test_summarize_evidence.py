from __future__ import annotations

import importlib.util
import json
import tempfile
import unittest
import xml.etree.ElementTree as ET
from pathlib import Path

MODULE_PATH = Path(__file__).with_name("summarize_evidence.py")
SPEC = importlib.util.spec_from_file_location("summarize_evidence", MODULE_PATH)
assert SPEC and SPEC.loader
summary = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(summary)


class EvidenceSummaryTest(unittest.TestCase):
    def test_repository_registry_and_matrix_are_complete(self) -> None:
        root = Path(__file__).resolve().parents[2]
        registry = summary.load_registry(root / "docs/testing/brc34_acceptance_registry.json")
        coverage = summary.load_coverage(root / "docs/ci/BRC34_AC_COVERAGE_MATRIX.md")
        self.assertEqual(69, len(registry["scenarios"]))
        self.assertEqual(summary.EXPECTED_IDS, sorted(coverage))

    def test_only_current_junit_can_produce_pass(self) -> None:
        cases = {summary.testcase_key("Tests.Feature.ExampleTest", "test_rule_is_enforced"): ["PASS"]}
        self.assertEqual(
            "PASS",
            summary.resolve_actual("COVERED — EXECUTED PASS", ["ExampleTest::rule_is_enforced"], cases)["status"],
        )
        self.assertEqual(
            "NOT_RUN",
            summary.resolve_actual("COVERED — EXECUTED PASS", ["ExampleTest::rule_is_enforced"], {})["status"],
        )
        self.assertEqual(
            "BLOCKED",
            summary.resolve_actual("LEGACY CONFLICT — BUSINESS RULE MISMATCH", [], cases)["status"],
        )

    def test_phpunit_prefix_and_dataset_suffix_match_reviewed_reference(self) -> None:
        key = summary.testcase_key(
            "Tests\\Feature\\ExampleTest",
            'test_rule_is_enforced with data set "mysql"',
        )
        self.assertEqual(summary.reference_key("ExampleTest::rule_is_enforced"), key)

    def test_junit_failures_override_prior_classification(self) -> None:
        actual = summary.resolve_actual(
            "COVERED — EXECUTED PASS",
            ["ExampleTest::rule_is_enforced"],
            {("ExampleTest", "rule_is_enforced"): ["FAIL"]},
        )
        self.assertEqual("FAIL", actual["status"])
        self.assertEqual(["ExampleTest::rule_is_enforced"], actual["failedEvidence"])

    def test_nonzero_runner_exit_fails_execution_even_without_mapped_failure(self) -> None:
        result = summary.execution_summary(
            [{"path": "x.xml", "tests": 1, "PASS": 1}],
            [{"path": "x.exit", "exitCode": 2}],
        )
        self.assertEqual("FAIL", result["status"])
        self.assertEqual(1, result["tests"])


if __name__ == "__main__":
    unittest.main()
