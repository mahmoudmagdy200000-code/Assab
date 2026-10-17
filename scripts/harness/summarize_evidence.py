#!/usr/bin/env python3
"""Build current-run JSON, Markdown and JUnit evidence for BRC v3.4 AC-01..69.

The coverage matrix is prior reviewed mapping evidence. A scenario is marked
PASS only when every mapped test is present and successful in the JUnit files
provided for this invocation. Gaps, partial coverage and legacy conflicts never
become PASS merely because a neighbouring test ran.
"""

from __future__ import annotations

import argparse
import json
import re
import sys
import xml.etree.ElementTree as ET
from collections import Counter
from datetime import datetime, timezone
from pathlib import Path

EXPECTED_IDS = [f"AC-{number:02d}" for number in range(1, 70)]


def load_registry(path: Path) -> dict:
    payload = json.loads(path.read_text(encoding="utf-8"))
    scenarios = payload.get("scenarios", [])
    ids = [item.get("businessRuleId") for item in scenarios]
    if ids != EXPECTED_IDS:
        raise ValueError(f"registry must contain AC-01..AC-69 exactly once; got {ids!r}")
    return payload


def split_markdown_row(line: str) -> list[str]:
    return [cell.strip() for cell in line.strip().strip("|").split("|")]


def load_coverage(path: Path) -> dict[str, dict]:
    rows: dict[str, dict] = {}
    for line in path.read_text(encoding="utf-8").splitlines():
        if not re.match(r"^\| AC-\d{2} \|", line):
            continue
        cells = split_markdown_row(line)
        if len(cells) != 11:
            raise ValueError(f"unexpected coverage row shape ({len(cells)} cells): {line}")
        ac_id, rule_ids, required, implementation, tests, assertions, genuine, engine, execution, classification, gap = cells
        references = []
        for token in re.findall(r"`([^`]+::[^`]+)`", tests):
            reference = re.sub(r"\s+", " ", token.strip())
            if reference not in references:
                references.append(reference)
        rows[ac_id] = {
            "businessRuleReferencesFromCoverage": rule_ids,
            "requiredBehaviorFromCoverage": required,
            "implementationReferences": re.findall(r"`([^`]+)`", implementation),
            "evidenceReferences": references,
            "priorAssertions": assertions,
            "priorEngine": engine,
            "priorExecution": execution,
            "baselineClassification": re.sub(r"\*", "", classification).strip(),
            "coverageGap": gap,
            "genuineV34": genuine,
        }
    if sorted(rows) != EXPECTED_IDS:
        raise ValueError(f"coverage matrix must contain AC-01..AC-69 exactly once; got {sorted(rows)!r}")
    return rows


def canonical_method(name: str) -> str:
    """Normalize PHPUnit's test_* JUnit name to the reviewed matrix convention."""
    clean_name = re.split(r"\s+with data set\s+", name, maxsplit=1)[0]
    return clean_name.removeprefix("test_")


def testcase_key(classname: str, name: str) -> tuple[str, str]:
    short_class = re.split(r"[.\\]", classname)[-1]
    return short_class, canonical_method(name)


def load_junit(paths: list[Path]) -> tuple[dict[tuple[str, str], list[str]], list[dict]]:
    cases: dict[tuple[str, str], list[str]] = {}
    files: list[dict] = []
    for path in paths:
        root = ET.parse(path).getroot()
        counts = Counter()
        for case in root.findall(".//testcase"):
            status = "PASS"
            if case.find("failure") is not None or case.find("error") is not None:
                status = "FAIL"
            elif case.find("skipped") is not None:
                status = "SKIPPED"
            classname = case.get("classname") or case.get("class", "")
            key = testcase_key(classname, case.get("name", ""))
            cases.setdefault(key, []).append(status)
            counts[status] += 1
        files.append({"path": str(path), "tests": sum(counts.values()), **dict(counts)})
    return cases, files


def load_runner_exits(paths: list[Path]) -> list[dict]:
    exits = []
    for path in paths:
        value = path.read_text(encoding="utf-8").strip()
        if not re.fullmatch(r"[0-9]+", value):
            raise ValueError(f"runner exit file is not a non-negative integer: {path}")
        exits.append({"path": str(path), "exitCode": int(value)})
    return exits


def execution_summary(junit_files: list[dict], runner_exits: list[dict]) -> dict:
    tests = sum(item.get("tests", 0) for item in junit_files)
    failures = sum(item.get("FAIL", 0) for item in junit_files)
    skipped = sum(item.get("SKIPPED", 0) for item in junit_files)
    nonzero = [item for item in runner_exits if item["exitCode"] != 0]
    if failures or nonzero:
        status = "FAIL"
    elif runner_exits and junit_files:
        status = "PASS"
    elif runner_exits:
        status = "UNKNOWN"
    elif junit_files:
        status = "UNKNOWN"
    else:
        status = "NOT_RUN"
    return {
        "status": status,
        "tests": tests,
        "failuresOrErrors": failures,
        "skipped": skipped,
        "runnerExits": runner_exits,
    }


def reference_key(reference: str) -> tuple[str, str]:
    class_name, method = reference.split("::", 1)
    return re.split(r"[.\\]", class_name)[-1], canonical_method(method)


def resolve_actual(classification: str, references: list[str], cases: dict[tuple[str, str], list[str]]) -> dict:
    executed: list[str] = []
    missing: list[str] = []
    failed: list[str] = []
    skipped: list[str] = []
    for reference in references:
        statuses = cases.get(reference_key(reference), [])
        if not statuses:
            missing.append(reference)
            continue
        executed.append(reference)
        if "FAIL" in statuses:
            failed.append(reference)
        elif all(status == "SKIPPED" for status in statuses):
            skipped.append(reference)

    upper = classification.upper()
    if failed:
        status = "FAIL"
    elif "LEGACY CONFLICT" in upper:
        status = "BLOCKED"
    elif "GAP" in upper:
        status = "NOT_RUN"
    elif "PARTIALLY" in upper:
        status = "PARTIAL" if executed else "NOT_RUN"
    elif references and not missing and not skipped:
        status = "PASS"
    elif executed:
        status = "PARTIAL"
    else:
        status = "NOT_RUN"

    return {
        "status": status,
        "executedEvidence": executed,
        "missingEvidence": missing,
        "skippedEvidence": skipped,
        "failedEvidence": failed,
        "currentRunOnly": True,
    }


def build_report(
    registry: dict,
    coverage: dict[str, dict],
    cases: dict,
    junit_files: list[dict],
    runner_exits: list[dict],
    args: argparse.Namespace,
) -> dict:
    scenarios = []
    for metadata in registry["scenarios"]:
        ac_id = metadata["businessRuleId"]
        mapped = coverage[ac_id]
        actual = resolve_actual(mapped["baselineClassification"], mapped["evidenceReferences"], cases)
        scenarios.append({
            **{key: value for key, value in metadata.items() if key != "operation"},
            "apiOrServiceOperation": metadata.get("operation"),
            "expectedResult": metadata["expectedResult"],
            "actualResult": actual,
            "evidenceReferences": mapped["evidenceReferences"],
            "implementationReferences": mapped["implementationReferences"],
            "baselineClassification": mapped["baselineClassification"],
            "coverageGap": mapped["coverageGap"],
            "priorReviewedEvidence": {
                "engine": mapped["priorEngine"],
                "execution": mapped["priorExecution"],
                "assertions": mapped["priorAssertions"],
                "notTreatedAsCurrentRun": True,
            },
        })
    summary = Counter(item["actualResult"]["status"] for item in scenarios)
    return {
        "schemaVersion": 1,
        "authority": registry["authority"],
        "testedSha": args.tested_sha,
        "engine": args.engine,
        "generatedAt": datetime.now(timezone.utc).isoformat(),
        "currentExecutionOnly": True,
        "junitFiles": junit_files,
        "testExecution": execution_summary(junit_files, runner_exits),
        "summary": {status: summary.get(status, 0) for status in ["PASS", "FAIL", "BLOCKED", "PARTIAL", "NOT_RUN"]},
        "scenarios": scenarios,
    }


def write_markdown(report: dict, path: Path) -> None:
    lines = [
        "# ASSAB Business Rules v3.4 — current-run evidence",
        "",
        f"- Tested SHA: `{report['testedSha']}`",
        f"- Engine: `{report['engine']}`",
        f"- Test execution: `{report['testExecution']['status']}` "
        f"({report['testExecution']['tests']} tests; "
        f"{report['testExecution']['failuresOrErrors']} failures/errors; "
        f"{report['testExecution']['skipped']} skipped)",
        "- Current execution only: prior matrix prose is retained for traceability but never counted as this run.",
        "",
        "| Result | Count |",
        "|---|---:|",
    ]
    for status, count in report["summary"].items():
        lines.append(f"| {status} | {count} |")
    lines += ["", "| AC | Rules | Current result | Baseline classification | Evidence gap |", "|---|---|---|---|---|"]
    for item in report["scenarios"]:
        rules = ", ".join(item["businessRuleReferences"])
        gap = re.sub(r"<br\s*/?>", " ", item["coverageGap"])
        gap = gap.replace("|", "\\|")
        lines.append(f"| {item['businessRuleId']} | {rules} | {item['actualResult']['status']} | {item['baselineClassification']} | {gap} |")
    path.write_text("\n".join(lines) + "\n", encoding="utf-8")


def write_junit(report: dict, path: Path) -> None:
    summary = report["summary"]
    execution_status = report["testExecution"]["status"]
    suite = ET.Element("testsuite", {
        "name": "ASSAB Business Rules v3.4 acceptance evidence",
        "tests": "70",
        "failures": str(summary["FAIL"] + (1 if execution_status == "FAIL" else 0)),
        "errors": "1" if execution_status == "UNKNOWN" else "0",
        "skipped": str(summary["BLOCKED"] + summary["PARTIAL"] + summary["NOT_RUN"]
                       + (1 if execution_status == "NOT_RUN" else 0)),
        "time": "0",
    })
    execution_case = ET.SubElement(suite, "testcase", {
        "classname": "Assab.Harness",
        "name": "HARNESS-EXECUTION",
        "time": "0",
    })
    if execution_status == "FAIL":
        child = ET.SubElement(execution_case, "failure", {"message": "One or more test runners failed"})
        child.text = json.dumps(report["testExecution"], ensure_ascii=False)
    elif execution_status == "UNKNOWN":
        child = ET.SubElement(execution_case, "error", {"message": "Runner evidence is incomplete"})
        child.text = json.dumps(report["testExecution"], ensure_ascii=False)
    elif execution_status == "NOT_RUN":
        ET.SubElement(execution_case, "skipped", {"message": "NOT_RUN"})

    for item in report["scenarios"]:
        status = item["actualResult"]["status"]
        case = ET.SubElement(suite, "testcase", {
            "classname": "Assab.BusinessRules.v3_4",
            "name": item["businessRuleId"],
            "time": "0",
        })
        if status == "FAIL":
            child = ET.SubElement(case, "failure", {"message": "Mapped executable evidence failed"})
            child.text = json.dumps(item["actualResult"], ensure_ascii=False)
        elif status != "PASS":
            child = ET.SubElement(case, "skipped", {"message": status})
            child.text = item["coverageGap"]
    tree = ET.ElementTree(suite)
    ET.indent(tree, space="  ")
    tree.write(path, encoding="utf-8", xml_declaration=True)


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--registry", required=True)
    parser.add_argument("--coverage", required=True)
    parser.add_argument("--junit", action="append", default=[])
    parser.add_argument("--runner-exit-file", action="append", default=[])
    parser.add_argument("--tested-sha", required=True)
    parser.add_argument("--engine", required=True)
    parser.add_argument("--out-dir", required=True)
    args = parser.parse_args(argv)

    registry = load_registry(Path(args.registry))
    coverage = load_coverage(Path(args.coverage))
    junit_paths = [Path(value) for value in args.junit]
    exit_paths = [Path(value) for value in args.runner_exit_file]
    missing = [str(path) for path in junit_paths + exit_paths if not path.is_file()]
    if missing:
        raise FileNotFoundError(f"JUnit file(s) missing: {missing}")
    cases, junit_files = load_junit(junit_paths)
    runner_exits = load_runner_exits(exit_paths)
    report = build_report(registry, coverage, cases, junit_files, runner_exits, args)

    out = Path(args.out_dir)
    out.mkdir(parents=True, exist_ok=True)
    (out / "business-rules-evidence.json").write_text(
        json.dumps(report, ensure_ascii=False, indent=2) + "\n", encoding="utf-8"
    )
    write_markdown(report, out / "business-rules-evidence.md")
    write_junit(report, out / "business-rules-evidence.junit.xml")
    print(json.dumps(report["summary"], sort_keys=True))
    return 1 if report["summary"]["FAIL"] or report["testExecution"]["status"] in {"FAIL", "UNKNOWN"} else 0


if __name__ == "__main__":
    sys.exit(main())
