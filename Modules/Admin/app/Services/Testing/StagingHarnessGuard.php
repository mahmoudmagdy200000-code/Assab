<?php

namespace Modules\Admin\Services\Testing;

use RuntimeException;

final class StagingHarnessGuard
{
    public const ACKNOWLEDGEMENT = 'PROVISION_ASSAB_STAGING_HARNESS';

    /**
     * Validate the target before even a dry-run plan is shown.
     *
     * @param  string[]  $allowedDatabases
     */
    public function assertTarget(
        string $environment,
        string $driver,
        string $database,
        array $allowedDatabases,
    ): void {
        if (strtolower($environment) === 'production') {
            throw new RuntimeException('Refusing to run the ASSAB fixture harness in production.');
        }

        if ($environment !== 'staging') {
            throw new RuntimeException("Refusing target environment '{$environment}'; exact APP_ENV=staging is required.");
        }

        if ($driver !== 'mysql') {
            throw new RuntimeException("Refusing database driver '{$driver}'; controlled staging fixtures require MySQL.");
        }

        $allowed = array_values(array_unique(array_filter(array_map(
            static fn (mixed $value): string => trim((string) $value),
            $allowedDatabases,
        ))));

        if ($allowed === []) {
            throw new RuntimeException('Refusing an empty ASSAB staging database allowlist.');
        }

        if (preg_match('/(^|[_-])prod(uction)?([_-]|$)/i', $database) === 1) {
            throw new RuntimeException("Refusing production-like database name '{$database}'.");
        }

        if (! in_array($database, $allowed, true)) {
            throw new RuntimeException("Database '{$database}' is not in the exact ASSAB staging allowlist.");
        }
    }

    /**
     * Validate every mutation gate. This is deliberately separate from
     * assertTarget() so the default command mode can remain read-only.
     *
     * @param  string[]  $allowedDatabases
     */
    public function assertCanApply(
        bool $enabled,
        string $environment,
        string $driver,
        string $database,
        array $allowedDatabases,
        ?string $acknowledgement,
    ): void {
        $this->assertTarget($environment, $driver, $database, $allowedDatabases);

        if (! $enabled) {
            throw new RuntimeException('Staging fixture writes are disabled; ASSAB_STAGING_HARNESS_ENABLED must be explicitly true.');
        }

        if (! hash_equals(self::ACKNOWLEDGEMENT, (string) $acknowledgement)) {
            throw new RuntimeException('The exact staging fixture acknowledgement phrase is required.');
        }
    }

    public function assertServerVersion(string $version): void
    {
        if (preg_match('/\A8\.4\.[0-9]+/', $version) !== 1 || stripos($version, 'MariaDB') !== false) {
            throw new RuntimeException('Refusing database server version; exact MySQL 8.4.x is required.');
        }
    }
}
