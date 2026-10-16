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

    def test_skip_with_data_set_in_log_is_parsed(self) -> None:
        log = ("There were 1 skipped tests:\n\n1) Tests\\Feature\\XMySqlTest::t with data set #1\n"
               "Opt-in MySQL concurrency gate is not enabled (set X=1).\n\nOK, but some tests were skipped!\n")
        skips = sj.parse_skips(log)
        self.assertEqual(len(skips), 1)
        self.assertEqual(skips[0]["test"], "Tests\\Feature\\XMySqlTest::t with data set #1")
        self.assertEqual(skips[0]["category"], "DB_CONCURRENCY_OPT_IN")

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

    def test_annotations_escape_newlines_and_report_failures(self) -> None:
        tmp = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, tmp, True)
        (tmp / "j.xml").write_text(junit([("C", "t1", "fail"), ("C", "t2", "pass")]).replace("boom", "line1\nline2 100%"))
        (tmp / "l.log").write_text("")
        r = subprocess.run([sys.executable, "-I", str(HERE / "summarize_junit.py"), "--gate", "fast", "--junit", str(tmp / "j.xml"),
                            "--log", str(tmp / "l.log"), "--exit-code", "1", "--out-dir", str(tmp), "--wall-seconds", "2"],
                           capture_output=True, text=True, timeout=60)
        self.assertEqual(r.returncode, 1)
        cmds = [ln for ln in r.stdout.splitlines() if ln.startswith("::")]
        self.assertTrue(any("FAILED test" in c and "%0A" in c and "100%25" in c for c in cmds), cmds)
        self.assertTrue(all("\n" not in c for c in cmds))
        self.assertTrue((tmp / "fast.summary.json").is_file() and (tmp / "fast.summary.md").is_file())

    def test_classification(self) -> None:
        self.assertEqual(sj.classify_skip("needs MySQL")[0], "DB_CONCURRENCY_OPT_IN")
        self.assertEqual(sj.classify_skip("requires explicit flag FOO=1")[0], "OTHER_ENV_OPT_IN")
        self.assertEqual(sj.classify_skip("This driver does not enforce the column length.")[0], "SQLITE_DRIVER_LIMITATION")
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
                         {"APP_KEY": "base64:AAAA"}, {"ASSAB_CI_ISOLATION_VERIFIED": "1"}):
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


@unittest.skipUnless(shutil.which("php") and (REPO / "vendor/autoload.php").is_file(), "php and vendor/ required")
class PhpunitBootstrap(unittest.TestCase):
    """The PHPUnit bootstrap distinguishes the top-level process (strict) from a
    #[RunInSeparateProcess] child that inherits Laravel-Dotenv values (tolerant,
    loopback only). Run with a throw-away environment, no Laravel boot."""

    def run_bootstrap(self, **overrides: str) -> subprocess.CompletedProcess:
        env = {"PATH": os.environ.get("PATH", "/usr/bin:/bin"), "HOME": "/tmp", "APP_ENV": "testing",
               "DB_CONNECTION": "sqlite", "DB_DATABASE": ":memory:"}
        env.update(overrides)
        return subprocess.run(["php", str(HERE / "phpunit-isolation-bootstrap.php")], cwd=REPO, env=env,
                              capture_output=True, text=True, timeout=60)

    def test_clean_top_level_process_passes(self) -> None:
        self.assertEqual(self.run_bootstrap().returncode, 0)

    def test_top_level_process_rejects_any_endpoint_variable(self) -> None:
        for override in ({"DB_HOST": "127.0.0.1"}, {"DB_USERNAME": "root"}, {"DB_PASSWORD": "x"},
                         {"DB_URL": "mysql://u@h/d"}, {"DATABASE_URL": "mysql://u@h/d"}):
            with self.subTest(override=override):
                r = self.run_bootstrap(**override)
                self.assertEqual(r.returncode, 1, override)
                self.assertIn("ISOLATION BOOTSTRAP", r.stderr)

    def test_wrong_connection_is_rejected_everywhere(self) -> None:
        for marker in ({}, {"ASSAB_CI_ISOLATION_VERIFIED": "1"}):
            for override in ({"DB_CONNECTION": "mysql"}, {"DB_DATABASE": "assab_staging"}, {"APP_ENV": "production"}):
                with self.subTest(marker=marker, override=override):
                    self.assertEqual(self.run_bootstrap(**marker, **override).returncode, 1)

    def test_verified_child_tolerates_inherited_loopback_dotenv_values_only(self) -> None:
        marker = {"ASSAB_CI_ISOLATION_VERIFIED": "1"}
        ok = self.run_bootstrap(**marker, DB_HOST="127.0.0.1", DB_USERNAME="root", DB_PASSWORD="inherited")
        self.assertEqual(ok.returncode, 0, ok.stderr)
        for host in ("10.0.0.5", "db.internal", "assab-staging.example"):
            with self.subTest(host=host):
                r = self.run_bootstrap(**marker, DB_HOST=host)
                self.assertEqual(r.returncode, 1)
                self.assertNotIn(host, r.stderr)
        for dsn in ({"DB_URL": "mysql://u@h/d"}, {"DATABASE_URL": "mysql://u@h/d"}):
            with self.subTest(dsn=dsn):
                self.assertEqual(self.run_bootstrap(**marker, **dsn).returncode, 1)


def _git(repo: Path, *args: str) -> str:
    env = dict(os.environ, GIT_AUTHOR_NAME="t", GIT_AUTHOR_EMAIL="t@t", GIT_COMMITTER_NAME="t", GIT_COMMITTER_EMAIL="t@t")
    return subprocess.run(["git", "-C", str(repo), *args], check=True, capture_output=True, text=True, env=env).stdout.strip()


def _commit(repo: Path, files: dict[str, str], msg: str) -> str:
    for rel, text in files.items():
        f = repo / rel
        f.parent.mkdir(parents=True, exist_ok=True)
        f.write_text(text)
    _git(repo, "add", "-A")
    _git(repo, "commit", "-q", "-m", msg)
    return _git(repo, "rev-parse", "HEAD")


@unittest.skipUnless(shutil.which("git") and shutil.which("bash"), "git and bash required")
class SourceGuardScript(unittest.TestCase):
    """The source guard must accept approved application corrections and reject denied paths."""

    def setUp(self) -> None:
        self.tmp = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, self.tmp, True)
        self.repo = self.tmp / "r"
        self.repo.mkdir()
        _git(self.repo, "init", "-q", "-b", "main")
        self.baseline = _commit(self.repo, {"README.md": "a"}, "baseline")
        self.anchor = _commit(self.repo, {"app/Old.php": "<?php"}, "anchor")
        self.anchor_tree = _git(self.repo, "rev-parse", f"{self.anchor}^{{tree}}")

    def guard(self, tested: str | None = None, **over: str) -> subprocess.CompletedProcess:
        head = _git(self.repo, "rev-parse", "HEAD")
        env = {"PATH": os.environ.get("PATH", "/usr/bin:/bin"), "HOME": str(self.tmp), "TESTED_HEAD": tested or head,
               "ANCHOR_SHA": self.anchor, "ANCHOR_TREE": self.anchor_tree, "BASELINE_SHA": self.baseline,
               "POLICY_FILE": str(HERE / "source_scope_policy.txt"), "ARTIFACT_DIR": str(self.tmp / "art")}
        env.update(over)
        return subprocess.run(["bash", str(HERE / "source_guard.sh")], cwd=self.repo, env=env, capture_output=True, text=True, timeout=60)

    def test_application_corrections_are_allowed_and_counted(self) -> None:
        _commit(self.repo, {"Modules/Shift/app/Services/X.php": "<?php", "tests/Feature/XTest.php": "<?php",
                            "docs/ci/a.md": "x", ".github/workflows/assab-brc34-regression.yml": "x"}, "fix")
        r = self.guard()
        self.assertEqual(r.returncode, 0, r.stdout + r.stderr)
        self.assertIn("SOURCE_GUARD=PASS", r.stdout)
        self.assertIn("files_changed_application=1", r.stdout)
        self.assertIn("files_changed_tests=1", r.stdout)

    def test_anchor_itself_passes(self) -> None:
        self.assertEqual(self.guard().returncode, 0)

    def test_denied_paths_fail_closed(self) -> None:
        for path in (".env", ".env.testing", ".env.staging", "railpack.json", "Dockerfile", "docker-compose.yml",
                     "database/migrations/2026_01_01_000000_x.php", "Modules/Shift/database/migrations/x.php",
                     "database/seeders/DatabaseSeeder.php", "Modules/Admin/database/seeders/S.php", "composer.lock", "composer.json",
                     ".github/workflows/assab-staging-api-diagnostics.yml", ".coolify/x"):
            with self.subTest(path=path):
                _git(self.repo, "checkout", "-q", "-B", "work", self.anchor)
                _commit(self.repo, {path: "x"}, "denied")
                r = self.guard()
                self.assertEqual(r.returncode, 1, path)
                self.assertIn(path, r.stdout)

    def test_env_example_is_the_only_env_file_allowed(self) -> None:
        _commit(self.repo, {".env.example": "APP_NAME=x"}, "ok")
        self.assertEqual(self.guard().returncode, 0)

    def test_head_must_equal_requested_commit(self) -> None:
        _commit(self.repo, {"app/N.php": "<?php"}, "new")
        r = self.guard(tested=self.baseline)
        self.assertEqual(r.returncode, 1)
        self.assertIn("requested tested head", r.stdout)

    def test_commit_not_descending_from_anchor_is_rejected(self) -> None:
        _git(self.repo, "checkout", "-q", "-B", "other", self.baseline)
        _commit(self.repo, {"app/Other.php": "<?php"}, "unrelated lineage")
        r = self.guard()
        self.assertEqual(r.returncode, 1)
        self.assertIn("does not descend", r.stdout)

    def test_wrong_anchor_tree_and_missing_baseline_ancestry_are_rejected(self) -> None:
        self.assertEqual(self.guard(ANCHOR_TREE="0" * 40).returncode, 1)
        stray = _git(self.repo, "commit-tree", "-m", "stray", f"{self.anchor}^{{tree}}")
        self.assertEqual(self.guard(BASELINE_SHA=stray).returncode, 1)

    def test_policy_without_deny_rules_fails(self) -> None:
        p = self.tmp / "empty.txt"
        p.write_text("# nothing\n")
        self.assertEqual(self.guard(POLICY_FILE=str(p)).returncode, 1)


@unittest.skipUnless(shutil.which("git"), "git required")
class FastListIncludesChangedTests(unittest.TestCase):
    def test_new_and_modified_test_classes_are_added_to_the_fast_suite(self) -> None:
        tmp = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, tmp, True)
        _git(tmp, "init", "-q", "-b", "main")
        shutil.copy(REPO / "phpunit.xml", tmp / "phpunit.xml")
        since = _commit(tmp, {"tests/A.php": "<?php", "tests/KeepTest.php": "<?php", "tests/SkipTest.php": "<?php"}, "base")
        _commit(tmp, {"tests/NewFeatureTest.php": "<?php", "tests/KeepTest.php": "<?php // changed",
                      "tests/Support/Helper.php": "<?php", "app/X.php": "<?php"}, "change")
        lst = tmp / "list.txt"
        lst.write_text("tests/A.php\n")
        root = mpc.build("fast", tmp, lst, since).getroot()
        files = [f.text for f in root.findall("./testsuites/testsuite/file")]
        self.assertEqual(files, ["tests/A.php", "tests/KeepTest.php", "tests/NewFeatureTest.php"])

    def test_without_option_only_the_list_is_used(self) -> None:
        tmp = Path(tempfile.mkdtemp())
        self.addCleanup(shutil.rmtree, tmp, True)
        (tmp / "tests").mkdir()
        (tmp / "tests" / "A.php").write_text("<?php")
        shutil.copy(REPO / "phpunit.xml", tmp / "phpunit.xml")
        lst = tmp / "list.txt"
        lst.write_text("tests/A.php\n")
        files = [f.text for f in mpc.build("fast", tmp, lst).getroot().findall("./testsuites/testsuite/file")]
        self.assertEqual(files, ["tests/A.php"])


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
        self.assertNotIn("enforce-scope", data + act.read_text())
        self.assertIn("integration/shift-cycle-staging-candidate-*", data)
        self.assertIn("needs.fast-gate.outputs.run_full", data)
        self.assertIn("source_guard.sh", act.read_text())
        ET.fromstring("<a/>")  # keep xml import used


if __name__ == "__main__":
    unittest.main()
