#!/usr/bin/env python3
"""Trusted guards for AI-produced output in the ASSAB Staging automation.

This file is always executed from a *trusted* checkout of the workflow ref,
never from the workspace that the AI was allowed to touch.

Subcommands
  redact       stdin -> stdout, masks secret-looking strings, truncates.
  has-secret   exit 1 if stdin contains something secret-looking.
  patch-guard  validates the AI's working-tree changes in --repo against HEAD
               and writes fix.patch / changed-paths.txt / guard-result.json.

patch-guard result classes (written to $GITHUB_OUTPUT as ``result=``)
  OK                   in-scope, safe-looking, ready for human review.
  NO_DIFF              the AI produced no change.
  NEEDS_HUMAN_REVIEW   touches authorization, tenant isolation, ledger, cash
                       custody, idempotency or transaction territory: no PR.
  REJECTED             outside the approved path/shape/size policy.
The process exits 0 for all four classes; non-zero means the guard itself failed.
"""
import argparse
import json
import os
import re
import subprocess
import sys
from pathlib import Path

ALLOWED_ROOTS = ('app/', 'Modules/', 'routes/', 'tests/')
MAX_FILES = 6
MAX_PATCH_BYTES = 80000
SAFE_PATH = re.compile(r'^[A-Za-z0-9_./-]+$')
DENIED_SEGMENTS = {'database', 'migrations', 'seeders', 'config', 'vendor', 'node_modules',
                   'storage', 'public', 'bootstrap', '.git', '.github'}
DENIED_NAME = re.compile(r'(migration|seeder)', re.I)
# Tokens matched against camelCase/snake/path segments, so "Aggregator" does not hit "gate".
SENSITIVE_PATH_TOKENS = {
    'auth', 'authenticate', 'authentication', 'authorization', 'authorize', 'middleware',
    'policy', 'policies', 'gate', 'tenant', 'tenants', 'sanctum', 'permission', 'permissions',
    'role', 'roles', 'guard', 'guards', 'shift', 'shifts', 'cash', 'cashier', 'custody',
    'handover', 'ledger', 'finance', 'financial', 'payment', 'payments', 'money',
    'idempotency', 'idempotent', 'transaction', 'transactions', 'expense', 'expenses',
    'settlement', 'revenue', 'vat', 'refund', 'payroll', 'salary', 'wallet', 'balance',
    'invoice', 'shortage', 'surplus', 'halala', 'commission', 'admin'}
SENSITIVE_CONTENT = re.compile(
    r'\b(authoriz\w*|authenticat\w*|policy|policies|gate::|middleware|sanctum|tenant\w*|'
    r'company_id|permission\w*|ledger|custody|cash\w*|handover|shortage|surplus|idempoten\w*|'
    r'lockforupdate|db::transaction|begintransaction|halala\w*|vat|commission|refund|payroll)\b', re.I)
# Environment files are never part of a patch: the workflows delete them from the AI
# workspace so the model cannot read them, which would otherwise look like a deletion.
PATHSPEC = ['--', '.', ':(exclude,glob).env', ':(exclude,glob).env.*',
            ':(exclude,glob)**/.env', ':(exclude,glob)**/.env.*']
UNSAFE_LOCAL_CONFIG = re.compile(
    r'^(filter\.|diff\.|alias\.|core\.(fsmonitor|hookspath|sshcommand|pager|editor|attributesfile)|include)', re.I)

SECRET_PATTERNS = [
    re.compile(r'-----BEGIN [A-Z ]*PRIVATE KEY-----.*?(-----END [A-Z ]*PRIVATE KEY-----|\Z)', re.S),
    re.compile(r'\bsk-[A-Za-z0-9_-]{16,}'),
    re.compile(r'\bgh[pousr]_[A-Za-z0-9]{20,}'),
    re.compile(r'\bgithub_pat_[A-Za-z0-9_]{20,}'),
    re.compile(r'\bAKIA[0-9A-Z]{16}\b'),
    re.compile(r'\bBearer\s+[A-Za-z0-9._~+/=-]{16,}', re.I),
    re.compile(r'base64:[A-Za-z0-9+/=]{20,}'),
    re.compile(r'(?i)(password|passwd|secret|token|api[_-]?key|app_key|authorization)[A-Za-z0-9_]*\s*[=:]\s*[^\s,;]+'),
    re.compile(r'\b[A-Za-z0-9._%+-]+:[^\s:@/]{3,}@[A-Za-z0-9.-]+'),
]


def redact(text, max_bytes=12000):
    for pattern in SECRET_PATTERNS:
        text = pattern.sub('[REDACTED]', text)
    return text.encode('utf8')[:max_bytes].decode('utf8', 'ignore')


def has_secret(text):
    return any(p.search(text) for p in SECRET_PATTERNS)


def tokens(path):
    found = set()
    for part in re.split(r'[^A-Za-z0-9]+', path):
        for token in re.findall(r'[A-Z]?[a-z]+|[A-Z]+(?![a-z])|\d+', part):
            found.add(token.lower())
    return found


def git(repo, *args, check=True):
    env = dict(os.environ, GIT_CONFIG_GLOBAL=os.devnull, GIT_CONFIG_SYSTEM=os.devnull,
               GIT_TERMINAL_PROMPT='0')
    result = subprocess.run(
        ['git', '--no-pager', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false',
         '-C', str(repo), *args],
        capture_output=True, env=env, check=False)
    if check and result.returncode != 0:
        raise RuntimeError(f'git {args[0]} failed: {result.stderr.decode("utf8", "replace")[:200]}')
    return result


def evaluate(repo, base_sha, max_files=MAX_FILES, max_bytes=MAX_PATCH_BYTES):
    """Return (result, details dict, patch bytes)."""
    details = {'violations': [], 'sensitive_reasons': [], 'files': []}

    def reject(reason):
        details['violations'].append(reason)
        return 'REJECTED', details, b''

    head = git(repo, 'rev-parse', 'HEAD').stdout.decode().strip()
    if head != base_sha:
        return reject('HEAD does not equal the verified base SHA')
    local_cfg = git(repo, 'config', '--local', '--list', check=False).stdout.decode('utf8', 'replace')
    for line in local_cfg.splitlines():
        if UNSAFE_LOCAL_CONFIG.match(line.split('=', 1)[0]):
            return reject('unsafe local git configuration present')

    git(repo, 'add', '--intent-to-add', *PATHSPEC)
    raw = git(repo, 'diff', '--raw', '-z', '--no-renames', '--no-ext-diff', '--no-textconv', 'HEAD', *PATHSPEC).stdout
    entries, parts, i = [], raw.split(b'\0'), 0
    while i < len(parts) and parts[i]:
        meta = parts[i].decode('utf8', 'replace').lstrip(':').split()
        path = parts[i + 1].decode('utf8', 'replace')
        entries.append((meta[0], meta[1], meta[4][:1], path))
        i += 2
    if not entries:
        return 'NO_DIFF', details, b''
    if len(entries) > max_files:
        return reject(f'more than {max_files} files changed')

    for old_mode, new_mode, status, path in entries:
        details['files'].append(path)
        if status not in {'A', 'M'}:
            return reject(f'unsupported change type {status} (deletion/rename/type change)')
        if new_mode != '100644' or (status == 'M' and old_mode != '100644'):
            return reject(f'non-regular or executable file mode for {path}')
        if not SAFE_PATH.match(path) or '..' in path.split('/') or path.startswith('/'):
            return reject(f'unsafe path characters: {path!r}')
        if not path.startswith(ALLOWED_ROOTS) or not path.endswith('.php'):
            return reject(f'path outside approved application PHP scope: {path}')
        lowered = [s.lower() for s in path.split('/')]
        if DENIED_SEGMENTS & set(lowered) or DENIED_NAME.search(path):
            return reject(f'path in a denied area (database/config/infrastructure): {path}')
        hit = tokens(path) & SENSITIVE_PATH_TOKENS
        if hit:
            details['sensitive_reasons'].append(f'{path}: {",".join(sorted(hit))}')

    numstat = git(repo, 'diff', '--numstat', '-z', '--no-renames', '--no-ext-diff', '--no-textconv', 'HEAD', *PATHSPEC).stdout
    if any(rec.startswith(b'-\t-\t') for rec in numstat.split(b'\0') if rec):
        return reject('binary change rejected')
    if git(repo, 'diff', '--check', 'HEAD', *PATHSPEC, check=False).returncode != 0:
        return reject('git diff --check found whitespace/conflict-marker errors')
    patch = git(repo, 'diff', '--binary', '--no-renames', '--no-ext-diff', '--no-textconv', 'HEAD', *PATHSPEC).stdout
    if not patch or len(patch) > max_bytes:
        return reject('patch empty or larger than the size limit')

    for line in patch.decode('utf8', 'replace').splitlines():
        if line[:1] in {'+', '-'} and not line.startswith(('+++', '---')):
            match = SENSITIVE_CONTENT.search(line)
            if match:
                details['sensitive_reasons'].append(f'changed line mentions "{match.group(0).lower()}"')
    if details['sensitive_reasons']:
        details['sensitive_reasons'] = sorted(set(details['sensitive_reasons']))
        return 'NEEDS_HUMAN_REVIEW', details, patch
    return 'OK', details, patch


def cmd_patch_guard(args):
    result, details, patch = evaluate(Path(args.repo), args.base_sha, args.max_files, args.max_bytes)
    out = Path(args.out)
    out.mkdir(parents=True, exist_ok=True)
    (out / 'changed-paths.txt').write_text('\n'.join(details['files']) + ('\n' if details['files'] else ''))
    (out / 'guard-result.json').write_text(json.dumps({'result': result, **details}, indent=2) + '\n')
    if patch:
        (out / 'fix.patch').write_bytes(patch)
    print(f'patch-guard: {result}')
    for reason in details['violations'] + details['sensitive_reasons']:
        print(f'  - {reason}')
    with open(os.environ.get('GITHUB_OUTPUT', os.devnull), 'a', encoding='utf8') as handle:
        handle.write(f'result={result}\n')
    return 0


def main(argv=None):
    parser = argparse.ArgumentParser(description=__doc__, formatter_class=argparse.RawDescriptionHelpFormatter)
    sub = parser.add_subparsers(dest='command', required=True)
    r = sub.add_parser('redact')
    r.add_argument('--max-bytes', type=int, default=12000)
    sub.add_parser('has-secret')
    g = sub.add_parser('patch-guard')
    g.add_argument('--repo', required=True)
    g.add_argument('--base-sha', required=True)
    g.add_argument('--out', required=True)
    g.add_argument('--max-files', type=int, default=MAX_FILES)
    g.add_argument('--max-bytes', type=int, default=MAX_PATCH_BYTES)
    args = parser.parse_args(argv)
    if args.command == 'redact':
        sys.stdout.write(redact(sys.stdin.read(), args.max_bytes))
        return 0
    if args.command == 'has-secret':
        return 1 if has_secret(sys.stdin.read()) else 0
    return cmd_patch_guard(args)


if __name__ == '__main__':
    raise SystemExit(main())
