"""Offline tests for the ASSAB BRC v3.4 CI helper scripts.

Run: python3 -I -m unittest discover -s scripts/ci -p 'test_*.py'
No network, no database, no Laravel boot.
"""
from __future__ import annotations

import json
import os
import shutil
import subprocess
import sys
import tempfile
import unittest
import xml.etree.ElementTree as ET
from pathlib import Path

HERE = Path(__file__).resolve().parent
REPO = HERE.parents[1]
sys.path.insert(0, str(HERE))

import make_phpunit_config as mpc  # noqa: E402
import summarize_junit as sj  # noqa: E402

GOOD_ENV = {
    "PATH": os.environ.get("PATH", "/usr/bin:/bin"),
    "HOME": "/tmp",
    "ASSAB_CI_LOCAL_SELFTEST": "1",
    "APP_ENV": "testing",
    "DB_CONNECTION": "sqlite",
    "DB_DATABASE": ":memory:",
    "CACHE_STORE": "array",
    "SESSION_DRIVER": "array",
    "QUEUE_CONNECTION": "sync",
    "MAIL_MAILER": "array",
    "APP_KEY": "base64:MDEyMzQ1Njc4OWFiY2RlZjAxMjM0NTY3ODlhYmNkZWY=",
}


def junit(cases: list[tuple[str, str, str]]) -> str:
    """cases: (class, name, outcome) outcome in pass|fail|error|skip"""
    body = []
    for cls, name, outcome in cases:
        inner = {"pass": "", "fail": '<failure type="Fail">boom /home/runner/x.php:1</failure>',
                 "error": '<error type="Err">kaboom</error>', "skip": "<skipped/>"}[outcome]
        body.append(f'<testcase name="{name}" class="{cls}" classname="{cls}" assertions="2" time="0.1">{inner}</testcase>')
    return f'<?xml version="1.0"?><testsuites><testsuite name="x" tests="{len(cases)}" time="1.5">{"".join(body)}</testsuite></testsuites>'


class MakePhpunitConfig(unittest.TestCase):
    def setUp(self) -> None:
        self.tmp = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, self.tmp, True)
        (self.tmp / "tests").mkdir()
        (self.tmp / "tests" / "A.php").write_text("<?php")
        shutil.copy(REPO / "phpunit.xml", self.tmp / "phpunit.xml")

    def test_fast_lists_files_and_forces_sqlite(self) -> None:
        lst = self.tmp / "list.txt"
        lst.write_text("# c\n\ntests/A.php\n")
        tree = mpc.build("fast", self.tmp, lst)
        root = tree.getroot()
        self.assertEqual(root.get("bootstrap"), mpc.BOOTSTRAP)
        self.assertEqual([f.text for f in root.findall("./testsuites/testsuite/file")], ["tests/A.php"])
        forced = {e.get("name"): (e.get("value"), e.get("force")) for e in root.findall("./php/env")}
        self.assertEqual(forced["DB_CONNECTION"], ("sqlite", "true"))
        self.assertEqual(forced["DB_DATABASE"], (":memory:", "true"))
        self.assertEqual(forced["APP_ENV"], ("testing", "true"))

    def test_missing_file_aborts(self) -> None:
        lst = self.tmp / "list.txt"
        lst.write_text("tests/Nope.php\n")
        with self.assertRaises(SystemExit):
            mpc.build("fast", self.tmp, lst)

    def test_duplicates_and_traversal_abort(self) -> None:
        lst = self.tmp / "list.txt"
        lst.write_text("tests/A.php\ntests/A.php\n")
        with self.assertRaises(SystemExit):
            mpc.read_list(lst)
        lst.write_text("../etc/passwd\n")
        with self.assertRaises(SystemExit):
            mpc.read_list(lst)

    def test_phpunit_xml_with_conflicting_db_env_is_refused(self) -> None:
        text = (self.tmp / "phpunit.xml").read_text().replace('value="sqlite"', 'value="mysql"')
        (self.tmp / "phpunit.xml").write_text(text)
        with self.assertRaises(SystemExit):
            mpc.build("full", self.tmp, None)

    def test_full_keeps_repository_suites(self) -> None:
        tree = mpc.build("full", REPO, None)
        names = [s.get("name") for s in tree.getroot().findall("./testsuites/testsuite")]
        self.assertEqual(names, ["Unit", "Feature", "NFR"])

    def test_committed_fast_list_is_valid_for_this_checkout(self) -> None:
        if not (REPO / "tests").is_dir():
            self.skipTest("no tests directory")
        tree = mpc.build("fast", REPO, HERE / "brc34_fast_suite.txt")
        files = [f.text for f in tree.getroot().findall("./testsuites/testsuite/file")]
        self.assertGreaterEqual(len(files), 100)
        self.assertIn("tests/Unit/ShiftFinancialCalculatorTest.php", files)
        self.assertIn("tests/Feature/ApiUnauthenticatedJsonResponseTest.php", files)


class SummarizeJunit(unittest.TestCase):
    def run_summary(self, xml: str | None, log: str, exit_code: int, floor: int = 1) -> dict:
        tmp = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, tmp, True)
        j = tmp / "j.xml"
        if xml is not None:
            j.write_text(xml)
        lg = tmp / "l.log"
        lg.write_text(log)
        args = sj.argparse.Namespace(
            gate="fast", junit=str(j), log=str(lg), exit_code=exit_code, out_dir=str(tmp), min_tests=floor,
            tested_sha="abc", event="push", php="8.3.0", db_driver="sqlite", db_database=":memory:",
            runner="github-hosted", run_url="", wall_seconds=1.0)
        return sj.build(args)

    SKIP_LOG = ("There were 2 skipped tests:\n\n"
                "1) Tests\\Feature\\XMySqlTest::a\nMariaDB/RG-01 NOT_RUN: requires explicit approved PKG7_MYSQL_TESTS=1.\n\n"
                "2) Tests\\Feature\\YTest::b\nNOT_RUN: isolated MariaDB unavailable. SQLite cannot establish financial lock safety.\n\n"
                "OK, but some tests were skipped!\nTests: 3, Assertions: 4, Skipped: 2.\n")

    def test_pass_with_classified_skips(self) -> None:
        r = self.run_summary(junit([("C", "t1", "pass"), ("X", "a", "skip"), ("Y", "b", "skip")]), self.SKIP_LOG, 0)
        self.assertEqual(r["status"], "PASS")
        self.assertEqual((r["tests"], r["executed"], r["skipped"]), (3, 1, 2))
        self.assertEqual(r["skip_categories"], {"DB_CONCURRENCY_OPT_IN": 2})
        self.assertTrue(r["skip_log_matches_junit"])

    def test_failure_and_error_make_fail(self) -> None:
        r = self.run_summary(junit([("C", "t1", "fail"), ("C", "t2", "error"), ("C", "t3", "pass")]), "", 2)
        self.assertEqual(r["status"], "FAIL")
        self.assertEqual((r["failures"], r["errors"]), (1, 1))
        self.assertNotIn("/home/runner", json.dumps(r))

    def test_missing_junit_is_not_run(self) -> None:
        r = self.run_summary(None, "", 99)
        self.assertEqual(r["status"], "NOT_RUN")

    def test_floor_protects_against_empty_suite(self) -> None:
        r = self.run_summary(junit([("C", "t1", "pass")]), "", 0, floor=800)
        self.assertEqual(r["status"], "FAIL")
        self.assertIn("floor", r["reason"])

    def test_nonzero_exit_without_failures_still_fails(self) -> None:
        r = self.run_summary(junit([("C", "t1", "pass")]), "", 255)
        self.assertEqual(r["status"], "FAIL")

    def test_skip_without_log_reason_is_unclassified_not_hidden(self) -> None:
        r = self.run_summary(junit([("C", "t1", "pass"), ("X", "a", "skip")]), "", 0)
        self.assertFalse(r["skip_log_matches_junit"])
        self.assertEqual(r["skip_categories"], {"UNCLASSIFIED": 1})
        self.assertEqual(r["status"], "PASS")

    def test_classification(self) -> None:
        self.assertEqual(sj.classify_skip("needs MySQL")[0], "DB_CONCURRENCY_OPT_IN")
        self.assertEqual(sj.classify_skip("requires explicit flag FOO=1")[0], "OTHER_ENV_OPT_IN")
        self.assertEqual(sj.classify_skip("because")[0], "UNCLASSIFIED")


@unittest.skipUnless(shutil.which("bash"), "bash required")
class EnvGuardScript(unittest.TestCase):
    def run_guard(self, **overrides: str) -> subprocess.CompletedProcess:
        env = dict(GOOD_ENV)
        env.update(overrides)
        return subprocess.run(["bash", str(HERE / "assert_isolated_env.sh")], cwd=REPO, env=env,
                              capture_output=True, text=True, timeout=60)

    def test_good_environment_passes(self) -> None:
        r = self.run_guard()
        self.assertEqual(r.returncode, 0, r.stdout + r.stderr)
        self.assertIn("ISOLATION_GUARD=PASS", r.stdout)

    def test_bad_environments_fail(self) -> None:
        for override in ({"DB_CONNECTION": "mysql"}, {"DB_DATABASE": "assab_staging"}, {"DB_HOST": "10.0.0.1"},
                         {"DB_URL": "mysql://u@h/d"}, {"APP_ENV": "production"}, {"CACHE_STORE": "redis"},
                         {"COOLIFY_URL": "x"}, {"X_STAGING_FLAG": "1"}, {"OTHER": "db=assab_staging"},
                         {"APP_KEY": "base64:AAAA"}):
            with self.subTest(override=override):
                r = self.run_guard(**override)
                self.assertEqual(r.returncode, 1, (override, r.stdout))
                self.assertIn("ISOLATION_GUARD=FAIL", r.stdout)

    def test_app_key_value_is_never_printed(self) -> None:
        r = self.run_guard(APP_KEY="base64:SUPERSECRETVALUE")
        self.assertNotIn("SUPERSECRETVALUE", r.stdout + r.stderr)

    def test_runner_assertion_without_selftest_flag(self) -> None:
        env = {k: v for k, v in GOOD_ENV.items() if k != "ASSAB_CI_LOCAL_SELFTEST"}
        r = subprocess.run(["bash", str(HERE / "assert_isolated_env.sh")], cwd=REPO, env=env, capture_output=True, text=True, timeout=60)
        self.assertEqual(r.returncode, 1)


class WorkflowStaticChecks(unittest.TestCase):
    def test_workflow_has_no_secrets_vars_or_deploy_and_pins_actions(self) -> None:
        wf = REPO / ".github/workflows/assab-brc34-regression.yml"
        act = REPO / ".github/actions/brc34-sqlite-setup/action.yml"
        if not wf.is_file():
            self.skipTest("workflow not present")
        import re
        for f in (wf, act):
            text = f.read_text()
            self.assertIsNone(re.search(r"\$\{\{\s*(secrets|vars)\.", text), f)
            for m in re.finditer(r"uses:\s*(\S+)", text):
                ref = m.group(1)
                if ref.startswith("./"):
                    continue
                self.assertRegex(ref, r"@[0-9a-f]{40}$", f"unpinned action {ref} in {f.name}")
            code = "\n".join(re.sub(r"\s#.*$", "", ln) for ln in text.splitlines() if not ln.lstrip().startswith("#"))
            self.assertNotRegex(code.lower(), r"coolify|assab_staging|docker login|ssh |kubectl|openai")
        data = wf.read_text()
        self.assertIn("contents: read", data)
        self.assertNotIn("pull_request_target", data)
        self.assertNotIn("services:", data)
        ET.fromstring("<a/>")  # keep xml import used


if __name__ == "__main__":
    unittest.main()
