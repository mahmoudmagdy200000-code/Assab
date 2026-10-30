<?php

namespace Tests\Feature;

use App\Support\ShiftFinancialCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Aggregator\Models\Aggregator;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftSalesBreakdown;
use Modules\Shift\Services\BranchManagerShiftService;
use Modules\Shift\Services\ShiftFinancialService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ShiftManagerCorrectionBulkTest extends TestCase
{
    use RefreshDatabase;

    private string $companyId;

    private Branch $branch;

    private BranchManager $manager;

    private BranchManagerShift $managerShift;

    private Cashier $cashier1;

    private Cashier $cashier2;

    private Shift $shift;

    private CashierShift $cashierShift1;

    private CashierShift $cashierShift2;

    private BranchManagerShiftService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyId = (string) Str::uuid();
        $this->branch = Branch::factory()->create([
            'asab_company_id' => $this->companyId,
        ]);

        $this->manager = BranchManager::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 'active',
        ]);

        $this->managerShift = BranchManagerShift::create([
            'branch_id' => $this->branch->id,
            'branch_manager_id' => $this->manager->id,
            'shift_date' => today(),
            'status' => 'in_progress',
        ]);

        $this->cashier1 = Cashier::factory()->create([
            'branch_id' => $this->branch->id,
        ]);
        $this->cashier2 = Cashier::factory()->create([
            'branch_id' => $this->branch->id,
        ]);

        $this->shift = Shift::factory()->create([
            'branch_id' => $this->branch->id,
        ]);

        $split1 = ShiftFinancialCalculator::calculateVatInclusiveSales('100.00');
        $this->cashierShift1 = CashierShift::factory()->create([
            'shift_id' => $this->shift->id,
            'cashier_id' => $this->cashier1->id,
            'shift_date' => today(),
            'total_sales' => '100.00',
            'net_sales' => $split1['net'],
            'vat_amount' => $split1['vat'],
            'card_payments' => '20.00',
            'cash_collected' => '80.00',
            'status' => 'in_progress',
        ]);
        app(ShiftReportRevisionService::class)->recordCashierRevision($this->cashierShift1, 'cashier', $this->cashier1->id, 0);

        $split2 = ShiftFinancialCalculator::calculateVatInclusiveSales('200.00');
        $this->cashierShift2 = CashierShift::factory()->create([
            'shift_id' => $this->shift->id,
            'cashier_id' => $this->cashier2->id,
            'shift_date' => today(),
            'total_sales' => '200.00',
            'net_sales' => $split2['net'],
            'vat_amount' => $split2['vat'],
            'card_payments' => '50.00',
            'cash_collected' => '150.00',
            'status' => 'in_progress',
        ]);
        app(ShiftReportRevisionService::class)->recordCashierRevision($this->cashierShift2, 'cashier', $this->cashier2->id, 0);

        $this->service = app(BranchManagerShiftService::class);
    }

    public function test_bulk_update_requires_branch_manager_actor_and_rejects_foreign_manager(): void
    {
        $foreignBranch = Branch::factory()->create(['asab_company_id' => $this->companyId]);
        $foreignManager = BranchManager::factory()->create([
            'branch_id' => $foreignBranch->id,
            'status' => 'active',
        ]);

        $this->expectException(AccessDeniedHttpException::class);
        $this->service->bulkUpdateCashierShifts([
            [
                'cashier_shift_id' => $this->cashierShift1->id,
                'sales' => '120.00',
                'expected_revision' => 1,
                'reason' => 'Manager sales adjustment',
            ],
        ], $this->managerShift, $foreignManager);
    }

    public function test_bulk_update_resolves_cashier_shift_id_and_rejects_foreign_shift(): void
    {
        $otherBranch = Branch::factory()->create(['asab_company_id' => $this->companyId]);
        $otherShift = Shift::factory()->create(['branch_id' => $otherBranch->id]);
        $otherCashier = Cashier::factory()->create(['branch_id' => $otherBranch->id]);
        $foreignCashierShift = CashierShift::factory()->create([
            'shift_id' => $otherShift->id,
            'cashier_id' => $otherCashier->id,
            'shift_date' => today(),
        ]);

        $this->expectException(ValidationException::class);
        $this->service->bulkUpdateCashierShifts([
            [
                'cashier_shift_id' => $foreignCashierShift->id,
                'sales' => '150.00',
                'expected_revision' => 1,
                'reason' => 'Attempt foreign branch update',
            ],
        ], $this->managerShift, $this->manager);
    }

    public function test_bulk_update_resolves_legacy_cashier_id_only_when_unique(): void
    {
        $this->service->bulkUpdateCashierShifts([
            [
                'cashier_id' => $this->cashier1->id,
                'sales' => '115.00',
                'expected_revision' => 1,
                'reason' => 'Legacy ID lookup unique resolution',
            ],
        ], $this->managerShift, $this->manager);

        $this->cashierShift1->refresh();
        $this->assertSame('115.00', (string) $this->cashierShift1->total_sales);
        $rev = app(ShiftReportRevisionService::class)->currentCashierRevision($this->cashierShift1);
        $this->assertSame(2, $rev->revision_number);
    }

    public function test_bulk_update_rejects_ambiguous_cashier_id_when_multiple_shifts_exist(): void
    {
        // Create second shift for same cashier on same date and branch with different parent shift
        $otherShift = Shift::factory()->create([
            'branch_id' => $this->branch->id,
            'start_time' => '18:00:00',
            'end_time' => '02:00:00',
        ]);
        CashierShift::factory()->create([
            'shift_id' => $otherShift->id,
            'cashier_id' => $this->cashier1->id,
            'shift_date' => today(),
            'status' => 'in_progress',
        ]);

        $this->expectException(ValidationException::class);
        $this->service->bulkUpdateCashierShifts([
            [
                'cashier_id' => $this->cashier1->id,
                'sales' => '120.00',
                'expected_revision' => 1,
                'reason' => 'Ambiguous update',
            ],
        ], $this->managerShift, $this->manager);
    }

    public function test_bulk_update_rejects_duplicate_shift_targets_in_same_payload(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->bulkUpdateCashierShifts([
            [
                'cashier_shift_id' => $this->cashierShift1->id,
                'sales' => '110.00',
                'expected_revision' => 1,
                'reason' => 'First attempt',
            ],
            [
                'cashier_shift_id' => $this->cashierShift1->id,
                'sales' => '120.00',
                'expected_revision' => 1,
                'reason' => 'Duplicate attempt',
            ],
        ], $this->managerShift, $this->manager);
    }

    public function test_bulk_update_rejects_client_supplied_variance(): void
    {
        $this->expectException(ValidationException::class);
        $this->service->bulkUpdateCashierShifts([
            [
                'cashier_shift_id' => $this->cashierShift1->id,
                'variance' => '-10.00',
                'expected_revision' => 1,
                'reason' => 'Direct variance manipulation',
            ],
        ], $this->managerShift, $this->manager);
    }

    public function test_bulk_update_rejects_scalar_delivery_app_payments_when_multiple_aggregators_exist(): void
    {
        $agg1 = Aggregator::factory()->create();
        $agg2 = Aggregator::factory()->create();
        ShiftSalesBreakdown::create([
            'cashier_shift_id' => $this->cashierShift1->id,
            'aggregator_id' => $agg1->id,
            'amount' => '10.00',
        ]);
        ShiftSalesBreakdown::create([
            'cashier_shift_id' => $this->cashierShift1->id,
            'aggregator_id' => $agg2->id,
            'amount' => '10.00',
        ]);

        $this->expectException(ValidationException::class);
        $this->service->bulkUpdateCashierShifts([
            [
                'cashier_shift_id' => $this->cashierShift1->id,
                'delivery_app_payments' => '25.00',
                'expected_revision' => 1,
                'reason' => 'Scalar delivery apps with multiple existing',
            ],
        ], $this->managerShift, $this->manager);
    }

    public function test_bulk_update_allows_scalar_delivery_app_payments_when_single_aggregator_exists(): void
    {
        $agg = Aggregator::factory()->create();
        DB::table('branch_aggregators')->insert([
            'id' => (string) Str::uuid(),
            'branch_id' => $this->branch->id,
            'aggregator_id' => $agg->id,
            'is_enabled' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        ShiftSalesBreakdown::create([
            'cashier_shift_id' => $this->cashierShift1->id,
            'aggregator_id' => $agg->id,
            'amount' => '10.00',
        ]);

        $this->service->bulkUpdateCashierShifts([
            [
                'cashier_shift_id' => $this->cashierShift1->id,
                'delivery_app_payments' => '15.00',
                'expected_revision' => 1,
                'reason' => 'Scalar delivery apps with single aggregator',
            ],
        ], $this->managerShift, $this->manager);

        $breakdown = ShiftSalesBreakdown::where('cashier_shift_id', $this->cashierShift1->id)->sole();
        $this->assertSame('15.00', (string) $breakdown->amount);
    }

    public function test_bulk_update_noop_row_does_not_require_reason_or_revision_and_does_not_advance_version(): void
    {
        $initialRev = app(ShiftReportRevisionService::class)->currentCashierRevision($this->cashierShift1);

        // No-op payload: identical sales figure, no revision or reason supplied
        $this->service->bulkUpdateCashierShifts([
            [
                'cashier_shift_id' => $this->cashierShift1->id,
                'sales' => '100.00',
            ],
        ], $this->managerShift, $this->manager);

        $currentRev = app(ShiftReportRevisionService::class)->currentCashierRevision($this->cashierShift1);
        $this->assertSame($initialRev->id, $currentRev->id);
        $this->assertSame(1, $currentRev->revision_number);
    }

    public function test_bulk_update_mutating_row_requires_expected_revision_and_reason(): void
    {
        // Mutating sales without expected_revision should throw 422
        try {
            $this->service->bulkUpdateCashierShifts([
                [
                    'cashier_shift_id' => $this->cashierShift1->id,
                    'sales' => '120.00',
                    'reason' => 'Missing expected revision',
                ],
            ], $this->managerShift, $this->manager);
            $this->fail('Expected ValidationException for missing expected_revision');
        } catch (ValidationException $e) {
            $this->assertTrue($e->validator->errors()->has('expected_revision') || $e->validator->errors()->has('cashier_breakdown.0.expected_revision'));
        }

        // Mutating sales without reason should throw 422
        try {
            $this->service->bulkUpdateCashierShifts([
                [
                    'cashier_shift_id' => $this->cashierShift1->id,
                    'sales' => '120.00',
                    'expected_revision' => 1,
                ],
            ], $this->managerShift, $this->manager);
            $this->fail('Expected ValidationException for missing reason');
        } catch (ValidationException $e) {
            $this->assertTrue($e->validator->errors()->has('reason') || $e->validator->errors()->has('cashier_breakdown.0.reason'));
        }
    }

    public function test_bulk_update_rejects_stale_expected_revision_with_409(): void
    {
        $this->expectException(ConflictHttpException::class);
        $this->service->bulkUpdateCashierShifts([
            [
                'cashier_shift_id' => $this->cashierShift1->id,
                'sales' => '120.00',
                'expected_revision' => 999, // Stale
                'reason' => 'Stale update attempt',
            ],
        ], $this->managerShift, $this->manager);
    }

    public function test_bulk_update_rolls_back_entire_batch_if_one_row_fails(): void
    {
        try {
            $this->service->bulkUpdateCashierShifts([
                [
                    'cashier_shift_id' => $this->cashierShift1->id,
                    'sales' => '110.00',
                    'expected_revision' => 1,
                    'reason' => 'Valid row A',
                ],
                [
                    'cashier_shift_id' => $this->cashierShift2->id,
                    'sales' => '220.00',
                    'expected_revision' => 999, // Stale row B
                    'reason' => 'Invalid row B',
                ],
            ], $this->managerShift, $this->manager);
            $this->fail('Expected ConflictHttpException');
        } catch (ConflictHttpException) {
            // Expected
        }

        // Verify shift 1 was completely rolled back
        $this->cashierShift1->refresh();
        $this->assertSame('100.00', (string) $this->cashierShift1->total_sales);
        $rev1 = app(ShiftReportRevisionService::class)->currentCashierRevision($this->cashierShift1);
        $this->assertSame(1, $rev1->revision_number);
    }

    public function test_read_response_supplies_cashier_shift_id_expected_revision_and_revision_token(): void
    {
        $financialService = app(ShiftFinancialService::class);
        $summary = $financialService->prepareDailyCloseSummary($this->managerShift);

        $breakdown = $summary['cashier_breakdown'];
        $this->assertNotEmpty($breakdown);

        foreach ($breakdown as $item) {
            $this->assertArrayHasKey('cashier_shift_id', $item);
            $this->assertArrayHasKey('expected_revision', $item);
            $this->assertArrayHasKey('revision_token', $item);
            $this->assertSame((string) $item['expected_revision'], (string) $item['revision_token']);
        }
    }
}
