<?php

namespace Modules\Admin\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Admin\Services\Testing\StagingHarnessGuard;
use Modules\Admin\Services\Testing\StagingHarnessProvisioner;
use Throwable;

final class ProvisionStagingHarnessCommand extends Command
{
    protected $signature = 'asab:harness:provision-staging
        {--namespace= : Deterministic lowercase fixture namespace}
        {--apply : Apply fixture-only writes; omitted means read-only plan}
        {--ack= : Exact operator acknowledgement phrase}
        {--password-env= : Environment variable containing the runtime password}
        {--json : Emit the secret-free plan/manifest as JSON}';

    protected $description = 'Plan or provision the guarded ASSAB staging harness fixture (never runs migrations or cleanup).';

    public function handle(StagingHarnessGuard $guard, StagingHarnessProvisioner $provisioner): int
    {
        $appliedManifest = null;

        try {
            $connection = DB::connection();
            $environment = app()->environment();
            $driver = $connection->getDriverName();
            $database = (string) $connection->getDatabaseName();
            $allowed = (array) config('admin.harness.allowed_databases', []);
            $namespace = (string) ($this->option('namespace') ?: config('admin.harness.default_namespace'));

            $guard->assertTarget($environment, $driver, $database, $allowed);
            $server = DB::selectOne('SELECT VERSION() AS version, DATABASE() AS database');
            $actualDatabase = (string) ($server->database ?? '');
            $guard->assertTarget($environment, $driver, $actualDatabase, $allowed);
            if (! hash_equals($database, $actualDatabase)) {
                throw new \RuntimeException('Resolved and live database names differ; refusing the target.');
            }
            $serverVersion = (string) ($server->version ?? '');
            $guard->assertServerVersion($serverVersion);
            $plan = $provisioner->plan($namespace);

            if (! $this->option('apply')) {
                $this->emit($plan + [
                    'mode' => 'READ_ONLY_PLAN',
                    'target' => ['environment' => $environment, 'driver' => $driver, 'database' => $database],
                ]);
                if (! $this->option('json')) {
                    $this->warn('READ_ONLY_PLAN: no database rows or files were changed.');
                }

                return self::SUCCESS;
            }

            $guard->assertCanApply(
                (bool) config('admin.harness.enabled', false),
                $environment,
                $driver,
                $database,
                $allowed,
                is_string($this->option('ack')) ? $this->option('ack') : null,
            );

            $passwordEnv = (string) ($this->option('password-env') ?: config('admin.harness.password_env'));
            if (preg_match('/^[A-Z][A-Z0-9_]{2,63}$/', $passwordEnv) !== 1) {
                throw new \RuntimeException('The password environment-variable name is invalid.');
            }
            $password = getenv($passwordEnv);
            if (! is_string($password) || $password === '') {
                throw new \RuntimeException("Runtime password variable '{$passwordEnv}' is missing; no default credential exists.");
            }

            $path = 'assab-harness/'.$namespace.'/manifest.json';
            $appliedManifest = $provisioner->provision($namespace, $password) + [
                'mode' => 'APPLIED_FIXTURE_ONLY',
                'target' => ['environment' => $environment, 'driver' => $driver, 'database' => $database],
                'credentialSource' => ['environmentVariable' => $passwordEnv, 'valueIncluded' => false],
                'manifestPath' => $path,
            ];
            unset($password);

            $stored = Storage::disk('local')->put(
                $path,
                json_encode($appliedManifest, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL,
            );
            if (! $stored) {
                throw new \RuntimeException('Fixture rows were applied, but the local manifest could not be persisted; rerun is idempotent.');
            }

            $this->emit($appliedManifest);
            if (! $this->option('json')) {
                $this->info("Fixture manifest written to the local storage disk at {$path}; no cleanup was executed.");
            }

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $mode = $appliedManifest === null ? 'REFUSED' : 'APPLIED_WITH_EVIDENCE_ERROR';
            if ($this->option('json')) {
                $payload = [
                    'mode' => $mode,
                    'error' => ['message' => $exception->getMessage()],
                ];
                if ($appliedManifest !== null) {
                    $payload['appliedManifest'] = $appliedManifest;
                }
                $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            } else {
                $prefix = $appliedManifest === null
                    ? 'ASSAB staging harness refused: '
                    : 'ASSAB staging fixture was applied but evidence persistence failed: ';
                $this->error($prefix.$exception->getMessage());
            }

            return self::FAILURE;
        }
    }

    /** @param array<string, mixed> $payload */
    private function emit(array $payload): void
    {
        if ($this->option('json')) {
            $this->line(json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

            return;
        }

        $this->info('ASSAB staging harness');
        $this->line('  mode: '.($payload['mode'] ?? 'PLAN'));
        $this->line('  namespace: '.$payload['namespace']);
        $this->line('  definition sha256: '.$payload['definitionSha256']);
        $this->line('  accounts: '.(count($payload['accounts']) + 1).' (including legacy cashier)');
        $this->line('  plaintext credentials in output: no');
    }
}
