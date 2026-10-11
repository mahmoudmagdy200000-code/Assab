<?php

// Opt-in existing-schema upgrade probe for S1-11 Phases 8, 9, 10.
// Proves migration upgrade over existing populated schema without rebuild or manual repair.
require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

try {
    $db = \Illuminate\Support\Facades\DB::connection();
    if ($db->getDriverName() !== 'mysql'
        || ! preg_match('/^s111_phase(567_phase)?8910_upgrade[a-z0-9_]*$/', $db->getDatabaseName())
        || ! in_array($db->getConfig('host'), ['127.0.0.1', 'localhost'], true)) {
        throw new LogicException('Upgrade probe requires an owned disposable local Phase 8-10 upgrade schema.');
    }

    $mode = $argv[1] ?? '';
    $directory = dirname(base_path()).'/.tools/s111-p8910-mysql-upgrade/'.$db->getDatabaseName();
    $stateFile = $directory.'/upgrade-state.json';
    $assert = function (bool $condition, string $message): void {
        if (! $condition) {
            throw new RuntimeException($message);
        }
    };

    $phases8910Migrations = [
        '2026_10_11_000001_create_shift_handover_settlements',
    ];

    $doPrepare = function () use ($db, $directory, $stateFile, $assert, $phases8910Migrations) {
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
            $isPhase8910 = false;
            foreach ($phases8910Migrations as $pMigration) {
                if (str_starts_with($name, $pMigration)) {
                    $isPhase8910 = true;
                    break;
                }
            }
            if (! $isPhase8910) {
                copy($path, $baseline.'/'.basename($path));
            }
        }
        echo "Migrating baseline schema (pre-Phase 8/9/10)...\n";
        $exit = \Illuminate\Support\Facades\Artisan::call('migrate', ['--path' => [$baseline], '--realpath' => true, '--force' => true]);
        $assert($exit === 0, 'Baseline migration failed: '.\Illuminate\Support\Facades\Artisan::output());

        // Assert Phase 9 tables and columns do NOT exist in the baseline
        $assert(! \Illuminate\Support\Facades\Schema::hasTable('shift_handover_settlements'), 'Baseline must not include shift_handover_settlements table.');
        $assert(! \Illuminate\Support\Facades\Schema::hasColumn('cashier_custody_transactions', 'settlement_id'), 'Baseline must not include custody settlement_id column.');
        $assert(! \Illuminate\Support\Facades\Schema::hasColumn('personal_ledger_transactions', 'settlement_id'), 'Baseline must not include personal ledger settlement_id column.');

        // Seed realistic operational data
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
            'status' => 'completed',
            'daily_report_submitted' => false,
        ]);

        $cashierShift = \Modules\Shift\Models\CashierShift::factory()->completed()->create([
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

        $revision = \Illuminate\Support\Facades\DB::transaction(function () use ($cashierShift, $cashier) {
            $rev = app(\Modules\Shift\Services\ShiftReportRevisionService::class)->recordCashierRevision($cashierShift, 'cashier', $cashier->id, 0);
            app(\Modules\Shift\Services\ShiftCashCountService::class)->record($cashierShift, $rev, 10000, 2000, 0, 8000);

            return $rev;
        });

        $handover = \Modules\Shift\Models\CashierShiftHandover::create([
            'cashier_shift_id' => $cashierShift->id,
            'shift_id' => $template->id,
            'branch_id' => $branch->id,
            'handover_type' => 'manager',
            'handover_to_type' => 'branch_manager',
            'handover_to_id' => $manager->id,
            'handover_amount' => '80.00',
            'declared_amount' => '80.00',
            'status' => 'approved',
            'handover_date' => today(),
            'handover_time' => now(),
            'report_revision_id' => $revision->id,
        ]);

        $receipt = \Modules\Shift\Models\CashierShiftHandoverReceipt::create([
            'cashier_shift_handover_id' => $handover->id,
            'receiving_branch_manager_shift_id' => $day->id,
            'receiving_branch_manager_id' => $manager->id,
            'confirmed_by_branch_manager_id' => $manager->id,
            'report_revision_id' => $revision->id,
            'confirmed_amount' => '100.00',
            'confirmed_at' => now(),
        ]);

        \Modules\Custody\Models\PersonalLedgerTransaction::create([
            'branch_manager_id' => $manager->id, 'transaction_type' => 'Total Sales',
            'amount' => '100.00', 'is_cash_in' => true, 'category' => 'shift_handover',
            'receipt_id' => $receipt->id, 'related_shift_id' => $cashierShift->id,
            'related_handover_id' => $handover->id, 'transaction_date' => now(),
        ]);
        $state = [
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'manager_id' => $manager->id,
            'cashier_id' => $cashier->id,
            'cashier_shift_id' => $cashierShift->id,
            'day_id' => $day->id,
            'receipt_id' => $receipt->id,
            'receipt_confirmed_amount' => '100.00',
        ];
        file_put_contents($stateFile, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));
        echo "Baseline preparation complete. State captured in {$stateFile}.\n";
    };

    $doMigrate = function () use ($assert) {
        echo "--- STAGE 2: Running Phase 8-10 migrations on existing populated schema ---\n";
        $exit = \Illuminate\Support\Facades\Artisan::call('migrate', ['--force' => true]);
        $assert($exit === 0, 'Migration failed: '.\Illuminate\Support\Facades\Artisan::output());
        echo "Migration completed successfully.\n";
    };

    $doVerify = function () use ($stateFile, $assert) {
        echo "--- STAGE 3: Verifying existing schema preservation and post-upgrade mutations ---\n";
        $state = json_decode(file_get_contents($stateFile), true, 512, JSON_THROW_ON_ERROR);

        // Verify schema additions
        $assert(\Illuminate\Support\Facades\Schema::hasTable('shift_handover_settlements'), 'shift_handover_settlements table must exist post-upgrade.');
        $assert(\Illuminate\Support\Facades\Schema::hasColumn('cashier_custody_transactions', 'settlement_id'), 'settlement_id column in custody transactions must exist post-upgrade.');
        $assert(\Illuminate\Support\Facades\Schema::hasColumn('personal_ledger_transactions', 'settlement_id'), 'settlement_id column in personal ledger must exist post-upgrade.');

        // Verify baseline record integrity
        $receipt = \Modules\Shift\Models\CashierShiftHandoverReceipt::findOrFail($state['receipt_id']);
        $assert((string) $receipt->confirmed_amount === $state['receipt_confirmed_amount'], 'Confirmed receipt amount must remain strictly unchanged.');

        $manager = \Modules\BranchManagers\Models\BranchManager::findOrFail($state['manager_id']);

        // Execute real settlement over upgraded schema
        // Disposable test simulation only; no production settlement authority is granted.
        config(['shift.settlement_authority_approved' => true]);
        $service = app(\Modules\Shift\Services\HandoverSettlementService::class);
        $settlement = $service->settle(
            $receipt,
            $manager,
            'overpayment_return',
            2000,
            0,
            'Post-upgrade probe settlement verification',
            (string) \Illuminate\Support\Str::uuid()
        );

        $assert($settlement instanceof \Modules\Shift\Models\ShiftHandoverSettlement, 'Settlement record must be created successfully.');
        $assert($settlement->signed_delta_halalas === -2000, 'Settlement signed delta must match -2000 halalas.');

        $custodyCount = \Modules\Custody\Models\CashierCustodyTransaction::where('settlement_id', $settlement->id)->count();
        $ledgerCount = \Modules\Custody\Models\PersonalLedgerTransaction::where('settlement_id', $settlement->id)->count();

        $assert($custodyCount === 1, 'Exactly one counterpart custody transaction must exist for settlement.');
        $assert($ledgerCount === 1, 'Exactly one counterpart personal ledger transaction must exist for settlement.');
        $assert((string) $receipt->fresh()->confirmed_amount === $state['receipt_confirmed_amount'], 'Original receipt changed after adjustment.');

        echo "Post-upgrade verification PASSED! Schema, records, and new capabilities proven on MySQL.\n";
    };

    switch ($mode) {
        case 'prepare':
            $doPrepare();
            break;
        case 'migrate':
            $doMigrate();
            break;
        case 'verify':
            $doVerify();
            break;
        default:
            fwrite(STDERR, "Usage: php ShiftPhases8910UpgradeProbe.php [prepare|migrate|verify]\n");
            exit(1);
    }
} catch (\Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage().PHP_EOL);
    exit(1);
}
