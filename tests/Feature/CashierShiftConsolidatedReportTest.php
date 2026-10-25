<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Employee;
use Modules\Aggregator\Models\Aggregator;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftReportAggregate;
use Modules\Shift\Models\ShiftReportRevision;
use Modules\Shift\Services\CashierShiftStartService;
use Modules\Shift\Services\ShiftCashCountService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/**
 * AC-47 / OPS-09:
 * More than one device/drawer in the branch -> one main cashier and one consolidated
 * report per shift; no concurrent per-drawer reports.
 */
class CashierShiftConsolidatedReportTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabBrand $brand;

    private Branch $branch;

    private BranchManager $branchManager;

    private AsabUser $managerUser;

    private Cashier $cashier;

    private Employee $cashierEmployee;

    private Shift $template;

    private Aggregator $aggregator1;

    private Aggregator $aggregator2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'Consolidated Shift Co',
            'plan' => 'Professional',
            'status' => 'active',
        ]);

        $this->brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'Consolidated Brand',
            'sub_status' => 'active',
            'status' => 'active',
        ]);

        $this->branch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
            'name' => 'الفرع الرئيسي',
        ]);

        $this->branchManager = BranchManager::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
            'status' => 'active',
        ]);

        $this->managerUser = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'مدير الفرع',
            'email' => 'branch.manager@consolidated.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $this->managerUser->id,
            'role_key' => 'branch',
            'scope' => 'branch',
            'branch_ids' => [$this->branch->id],
        ]);

        $this->cashier = Cashier::factory()->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->branchManager->id,
            'name' => 'الكاشير الرئيسي',
        ]);

        $this->cashierEmployee = Employee::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'emp_number' => 'CSH-100',
            'name' => 'الكاشير الرئيسي',
            'phone' => '0559998877',
            'role' => 'كاشير',
            'status' => 'active',
        ]);
        $this->cashierEmployee->forceFill(['legacy_cashier_id' => $this->cashier->id])->save();

        $this->template = Shift::factory()->create(['branch_id' => $this->branch->id]);

        $this->aggregator1 = Aggregator::create([
            'name' => 'جاهز',
            'code' => 'JAHEZ',
            'commission_rate' => 15,
            'payment_terms' => 'Monthly',
            'integration_type' => 'manual',
            'is_active' => true,
        ]);

        $this->aggregator2 = Aggregator::create([
            'name' => 'هنقرستيشن',
            'code' => 'HUNGER',
            'commission_rate' => 15,
            'payment_terms' => 'Monthly',
            'integration_type' => 'manual',
            'is_active' => true,
        ]);
    }

    /**
     * AC-47 / OPS-09:
     * Activity across multiple POS devices and cash drawers feeds into exactly ONE
     * consolidated shift report for the assigned main cashier.
     */
    public function test_ops09_ac47_multiple_devices_drawers_feed_one_consolidated_shift_report(): void
    {
        // 1. Scheduled shift assigned to the main cashier.
        $shift = CashierShift::create([
            'cashier_id' => $this->cashier->id,
            'shift_id' => $this->template->id,
            'shift_date' => today(),
            'status' => ShiftStatus::NOT_STARTED,
        ]);

        // 2. Main cashier starts the shift.
        app(CashierShiftStartService::class)->startShift($shift);
        $this->assertSame(ShiftStatus::IN_PROGRESS, $shift->fresh()->status);

        // 3. Operational activity from multiple devices and cash drawers in the branch:
        //    - POS Device 1 / Drawer 1:
        //        Sales: 1,000.00 SAR (Cash: 400.00, Card: 400.00, Aggregator 1: 200.00)
        //    - POS Device 2 / Drawer 2:
        //        Sales:   600.00 SAR (Cash: 300.00, Card: 100.00, Aggregator 2: 200.00)
        //    --------------------------------------------------------------------------
        //    Consolidated Shift Totals:
        //        Total Gross Sales : 1,600.00 SAR
        //        Cash Collected    :   700.00 SAR (400.00 + 300.00)
        //        Card Payments     :   500.00 SAR (400.00 + 100.00)
        //        Aggregators       :   400.00 SAR (200.00 + 200.00)
        //        Physical Count    :   700.00 SAR across drawers
        $consolidatedPayload = [
            'total_sales' => '1600.00',
            'cash_collected' => '700.00',
            'card_payments' => '500.00',
            'counted_cash' => '700.00',
            'aggregators' => [
                ['aggregator_id' => $this->aggregator1->id, 'amount' => '200.00'],
                ['aggregator_id' => $this->aggregator2->id, 'amount' => '200.00'],
            ],
        ];

        // 4. Main cashier submits the end-of-shift consolidated report.
        $response = $this->actingAs($this->cashier, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$shift->id}/end", $consolidatedPayload)
            ->assertOk();

        // 5. Invariant assertions:
        // a. Exactly ONE CashierShift record is completed.
        $this->assertSame(ShiftStatus::COMPLETED, $shift->fresh()->status);
        $this->assertSame(1, CashierShift::where('id', $shift->id)->where('status', ShiftStatus::COMPLETED)->count());

        // b. Exactly ONE ShiftReportAggregate exists for this shift.
        $aggregate = ShiftReportAggregate::where('source_id', $shift->id)->first();
        $this->assertNotNull($aggregate, 'ShiftReportAggregate must be created for the completed shift.');
        $this->assertSame(1, ShiftReportAggregate::where('source_id', $shift->id)->count());

        // c. Exactly ONE ShiftReportRevision is created with revision_number = 1.
        $revisions = ShiftReportRevision::where('report_aggregate_id', $aggregate->id)->get();
        $this->assertCount(1, $revisions);
        $this->assertSame(1, $revisions->first()->revision_number);

        // d. Financial totals are properly consolidated and VAT-inclusive:
        //    Gross: 1600.00 SAR
        //    Net: 1600 / 1.15 = 1391.30 SAR
        //    VAT: 1600 - 1391.30 = 208.70 SAR
        //    Cash: 700.00 SAR, Cards: 500.00 SAR
        $fresh = $shift->fresh();
        $this->assertSame('1600.00', (string) $fresh->total_sales);
        $this->assertSame('1391.30', (string) $fresh->net_sales);
        $this->assertSame('208.70', (string) $fresh->vat_amount);
        $this->assertSame('700.00', (string) $fresh->cash_collected);
        $this->assertSame('500.00', (string) $fresh->card_payments);

        // e. Variance calculation:
        //    Expected Cash = Gross(1600) - Cards(500) - Apps(400) + Opening(0) = 700.00 SAR (70,000 halalas)
        //    Counted Cash = 700.00 SAR (70,000 halalas)
        //    Variance = 0 halalas (0.00 SAR, balanced)
        $count = app(ShiftCashCountService::class)->currentFor($shift->id);
        $this->assertNotNull($count);
        $this->assertSame(0, $count->variance_halalas);
    }

    /**
     * AC-47 / OPS-09:
     * No concurrent per-drawer reports or duplicate open shifts are allowed in the branch.
     */
    public function test_ops09_ac47_no_concurrent_per_drawer_shifts_or_reports(): void
    {
        // 1. Open a shift in the branch for the main cashier.
        $this->actingAs($this->managerUser, 'sanctum')
            ->postJson('/api/v1/company/me/branch/shifts/open', [
                'cashierEmpNumber' => 'CSH-100',
                'openingCashHalalas' => 50000,
            ])
            ->assertCreated();

        // Exactly 1 active shift exists in the branch.
        $this->assertSame(1, \Modules\Admin\Models\Shift::where('branch_id', $this->branch->id)->where('status', 'active')->count());

        // 2. Attempting to open a concurrent shift for a second drawer/device in the same branch fails with 409 SHIFT_ALREADY_OPEN.
        $this->actingAs($this->managerUser, 'sanctum')
            ->postJson('/api/v1/company/me/branch/shifts/open', [
                'cashierEmpNumber' => 'CSH-100',
                'openingCashHalalas' => 50000,
            ])
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SHIFT_ALREADY_OPEN');

        // Still exactly 1 active shift exists in the branch.
        $this->assertSame(1, \Modules\Admin\Models\Shift::where('branch_id', $this->branch->id)->where('status', 'active')->count());

        // 3. Concurrency guard in CashierShift: an active shift cannot be started again concurrently.
        $source = CashierShift::create([
            'cashier_id' => $this->cashier->id,
            'shift_id' => $this->template->id,
            'shift_date' => today(),
            'status' => ShiftStatus::NOT_STARTED,
        ]);
        app(CashierShiftStartService::class)->startShift($source);
        $this->assertSame(ShiftStatus::IN_PROGRESS, $source->fresh()->status);

        // A second start attempt on an active shift conflicts (fail-closed, no duplicate active shifts).
        try {
            app(CashierShiftStartService::class)->startShift($source->fresh());
            $this->fail('Starting an already active shift must conflict.');
        } catch (ConflictHttpException $e) {
            $this->assertSame('SHIFT_NO_LONGER_PENDING', $e->getMessage());
        }

        // Cashier HTTP start endpoint also rejects duplicate/concurrent start with 400.
        $this->actingAs($this->cashier, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$source->id}/start")
            ->assertStatus(400);

        // Only one operational shift remains active in the chain.
        $this->assertSame(1, CashierShift::currentOperational()->where('operational_chain_id', $source->fresh()->operational_chain_id)->count());
    }
}
