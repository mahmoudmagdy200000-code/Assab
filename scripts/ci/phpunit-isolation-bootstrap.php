<?php

declare(strict_types=1);

/*
 * ASSAB BRC v3.4 isolated regression CI -- Layer 3 isolation guard (PHPUnit).
 *
 * Used as the PHPUnit bootstrap of the CI-generated configuration. Runs in the
 * very process that executes the tests, after phpunit.xml <env> values have
 * been applied and before any test class is loaded. If the process is not
 * pointed at the in-memory SQLite database the test run aborts immediately.
 *
 * Two kinds of process run this file:
 *
 *  1. The top-level PHPUnit / ParaTest-worker process. The job environment was
 *     already proven clean by scripts/ci/assert_isolated_env.sh, so any
 *     DB_HOST / DB_USERNAME / DB_PASSWORD / DB_URL here is an error and aborts.
 *
 *  2. A separate-process child that PHPUnit spawns for a test annotated with
 *     #[RunInSeparateProcess] (tests/Feature/IdempotencyCommitRecoveryTest.php).
 *     The parent worker has by then booted Laravel, whose Dotenv loader copied
 *     the committed `.env.testing` values (DB_HOST=127.0.0.1, DB_USERNAME, ...)
 *     into the process environment with putenv(); the child inherits them.
 *     Those values are inert because DB_CONNECTION=sqlite and
 *     DB_DATABASE=:memory: are already set (Dotenv never overrides an existing
 *     variable), but they must not trip the strict check of case 1.
 *
 * The child is recognised by ASSAB_CI_ISOLATION_VERIFIED=1, which this file
 * itself exports only after the strict top-level check passed (the shell guard
 * refuses to start if the variable is pre-set in the job environment). In a
 * verified child the invariants that decide which database is used are still
 * enforced; inherited endpoint values are tolerated only when the host is a
 * loopback address, and URL-style DSNs are never tolerated.
 */

const ASSAB_CI_MARKER = 'ASSAB_CI_ISOLATION_VERIFIED';

$expected = [
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => ':memory:',
];

foreach ($expected as $name => $value) {
    $actual = getenv($name);
    if ($actual !== $value) {
        fwrite(STDERR, "ASSAB CI ISOLATION BOOTSTRAP: {$name} must be '{$value}' but is '".($actual === false ? 'unset' : $actual)."'. Aborting before any test runs.\n");
        exit(1);
    }
}

$verifiedChild = getenv(ASSAB_CI_MARKER) === '1';

// DSN-style variables are never acceptable, in either kind of process.
foreach (['DB_URL', 'DATABASE_URL'] as $name) {
    $actual = getenv($name);
    if ($actual !== false && $actual !== '') {
        fwrite(STDERR, "ASSAB CI ISOLATION BOOTSTRAP: {$name} must be empty/unset in the SQLite gate. Aborting before any test runs.\n");
        exit(1);
    }
}

foreach (['DB_HOST', 'DB_USERNAME', 'DB_PASSWORD'] as $name) {
    $actual = getenv($name);
    if ($actual === false || $actual === '') {
        continue;
    }
    if (! $verifiedChild) {
        fwrite(STDERR, "ASSAB CI ISOLATION BOOTSTRAP: {$name} must be empty/unset in the SQLite gate. Aborting before any test runs.\n");
        exit(1);
    }
    if ($name === 'DB_HOST' && ! in_array(strtolower($actual), ['127.0.0.1', 'localhost', '::1'], true)) {
        fwrite(STDERR, "ASSAB CI ISOLATION BOOTSTRAP: inherited DB_HOST is not a loopback address. Aborting before any test runs.\n");
        exit(1);
    }
}

putenv(ASSAB_CI_MARKER.'=1');
$_ENV[ASSAB_CI_MARKER] = '1';
$_SERVER[ASSAB_CI_MARKER] = '1';

require dirname(__DIR__, 2).'/vendor/autoload.php';
