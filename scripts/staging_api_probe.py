#!/usr/bin/env python3
"""GET-only HTTP smoke probe for the single approved ASSAB Staging host.

Safety properties (enforced by code and covered by offline tests):
  * GET only; never sends credentials, cookies or a request body.
  * Only the pinned Staging hostname is reachable (see ``probe``).
  * Never follows redirects; never reads or stores a response body.
  * Persists only HTTP status codes and exception class names.

Result classes (per check and overall):
  PASS     every expected status observed.
  FAIL     a response was received but its status was not expected.
  BLOCKED  no HTTP response could be obtained (DNS, TCP, TLS, timeout, bad URL),
           so the endpoint contract could not be evaluated.
An ``informational`` check records a status for diagnosis only and never
influences the overall result.

Exit codes: 0 PASS, 1 FAIL, 2 BLOCKED.
"""
import http.client
import json
import os
from pathlib import Path
from urllib.parse import urlsplit

# UNVERIFIED by the automation author: the operator must confirm this is the
# Staging host before the first live run (see the runbook).
HOST = 'szkrjqiuo9s6d6nsy5fldc0j.62.72.19.141.sslip.io'
BASE_URL = os.getenv('STAGING_BASE_URL', f'http://{HOST}')
TIMEOUT_SECONDS = 15

# (path, expected statuses, purpose, send Accept: application/json?, informational?)
CHECKS = [
    ('/up', [200], 'Laravel health route', True, False),
    ('/api/aggregators', [401, 403],
     'Sanctum-protected resource, anonymous JSON client', True, False),
    ('/api/aggregators/available', [200], 'Public read-only collection', True, False),
    # Hypothesis control, never part of the verdict. Laravel 12.33 answers an
    # unauthenticated NON-JSON request by redirecting to route('login'); this
    # application defines no route named 'login', which would surface as HTTP 500.
    ('/api/aggregators', [401, 403],
     'Control: same route, anonymous client WITHOUT Accept header', False, True),
]


def probe(path, send_json_accept=True):
    parsed = urlsplit(BASE_URL)
    if (parsed.scheme not in {'http', 'https'} or parsed.hostname != HOST
            or parsed.username or parsed.password or parsed.path not in {'', '/'}
            or parsed.query or parsed.fragment or parsed.port not in {None, 80, 443}):
        raise ValueError('Unsafe staging URL; only the pinned staging hostname is allowed')
    connection_type = http.client.HTTPSConnection if parsed.scheme == 'https' else http.client.HTTPConnection
    connection = connection_type(parsed.hostname, port=parsed.port, timeout=TIMEOUT_SECONDS)
    headers = {'User-Agent': 'assab-staging-readonly-health/2.0', 'Cache-Control': 'no-cache'}
    if send_json_accept:
        headers['Accept'] = 'application/json'
    try:
        connection.request('GET', path, headers=headers)
        response = connection.getresponse()
        # Never read or persist the potentially sensitive response body.
        return response.status, None
    except (OSError, TimeoutError, http.client.HTTPException) as exc:
        # Don't persist exception messages: DNS, proxies or URLs can leak data.
        return None, exc.__class__.__name__
    finally:
        connection.close()


def classify(status, error, expected):
    if error is not None or status is None:
        return 'BLOCKED'
    return 'PASS' if status in expected else 'FAIL'


def overall_result(checks):
    verdict = [c['result'] for c in checks if not c['informational']]
    if 'FAIL' in verdict:
        return 'FAIL'
    if 'BLOCKED' in verdict:
        return 'BLOCKED'
    return 'PASS'


def main():
    data = {'schema_version': 2, 'environment': 'staging', 'mode': 'GET-only', 'checks': []}
    for path, expected, label, send_json, informational in CHECKS:
        try:
            status, error = probe(path, send_json)
        except ValueError:
            status, error = None, 'URL_VALIDATION_ERROR'
        result = classify(status, error, expected)
        data['checks'].append({
            'path': path, 'purpose': label, 'expected_http': expected,
            'actual_http': status, 'error_type': error,
            'request_profile': 'accept-json' if send_json else 'no-accept-header',
            'informational': informational, 'result': result})
        shown = status if status is not None else error
        kind = 'INFO ' if informational else ''
        print(f'{kind}{result}: GET {path} [{"json" if send_json else "no-accept"}] -> {shown} (expected {expected})')
    data['overall'] = overall_result(data['checks'])
    data['passed'] = data['overall'] == 'PASS'

    target = Path('artifacts')
    target.mkdir(exist_ok=True)
    (target / 'api-probe.json').write_text(json.dumps(data, indent=2) + '\n', encoding='utf8')
    rows = ['# ASSAB Staging API HTTP Smoke', '',
            'GET requests only. No credentials, response bodies, or redirects were stored.', '',
            '| Route | Request profile | Expected HTTP | Actual HTTP | Result |', '|---|---|---|---|---|']
    for item in data['checks']:
        actual = item['actual_http'] if item['actual_http'] is not None else item['error_type']
        result = f"INFO ({item['result']})" if item['informational'] else item['result']
        rows.append(f"| `{item['path']}` | {item['request_profile']} | "
                    f"{', '.join(map(str, item['expected_http']))} | {actual} | {result} |")
    rows += ['', f"**Overall:** {data['overall']}", '',
             'A failing status is not a confirmed root cause. Runtime stack traces remain a separate, redacted evidence requirement.', '']
    (target / 'api-probe.md').write_text('\n'.join(rows), encoding='utf8')
    with open(os.environ.get('GITHUB_STEP_SUMMARY', os.devnull), 'a', encoding='utf8') as summary:
        summary.write('\n'.join(rows) + '\n')
    with open(os.environ.get('GITHUB_OUTPUT', os.devnull), 'a', encoding='utf8') as out:
        out.write(f"overall={data['overall']}\n")
    return {'PASS': 0, 'FAIL': 1, 'BLOCKED': 2}[data['overall']]


if __name__ == '__main__':
    raise SystemExit(main())
