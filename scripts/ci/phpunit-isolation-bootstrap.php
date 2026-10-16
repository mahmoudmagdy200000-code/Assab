<?php

declare(strict_types=1);

/*
 * ASSAB BRC v3.4 isolated regression CI -- Layer 3 isolation guard (PHPUnit).
 *
 * Used as the PHPUnit bootstrap of the CI-generated configuration. Runs in the
 * very process that executes the tests, after phpunit.xml <env> values have
 * been applied and before any test class is loaded. If the process is not
 * pointed at the in-memory SQLite database the test run aborts immediately.
 */

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

foreach (['DB_HOST', 'DB_URL', 'DATABASE_URL', 'DB_USERNAME', 'DB_PASSWORD'] as $name) {
    $actual = getenv($name);
    if ($actual !== false && $actual !== '') {
        fwrite(STDERR, "ASSAB CI ISOLATION BOOTSTRAP: {$name} must be empty/unset in the SQLite gate. Aborting before any test runs.\n");
        exit(1);
    }
}

require dirname(__DIR__, 2).'/vendor/autoload.php';
