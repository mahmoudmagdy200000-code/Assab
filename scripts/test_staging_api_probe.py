"""Offline contract tests. No live network calls, databases, or mutations."""
import http.client
import json
import os
import tempfile
import unittest
from pathlib import Path
from unittest.mock import MagicMock, patch

import staging_api_probe as probe


class ProbeFixture(unittest.TestCase):
    def run_main(self, side_effect):
        with tempfile.TemporaryDirectory() as tmp:
            old = os.getcwd()
            out = Path(tmp) / 'gh_output'
            try:
                os.chdir(tmp)
                with patch.dict(os.environ, {'GITHUB_OUTPUT': str(out), 'GITHUB_STEP_SUMMARY': str(Path(tmp) / 'sum')}):
                    with patch.object(probe, 'probe', side_effect=side_effect):
                        code = probe.main()
                data = json.loads(Path('artifacts/api-probe.json').read_text())
                return code, data, out.read_text()
            finally:
                os.chdir(old)


class StageProbeTests(ProbeFixture):
    def test_url_allowlist_rejects_other_hosts_before_network(self):
        for url in ('http://127.0.0.1', f'http://user:pw@{probe.HOST}', f'http://{probe.HOST}:8080',
                    f'http://{probe.HOST}/x', f'ftp://{probe.HOST}', f'http://{probe.HOST}/?q=1'):
            with patch.object(probe, 'BASE_URL', url):
                with patch('http.client.HTTPConnection') as conn, patch('http.client.HTTPSConnection') as sconn:
                    with self.assertRaisesRegex(ValueError, 'Unsafe staging URL'):
                        probe.probe('/up')
                    conn.assert_not_called()
                    sconn.assert_not_called()

    def test_probe_sends_get_only_and_never_reads_body(self):
        response = MagicMock(status=401)
        connection = MagicMock()
        connection.getresponse.return_value = response
        with patch.object(probe, 'BASE_URL', f'http://{probe.HOST}'), \
                patch('http.client.HTTPConnection', return_value=connection):
            self.assertEqual(probe.probe('/api/aggregators', True), (401, None))
            headers = connection.request.call_args.kwargs['headers']
            self.assertEqual(connection.request.call_args.args, ('GET', '/api/aggregators'))
            self.assertEqual(headers['Accept'], 'application/json')
            self.assertFalse({'Authorization', 'Cookie'} & set(headers))
            response.read.assert_not_called()
            probe.probe('/api/aggregators', False)
            self.assertNotIn('Accept', connection.request.call_args.kwargs['headers'])

    def test_network_errors_are_reduced_to_class_names(self):
        connection = MagicMock()
        connection.request.side_effect = TimeoutError('secret detail 10.0.0.1')
        with patch.object(probe, 'BASE_URL', f'http://{probe.HOST}'), \
                patch('http.client.HTTPConnection', return_value=connection):
            self.assertEqual(probe.probe('/up'), (None, 'TimeoutError'))
        connection.request.side_effect = http.client.RemoteDisconnected('x')
        with patch.object(probe, 'BASE_URL', f'http://{probe.HOST}'), \
                patch('http.client.HTTPConnection', return_value=connection):
            self.assertEqual(probe.probe('/up'), (None, 'RemoteDisconnected'))

    def test_pass_contract_and_no_body_in_artifacts(self):
        code, data, out = self.run_main([(200, None), (401, None), (200, None), (500, None)])
        self.assertEqual(code, 0)
        self.assertEqual(data['overall'], 'PASS')
        self.assertTrue(data['passed'])
        self.assertNotIn('body', json.dumps(data))
        self.assertIn('overall=PASS', out)

    def test_informational_control_never_changes_verdict(self):
        for control in ((500, None), (302, None), (None, 'TimeoutError'), (401, None)):
            code, data, _ = self.run_main([(200, None), (403, None), (200, None), control])
            self.assertEqual((code, data['overall']), (0, 'PASS'))
        self.assertTrue(data['checks'][3]['informational'])
        self.assertEqual(data['checks'][3]['request_profile'], 'no-accept-header')

    def test_500_is_failure(self):
        code, data, out = self.run_main([(200, None), (500, None), (200, None), (500, None)])
        self.assertEqual((code, data['overall'], data['passed']), (1, 'FAIL', False))
        self.assertIn('overall=FAIL', out)

    def test_200_on_protected_route_is_failure(self):
        code, data, _ = self.run_main([(200, None), (200, None), (200, None), (200, None)])
        self.assertEqual((code, data['overall']), (1, 'FAIL'))

    def test_unreachable_host_is_blocked_not_fail(self):
        code, data, out = self.run_main([(None, 'TimeoutError')] * 4)
        self.assertEqual((code, data['overall']), (2, 'BLOCKED'))
        self.assertIn('overall=BLOCKED', out)

    def test_fail_dominates_blocked(self):
        code, data, _ = self.run_main([(None, 'TimeoutError'), (500, None), (200, None), (500, None)])
        self.assertEqual((code, data['overall']), (1, 'FAIL'))

    def test_url_validation_error_is_blocked(self):
        with patch.object(probe, 'BASE_URL', 'http://127.0.0.1'):
            with tempfile.TemporaryDirectory() as tmp:
                old = os.getcwd()
                try:
                    os.chdir(tmp)
                    with patch.dict(os.environ, {'GITHUB_OUTPUT': os.devnull}):
                        self.assertEqual(probe.main(), 2)
                    data = json.loads(Path('artifacts/api-probe.json').read_text())
                    self.assertEqual(data['checks'][0]['error_type'], 'URL_VALIDATION_ERROR')
                finally:
                    os.chdir(old)

    def test_endpoint_contract_is_the_approved_one(self):
        verdict = [(p, e) for p, e, _l, _j, info in probe.CHECKS if not info]
        self.assertEqual(verdict, [('/up', [200]), ('/api/aggregators', [401, 403]),
                                   ('/api/aggregators/available', [200])])


if __name__ == '__main__':
    unittest.main()
