<?php

// Opt-in existing-schema upgrade probe. Never rebuilds or repairs the schema during upgrade.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$db = \Illuminate\Support\Facades\DB::connection();
if ($db->getDriverName() !== 'mysql'
    || ! preg_match('/^s111_phase234_audit_cp1_upgrade[a-z0-9_]+$/', $db->getDatabaseName())
    || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
    throw new LogicException('Upgrade probe requires an owned disposable local CP1 upgrade schema.');
}
$mode = $argv[1] ?? '';
$directory = dirname(base_path()).'/.tools/s111-cp1-mysql-20261010';
$stateFile = $directory.'/upgrade-state.json';
$assert = function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};
if ($mode === 'prepare') {
    $assert(count(\Illuminate\Support\Facades\Schema::getTableListing()) === 0, 'Baseline preparation requires an empty NEW schema.');
    $baseline = $directory.'/baseline-migrations';
    if (! is_dir($baseline)) {
        mkdir($baseline, 0777, true);
    }
    $migrator = app('migrator');
    $files = $migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')]));
    foreach ($files as $name => $path) {
        if (! str_starts_with($name, '2026_10_10_')) {
            copy($path, $baseline.'/'.basename($path));
        }
    }
    $exit = \Illuminate\Support\Facades\Artisan::call('migrate', ['--path' => [$baseline], '--realpath' => true, '--force' => true]);
    echo \Illuminate\Support\Facades\Artisan::output();
    $assert($exit === 0, 'Baseline migration failed.');
    $assert(! \Illuminate\Support\Facades\Schema::hasTable('shift_report_corrections'), 'Baseline includes Phase 3 corrections.');
    $assert(! \Illuminate\Support\Facades\Schema::hasColumn('cashier_shift_handovers', 'cancelled_at'), 'Baseline includes Phase 2 lifecycle.');
    $assert(! \Illuminate\Support\Facades\Schema::hasColumn('shift_liability_daily_locks', 'superseded_at'), 'Baseline includes Phase 4 locks.');
    $branch = \Modules\Branch\Models\Branch::factory()->create();
    $manager = \Modules\BranchManagers\Models\BranchManager::factory()->create(['branch_id' => $branch->id]);
    $cashier = \Modules\Cashier\Models\Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
    $recipient = \Modules\Cashier\Models\Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
    $template = \Modules\Shift\Models\Shift::factory()->create(['branch_id' => $branch->id]);
    $source = \Modules\Shift\Models\CashierShift::factory()->completed()->create([
        'cashier_id' => $cashier->id, 'shift_id' => $template->id, 'shift_date' => today(),
        'total_sales' => '100.00', 'cash_collected' => '100.00', 'card_payments' => '0.00',
        'net_sales' => '86.96', 'vat_amount' => '13.04', 'closing_balance' => '100.00',
    ]);
    $revision = $db->transaction(function () use ($source) {
        $revision = app(\Modules\Shift\Services\ShiftReportRevisionService::class)->recordCashierRevision($source, 'cashier', $source->cashier_id, 0);
        // Seed the OLD schema directly: the new writer requires the Phase 3 evidence column.
        \Illuminate\Support\Facades\DB::table('shift_report_cash_counts')->insert([
            'id' => (string) \Illuminate\Support\Str::uuid(), 'report_revision_id' => $revision->id,
            'counted_revision_id' => $revision->id, 'cashier_shift_id' => $source->id,
            'gross_halalas' => 10000, 'cards_halalas' => 0, 'apps_halalas' => 0,
            'confirmed_opening_halalas' => 0, 'pending_incoming_counted_halalas' => 0,
            'counted_halalas' => 10000, 'expected_halalas' => 10000, 'variance_halalas' => 0,
            'created_at' => now(),
        ]);

        return $revision;
    });
    \Modules\Shift\Models\CashierShiftHandover::create([
        'cashier_shift_id' => $source->id, 'handover_to_type' => 'cashier', 'handover_to_id' => $recipient->id,
        'handover_amount' => '100.00', 'status' => 'rejected', 'report_revision_id' => $revision->id,
        'handover_date' => today(), 'handover_time' => now(), 'rejection_reason' => 'Upgrade sentinel',
    ]);
    $tables = ['cashier_shifts', 'cashier_shift_handovers', 'shift_report_aggregates', 'shift_report_revisions', 'shift_report_cash_counts'];
    $state = ['schema' => $db->getDatabaseName(), 'rows' => [], 'migrations' => $db->table('migrations')->count()];
    foreach ($tables as $table) {
        $state['rows'][$table] = $db->table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }
    file_put_contents($stateFile, json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    echo "PRE_PHASES_234_BASELINE=PASS; existing report, revision, count and rejected request committed.\n";
} elseif ($mode === 'verify') {
    $state = json_decode(file_get_contents($stateFile), true, 512, JSON_THROW_ON_ERROR);
    $assert($state['schema'] === $db->getDatabaseName(), 'Baseline/schema mismatch.');
    foreach ($state['rows'] as $table => $rows) {
        $assert($db->table($table)->count() === count($rows), 'Unexpected row count: '.$table);
        foreach ($rows as $row) {
            $actual = (array) $db->table($table)->where('id', $row['id'])->sole();
            $assert(array_intersect_key($actual, $row) === $row, 'Existing data changed: '.$table);
        }
    }
    $assert(\Illuminate\Support\Facades\Schema::hasTable('shift_report_corrections'), 'Correction table missing.');
    $assert(\Illuminate\Support\Facades\Schema::hasTable('shift_report_revision_snapshots'), 'Snapshot table missing.');
    $assert(\Illuminate\Support\Facades\Schema::hasColumn('cashier_shift_handovers', 'cancelled_at'), 'Lifecycle column missing.');
    $assert(\Illuminate\Support\Facades\Schema::hasColumn('shift_liability_daily_locks', 'superseded_at'), 'Lock supersession missing.');
    $assert($db->table('migrations')->count() === $state['migrations'] + 6, 'Expected exactly six pending S1-11 migrations.');
    echo "EXISTING_SCHEMA_UPGRADE=PASS; six pending migrations; all existing IDs, money and evidence preserved.\n";
} else {
    throw new InvalidArgumentException('Use prepare on a new schema, then normal artisan migrate, then verify.');
}
