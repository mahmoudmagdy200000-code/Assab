#!/usr/bin/env python3
"""Summarise a PHPUnit JUnit log + console log for the ASSAB BRC v3.4 CI.

Produces (in --out-dir):
  <gate>.summary.json   machine-readable result
  <gate>.summary.md     human-readable result (also appended to $GITHUB_STEP_SUMMARY by the workflow)
and prints ``::notice::`` / ``::warning::`` / ``::error::`` workflow commands so
the key numbers are visible as check-run annotations (readable via the Checks
API even when job logs are not downloadable).

Status model:
  PASS     exit code 0, >= --min-tests testcases, 0 failures, 0 errors
  FAIL     any failure/error, non-zero exit code, or fewer testcases than --min-tests
  NOT_RUN  no readable JUnit file

Skips are never counted as passes. JUnit ``<skipped/>`` carries no message with
PHPUnit 12, so skip reasons are read from the console log's "skipped tests"
section and cross-checked against the JUnit skipped count.
"""
from __future__ import annotations

import argparse
import json
import os
import re
import sys
import xml.etree.ElementTree as ET
from collections import Counter, defaultdict
from pathlib import Path

SKIP_BLOCK = re.compile(r"^\d+\) (?P<id>\S+::[^\n]+)\n(?P<reason>.*?)(?:\n\n|\Z)", re.M | re.S)
MAX_MSG = 400


def classify_skip(reason: str) -> tuple[str, str]:
    """Return (category, engine_hint)."""
    r = reason or ""
    if re.search(r"maria\s*db|mysql", r, re.I):
        engine = "mysql" if re.search(r"mysql", r, re.I) and not re.search(r"maria", r, re.I) else (
            "mariadb+mysql-flag" if re.search(r"maria", r, re.I) and re.search(r"mysql", r, re.I) else "mariadb")
        return "DB_CONCURRENCY_OPT_IN", engine
    if re.search(r"requires|opt[- ]?in|explicit|not run|NOT_RUN", r, re.I):
        return "OTHER_ENV_OPT_IN", ""
    return "UNCLASSIFIED", ""


def parse_skips(log_text: str) -> list[dict]:
    start = log_text.find("skipped test")
    if start < 0:
        return []
    section = log_text[start:]
    section = section.split("\n", 1)[1] if "\n" in section else ""
    out = []
    for m in SKIP_BLOCK.finditer(section.lstrip("\n")):
        reason = m.group("reason").strip()
        cat, engine = classify_skip(reason)
        out.append({"test": m.group("id"), "reason": reason[:MAX_MSG], "category": cat, "engine_hint": engine})
    return out


def esc(text: str) -> str:
    """Escape a value for a GitHub workflow command message."""
    return str(text).replace("%", "%25").replace("\r", "%0D").replace("\n", "%0A")


def redact(text: str) -> str:
    text = re.sub(r"/(?:home|tmp|root|github|__w|runner)[^\s:'\"]*", "<path>", text or "")
    return text[:MAX_MSG]


def parse_junit(path: Path) -> dict | None:
    if not path.is_file():
        return None
    try:
        root = ET.parse(path).getroot()
    except ET.ParseError:
        return None
    cases = root.findall(".//testcase")
    failures, errors, skipped = [], [], []
    assertions = 0
    classes = Counter()
    for c in cases:
        cls = c.get("class") or c.get("classname") or ""
        name = c.get("name") or ""
        assertions += int(c.get("assertions") or 0)
        classes[cls] += 1
        ident = f"{cls}::{name}"
        f = c.find("failure")
        e = c.find("error")
        if f is not None:
            failures.append({"test": ident, "message": redact(f.get("type", "") + " " + (f.text or ""))})
        if e is not None:
            errors.append({"test": ident, "message": redact(e.get("type", "") + " " + (e.text or ""))})
        if c.find("skipped") is not None:
            skipped.append(ident)
    top = root if root.tag == "testsuite" else root.find("testsuite")
    time_s = float(top.get("time") or 0) if top is not None else 0.0
    return {
        "tests": len(cases),
        "assertions": assertions,
        "failures": failures,
        "errors": errors,
        "skipped": skipped,
        "classes": len(classes),
        "time_seconds": round(time_s, 3),
    }


def build(args: argparse.Namespace) -> dict:
    junit = parse_junit(Path(args.junit))
    log_text = Path(args.log).read_text(encoding="utf-8", errors="replace") if args.log and Path(args.log).is_file() else ""
    skips = parse_skips(log_text)
    res: dict = {
        "gate": args.gate,
        "tested_sha": args.tested_sha,
        "event": args.event,
        "php": args.php,
        "db_driver": args.db_driver,
        "db_database": args.db_database,
        "runner": args.runner,
        "run_url": args.run_url,
        "wall_seconds": args.wall_seconds,
        "exit_code": args.exit_code,
        "min_tests_floor": args.min_tests,
    }
    if junit is None:
        res.update(status="NOT_RUN", reason="JUnit file missing or unreadable", tests=0, assertions=0,
                   failures=0, errors=0, skipped=0, executed=0)
        res.update(skip_categories={}, skip_by_class={}, skips=[], failure_details=[], error_details=[],
                   skip_log_matches_junit=None, classes=0, time_seconds=0.0)
        return res
    n_skipped = len(junit["skipped"])
    executed = junit["tests"] - n_skipped
    status = "PASS"
    reasons = []
    if args.exit_code != 0:
        status = "FAIL"
        reasons.append(f"runner exit code {args.exit_code}")
    if junit["failures"] or junit["errors"]:
        status = "FAIL"
        reasons.append(f"{len(junit['failures'])} failure(s), {len(junit['errors'])} error(s)")
    if junit["tests"] < args.min_tests:
        status = "FAIL"
        reasons.append(f"only {junit['tests']} testcases, floor is {args.min_tests}")
    by_cat = Counter(s["category"] for s in skips)
    by_class: dict[str, int] = defaultdict(int)
    for s in skips:
        by_class[s["test"].split("::")[0]] += 1
    match = len(skips) == n_skipped
    if len(skips) < n_skipped:
        # JUnit says more tests were skipped than the console log explained: never hide them.
        by_cat["UNCLASSIFIED"] += n_skipped - len(skips)
    res.update(
        status=status, reason="; ".join(reasons),
        tests=junit["tests"], assertions=junit["assertions"], failures=len(junit["failures"]),
        errors=len(junit["errors"]), skipped=n_skipped, executed=executed, classes=junit["classes"],
        time_seconds=junit["time_seconds"], skip_categories=dict(by_cat), skip_by_class=dict(sorted(by_class.items())),
        skips=skips, failure_details=junit["failures"][:50], error_details=junit["errors"][:50],
        skip_log_matches_junit=match,
    )
    return res


def markdown(r: dict) -> str:
    lines = [
        f"## ASSAB BRC v3.4 -- {r['gate'].upper()} SQLite gate: **{r['status']}**",
        "",
        "| Item | Value |",
        "|---|---|",
        f"| Tested SHA | `{r['tested_sha']}` |",
        f"| Event | {r['event']} |",
        f"| PHP | {r['php']} |",
        f"| DB driver / database | `{r['db_driver']}` / `{r['db_database']}` |",
        f"| Runner | {r['runner']} |",
        f"| Test cases | {r['tests']} (executed {r['executed']}, skipped {r['skipped']}) |",
        f"| Assertions | {r['assertions']} |",
        f"| Failures / errors | {r['failures']} / {r['errors']} |",
        f"| Test classes | {r['classes']} |",
        f"| Wall-clock duration of PHPUnit step | {r['wall_seconds']} s |",
        f"| Sum of per-test time (JUnit) | {r['time_seconds']} s |",
        f"| Exit code | {r['exit_code']} |",
    ]
    if r.get("reason"):
        lines += ["", f"**Reason:** {r['reason']}"]
    if r["skips"]:
        lines += ["", "### Skips are NOT passes -- classification", "", "| Category | Count |", "|---|---|"]
        lines += [f"| {k} | {v} |" for k, v in sorted(r["skip_categories"].items())]
        lines += ["", "| Skipped class | Count |", "|---|---|"]
        lines += [f"| `{k}` | {v} |" for k, v in r["skip_by_class"].items()]
    for title, key in (("Failures", "failure_details"), ("Errors", "error_details")):
        if r[key]:
            lines += ["", f"### {title} (first {len(r[key])})", ""]
            lines += [f"- `{d['test']}` -- {d['message']}" for d in r[key]]
    lines += ["", f"_Evidence: runner type `{r['runner']}`, in-memory SQLite only. This gate does not execute any "
              "MySQL/MariaDB path, and a green run is not business acceptance._", ""]
    return "\n".join(lines)


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("--gate", required=True, choices=["fast", "full"])
    ap.add_argument("--junit", required=True)
    ap.add_argument("--log", default="")
    ap.add_argument("--exit-code", type=int, required=True)
    ap.add_argument("--out-dir", required=True)
    ap.add_argument("--min-tests", type=int, default=1)
    ap.add_argument("--tested-sha", default=os.environ.get("GITHUB_SHA", "unknown"))
    ap.add_argument("--event", default=os.environ.get("GITHUB_EVENT_NAME", "local"))
    ap.add_argument("--php", default=os.environ.get("ASSAB_CI_PHP_VERSION", "unknown"))
    ap.add_argument("--db-driver", default=os.environ.get("DB_CONNECTION", "unknown"))
    ap.add_argument("--db-database", default=os.environ.get("DB_DATABASE", "unknown"))
    ap.add_argument("--runner", default=os.environ.get("RUNNER_ENVIRONMENT", "local"))
    ap.add_argument("--run-url", default="")
    ap.add_argument("--wall-seconds", type=float, default=0.0, help="wall-clock duration of the PHPUnit step")
    args = ap.parse_args(argv)

    res = build(args)
    out = Path(args.out_dir)
    out.mkdir(parents=True, exist_ok=True)
    (out / f"{args.gate}.summary.json").write_text(json.dumps(res, indent=2, sort_keys=True) + "\n", encoding="utf-8")
    md = markdown(res)
    (out / f"{args.gate}.summary.md").write_text(md, encoding="utf-8")

    head = (f"BRC34 {args.gate.upper()} SQLite gate {res['status']}: tests={res['tests']} executed={res['executed']} "
            f"skipped={res['skipped']} failures={res['failures']} errors={res['errors']} assertions={res['assertions']} "
            f"classes={res['classes']} wall_s={res['wall_seconds']} exit={res['exit_code']} "
            f"sha={res['tested_sha']} php={res['php']} db={res['db_driver']}:{res['db_database']}")
    level = "notice" if res["status"] == "PASS" else "error"
    print(f"::{level} title=BRC34 {args.gate} gate {res['status']}::{esc(head)}")
    for cat, n in sorted(res["skip_categories"].items()):
        lvl = "warning" if cat == "UNCLASSIFIED" else "notice"
        print(f"::{lvl} title=BRC34 {args.gate} skips::{esc(cat)}={n}")
    if res["skip_by_class"]:
        top = ", ".join(f"{k.rsplit(chr(92), 1)[-1]}={v}" for k, v in list(res["skip_by_class"].items())[:12])
        print(f"::notice title=BRC34 {args.gate} skipped classes::{esc(top)}")
    for d in res["failure_details"][:8]:
        print(f"::error title=BRC34 {args.gate} FAILED test::{esc(d['test'] + ' -- ' + d['message'])}")
    for d in res["error_details"][:8]:
        print(f"::error title=BRC34 {args.gate} ERRORED test::{esc(d['test'] + ' -- ' + d['message'])}")
    if res.get("skip_log_matches_junit") is False:
        print(f"::warning title=BRC34 {args.gate} skips::skip reasons in the console log do not account for every JUnit skip")
    if res.get("reason"):
        print(f"::error title=BRC34 {args.gate} reason::{esc(res['reason'])}")
    print(md)
    return 0 if res["status"] == "PASS" else 1


if __name__ == "__main__":
    sys.exit(main())
