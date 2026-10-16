"""Offline tests for the AI output guard. Uses throw-away local git repositories only."""
import os
import subprocess
import tempfile
import unittest
from pathlib import Path

import ai_output_guard as guard


def run(repo, *args):
    env = dict(os.environ, GIT_CONFIG_GLOBAL=os.devnull, GIT_CONFIG_SYSTEM=os.devnull)
    return subprocess.run(['git', '-C', str(repo), *args], check=True, capture_output=True, env=env,
                          text=True).stdout.strip()


class GuardTests(unittest.TestCase):
    def setUp(self):
        self._tmp = tempfile.TemporaryDirectory()
        self.repo = Path(self._tmp.name)
        run(self.repo, 'init', '-q', '-b', 'main')
        run(self.repo, 'config', 'user.email', 't@example.invalid')
        run(self.repo, 'config', 'user.name', 't')
        self.write('app/Http/Controllers/ThingController.php', '<?php\nreturn 1;\n')
        self.write('Modules/Aggregator/app/Http/Controllers/AggregatorController.php', '<?php\nreturn 1;\n')
        self.write('composer.json', '{}\n')
        run(self.repo, 'add', '-A')
        run(self.repo, 'commit', '-q', '-m', 'base')
        self.base = run(self.repo, 'rev-parse', 'HEAD')

    def tearDown(self):
        self._tmp.cleanup()

    def write(self, rel, text, mode=None):
        p = self.repo / rel
        p.parent.mkdir(parents=True, exist_ok=True)
        p.write_text(text)
        if mode:
            p.chmod(mode)

    def evaluate(self, **kw):
        return guard.evaluate(self.repo, self.base, **kw)

    def test_no_diff(self):
        self.assertEqual(self.evaluate()[0], 'NO_DIFF')

    def test_ok_for_small_neutral_php_change_and_new_test(self):
        self.write('app/Http/Controllers/ThingController.php', '<?php\nreturn 2;\n')
        self.write('tests/Feature/ThingTest.php', '<?php\n// ok\n')
        result, details, patch = self.evaluate()
        self.assertEqual(result, 'OK', details)
        self.assertIn(b'ThingTest.php', patch)  # new file captured via intent-to-add

    def test_aggregator_is_not_mistaken_for_gate(self):
        self.write('Modules/Aggregator/app/Http/Controllers/AggregatorController.php', '<?php\nreturn 3;\n')
        self.assertEqual(self.evaluate()[0], 'OK')

    def test_rejects_files_outside_scope_and_wrong_type(self):
        for rel, body in [('composer.json', '{"a":1}\n'), ('bootstrap/app.php', '<?php\n'),
                          ('app/notes.txt', 'x\n'), ('config/app.php', '<?php\n'),
                          ('Modules/X/database/migrations/2026_create.php', '<?php\n'),
                          ('database/seeders/S.php', '<?php\n'), ('app/Foo Bar.php', '<?php\n'),
                          ('.github/workflows/x.yml', 'on: push\n'), ('app/MigrationHelper.php', '<?php\n')]:
            with self.subTest(rel=rel):
                run(self.repo, 'checkout', '-q', '--', '.')
                run(self.repo, 'clean', '-fdq')
                self.write(rel, body)
                self.assertEqual(self.evaluate()[0], 'REJECTED')

    def test_env_files_are_excluded_from_the_patch(self):
        self.write('.env.testing', 'APP_KEY=x\n')
        self.write('Modules/X/.env', 'A=1\n')
        run(self.repo, 'add', '-A')
        run(self.repo, 'commit', '-q', '-m', 'env')
        self.base = run(self.repo, 'rev-parse', 'HEAD')
        (self.repo / '.env.testing').unlink()          # what the workflow does before the AI runs
        (self.repo / 'Modules/X/.env').unlink()
        self.assertEqual(self.evaluate()[0], 'NO_DIFF')
        self.write('.env.new', 'SECRET=1\n')
        self.write('app/Http/Controllers/ThingController.php', '<?php\nreturn 5;\n')
        result, details, patch = self.evaluate()
        self.assertEqual(result, 'OK', details)
        self.assertNotIn(b'.env', patch)

    def test_rejects_deletion(self):
        (self.repo / 'app/Http/Controllers/ThingController.php').unlink()
        self.assertEqual(self.evaluate()[0], 'REJECTED')

    def test_rejects_symlink_and_exec_bit(self):
        os.symlink('/etc/passwd', self.repo / 'app/Link.php')
        self.assertEqual(self.evaluate()[0], 'REJECTED')
        run(self.repo, 'rm', '-q', '--cached', '-f', 'app/Link.php')
        (self.repo / 'app/Link.php').unlink()
        self.write('app/Exec.php', '<?php\n', mode=0o755)
        self.assertEqual(self.evaluate()[0], 'REJECTED')

    def test_rejects_binary_and_too_many_files_and_big_patch(self):
        (self.repo / 'app').mkdir(exist_ok=True)
        (self.repo / 'app/Blob.php').write_bytes(b'\x00\x01\x02' * 50)
        self.assertEqual(self.evaluate()[0], 'REJECTED')
        os.remove(self.repo / 'app/Blob.php')
        for n in range(7):
            self.write(f'app/F{n}.php', '<?php\n')
        self.assertEqual(self.evaluate()[0], 'REJECTED')
        run(self.repo, 'rm', '-q', '--cached', '-r', '-f', 'app')
        run(self.repo, 'checkout', '-q', '--', '.')
        run(self.repo, 'clean', '-fdq')
        self.write('app/Big.php', '<?php\n' + '// x\n' * 40)
        self.assertEqual(self.evaluate(max_bytes=100)[0], 'REJECTED')

    def test_rejects_whitespace_errors(self):
        self.write('app/Http/Controllers/ThingController.php', '<?php\nreturn 2;   \n')
        self.assertEqual(self.evaluate()[0], 'REJECTED')

    def test_rejects_base_mismatch_and_unsafe_git_config(self):
        self.write('app/A.php', '<?php\n')
        self.assertEqual(guard.evaluate(self.repo, '0' * 40)[0], 'REJECTED')
        run(self.repo, 'config', 'filter.evil.clean', 'cat')
        self.assertEqual(self.evaluate()[0], 'REJECTED')

    def test_sensitive_paths_need_human_review(self):
        for rel in ('app/Http/Middleware/Foo.php', 'app/Policies/ThingPolicy.php',
                    'Modules/Cashier/app/Services/CashHandoverService.php',
                    'app/Services/TenantResolver.php', 'app/Models/LedgerEntry.php',
                    'app/Services/IdempotencyKeyStore.php'):
            with self.subTest(rel=rel):
                run(self.repo, 'clean', '-fdq')
                self.write(rel, '<?php\n')
                result, details, _ = self.evaluate()
                self.assertEqual(result, 'NEEDS_HUMAN_REVIEW', details)
                run(self.repo, 'rm', '-q', '--cached', '-f', rel)

    def test_sensitive_content_needs_human_review(self):
        self.write('app/Http/Controllers/ThingController.php',
                   '<?php\n$this->authorize("x");\n')
        self.assertEqual(self.evaluate()[0], 'NEEDS_HUMAN_REVIEW')

    def test_context_lines_do_not_trigger_content_check(self):
        base = '<?php\n// uses middleware and ledger in comment\n' + 'a;\n' * 5 + 'return 1;\n'
        self.write('app/Ctx.php', base)
        run(self.repo, 'add', '-A')
        run(self.repo, 'commit', '-q', '-m', 'ctx')
        self.base = run(self.repo, 'rev-parse', 'HEAD')
        self.write('app/Ctx.php', base.replace('return 1;', 'return 2;'))
        self.assertEqual(self.evaluate()[0], 'OK')

    def test_cli_writes_outputs(self):
        self.write('app/Http/Controllers/ThingController.php', '<?php\nreturn 9;\n')
        out = self.repo.parent / (self.repo.name + '_out')
        gh = self.repo.parent / (self.repo.name + '_gh')
        os.environ['GITHUB_OUTPUT'] = str(gh)
        try:
            guard.main(['patch-guard', '--repo', str(self.repo), '--base-sha', self.base, '--out', str(out)])
        finally:
            del os.environ['GITHUB_OUTPUT']
        self.assertIn('result=OK', gh.read_text())
        self.assertTrue((out / 'fix.patch').read_bytes().startswith(b'diff --git'))


class RedactionTests(unittest.TestCase):
    SAMPLES = ['sk-' + 'a' * 24, 'ghp_' + 'A1' * 15, 'AKIA' + 'B' * 16, 'Bearer ' + 'x' * 30,
               'APP_KEY=base64:' + 'Zm9v' * 10, 'DB_PASSWORD=hunter2',
               'mysql://root:pa55w0rd@db.internal', '-----BEGIN PRIVATE KEY-----\nabc\n-----END PRIVATE KEY-----']

    def test_masks_known_secret_shapes(self):
        for sample in self.SAMPLES:
            with self.subTest(sample=sample[:12]):
                self.assertTrue(guard.has_secret(sample))
                out = guard.redact(f'prefix {sample} suffix')
                self.assertIn('[REDACTED]', out)
                self.assertNotIn('hunter2', out)
                self.assertNotIn('pa55w0rd', out)

    def test_plain_text_untouched_and_truncated(self):
        self.assertEqual(guard.redact('Route model binding fails at line 12.'),
                         'Route model binding fails at line 12.')
        self.assertFalse(guard.has_secret('Route model binding fails at line 12.'))
        self.assertEqual(len(guard.redact('x' * 50000).encode()), 12000)


if __name__ == '__main__':
    unittest.main()
