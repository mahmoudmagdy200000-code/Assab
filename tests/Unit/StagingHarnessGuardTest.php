<?php

namespace Tests\Unit;

use Modules\Admin\Services\Testing\StagingHarnessGuard;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class StagingHarnessGuardTest extends TestCase
{
    private StagingHarnessGuard $guard;

    protected function setUp(): void
    {
        parent::setUp();
        $this->guard = new StagingHarnessGuard();
    }

    public function test_exact_staging_mysql_allowlist_target_is_accepted(): void
    {
        $this->guard->assertCanApply(
            true,
            'staging',
            'mysql',
            'assab_staging',
            ['assab_staging'],
            StagingHarnessGuard::ACKNOWLEDGEMENT,
        );

        $this->addToAssertionCount(1);
    }

    public function test_production_is_always_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('production');

        $this->guard->assertCanApply(true, 'production', 'mysql', 'assab_staging', ['assab_staging'], StagingHarnessGuard::ACKNOWLEDGEMENT);
    }

    public function test_non_staging_environment_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('APP_ENV=staging');

        $this->guard->assertCanApply(true, 'testing', 'mysql', 'assab_staging', ['assab_staging'], StagingHarnessGuard::ACKNOWLEDGEMENT);
    }

    public function test_non_mysql_driver_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('require MySQL');

        $this->guard->assertCanApply(true, 'staging', 'sqlite', 'assab_staging', ['assab_staging'], StagingHarnessGuard::ACKNOWLEDGEMENT);
    }

    public function test_database_must_match_exact_allowlist_entry(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('not in the exact');

        $this->guard->assertCanApply(true, 'staging', 'mysql', 'assab_staging_copy', ['assab_staging'], StagingHarnessGuard::ACKNOWLEDGEMENT);
    }

    public function test_database_allowlist_must_be_explicit_and_non_empty(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('empty');

        $this->guard->assertCanApply(true, 'staging', 'mysql', 'assab_staging', [], StagingHarnessGuard::ACKNOWLEDGEMENT);
    }

    public function test_production_like_database_name_is_refused_even_if_allowlisted(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('production-like');

        $this->guard->assertCanApply(true, 'staging', 'mysql', 'assab_prod', ['assab_prod'], StagingHarnessGuard::ACKNOWLEDGEMENT);
    }

    public function test_disabled_switch_is_refused(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('disabled');

        $this->guard->assertCanApply(false, 'staging', 'mysql', 'assab_staging', ['assab_staging'], StagingHarnessGuard::ACKNOWLEDGEMENT);
    }

    public function test_acknowledgement_must_match_exactly(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('acknowledgement');

        $this->guard->assertCanApply(true, 'staging', 'mysql', 'assab_staging', ['assab_staging'], 'yes');
    }

    public function test_exact_mysql_84_server_is_accepted(): void
    {
        $this->guard->assertServerVersion('8.4.6');

        $this->addToAssertionCount(1);
    }

    public function test_other_mysql_or_mariadb_versions_are_refused(): void
    {
        foreach (['8.0.42', '9.0.1', '8.4.3-MariaDB'] as $version) {
            try {
                $this->guard->assertServerVersion($version);
                $this->fail("{$version} must be refused.");
            } catch (RuntimeException $exception) {
                $this->assertStringContainsString('MySQL 8.4', $exception->getMessage());
            }
        }
    }
}
