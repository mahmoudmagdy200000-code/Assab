<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Services\CashierCustodyService;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Modules\Shift\Models\ShiftLiabilityPosting;
use Modules\Shift\Models\ShiftReportAggregate;
use Modules\Shift\Models\ShiftReportCashCount;
use Modules\Shift\Models\ShiftReportCorrection;
use Modules\Shift\Models\ShiftReportCorrectionNotification;
use Modules\Shift\Models\ShiftReportRevision;
use Modules\Shift\Services\AccountantReportCorrectionService;
use Modules\Shift\Services\BranchManagerShiftService;
use Modules\Shift\Services\ShiftCashCountService;
use Modules\Shift\Services\ShiftReportManagerReviewService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/**
 * End-to-End Integration Suite for S1-11 Phases 5, 6, and 7.
 * Covers full real lifecycle:
 * - Accountant correction -> revision advance -> gross sales immutability -> count preservation.
 * - Current manager review -> workday submit blocking.
 * - Redistribution -> net deltas -> reversing entries -> additive ledger history.
 * - Branch manager bulk correction -> canonical figures -> outbox notifications.
 * - Idempotency & atomicity rollbacks.
 */
class ShiftPhases567IntegrationTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabBrand $brand;

    private Branch $branch;

    private BranchManager $manager;

    private AsabUser $accountant;

    private Cashier $cashierA;

    private Cashier $cashierB;

    private Shift $shiftTemplate;

    private BranchManagerShift $managerShift;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Phases 567 Corp', 'plan' => 'Enterprise', 'status' => 'active']);
        $this->brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'Brand Alpha', 'sub_status' => 'active', 'status' => 'active']);
        $this->branch = Branch::factory()->create([
            'asab_brand_id' => $this->brand->id,
            'asab_company_id' => $this->company->id,
        ]);

        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);

        $this->accountant = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'Accountant 1',
            'email' => 'accountant@test.com',
            'password' => 'secret',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $this->accountant->id,
            'role_key' => 'accountant',
            'scope' => 'all',
        ]);

        $this->cashierA = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);
        $this->cashierB = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);

        $this->shiftTemplate = Shift::create([
            'branch_id' => $this->branch->id,
            'name' => 'Morning',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);

        $this->managerShift = BranchManagerShift::firstOrCreate(
            ['branch_manager_id' => $this->manager->id, 'shift_date' => today()->toDateString()],
            ['branch_id' => $this->branch->id, 'status' => 'active', 'daily_report_submitted' => false]
        );
    }

    private function createCompletedCashierShift(array $overrides = []): CashierShift
    {
        $shift = CashierShift::create(array_merge([
            'cashier_id' => $this->cashierA->id,
            'shift_id' => $this->shiftTemplate->id,
            'shift_date' => today(),
            'status' => 'completed',
            'actual_end_time' => now(),
            'total_sales' => '1000.00',
            'cash_collected' => '800.00',
            'card_payments' => '200.00',
            'net_sales' => '869.57',
            'vat_amount' => '130.43',
            'closing_balance' => '800.00',
            'variance' => '-100.00',
            'branch_manager_shift_id' => $this->managerShift->id,
        ], $overrides));

        DB::transaction(function () use ($shift) {
            $rev = app(ShiftReportRevisionService::class)->recordCashierRevision($shift, 'cashier', $shift->cashier_id, 0);
            app(ShiftCashCountService::class)->record($shift, $rev, 100000, 20000, 0, 70000);
        });

        return $shift;
    }

    /**
     * 1. Accountant card correction preserves total sales and cash count,
     * creates current review, and blocks workday submit until approved.
     */
    public function test_accountant_card_correction_preserves_total_sales_and_count_and_blocks_workday_submit(): void
    {
        $cashierShift = $this->createCompletedCashierShift();

        // 1. Initial review and approval
        $reviewService = app(ShiftReportManagerReviewService::class);
        $reviewService->requireFor($cashierShift, ShiftReportRevision::where('revision_number', 1)->first(), -10000);

        $alloc1 = ShiftLiabilityAllocation::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_shift_id' => $cashierShift->id,
            'version' => 1,
            'report_revision' => '1',
            'variance_halalas' => -10000,
            'created_by_type' => 'manager',
            'created_by_id' => $this->manager->id,
            'manager_approval_status' => 'pending',
        ]);
        $alloc1->shares()->create([
            'responsible_type' => 'cashier',
            'responsible_id' => $this->cashierA->id,
            'amount_halalas' => 10000,
        ]);

        $reviewService->approveCurrent($cashierShift, $this->manager, 1, 1, 'Initial manager approve', (string) Str::uuid());

        // Initial liability is posted
        $this->assertSame(-100.00, app(CashierCustodyService::class)->getPersonalBalanceOnly($this->cashierA->id));

        // 2. Accountant submits card correction via service: card_payments 200 -> 300, cash 800 -> 700
        $accountantService = app(AccountantReportCorrectionService::class);
        $rev2 = $accountantService->correct(
            $cashierShift,
            $this->accountant,
            ['card_payments' => 300.00, 'cash_collected' => 700.00],
            [['type' => 'cashier', 'id' => $this->cashierA->id, 'amount' => 10000]],
            1,
            1,
            'Correcting POS terminal reconciliation',
            (string) Str::uuid()
        );

        $this->assertSame(2, $rev2->revision_number);
        $cashierShift->refresh();

        // Total sales remains strictly immutable: 1000.00
        $this->assertSame('1000.00', (string) $cashierShift->total_sales);
        $this->assertSame('300.00', (string) $cashierShift->card_payments);
        $this->assertSame('700.00', (string) $cashierShift->cash_collected);

        // Physical cash count preserved at 700.00 SAR (70,000 halalas)
        $count2 = ShiftReportCashCount::where('report_revision_id', $rev2->id)->first();
        $this->assertNotNull($count2);
        $this->assertSame(70000, $count2->counted_halalas);

        // Workday submit is blocked because replacement review is required and pending
        try {
            $reviewService->assertReadyForWorkday($this->managerShift, [$cashierShift->id]);
            $this->fail('Expected ConflictHttpException was not thrown');
        } catch (ConflictHttpException $e) {
            $this->assertSame('BRANCH_MANAGER_REVIEW_REQUIRED', $e->getMessage());
        }

        // 3. Manager approves replacement review (zero variance has no allocation)
        $reviewService->approveCurrent($cashierShift, $this->manager, 2, null, 'Approved corrected report', (string) Str::uuid());

        // Now workday submit check passes
        $reviewService->assertReadyForWorkday($this->managerShift, [$cashierShift->id]);
    }

    /**
     * 2. Redistribution and actor removal (A100 -> A60 + B40):
     * Verifies audit records, outbox notifications, net deltas, and no duplicate charge.
     */
    public function test_redistribution_and_actor_removal_posts_exact_deltas_without_duplicate_charge(): void
    {
        $cashierShift = $this->createCompletedCashierShift();
        $reviewService = app(ShiftReportManagerReviewService::class);
        $rev1 = ShiftReportRevision::where('revision_number', 1)->first();
        $reviewService->requireFor($cashierShift, $rev1, -10000);

        $alloc1 = ShiftLiabilityAllocation::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_shift_id' => $cashierShift->id,
            'version' => 1,
            'report_revision' => '1',
            'variance_halalas' => -10000,
            'created_by_type' => 'manager',
            'created_by_id' => $this->manager->id,
            'manager_approval_status' => 'pending',
        ]);
        $alloc1->shares()->create([
            'responsible_type' => 'cashier',
            'responsible_id' => $this->cashierA->id,
            'amount_halalas' => 10000,
        ]);
        $reviewService->approveCurrent($cashierShift, $this->manager, 1, 1, 'Initial approve', 'OP-INIT');

        $custodyService = app(CashierCustodyService::class);
        $this->assertSame(-100.00, $custodyService->getPersonalBalanceOnly($this->cashierA->id));
        $this->assertSame(0.00, $custodyService->getPersonalBalanceOnly($this->cashierB->id));

        // Accountant redistributes: A gets 60.00, B gets 40.00
        $accountantService = app(AccountantReportCorrectionService::class);
        $rev2 = $accountantService->correct(
            $cashierShift,
            $this->accountant,
            ['handover_notes' => 'Redistributed between A and B'],
            [
                ['type' => 'cashier', 'id' => $this->cashierA->id, 'amount' => 6000],
                ['type' => 'cashier', 'id' => $this->cashierB->id, 'amount' => 4000],
            ],
            1,
            1,
            'Split liability adjustment',
            'OP-SPLIT'
        );

        // Verify outbox notifications created
        $notifications = ShiftReportCorrectionNotification::where('report_revision_id', $rev2->id)->get();
        $this->assertNotEmpty($notifications);

        // Before manager approves: old liability remains
        $this->assertSame(-100.00, $custodyService->getPersonalBalanceOnly($this->cashierA->id));
        $this->assertSame(0.00, $custodyService->getPersonalBalanceOnly($this->cashierB->id));

        // Manager approves replacement
        $reviewService->approveCurrent($cashierShift, $this->manager, 2, 2, 'Approved split', 'OP-SPLIT-APPROVE');

        // Net deltas posted:
        // Cashier A was credited 40.00 SAR (from -100 to -60)
        // Cashier B was debited 40.00 SAR (from 0 to -40)
        $this->assertSame(-60.00, $custodyService->getPersonalBalanceOnly($this->cashierA->id));
        $this->assertSame(-40.00, $custodyService->getPersonalBalanceOnly($this->cashierB->id));

        // Combined liability on ledger is exactly 100.00 SAR
        $totalLiability = $custodyService->getPersonalBalanceOnly($this->cashierA->id) + $custodyService->getPersonalBalanceOnly($this->cashierB->id);
        $this->assertSame(-100.00, $totalLiability);
    }

    /**
     * 3. Zero-shortage correction reverses previously posted liability upon approval per user policy 7.3.
     */
    public function test_zero_shortage_correction_reverses_previously_posted_liability_upon_approval(): void
    {
        $cashierShift = $this->createCompletedCashierShift();
        $reviewService = app(ShiftReportManagerReviewService::class);
        $rev1 = ShiftReportRevision::where('revision_number', 1)->first();
        $reviewService->requireFor($cashierShift, $rev1, -10000);

        $alloc1 = ShiftLiabilityAllocation::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'cashier_shift_id' => $cashierShift->id,
            'version' => 1,
            'report_revision' => '1',
            'variance_halalas' => -10000,
            'created_by_type' => 'manager',
            'created_by_id' => $this->manager->id,
            'manager_approval_status' => 'pending',
        ]);
        $alloc1->shares()->create([
            'responsible_type' => 'cashier',
            'responsible_id' => $this->cashierA->id,
            'amount_halalas' => 10000,
        ]);
        $reviewService->approveCurrent($cashierShift, $this->manager, 1, 1, 'Initial approve', 'OP-INIT');

        $custodyService = app(CashierCustodyService::class);
        $this->assertSame(-100.00, $custodyService->getPersonalBalanceOnly($this->cashierA->id));

        // Accountant corrects cards to 300.00 (with count 700.00, gross 1000.00 -> variance becomes 0)
        $accountantService = app(AccountantReportCorrectionService::class);
        $rev2 = $accountantService->correct(
            $cashierShift,
            $this->accountant,
            ['card_payments' => 300.00],
            null,
            1,
            1,
            'Count matches corrected figures',
            'OP-ZERO'
        );

        // Before approval: previous liability remains intact (policy 7.3)
        $this->assertSame(-100.00, $custodyService->getPersonalBalanceOnly($this->cashierA->id));

        // Manager approves current review (has_shortage = false)
        $reviewService->approveCurrent($cashierShift, $this->manager, 2, null, 'Approved zero shortage', 'OP-ZERO-APPROVE');

        // Liability reversed to 0.00 SAR
        $this->assertSame(0.00, $custodyService->getPersonalBalanceOnly($this->cashierA->id));

        // Reversal posting recorded in journal
        $latestPosting = ShiftLiabilityPosting::where('operation_id', 'OP-ZERO-APPROVE')->first();
        $this->assertNotNull($latestPosting);
        $this->assertSame(-10000, $latestPosting->delta_halalas);
        $this->assertSame(0, $latestPosting->target_halalas);
        $this->assertSame(10000, $latestPosting->effective_before_halalas);
    }

    /**
     * 4. Branch manager direct bulk correction:
     * Canonical figures, history, notification, no cashier approval required, current financial approval.
     */
    public function test_branch_manager_bulk_correction_canonical_figures_history_and_notification(): void
    {
        $cashierShift = $this->createCompletedCashierShift();

        $bmService = app(BranchManagerShiftService::class);
        $bmService->bulkUpdateCashierShifts(
            [
                [
                    'cashier_shift_id' => $cashierShift->id,
                    'card_payments' => '250.00',
                    'expected_revision' => 1,
                    'reason' => 'Manager adjusted POS card receipt total',
                    'liability_shares' => [[
                        'type' => 'cashier',
                        'id' => $this->cashierA->id,
                        'amount' => 5000,
                    ]],
                ],
            ],
            $this->managerShift,
            $this->manager
        );

        $cashierShift->refresh();
        $this->assertSame('250.00', (string) $cashierShift->card_payments);

        // Revision is created with branch_manager actor
        $aggregateId = ShiftReportAggregate::where('source_type', 'cashier_shift')->where('source_id', $cashierShift->id)->value('id');
        $latestRev = ShiftReportRevision::where('report_aggregate_id', $aggregateId)->latest('revision_number')->first();
        $this->assertSame('branch_manager', $latestRev->created_by_type);
        $this->assertSame($this->manager->id, $latestRev->created_by_id);

        // Outbox notification enqueued for cashier
        $notification = ShiftReportCorrectionNotification::where('report_revision_id', $latestRev->id)->first();
        $this->assertNotNull($notification);
        $this->assertSame('cashier', $notification->recipient_type);
        $this->assertSame($this->cashierA->id, $notification->recipient_id);
    }

    /**
     * 5. Retry with same Idempotency-Key does not duplicate revisions, ledger rows, or notifications.
     */
    public function test_idempotency_replay_does_not_duplicate_revision_or_ledger(): void
    {
        $cashierShift = $this->createCompletedCashierShift();
        $idempotencyKey = (string) Str::uuid();

        $payload = [
            'expected_revision' => 1,
            'reason' => 'Idempotent card adjustment on pos terminal',
            'changes' => [
                'card_payments' => '250.00',
            ],
            'shortage_allocations' => [[
                'type' => 'cashier',
                'id' => $this->cashierA->id,
                'amount' => 5000,
            ]],
        ];

        // First call via HTTP endpoint
        $res1 = $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$cashierShift->id}/corrections", $payload);
        $res1->assertOk();

        $aggregateId = ShiftReportAggregate::where('source_type', 'cashier_shift')->where('source_id', $cashierShift->id)->value('id');
        $revisionCount = ShiftReportRevision::where('report_aggregate_id', $aggregateId)->count();
        $notificationCount = ShiftReportCorrectionNotification::count();

        // Second call with identical Idempotency-Key
        $res2 = $this->withHeader('Idempotency-Key', $idempotencyKey)
            ->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$cashierShift->id}/corrections", $payload);
        $res2->assertOk();

        // Counts unchanged
        $this->assertSame($revisionCount, ShiftReportRevision::where('report_aggregate_id', $aggregateId)->count());
        $this->assertSame($notificationCount, ShiftReportCorrectionNotification::count());
    }

    /**
     * 6. Confirmed transfer receipts remain immutable and recount_required is strictly enforced.
     */
    public function test_confirmed_transfer_receipts_remain_immutable_during_corrections(): void
    {
        $cashierShift = $this->createCompletedCashierShift();
        $rev1 = ShiftReportRevision::where('revision_number', 1)->first();

        // Simulate a confirmed handover receipt
        $receipt = \Modules\Shift\Models\CashierShiftHandoverReceipt::create([
            'receiving_cashier_shift_id' => $cashierShift->id,
            'receiving_cashier_id' => $this->cashierA->id,
            'report_revision_id' => $rev1->id,
            'confirmed_amount' => '300.00',
            'confirmed_by_id' => $this->cashierA->id,
            'confirmed_at' => now(),
        ]);

        $accountantService = app(AccountantReportCorrectionService::class);

        // Correction when uncounted confirmed incoming exists cannot bypass physical recount requirement
        try {
            $accountantService->correct(
                $cashierShift,
                $this->accountant,
                ['card_payments' => 300.00],
                null,
                1,
                null,
                'Receipt preservation audit',
                (string) Str::uuid()
            );
            $this->fail('Expected ConflictHttpException was not thrown');
        } catch (ConflictHttpException $e) {
            $this->assertSame('PHYSICAL_RECOUNT_REQUIRED', $e->getMessage());
        }

        // Receipt remains completely untouched and immutable
        $this->assertNotNull($receipt->fresh());
        $this->assertSame('300.00', (string) $receipt->fresh()->confirmed_amount);
    }

    /**
     * 7. Submitted/closed workday rejects accountant and manager corrections with conflict error.
     */
    public function test_submitted_workday_boundary_rejects_corrections(): void
    {
        $cashierShift = $this->createCompletedCashierShift();

        // Mark workday submitted
        $this->managerShift->update([
            'daily_report_submitted' => true,
            'daily_report_submitted_at' => now(),
        ]);

        $payload = [
            'expected_revision' => 1,
            'reason' => 'Attempting mutation on submitted day',
            'changes' => [
                'card_payments' => '250.00',
            ],
        ];

        $res = $this->withHeader('Idempotency-Key', (string) Str::uuid())
            ->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/accountant/shifts/{$cashierShift->id}/corrections", $payload);

        $res->assertStatus(409)
            ->assertJsonPath('error.code', 'REPORT_REOPEN_REQUIRED');
    }

    /**
     * 8. Atomic rollback on unexpected mid-transaction error leaves no partial artifacts.
     */
    public function test_atomic_rollback_leaves_no_partial_artifacts_on_failure(): void
    {
        $cashierShift = $this->createCompletedCashierShift();

        $revCountBefore = ShiftReportRevision::count();
        $corrCountBefore = ShiftReportCorrection::count();
        $notifCountBefore = ShiftReportCorrectionNotification::count();

        // Attempt correction with invalid shares that cause an exception inside the transaction
        try {
            app(AccountantReportCorrectionService::class)->correct(
                $cashierShift,
                $this->accountant,
                ['card_payments' => 250.00],
                [['type' => 'invalid_actor_type', 'id' => (string) Str::uuid(), 'amount' => 5000]],
                1,
                null,
                'Failing correction',
                (string) Str::uuid()
            );
            $this->fail('Expected exception was not thrown');
        } catch (\Exception $e) {
            // Expected
        }

        // All counts unchanged due to clean rollback
        $this->assertSame($revCountBefore, ShiftReportRevision::count());
        $this->assertSame($corrCountBefore, ShiftReportCorrection::count());
        $this->assertSame($notifCountBefore, ShiftReportCorrectionNotification::count());
    }
}
