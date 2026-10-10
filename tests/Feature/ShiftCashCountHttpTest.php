<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\EmployeeMovement;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\Shift as AdminShift;
use Modules\Admin\Services\OperationService;
use Modules\Aggregator\Models\Aggregator;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Events\ShiftEndedEvent;
use Modules\Shift\Liability\CashCountLiabilityEvidence;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Modules\Shift\Models\ShiftLiabilityShare;
use Modules\Shift\Models\ShiftReportAggregate;
use Modules\Shift\Models\ShiftReportCashCount;
use Modules\Shift\Models\ShiftReportRevision;
use Modules\Shift\Models\ShiftTransferRejectionEvidence;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

/** S1-10 Phase 2: the independent physical count, server calculation and atomic shortage allocation over real HTTP. */
class ShiftCashCountHttpTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private BranchManager $manager;

    private Cashier $sender;

    private Cashier $recipient;

    private CashierShift $senderShift;

    private CashierShift $recipientShift;

    private Aggregator $aggregator;

    protected function setUp(): void
    {
        parent::setUp();

        $company = AsabCompany::create(['name' => 'S1-10 Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => 'B', 'sub_status' => 'active', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['asab_brand_id' => $brand->id, 'asab_company_id' => $company->id]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true, 'status' => 'active']);
        $this->sender = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);
        $this->recipient = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);
        $this->senderShift = $this->liveShift($this->sender, '06:00:00', '14:00:00');
        $this->recipientShift = $this->liveShift($this->recipient, '14:00:00', '22:00:00');
        $this->aggregator = Aggregator::factory()->create();

        $accountant = AsabUser::create(['company_id' => $company->id, 'name' => 'محاسب', 'email' => 'acc@s110.test', 'password' => 'secret-password', 'status' => 'active']);
        AsabUserRole::create(['user_id' => $accountant->id, 'role_key' => 'accountant', 'scope' => 'all']);
        Employee::create(['company_id' => $company->id, 'branch_id' => $this->branch->id, 'emp_number' => '3001', 'name' => 'مستلم', 'role' => 'كاشير', 'status' => 'active'])
            ->forceFill(['legacy_cashier_id' => $this->recipient->id])->save();
    }

    /** The approved FIN-01 vector: gross 115, cards 50, apps 25, confirmed opening 10, counted 30. */
    private function fin01Payload(array $override = []): array
    {
        return array_merge([
            'total_sales' => '115.00', 'cash_collected' => '40.00', 'card_payments' => '50.00',
            'aggregators' => [['aggregator_id' => $this->aggregator->id, 'amount' => '25.00']],
            'counted_cash' => '30.00',
            'shortage_allocations' => [
                ['responsible_type' => 'cashier', 'responsible_id' => $this->recipient->id, 'amount' => '12.00'],
                ['responsible_type' => 'branch_manager', 'responsible_id' => $this->manager->id, 'amount' => '8.00'],
            ],
        ], $override);
    }

    /** Real S1-08 chain: the sender hands 10.00 over and the recipient confirms exactly 10.00. */
    private function confirmedOpening(string $amount = '10.00'): void
    {
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/end-with-handover", [
            'total_sales' => $amount, 'cash_collected' => $amount, 'counted_cash' => $amount,
            'next_cashier_id' => $this->recipient->id, 'handover_amount' => $amount,
        ])->assertOk();
        $this->actingAs($this->recipient, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/handover/accept", [
            'confirmed_amount' => $amount, 'receiving_shift_id' => $this->recipientShift->id,
        ])->assertOk();
    }

    private function end(array $payload, ?string $key = null)
    {
        $request = $this->actingAs($this->recipient, 'sanctum');
        if ($key !== null) {
            $request = $request->withHeaders(['Idempotency-Key' => $key]);
        }

        return $request->postJson("/api/v1/cashier/shifts/{$this->recipientShift->id}/end", $payload);
    }

    private function assertNothingWritten(): void
    {
        $this->assertSame(ShiftStatus::IN_PROGRESS, $this->recipientShift->fresh()->status);
        $this->assertSame(0, ShiftReportCashCount::where('cashier_shift_id', $this->recipientShift->id)->count());
        $this->assertSame(0, ShiftLiabilityAllocation::where('cashier_shift_id', $this->recipientShift->id)->count());
        $this->assertNull(ShiftReportAggregate::where('source_id', $this->recipientShift->id)->first());
        $this->assertSame(0, DB::table('cashier_custody_transactions')->where('related_shift_id', $this->recipientShift->id)->count());
    }

    public function test_fin01_over_real_http_shortage_of_20_with_complete_allocation_and_cashier_confirmation(): void
    {
        $this->confirmedOpening();
        $ledgerBefore = PersonalLedgerTransaction::count();

        $response = $this->end($this->fin01Payload())->assertOk();

        $expectedBlock = [
            'counted_cash' => 30.0, 'expected_cash' => 50.0, 'cash_variance' => -20.0, 'cash_variance_type' => 'shortage',
            'pending_incoming_cash' => 0.0, 'confirmed_opening_cash' => 10.0,
        ];
        // JSON drops the zero fraction (30.0 -> 30), exactly like the legacy SAR fields: clients parse `num`.
        $this->assertEquals($expectedBlock, $response->json('data.summary.cash_reconciliation'));
        $this->assertEquals($expectedBlock, $response->json('data.shift.cash_reconciliation'));
        $this->assertSame('shortage', $response->json('data.shift.cash_reconciliation.cash_variance_type'));
        $this->assertNull($response->json('data.summary.variance'), 'the legacy variance fields keep their own place and meaning');

        $shift = $this->recipientShift->fresh();
        $this->assertSame(ShiftStatus::COMPLETED, $shift->status);
        $this->assertSame('100.00', (string) $shift->net_sales);
        $this->assertSame('15.00', (string) $shift->vat_amount);

        $count = ShiftReportCashCount::where('cashier_shift_id', $shift->id)->sole();
        $this->assertSame(11500, $count->gross_halalas);
        $this->assertSame(5000, $count->cards_halalas);
        $this->assertSame(2500, $count->apps_halalas);
        $this->assertSame(1000, $count->confirmed_opening_halalas);
        $this->assertSame(0, $count->pending_incoming_counted_halalas);
        $this->assertSame(3000, $count->counted_halalas);
        $this->assertSame(5000, $count->expected_halalas);
        $this->assertSame(-2000, $count->variance_halalas);

        $revision = ShiftReportRevision::findOrFail($count->report_revision_id);
        $this->assertSame(1, $revision->revision_number);

        $allocation = ShiftLiabilityAllocation::where('cashier_shift_id', $shift->id)->sole();
        $this->assertSame(-2000, $allocation->variance_halalas);
        $this->assertSame($revision->id, $allocation->report_revision);
        $this->assertNotNull($allocation->cashier_confirmed_at);
        $this->assertSame('pending', $allocation->manager_approval_status);
        $shares = ShiftLiabilityShare::where('allocation_id', $allocation->id)->pluck('amount_halalas', 'responsible_type')->all();
        $this->assertSame(['branch_manager' => 800, 'cashier' => 1200], $shares);

        // The shortage is reported, not posted: no final ledger entry exists before S1-11.
        $this->assertSame($ledgerBefore, PersonalLedgerTransaction::count());

        // The same stored count is what the real LiabilityEvidenceSource resolves.
        DB::transaction(function () use ($shift, $revision) {
            $evidence = app(CashCountLiabilityEvidence::class)->report($shift->id);
            $this->assertSame(-2000, $evidence->varianceHalalas);
            $this->assertSame($revision->id, $evidence->revision);
            $this->assertSame($this->branch->id, $evidence->branchId);
            $this->assertTrue($evidence->completed);
        });
    }

    public function test_missing_counted_cash_is_rejected_and_never_treated_as_zero(): void
    {
        $payload = $this->fin01Payload();
        unset($payload['counted_cash']);

        $this->end($payload)->assertUnprocessable()->assertJsonValidationErrors('counted_cash');
        $this->assertNothingWritten();
    }

    public function test_explicit_zero_is_a_real_count_distinct_from_missing(): void
    {
        $this->end(['total_sales' => '0.00', 'counted_cash' => '0.00'])->assertOk();

        $count = ShiftReportCashCount::where('cashier_shift_id', $this->recipientShift->id)->sole();
        $this->assertSame(0, $count->counted_halalas);
        $this->assertSame(0, $count->variance_halalas);
    }

    public function test_over_precision_and_out_of_range_counts_are_rejected_before_any_write(): void
    {
        foreach (['30.001', '10000000000', '-1', 'abc'] as $bad) {
            $this->end($this->fin01Payload(['counted_cash' => $bad]))->assertUnprocessable()->assertJsonValidationErrors('counted_cash');
        }
        $this->assertNothingWritten();
    }

    public function test_approved_json_representation_noise_policy_still_applies_to_the_count(): void
    {
        $this->confirmedOpening();
        $this->end($this->fin01Payload(['counted_cash' => '30.000000000000004']))->assertOk();

        $this->assertSame(3000, ShiftReportCashCount::where('cashier_shift_id', $this->recipientShift->id)->value('counted_halalas'));
    }

    public function test_configured_opening_and_pending_requests_never_become_confirmed_opening(): void
    {
        // A configured opening figure and an unconfirmed pending request exist, but no confirmed receipt.
        $this->recipientShift->forceFill(['opening_balance' => '50.00'])->saveQuietly();
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/end-with-handover", [
            'total_sales' => '10.00', 'cash_collected' => '10.00', 'counted_cash' => '10.00',
            'next_cashier_id' => $this->recipient->id, 'handover_amount' => '10.00',
        ])->assertOk();
        $this->assertSame(0, CashierShiftHandoverReceipt::count());

        $this->end(['total_sales' => '20.00', 'cash_collected' => '20.00', 'counted_cash' => '20.00'])->assertOk();

        $count = ShiftReportCashCount::where('cashier_shift_id', $this->recipientShift->id)->sole();
        $this->assertSame(0, $count->confirmed_opening_halalas);
        $this->assertSame(2000, $count->expected_halalas);
        $this->assertSame(0, $count->variance_halalas);
    }

    public function test_d11_rejection_amount_is_pending_incoming_not_surplus_and_not_double_counted(): void
    {
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/end-with-handover", [
            'total_sales' => '500.00', 'cash_collected' => '500.00', 'counted_cash' => '500.00',
            'next_cashier_id' => $this->recipient->id, 'handover_amount' => '500.00',
        ])->assertOk();

        $this->actingAs($this->recipient, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/handover/reject", [
            'rejection_reason' => 'Counted 480', 'confirmed_amount' => '480.00', 'correction_reason' => 'actual_shortage',
        ])->assertOk();

        $evidence = ShiftTransferRejectionEvidence::query()->sole();
        $this->assertSame(CashierShiftHandover::where('cashier_shift_id', $this->senderShift->id)->value('id'), $evidence->cashier_shift_handover_id);
        $this->assertSame(50000, $evidence->requested_halalas);
        $this->assertSame(48000, $evidence->physical_halalas);
        $this->assertSame($this->recipient->id, $evidence->recipient_id);
        $this->assertSame($this->recipientShift->id, $evidence->receiving_cashier_shift_id);
        $this->assertNotNull($evidence->rejected_at);
        $this->assertSame(0, CashierShiftHandoverReceipt::count());

        // The recipient sold 100 in cash and physically holds 100 + the 480 still owned by the sender.
        $this->end(['total_sales' => '100.00', 'cash_collected' => '100.00', 'counted_cash' => '580.00'])->assertOk();

        $count = ShiftReportCashCount::where('cashier_shift_id', $this->recipientShift->id)->sole();
        $this->assertSame(58000, $count->counted_halalas);
        $this->assertSame(48000, $count->pending_incoming_counted_halalas);
        $this->assertSame(0, $count->confirmed_opening_halalas);
        $this->assertSame(10000, $count->expected_halalas);
        $this->assertSame(0, $count->variance_halalas, 'the 480 is not a surplus and is not double counted');
        $this->assertSame(0, ShiftLiabilityAllocation::count());
    }

    public function test_count_below_pending_incoming_is_rejected_without_writes(): void
    {
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/end-with-handover", [
            'total_sales' => '500.00', 'cash_collected' => '500.00', 'counted_cash' => '500.00',
            'next_cashier_id' => $this->recipient->id, 'handover_amount' => '500.00',
        ])->assertOk();
        $this->actingAs($this->recipient, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/handover/reject", [
            'rejection_reason' => 'Counted 480', 'confirmed_amount' => '480.00', 'correction_reason' => 'actual_shortage',
        ])->assertOk();

        $this->end(['total_sales' => '100.00', 'cash_collected' => '100.00', 'counted_cash' => '100.00'])
            ->assertUnprocessable()->assertJsonValidationErrors('counted_cash');
        $this->assertNothingWritten();
    }

    public function test_shortage_without_complete_allocation_rolls_everything_back(): void
    {
        $this->confirmedOpening();

        $partial = $this->fin01Payload(['shortage_allocations' => [
            ['responsible_type' => 'cashier', 'responsible_id' => $this->recipient->id, 'amount' => '12.00'],
        ]]);
        $this->end($partial)->assertUnprocessable();
        $this->assertNothingWritten();

        $excess = $this->fin01Payload(['shortage_allocations' => [
            ['responsible_type' => 'cashier', 'responsible_id' => $this->recipient->id, 'amount' => '20.01'],
        ]]);
        $this->end($excess)->assertUnprocessable();
        $this->assertNothingWritten();

        $none = $this->fin01Payload();
        unset($none['shortage_allocations']);
        $this->end($none)->assertUnprocessable();
        $this->assertNothingWritten();
    }

    public function test_allocation_outside_the_branch_or_company_is_rejected_without_partial_write(): void
    {
        $this->confirmedOpening();
        $otherBranch = Branch::factory()->create(['asab_company_id' => $this->branch->asab_company_id]);
        $otherCompanyBranch = Branch::factory()->create();
        $outsiders = [
            Cashier::factory()->create(['branch_id' => $otherBranch->id, 'created_by' => $this->manager->id]),
            Cashier::factory()->create(['branch_id' => $otherCompanyBranch->id, 'created_by' => $this->manager->id]),
        ];

        foreach ($outsiders as $outsider) {
            $this->end($this->fin01Payload(['shortage_allocations' => [
                ['responsible_type' => 'cashier', 'responsible_id' => $this->recipient->id, 'amount' => '12.00'],
                ['responsible_type' => 'cashier', 'responsible_id' => $outsider->id, 'amount' => '8.00'],
            ]]))->assertForbidden();
            $this->assertNothingWritten();
        }
    }

    public function test_balanced_and_surplus_reports_create_no_employee_liability_and_reject_allocations(): void
    {
        $this->confirmedOpening();

        // Surplus: counted 45 vs expected 50 + ... use 60 (> expected 50) with allocations supplied.
        $this->end($this->fin01Payload(['counted_cash' => '60.00']))->assertUnprocessable()->assertJsonValidationErrors('shortage_allocations');
        $this->assertNothingWritten();

        $surplus = $this->fin01Payload(['counted_cash' => '60.00']);
        unset($surplus['shortage_allocations']);
        $this->end($surplus)->assertOk();

        $count = ShiftReportCashCount::where('cashier_shift_id', $this->recipientShift->id)->sole();
        $this->assertSame(1000, $count->variance_halalas);
        $this->assertSame(11500, $count->gross_halalas, 'a surplus never increases sales');
        $this->assertSame(0, ShiftLiabilityAllocation::count());
    }

    public function test_injected_allocation_write_failure_rolls_back_report_count_and_revision(): void
    {
        $this->confirmedOpening();
        ShiftLiabilityAllocation::creating(static function () {
            throw new \RuntimeException('injected allocation failure');
        });

        $this->end($this->fin01Payload())->assertStatus(500);
        ShiftLiabilityAllocation::flushEventListeners();

        $this->assertNothingWritten();
    }

    public function test_same_key_same_payload_applies_the_effect_once_and_changed_payload_is_rejected(): void
    {
        $this->confirmedOpening();
        $key = (string) Str::uuid();

        $first = $this->end($this->fin01Payload(), $key);
        $retry = $this->end($this->fin01Payload(), $key);
        $first->assertOk();
        $this->assertSame($first->getContent(), $retry->getContent());
        $this->assertSame(1, ShiftReportCashCount::where('cashier_shift_id', $this->recipientShift->id)->count());
        $this->assertSame(1, ShiftLiabilityAllocation::where('cashier_shift_id', $this->recipientShift->id)->count());
        $this->assertSame(1, ShiftReportAggregate::where('source_id', $this->recipientShift->id)->sole()->current_revision_number, 'the retry must not record a second report revision');

        $changed = $this->end($this->fin01Payload(['counted_cash' => '31.00']), $key);
        $changed->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
        $this->assertSame(3000, ShiftReportCashCount::where('cashier_shift_id', $this->recipientShift->id)->value('counted_halalas'));
    }

    public function test_handover_revisions_carry_the_same_count_so_the_allocation_stays_current(): void
    {
        $service = app(\Modules\Shift\Liability\ShiftLiabilityService::class);
        $shortage = [
            'total_sales' => '100.00', 'cash_collected' => '100.00', 'counted_cash' => '90.00',
            'shortage_allocations' => [['responsible_type' => 'cashier', 'responsible_id' => $this->sender->id, 'amount' => '10.00']],
        ];

        // Path 1: end-with-handover records the report revision, then the handover advances it.
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/end-with-handover", $shortage + [
            'next_cashier_id' => $this->recipient->id, 'handover_amount' => '90.00',
        ])->assertOk();

        $counts = ShiftReportCashCount::where('cashier_shift_id', $this->senderShift->id)->get();
        $this->assertCount(2, $counts, 'the handover revision carries the count forward');
        $this->assertSame(1, $counts->pluck('counted_revision_id')->unique()->count());
        $this->assertSame([-1000], $counts->pluck('variance_halalas')->unique()->values()->all());

        DB::transaction(fn () => $service->approve($this->senderShift->id, $this->manager, 1));
        $this->assertSame('approved', ShiftLiabilityAllocation::where('cashier_shift_id', $this->senderShift->id)->sole()->manager_approval_status);

        // Path 2: end only, then start-handover advances the revision afterwards.
        $this->actingAs($this->recipient, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->recipientShift->id}/end", [
            'total_sales' => '100.00', 'cash_collected' => '100.00', 'counted_cash' => '90.00',
            'shortage_allocations' => [['responsible_type' => 'cashier', 'responsible_id' => $this->recipient->id, 'amount' => '10.00']],
        ])->assertOk();
        $this->actingAs($this->recipient, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->recipientShift->id}/start-handover", [
            'next_cashier_id' => $this->sender->id, 'handover_amount' => '90.00',
        ])->assertOk();

        $this->assertSame(2, ShiftReportCashCount::where('cashier_shift_id', $this->recipientShift->id)->count());
        DB::transaction(fn () => $service->approve($this->recipientShift->id, $this->manager, 1));
        $this->assertSame('approved', ShiftLiabilityAllocation::where('cashier_shift_id', $this->recipientShift->id)->sole()->manager_approval_status);
    }

    public function test_admin_projection_shows_the_real_count_expected_and_variance_not_a_sales_derivation(): void
    {
        $this->confirmedOpening();
        $this->end($this->fin01Payload())->assertOk();

        $admin = AdminShift::where('legacy_shift_id', $this->recipientShift->id)->firstOrFail();
        $this->assertSame(3000, (int) $admin->cash_actual, 'the physical count, not cash sales + opening (5000)');
        $this->assertSame(5000, (int) $admin->cash_expected);
        $this->assertSame(-2000, (int) $admin->variance);
        $this->assertSame(1000, (int) $admin->opening_float, 'confirmed receipts only');

        $op = Operation::where('module_key', 'shifts')->where('payload->shiftId', $admin->id)->firstOrFail();
        $this->assertSame(3000, $op->payload['cashActualHalalas']);
        $this->assertSame(5000, $op->payload['cashExpectedHalalas']);
        $this->assertSame(-2000, $op->payload['varianceHalalas']);
        $this->assertSame(11500, $op->payload['salesHalalas']);
        $this->assertSame(5000, $op->payload['cardTotalHalalas']);
        $this->assertSame(2500, $op->payload['aggregatorTotalsHalalas']);
        $this->assertSame('counted', $op->payload['cashCountState']);
        $this->assertSame(0, $op->payload['pendingIncomingCountedHalalas']);
    }

    public function test_admin_projection_excludes_pending_incoming_from_the_variance(): void
    {
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/end-with-handover", [
            'total_sales' => '500.00', 'cash_collected' => '500.00', 'counted_cash' => '500.00',
            'next_cashier_id' => $this->recipient->id, 'handover_amount' => '500.00',
        ])->assertOk();
        $this->actingAs($this->recipient, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/handover/reject", [
            'rejection_reason' => 'Counted 480', 'confirmed_amount' => '480.00', 'correction_reason' => 'actual_shortage',
        ])->assertOk();
        $this->end(['total_sales' => '100.00', 'cash_collected' => '100.00', 'counted_cash' => '580.00'])->assertOk();

        $admin = AdminShift::where('legacy_shift_id', $this->recipientShift->id)->firstOrFail();
        $op = Operation::where('module_key', 'shifts')->where('payload->shiftId', $admin->id)->firstOrFail();
        $this->assertSame(58000, $op->payload['cashActualHalalas']);
        $this->assertSame(10000, $op->payload['cashExpectedHalalas']);
        $this->assertSame(0, $op->payload['varianceHalalas'], 'the 480 owned by the sender is neither surplus nor the recipient\'s expected cash');
        $this->assertSame(48000, $op->payload['pendingIncomingCountedHalalas']);
    }

    public function test_a_shift_without_a_count_keeps_the_legacy_projection_labelled_unknown(): void
    {
        $template = Shift::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true, 'start_time' => '23:00:00', 'end_time' => '23:30:00']);
        $legacy = CashierShift::factory()->create(['cashier_id' => $this->recipient->id, 'shift_id' => $template->id, 'total_sales' => '115.00', 'cash_collected' => '40.00', 'card_payments' => '50.00', 'opening_balance' => '0.00']);

        event(new ShiftEndedEvent($legacy, false));

        $admin = AdminShift::where('legacy_shift_id', $legacy->id)->firstOrFail();
        $op = Operation::where('module_key', 'shifts')->where('payload->shiftId', $admin->id)->firstOrFail();
        $this->assertSame('unknown', $op->payload['cashCountState']);
        $this->assertArrayNotHasKey('pendingIncomingCountedHalalas', $op->payload);
        $this->assertSame(0, ShiftReportCashCount::where('cashier_shift_id', $legacy->id)->count());
    }

    public function test_reconciliation_is_null_without_a_count_and_absent_from_lists(): void
    {
        $this->assertNull(app(\Modules\Shift\Services\ShiftCashCountService::class)->reconciliation($this->recipientShift->id));
        $resource = (new \Modules\Shift\Transformers\ShiftDetailResource($this->recipientShift->fresh()))->resolve(request());
        $this->assertArrayNotHasKey('cash_reconciliation', $resource, 'a response that did not attach it (lists) never queries or carries it');

        $attached = app(\Modules\Shift\Services\ShiftCashCountService::class)->attachReconciliation($this->recipientShift->fresh());
        $resource = (new \Modules\Shift\Transformers\ShiftDetailResource($attached))->resolve(request());
        $this->assertArrayHasKey('cash_reconciliation', $resource);
        $this->assertNull($resource['cash_reconciliation'], 'no count evidence is null, never a zero count');
    }

    public function test_admin_presenter_exposes_count_state_and_pending_incoming_in_halalas(): void
    {
        $this->confirmedOpening();
        $this->end($this->fin01Payload())->assertOk();
        $admin = AdminShift::where('legacy_shift_id', $this->recipientShift->id)->firstOrFail();
        $presented = app(\Modules\Admin\Services\ShiftPresenter::class)->present($admin);
        $this->assertSame('counted', $presented['cashCountState']);
        $this->assertSame(0, $presented['pendingIncomingCountedHalalas']);
        $this->assertSame(3000, $presented['cashActualHalalas']);
        $this->assertSame(-2000, $presented['varianceHalalas']);

        $template = Shift::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true, 'start_time' => '23:00:00', 'end_time' => '23:30:00']);
        $legacy = CashierShift::factory()->create(['cashier_id' => $this->recipient->id, 'shift_id' => $template->id, 'total_sales' => '115.00', 'cash_collected' => '40.00', 'card_payments' => '50.00']);
        event(new ShiftEndedEvent($legacy, false));
        $unknown = app(\Modules\Admin\Services\ShiftPresenter::class)->present(AdminShift::where('legacy_shift_id', $legacy->id)->firstOrFail());
        $this->assertSame('unknown', $unknown['cashCountState']);
        $this->assertNull($unknown['pendingIncomingCountedHalalas']);
    }

    public function test_historical_report_without_a_count_is_not_liability_evidence(): void
    {
        $historical = $this->liveShift($this->recipient, '22:00:00', '23:00:00', today()->subDay()->toDateString());
        $historical->update(['status' => ShiftStatus::COMPLETED, 'total_sales' => '115.00', 'cash_collected' => '40.00', 'closing_balance' => '40.00', 'variance' => '-20.00']);

        try {
            DB::transaction(fn () => app(CashCountLiabilityEvidence::class)->report($historical->id));
            $this->fail('A report without a stored count must not produce evidence.');
        } catch (ConflictHttpException $e) {
            $this->assertSame('LIABILITY_EVIDENCE_UNAVAILABLE', $e->getMessage());
        }
        $this->assertSame(0, ShiftReportCashCount::count());
    }

    private function headUser(): AsabUser
    {
        $head = AsabUser::create(['company_id' => $this->branch->asab_company_id, 'name' => 'رئيس', 'email' => 'head@s110.test', 'password' => 'secret-password', 'status' => 'active']);
        AsabUserRole::create(['user_id' => $head->id, 'role_key' => 'head', 'scope' => 'all']);

        return $head;
    }

    private function countedShortageOperation(): array
    {
        $this->confirmedOpening();
        $this->end($this->fin01Payload())->assertOk();
        $admin = AdminShift::where('legacy_shift_id', $this->recipientShift->id)->firstOrFail();
        $op = Operation::where('module_key', 'shifts')->where('payload->shiftId', $admin->id)->firstOrFail();

        return [$admin, $op];
    }

    /** D15: Head final approval cannot charge a counted legacy shortage outside the branch allocation. */
    public function test_final_approval_of_a_counted_shortage_is_refused_and_posts_nothing(): void
    {
        [$admin, $op] = $this->countedShortageOperation();
        $accountant = AsabUser::where('email', 'acc@s110.test')->firstOrFail();
        app(OperationService::class)->approve($op, $accountant);

        try {
            app(OperationService::class)->finalApprove($op->fresh(), $this->headUser());
            $this->fail('A counted shortage must wait for the branch liability approval.');
        } catch (AsabException $exception) {
            $this->assertSame('BRANCH_LIABILITY_APPROVAL_PENDING', $exception->errorCode);
            $this->assertSame(409, $exception->status);
        }

        $this->assertSame(0, EmployeeMovement::where('ref_operation_id', $op->id)->count());
        $this->assertSame(Operation::STATUS_APPROVED, $op->fresh()->status);
        $this->assertSame('pending_review', $admin->fresh()->status);
    }

    /** D15: the accountant's own split is not an authority over a counted shortage. */
    public function test_accountant_split_of_a_counted_shortage_is_refused(): void
    {
        [$admin, $op] = $this->countedShortageOperation();
        $accountant = AsabUser::where('email', 'acc@s110.test')->firstOrFail();
        $employee = Employee::where('legacy_cashier_id', $this->recipient->id)->firstOrFail();

        try {
            app(\Modules\Admin\Services\ShiftCloseService::class)->setVarianceAllocations($op, [['employeeId' => $employee->id, 'amountHalalas' => 2000]], $accountant);
            $this->fail('The branch allocation is the only authority.');
        } catch (AsabException $exception) {
            $this->assertSame('BRANCH_ALLOCATION_AUTHORITATIVE', $exception->errorCode);
        }
        $this->assertArrayNotHasKey('varianceAllocations', $op->fresh()->payload);
    }

    /** D15: a balanced counted shift still closes normally, and one refusal does not fail the rest of a bulk. */
    public function test_bulk_final_approval_reports_each_item_and_balanced_counted_shifts_still_close(): void
    {
        [, $shortageOp] = $this->countedShortageOperation();
        $accountant = AsabUser::where('email', 'acc@s110.test')->firstOrFail();
        $head = $this->headUser();

        // A second counted, balanced shift for another cashier.
        $third = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);
        Employee::create(['company_id' => $this->branch->asab_company_id, 'branch_id' => $this->branch->id, 'emp_number' => '3002', 'name' => 'ثالث', 'role' => 'كاشير', 'status' => 'active'])
            ->forceFill(['legacy_cashier_id' => $third->id])->save();
        $thirdShift = $this->liveShift($third, '23:00:00', '23:30:00');
        $this->actingAs($third, 'sanctum')->postJson("/api/v1/cashier/shifts/{$thirdShift->id}/end", [
            'total_sales' => '100.00', 'cash_collected' => '100.00', 'counted_cash' => '100.00',
        ])->assertOk();
        $balancedAdmin = AdminShift::where('legacy_shift_id', $thirdShift->id)->first();
        $this->assertNotNull($balancedAdmin);
        $balancedOp = Operation::where('module_key', 'shifts')->where('payload->shiftId', $balancedAdmin->id)->firstOrFail();

        $service = app(OperationService::class);
        $service->approve($shortageOp, $accountant);
        $service->approve($balancedOp, $accountant);
        $result = $service->bulkFinalApprove([$shortageOp->id, $balancedOp->id], $head);

        $this->assertSame([$balancedOp->public_id], $result['finalApproved']);
        $this->assertSame([['id' => $shortageOp->id, 'code' => 'BRANCH_LIABILITY_APPROVAL_PENDING']], $result['failed']);
        $this->assertSame('closed', $balancedAdmin->fresh()->status);
        $this->assertSame(0, EmployeeMovement::count());
    }

    private function liveShift(Cashier $cashier, string $start, string $end, ?string $date = null): CashierShift
    {
        $template = Shift::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true, 'start_time' => $start, 'end_time' => $end]);
        $shift = CashierShift::create([
            'cashier_id' => $cashier->id, 'shift_id' => $template->id,
            'shift_date' => $date ?? today()->toDateString(), 'status' => ShiftStatus::NOT_STARTED->value,
        ]);
        $shift->update(['status' => ShiftStatus::IN_PROGRESS, 'actual_start_time' => now()]);

        return $shift->fresh();
    }

    public function test_preview_equals_end_for_fin01_and_writes_nothing(): void
    {
        $this->confirmedOpening();
        $payload = $this->fin01Payload();
        $before = [DB::table('shift_report_cash_counts')->count(), DB::table('shift_liability_allocations')->count(), DB::table('shift_report_revisions')->count()];
        $previewPayload = collect($payload)->only(['total_sales', 'card_payments', 'aggregators', 'counted_cash'])->all();

        $preview = $this->actingAs($this->recipient, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$this->recipientShift->id}/cash-reconciliation/preview", $previewPayload)->assertOk();

        $this->assertSame($before, [DB::table('shift_report_cash_counts')->count(), DB::table('shift_liability_allocations')->count(), DB::table('shift_report_revisions')->count()]);
        $this->assertSame(ShiftStatus::IN_PROGRESS, $this->recipientShift->fresh()->status);
        $this->assertEquals(20.0, $preview->json('data.shortage_to_allocate'));
        $this->assertTrue($preview->json('data.allocation_required'));

        $end = $this->end($payload)->assertOk();
        $this->assertEquals($end->json('data.summary.cash_reconciliation'), $preview->json('data.cash_reconciliation'));
        $this->assertEquals(50.0, $preview->json('data.cash_reconciliation.expected_cash'));
        $this->assertEquals(-20.0, $preview->json('data.cash_reconciliation.cash_variance'));
    }

    public function test_preview_balanced_surplus_and_missing_count_and_manager_access(): void
    {
        $this->confirmedOpening();
        $url = "/api/v1/cashier/shifts/{$this->recipientShift->id}/cash-reconciliation/preview";
        $base = ['total_sales' => '115.00', 'card_payments' => '50.00', 'aggregators' => [['aggregator_id' => $this->aggregator->id, 'amount' => '25.00']]];

        $balanced = $this->actingAs($this->recipient, 'sanctum')->postJson($url, $base + ['counted_cash' => '50.00'])->assertOk();
        $this->assertSame('balanced', $balanced->json('data.cash_reconciliation.cash_variance_type'));
        $this->assertFalse($balanced->json('data.allocation_required'));
        $this->assertEquals(0, $balanced->json('data.shortage_to_allocate'));

        $surplus = $this->actingAs($this->recipient, 'sanctum')->postJson($url, $base + ['counted_cash' => '55.00'])->assertOk();
        $this->assertSame('surplus', $surplus->json('data.cash_reconciliation.cash_variance_type'));
        $this->assertFalse($surplus->json('data.allocation_required'));

        $this->actingAs($this->recipient, 'sanctum')->postJson($url, $base)->assertUnprocessable()->assertJsonValidationErrors('counted_cash');

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/branch-manager/shifts/{$this->recipientShift->id}/cash-reconciliation/preview", $base + ['counted_cash' => '30.00'])
            ->assertOk()->assertJsonPath('data.allocation_required', true);

        // Another cashier never previews a shift that is not theirs.
        $this->actingAs($this->sender, 'sanctum')->postJson($url, $base + ['counted_cash' => '30.00'])->assertNotFound();
    }

    public function test_shift_detail_exposes_reconciliation_or_null(): void
    {
        $this->confirmedOpening();
        $detail = fn () => $this->actingAs($this->manager, 'sanctum')->getJson("/api/v1/branch-manager/shifts/cashiers/{$this->recipientShift->id}")->assertOk();

        $this->assertTrue(array_key_exists('cash_reconciliation', $detail()->json('data')));
        $this->assertNull($detail()->json('data.cash_reconciliation'));

        $this->end($this->fin01Payload())->assertOk();
        $block = $detail()->json('data.cash_reconciliation');
        $this->assertEquals(-20.0, $block['cash_variance']);
        $this->assertEquals(50.0, $block['expected_cash']);
        $this->assertSame('shortage', $block['cash_variance_type']);
        $this->actingAs($this->manager, 'sanctum')->getJson("/api/v1/branch-manager/shifts/{$this->recipientShift->id}")
            ->assertOk()->assertJsonPath('data.cash_reconciliation.cash_variance_type', 'shortage');
    }

    public function test_incomplete_allocation_error_is_on_shortage_allocations_key(): void
    {
        $this->confirmedOpening();
        $this->end($this->fin01Payload(['shortage_allocations' => [
            ['responsible_type' => 'cashier', 'responsible_id' => $this->recipient->id, 'amount' => '5.00'],
        ]]))->assertUnprocessable()->assertJsonValidationErrors('shortage_allocations');
        $this->assertNothingWritten();
    }

    public function test_cashier_token_on_reassign_with_handover_is_403_and_stores_no_file(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        $target = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);

        $this->actingAs($this->sender, 'sanctum')->post("/api/v1/branch-manager/shifts/{$this->senderShift->id}/reassign-with-handover", [
            'new_cashier_id' => $target->id, 'handover_amount' => '5.00',
            'pos_receipt' => \Illuminate\Http\UploadedFile::fake()->image('r.png'),
        ], ['Accept' => 'application/json'])->assertForbidden();

        $this->assertSame([], \Illuminate\Support\Facades\Storage::disk('public')->allFiles());
    }
}
