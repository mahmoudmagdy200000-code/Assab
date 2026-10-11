<?php

// Opt-in existing-schema upgrade probe for S1-11 Phases 5, 6, 7.
// Proves migration upgrade over existing populated schema without rebuild or manual repair.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$db = \Illuminate\Support\Facades\DB::connection();
if ($db->getDriverName() !== 'mysql'
    || ! preg_match('/^s111_phase567_upgrade[a-z0-9_]*$/', $db->getDatabaseName())
    || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
    throw new LogicException('Upgrade probe requires an owned disposable local Phase 5-7 upgrade schema.');
}

$mode = $argv[1] ?? '';
$directory = dirname(base_path()).'/.tools/s111-p567-mysql-upgrade';
$stateFile = $directory.'/upgrade-state.json';
$assert = function (bool $condition, string $message): void {
    if (! $condition) {
        throw new RuntimeException($message);
    }
};

// Exactly the 3 pending migrations introduced in Phases 5, 6, 7.
// 2026_10_10_000010_normalize_shift_report_correction_monetary_type is part of the CP1 baseline.
$phases567Migrations = [
    '2026_10_10_000006_create_shift_report_manager_reviews',
    '2026_10_10_000007_create_shift_report_correction_notifications',
    '2026_10_10_000008_create_shift_liability_posting_infrastructure',
];

$doPrepare = function () use ($db, $directory, $stateFile, $assert, $phases567Migrations) {
    echo "--- STAGE 1: Preparing baseline schema and seeding operational records ---\n";
    $assert(count($db->select('SHOW TABLES')) === 0, 'Baseline preparation requires an empty schema.');

    $baseline = $directory.'/baseline-migrations';
    if (! is_dir($baseline)) {
        mkdir($baseline, 0777, true);
    }
    foreach (glob($baseline.'/*.php') as $f) {
        unlink($f);
    }
    $migrator = app('migrator');
    $files = $migrator->getMigrationFiles(array_merge($migrator->paths(), [database_path('migrations')]));
    foreach ($files as $name => $path) {
        $isPhase567 = false;
        foreach ($phases567Migrations as $pMigration) {
            if (str_starts_with($name, $pMigration)) {
                $isPhase567 = true;
                break;
            }
        }
        if (! $isPhase567) {
            copy($path, $baseline.'/'.basename($path));
        }
    }
    echo "Migrating baseline CP1 schema...\n";
    $exit = \Illuminate\Support\Facades\Artisan::call('migrate', ['--path' => [$baseline], '--realpath' => true, '--force' => true]);
    $assert($exit === 0, 'Baseline migration failed: '.\Illuminate\Support\Facades\Artisan::output());

    // Assert Phase 5-7 tables and columns do NOT exist in the baseline
    $assert(! \Illuminate\Support\Facades\Schema::hasTable('shift_report_manager_reviews'), 'Baseline must not include Phase 5 manager reviews table.');
    $assert(! \Illuminate\Support\Facades\Schema::hasTable('shift_report_correction_notifications'), 'Baseline must not include Phase 6 notifications table.');
    $assert(! \Illuminate\Support\Facades\Schema::hasTable('shift_liability_postings'), 'Baseline must not include Phase 7 postings table.');
    $assert(! \Illuminate\Support\Facades\Schema::hasTable('shift_liability_posting_states'), 'Baseline must not include Phase 7 posting states table.');
    $assert(! \Illuminate\Support\Facades\Schema::hasTable('shift_liability_legacy_posting_links'), 'Baseline must not include Phase 7 legacy links table.');
    $assert(! \Illuminate\Support\Facades\Schema::hasColumn('shift_liability_shares', 'lineage_key'), 'Baseline must not include Phase 7 lineage_key column.');
    $assert(! \Illuminate\Support\Facades\Schema::hasColumn('shift_liability_allocations', 'supersedes_allocation_id'), 'Baseline must not include Phase 7 supersedes_allocation_id.');
    $assert(! \Illuminate\Support\Facades\Schema::hasColumn('shift_report_aggregates', 'required_manager_review_evidence_revision_id'), 'Baseline must not include Phase 5 required_manager_review column.');

    // Seed realistic operational data across company, brand, branch, manager, cashier, shifts, reports, and allocations
    $company = \Modules\Admin\Models\AsabCompany::create([
        'name' => 'Upgrade Test Company',
        'plan' => 'Professional',
        'status' => 'active',
    ]);
    $brand = \Modules\Admin\Models\AsabBrand::create([
        'company_id' => $company->id,
        'name' => 'Upgrade Test Brand',
        'abbr' => 'UTB',
        'sub_status' => 'active',
        'status' => 'active',
    ]);
    $branch = \Modules\Branch\Models\Branch::factory()->create([
        'asab_company_id' => $company->id,
        'asab_brand_id' => $brand->id,
    ]);
    $manager = \Modules\BranchManagers\Models\BranchManager::factory()->create(['branch_id' => $branch->id]);
    $cashier = \Modules\Cashier\Models\Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
    $template = \Modules\Shift\Models\Shift::factory()->create(['branch_id' => $branch->id]);

    $day = \Modules\Shift\Models\BranchManagerShift::create([
        'branch_manager_id' => $manager->id,
        'branch_id' => $branch->id,
        'shift_date' => today()->toDateString(),
        'status' => 'in_progress',
        'daily_report_submitted' => false,
    ]);

    $source = \Modules\Shift\Models\CashierShift::factory()->completed()->create([
        'cashier_id' => $cashier->id,
        'shift_id' => $template->id,
        'shift_date' => today(),
        'total_sales' => '100.00',
        'cash_collected' => '80.00',
        'card_payments' => '20.00',
        'net_sales' => '86.96',
        'vat_amount' => 13.04,
        'closing_balance' => '80.00',
    ]);

    $revision = $db->transaction(function () use ($source, $cashier) {
        $revision = app(\Modules\Shift\Services\ShiftReportRevisionService::class)->recordCashierRevision($source, 'cashier', $cashier->id, 0);
        app(\Modules\Shift\Services\ShiftCashCountService::class)->record($source, $revision, 10000, 2000, 0, 8000);

        return $revision;
    });

    $allocation = \Modules\Shift\Models\ShiftLiabilityAllocation::create([
        'company_id' => $company->id,
        'branch_id' => $branch->id,
        'cashier_shift_id' => $source->id,
        'version' => 1,
        'report_revision' => '1',
        'variance_halalas' => -1000,
        'created_by_type' => 'accountant',
        'created_by_id' => (string) \Illuminate\Support\Str::uuid(),
        'reason' => 'Baseline allocation before upgrade',
    ]);

    $share = $allocation->shares()->create([
        'responsible_type' => 'cashier',
        'responsible_id' => $cashier->id,
        'amount_halalas' => 1000,
        'employee_response_status' => 'pending',
    ]);

    \Modules\Shift\Models\CashierShiftHandover::create([
        'cashier_shift_id' => $source->id,
        'handover_to_type' => 'branch_manager',
        'handover_to_id' => $manager->id,
        'handover_amount' => '80.00',
        'status' => 'pending',
        'report_revision_id' => $revision->id,
        'handover_date' => today(),
        'handover_time' => now(),
    ]);

    $tables = [
        'cashier_shifts',
        'shift_report_aggregates',
        'shift_report_revisions',
        'shift_report_cash_counts',
        'shift_liability_allocations',
        'shift_liability_shares',
        'cashier_shift_handovers',
    ];
    $state = [
        'schema' => $db->getDatabaseName(),
        'rows' => [],
        'migrations' => $db->table('migrations')->count(),
    ];
    foreach ($tables as $table) {
        $state['rows'][$table] = $db->table($table)->orderBy('id')->get()->map(fn ($row) => (array) $row)->all();
    }
    file_put_contents($stateFile, json_encode($state, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT));
    echo "PRE_PHASES_567_BASELINE=PASS; existing report, revision, count, allocation, and handover committed.\n";
};

$doMigrate = function () use ($assert) {
    echo "--- STAGE 2: Applying pending S1-11 migrations via standard artisan migrate --force ---\n";
    $exit = \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
    $output = \Illuminate\Support\Facades\Artisan::output();
    echo $output;
    $assert($exit === 0, 'Standard migration upgrade failed: '.$output);
};

$doVerify = function () use ($db, $stateFile, $assert, $phases567Migrations) {
    echo "--- STAGE 3: Verifying post-upgrade integrity and non-destructive preservation ---\n";
    $state = json_decode(file_get_contents($stateFile), true, 512, JSON_THROW_ON_ERROR);
    $assert($state['schema'] === $db->getDatabaseName(), 'Baseline/schema mismatch.');
    foreach ($state['rows'] as $table => $rows) {
        $assert($db->table($table)->count() === count($rows), 'Unexpected row count in table: '.$table);
        foreach ($rows as $row) {
            $actual = (array) $db->table($table)->where('id', $row['id'])->sole();
            $assert(array_intersect_key($actual, $row) === $row, 'Existing data mutated during upgrade in table: '.$table);
        }
    }

    // Assert Phase 5-7 tables exist
    $assert(\Illuminate\Support\Facades\Schema::hasTable('shift_report_manager_reviews'), 'Phase 5 manager reviews table missing.');
    $assert(\Illuminate\Support\Facades\Schema::hasTable('shift_report_correction_notifications'), 'Phase 6 notifications table missing.');
    $assert(\Illuminate\Support\Facades\Schema::hasTable('shift_liability_postings'), 'Phase 7 postings table missing.');
    $assert(\Illuminate\Support\Facades\Schema::hasTable('shift_liability_posting_states'), 'Phase 7 posting states table missing.');
    $assert(\Illuminate\Support\Facades\Schema::hasTable('shift_liability_legacy_posting_links'), 'Phase 7 legacy links table missing.');

    // Assert Phase 5-7 columns exist
    $assert(\Illuminate\Support\Facades\Schema::hasColumn('shift_liability_shares', 'lineage_key'), 'Phase 7 lineage_key column missing on shift_liability_shares.');
    $assert(\Illuminate\Support\Facades\Schema::hasColumn('shift_liability_allocations', 'supersedes_allocation_id'), 'Phase 7 supersedes_allocation_id column missing on shift_liability_allocations.');
    $assert(\Illuminate\Support\Facades\Schema::hasColumn('shift_report_aggregates', 'required_manager_review_evidence_revision_id'), 'Phase 5 required_manager_review column missing on shift_report_aggregates.');

    $pendingCount = count($phases567Migrations);
    $appliedCount = $db->table('migrations')->count() - $state['migrations'];
    $assert($appliedCount === $pendingCount, "Expected exactly {$pendingCount} pending S1-11 migrations applied, found {$appliedCount}.");

    echo "EXISTING_SCHEMA_UPGRADE=PASS; exactly {$pendingCount} pending S1-11 migrations applied; all existing IDs, money, evidence and allocations preserved without rebuild.\n";
};

if ($mode === 'prepare') {
    $doPrepare();
} elseif ($mode === 'migrate') {
    $doMigrate();
} elseif ($mode === 'verify') {
    $doVerify();
} elseif ($mode === 'all') {
    $doPrepare();
    $doMigrate();
    $doVerify();
} else {
    throw new InvalidArgumentException('Usage: php ShiftPhases567UpgradeProbe.php prepare | migrate | verify | all');
}
