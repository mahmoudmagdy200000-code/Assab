<?php

namespace Tests\Support\Harness;

use Illuminate\Support\Str;
use Modules\Admin\Database\Seeders\AsabRolePermissionSeeder;
use Modules\Admin\Services\Testing\StagingHarnessProvisioner;

/** Builds the same identities as Mode B, but only inside a disposable test DB. */
final class AssabHarnessFixtureBuilder
{
    public function build(string $namespace = 'assab-harness-ci'): AssabHarnessFixture
    {
        // Isolated databases need the canonical role catalogue. Mode B never
        // runs this seeder and instead refuses when canonical roles are missing.
        (new AsabRolePermissionSeeder())->run();

        $runtimePassword = 'H!'.Str::random(38);
        $manifest = app(StagingHarnessProvisioner::class)->provision($namespace, $runtimePassword);

        return new AssabHarnessFixture($manifest, $runtimePassword);
    }
}
