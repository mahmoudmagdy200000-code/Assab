#!/usr/bin/env python3
"""Generate the PHPUnit configuration used by the ASSAB BRC v3.4 isolated CI.

PHPUnit 12 accepts a single <path> argument, so a focused file list is expressed
as a generated <testsuite>. The generated file is derived from the repository's
own phpunit.xml (so env/<source> settings cannot drift) and then:

  * replaces ``bootstrap`` with scripts/ci/phpunit-isolation-bootstrap.php
    (Layer-3 isolation guard, which loads vendor/autoload.php afterwards);
  * marks the database/target <env> entries as ``force="true"`` so that no
    outside value can redirect the test process;
  * disables colours (clean logs);
  * for ``fast`` mode only: replaces the <testsuites> block with one suite that
    lists the files from scripts/ci/brc34_fast_suite.txt. Missing files abort.

It never touches tracked files: the output path is an untracked, generated file.
"""
from __future__ import annotations

import argparse
import sys
import xml.etree.ElementTree as ET
from pathlib import Path

XSI = "http://www.w3.org/2001/XMLSchema-instance"
FORCED_ENV = {
    "APP_ENV": "testing",
    "DB_CONNECTION": "sqlite",
    "DB_DATABASE": ":memory:",
    "CACHE_STORE": "array",
    "SESSION_DRIVER": "array",
    "QUEUE_CONNECTION": "sync",
    "MAIL_MAILER": "array",
}
BOOTSTRAP = "scripts/ci/phpunit-isolation-bootstrap.php"


def read_list(path: Path) -> list[str]:
    files: list[str] = []
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#"):
            continue
        if line.startswith("/") or ".." in Path(line).parts:
            raise SystemExit(f"invalid path in {path}: {line!r}")
        files.append(line)
    if len(files) != len(set(files)):
        raise SystemExit(f"duplicate entries in {path}")
    return files


def build(mode: str, repo: Path, listing: Path | None) -> ET.ElementTree:
    ET.register_namespace("xsi", XSI)
    tree = ET.parse(repo / "phpunit.xml")
    root = tree.getroot()
    root.set("bootstrap", BOOTSTRAP)
    root.set("colors", "false")

    php = root.find("php")
    if php is None:
        raise SystemExit("phpunit.xml has no <php> block")
    seen = set()
    for env in php.findall("env"):
        name = env.get("name")
        if name in FORCED_ENV:
            if env.get("value") != FORCED_ENV[name]:
                raise SystemExit(f"phpunit.xml sets {name}={env.get('value')!r}, expected {FORCED_ENV[name]!r}")
            env.set("force", "true")
            seen.add(name)
    for name, value in FORCED_ENV.items():
        if name not in seen:
            e = ET.SubElement(php, "env")
            e.set("name", name)
            e.set("value", value)
            e.set("force", "true")

    if mode == "fast":
        if listing is None:
            raise SystemExit("--list is required in fast mode")
        files = read_list(listing)
        missing = [f for f in files if not (repo / f).is_file()]
        if missing:
            raise SystemExit("FAST list references missing files:\n  " + "\n  ".join(missing))
        suites = root.find("testsuites")
        if suites is None:
            raise SystemExit("phpunit.xml has no <testsuites>")
        for child in list(suites):
            suites.remove(child)
        suite = ET.SubElement(suites, "testsuite")
        suite.set("name", "BRC34-FAST")
        for f in files:
            ET.SubElement(suite, "file").text = f
    elif mode == "full":
        names = [s.get("name") for s in root.findall("./testsuites/testsuite")]
        if names != ["Unit", "Feature", "NFR"]:
            raise SystemExit(f"unexpected repository suites {names!r}; refusing to guess the full-suite definition")
    else:
        raise SystemExit(f"unknown mode {mode!r}")

    ET.indent(tree, space="    ")
    return tree


def main(argv: list[str] | None = None) -> int:
    ap = argparse.ArgumentParser()
    ap.add_argument("mode", choices=["fast", "full"])
    ap.add_argument("--repo", default=".")
    ap.add_argument("--list", dest="listing")
    ap.add_argument("--out", required=True)
    args = ap.parse_args(argv)
    repo = Path(args.repo).resolve()
    tree = build(args.mode, repo, Path(args.listing) if args.listing else None)
    out = Path(args.out)
    tree.write(out, encoding="utf-8", xml_declaration=True)
    suites = tree.getroot().findall("./testsuites/testsuite")
    nfiles = sum(len(s.findall("file")) for s in suites)
    print(f"generated {out} mode={args.mode} suites={[s.get('name') for s in suites]} files={nfiles}")
    return 0


if __name__ == "__main__":
    sys.exit(main())
