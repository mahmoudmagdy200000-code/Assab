<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use LogicException;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Modules\Shift\Models\ShiftLiabilityPosting;
use Modules\Shift\Models\ShiftLiabilityPostingState;
use Tests\TestCase;

class ShiftLiabilityPostingSchemaTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branch;

    private BranchManager $manager;

    private Cashier $cashier;

    private CashierShift $cashierShift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Schema Test Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'B', 'sub_status' => 'active', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['asab_brand_id' => $brand->id, 'asab_company_id' => $this->company->id]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier = Cashier::factory()->create(['branch_id' => $this->branch->id]);

        $shift = Shift::create([
            'branch_id' => $this->branch->id,
            'name' => 'Morning',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);

        $this->cashierShift = CashierShift::create([
            'cashier_id' => $this->cashier->id,
            'shift_id' => $shift->id,
            'branch_id' => $this->branch->id,
            'shift_date' => today(),
            'status' => 'completed',
        ]);
    }

    public function test_migration_000008_creates_required_tables_and_columns(): void
    {
        // Tables
        $this->assertTrue(Schema::hasTable('shift_liability_posting_states'));
        $this->assertTrue(Schema::hasTable('shift_liability_postings'));
        $this->assertTrue(Schema::hasTable('shift_liability_legacy_posting_links'));

        // Allocation and share columns
        $this->assertTrue(Schema::hasColumn('shift_liability_allocations', 'supersedes_allocation_id'));
        $this->assertTrue(Schema::hasColumn('shift_liability_shares', 'lineage_key'));

        // Ledger metadata columns
        $this->assertTrue(Schema::hasColumn('asab_employee_movements', 'lineage_key'));
        $this->assertTrue(Schema::hasColumn('asab_employee_movements', 'posting_id'));

        $this->assertTrue(Schema::hasColumn('cashier_custody_transactions', 'category'));
        $this->assertTrue(Schema::hasColumn('cashier_custody_transactions', 'lineage_key'));
        $this->assertTrue(Schema::hasColumn('cashier_custody_transactions', 'posting_id'));

        $this->assertTrue(Schema::hasColumn('personal_ledger_transactions', 'category'));
        $this->assertTrue(Schema::hasColumn('personal_ledger_transactions', 'lineage_key'));
        $this->assertTrue(Schema::hasColumn('personal_ledger_transactions', 'posting_id'));
    }

    public function test_lineage_key_is_automatically_populated_and_consistent(): void
    {
        $alloc1 = ShiftLiabilityAllocation::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_shift_id' => $this->cashierShift->id,
            'version' => 1,
            'report_revision' => '1',
            'variance_halalas' => -10000,
            'created_by_type' => 'manager',
            'created_by_id' => $this->manager->id,
            'manager_approval_status' => 'pending',
        ]);

        $share1 = $alloc1->shares()->create([
            'responsible_type' => 'cashier',
            'responsible_id' => $this->cashier->id,
            'amount_halalas' => 10000,
        ]);

        $expectedLineage = "{$this->cashierShift->id}:cashier:{$this->cashier->id}";
        $this->assertSame($expectedLineage, $share1->fresh()->lineage_key);

        // Version 2 supersedes version 1
        $alloc2 = ShiftLiabilityAllocation::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_shift_id' => $this->cashierShift->id,
            'version' => 2,
            'report_revision' => '2',
            'variance_halalas' => -12000,
            'created_by_type' => 'manager',
            'created_by_id' => $this->manager->id,
            'manager_approval_status' => 'pending',
            'supersedes_allocation_id' => $alloc1->id,
        ]);

        $share2 = $alloc2->shares()->create([
            'responsible_type' => 'cashier',
            'responsible_id' => $this->cashier->id,
            'amount_halalas' => 12000,
        ]);

        // Same responsible party in same shift has identical lineage key
        $this->assertSame($expectedLineage, $share2->fresh()->lineage_key);
        $this->assertSame($alloc1->id, $alloc2->supersedes->id);
    }

    public function test_shift_liability_posting_state_enforces_unique_lineage_key(): void
    {
        $lineageKey = "{$this->cashierShift->id}:cashier:{$this->cashier->id}";

        ShiftLiabilityPostingState::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_shift_id' => $this->cashierShift->id,
            'responsible_type' => 'cashier',
            'responsible_id' => $this->cashier->id,
            'lineage_key' => $lineageKey,
            'last_approved_target_halalas' => 10000,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        ShiftLiabilityPostingState::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_shift_id' => $this->cashierShift->id,
            'responsible_type' => 'cashier',
            'responsible_id' => $this->cashier->id,
            'lineage_key' => $lineageKey,
            'last_approved_target_halalas' => 12000,
        ]);
    }

    public function test_migration_down_refuses_rollback_if_financial_postings_exist(): void
    {
        ShiftLiabilityPosting::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_shift_id' => $this->cashierShift->id,
            'lineage_key' => "{$this->cashierShift->id}:cashier:{$this->cashier->id}",
            'responsible_type' => 'cashier',
            'responsible_id' => $this->cashier->id,
            'delta_halalas' => 10000,
            'target_halalas' => 10000,
            'effective_before_halalas' => 0,
            'operation_id' => 'OP-TEST-001',
            'actor_type' => 'branch_manager',
            'actor_id' => $this->manager->id,
            'ledger_table' => 'cashier_custody_transactions',
            'ledger_id' => (string) \Illuminate\Support\Str::uuid(),
            'status' => 'posted',
        ]);

        $migration = require database_path('../Modules/Shift/database/migrations/2026_10_10_000008_create_shift_liability_posting_infrastructure.php');

        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('CANNOT_ROLLBACK_POSTED_LIABILITIES_WITH_FINANCIAL_HISTORY');

        $migration->down();
    }
}
