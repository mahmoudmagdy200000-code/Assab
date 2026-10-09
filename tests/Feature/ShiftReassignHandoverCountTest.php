<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Aggregator\Models\Aggregator;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHistory;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftHandoverStatus;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Modules\Shift\Models\ShiftReportAggregate;
use Modules\Shift\Models\ShiftReportCashCount;
use Modules\Shift\Models\ShiftSalesBreakdown;
use Tests\TestCase;

/** S1-10: reassign-with-handover uses the shared count rule and the S1-09 replay protection, atomically. */
class ShiftReassignHandoverCountTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private BranchManager $manager;

    private Cashier $outgoing;

    private Cashier $incoming;

    private CashierShift $shift;

    private CashierShift $incomingShift;

    private Aggregator $aggregator;

    protected function setUp(): void
    {
        parent::setUp();

        $company = AsabCompany::create(['name' => 'Reassign Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => 'B', 'sub_status' => 'active', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['asab_brand_id' => $brand->id, 'asab_company_id' => $company->id]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true, 'status' => 'active']);
        $this->outgoing = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);
        $this->incoming = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);
        $template = Shift::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $this->shift = CashierShift::create([
            'cashier_id' => $this->outgoing->id, 'shift_id' => $template->id,
            'shift_date' => today()->toDateString(), 'status' => ShiftStatus::NOT_STARTED->value,
        ]);
        $this->shift->update(['status' => ShiftStatus::IN_PROGRESS, 'actual_start_time' => now()]);
        $this->aggregator = Aggregator::factory()->create();
    }

    /** gross 115, cards 50, apps 25 → expected 40; counted 30 → shortage 10, split 6 + 4. */
    private function report(array $override = []): array
    {
        return array_merge([
            'new_cashier_id' => $this->incoming->id, 'handover_amount' => '30.00',
            'current_sales' => '115.00', 'cash_collected' => '40.00', 'card_payments' => '50.00',
            'aggregators' => [['aggregator_id' => $this->aggregator->id, 'amount' => '25.00']],
            'counted_cash' => '30.00',
            'shortage_allocations' => [
                ['responsible_type' => 'cashier', 'responsible_id' => $this->outgoing->id, 'amount' => '6.00'],
                ['responsible_type' => 'branch_manager', 'responsible_id' => $this->manager->id, 'amount' => '4.00'],
            ],
            'allocation_reason' => 'Manager reassigned the shift mid-day',
        ], $override);
    }

    private function reassign(array $payload, ?string $key = null)
    {
        $request = $this->actingAs($this->manager, 'sanctum');
        if ($key !== null) {
            $request = $request->withHeaders(['Idempotency-Key' => $key]);
        }

        $response = $request->postJson("/api/branch-manager/shifts/{$this->shift->id}/reassign-with-handover", $payload);
        if ($response->isSuccessful()) {
            $this->incomingShift = CashierShift::findOrFail($response->json('data.shift.id'));
        }

        return $response;
    }

    private function assertNothingWritten(): void
    {
        $shift = $this->shift->fresh();
        $this->assertSame(ShiftStatus::IN_PROGRESS, $shift->status);
        $this->assertSame($this->outgoing->id, $shift->cashier_id);
        $this->assertSame('0.00', (string) $shift->total_sales);
        $this->assertSame(0, ShiftHandoverStatus::where('cashier_shift_id', $shift->id)->count());
        $this->assertSame(0, ShiftSalesBreakdown::where('cashier_shift_id', $shift->id)->count());
        $this->assertSame(0, ShiftReportCashCount::count());
        $this->assertSame(0, ShiftLiabilityAllocation::count());
        $this->assertNull(ShiftReportAggregate::where('source_id', $shift->id)->first());
        $this->assertSame(0, CashierShiftHistory::where('cashier_shift_id', $shift->id)->where('action', 'reassigned_with_handover')->count());
    }

    public function test_independent_incoming_report_preserves_predecessor_count_and_liability(): void
    {
        $response = $this->reassign($this->report())->assertOk();
        $incomingId = $response->json('data.shift.id');
        $this->assertNotSame($this->shift->id, $incomingId);
        $this->assertSame($this->outgoing->id, $this->shift->fresh()->cashier_id);
        $beforeCount = ShiftReportCashCount::where('cashier_shift_id', $this->shift->id)->sole()->getAttributes();
        $beforeAllocation = ShiftLiabilityAllocation::where('cashier_shift_id', $this->shift->id)->sole()->getAttributes();

        $this->actingAs($this->incoming, 'sanctum')->postJson("/api/v1/cashier/shifts/{$incomingId}/start")->assertOk();
        $incoming = CashierShift::findOrFail($incomingId);
        $this->assertSame('0.00', (string) $incoming->opening_balance);
        $this->assertSame(0, DB::table('cashier_shift_handover_receipts')->count());
        $this->assertSame(0, DB::table('cashier_custody_transactions')->count());
        $this->assertSame(1, ShiftLiabilityAllocation::count());

        $this->actingAs($this->incoming, 'sanctum')->postJson("/api/v1/cashier/shifts/{$incomingId}/end-with-handover", [
            'total_sales' => '100.00', 'cash_collected' => '100.00', 'counted_cash' => '100.00',
            'handover_to_type' => 'branch_manager', 'handover_amount' => '100.00',
        ])->assertOk();
        $this->assertNotNull(app(\Modules\Shift\Services\ShiftCashCountService::class)->currentFor($incomingId));
        $this->assertSame($beforeCount, ShiftReportCashCount::where('cashier_shift_id', $this->shift->id)->sole()->getAttributes());
        $this->assertSame($beforeAllocation, ShiftLiabilityAllocation::where('cashier_shift_id', $this->shift->id)->sole()->getAttributes());
        $this->assertSame(0, DB::table('cashier_shift_handover_receipts')->count());
    }

    public function test_incoming_can_report_then_request_handover_before_predecessor_report_or_receipt(): void
    {
        $response = $this->reassign(['new_cashier_id' => $this->incoming->id, 'handover_amount' => '30.00'])->assertOk();
        $incomingId = $response->json('data.shift.id');
        $this->assertNotSame($this->shift->id, $incomingId);
        $this->assertSame(ShiftStatus::IN_PROGRESS, $this->shift->fresh()->status);
        $this->actingAs($this->incoming, 'sanctum')->postJson("/api/v1/cashier/shifts/{$incomingId}/reassign/accept")->assertOk();
        $this->actingAs($this->incoming, 'sanctum')->postJson("/api/v1/cashier/shifts/{$incomingId}/start")->assertOk();
        $this->actingAs($this->incoming, 'sanctum')->postJson("/api/v1/cashier/shifts/{$incomingId}/end", [
            'total_sales' => '100.00', 'cash_collected' => '100.00', 'counted_cash' => '100.00',
        ])->assertOk();
        $this->actingAs($this->incoming, 'sanctum')->postJson("/api/v1/cashier/shifts/{$incomingId}/start-handover", [
            'handover_to_type' => 'branch_manager', 'handover_amount' => '100.00',
        ])->assertOk();
        $this->assertSame($this->outgoing->id, $this->shift->fresh()->cashier_id);
        $this->assertNull(app(\Modules\Shift\Services\ShiftCashCountService::class)->currentFor($this->shift->id));
        $this->assertSame(0, DB::table('cashier_shift_handover_receipts')->count());
        $this->actingAs($this->incoming, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->shift->id}/end", [
            'total_sales' => '999.00', 'counted_cash' => '999.00',
        ])->assertStatus(404);
    }

    public function test_timeout_notifies_without_financial_completion_and_report_can_still_be_submitted(): void
    {
        $this->shift->update(['actual_start_time' => now()->subHours(13)]);
        \Illuminate\Support\Facades\Event::fake([\Modules\Shift\Events\ShiftEndedEvent::class]);
        \Illuminate\Support\Facades\Notification::fake();
        $before = $this->financialSnapshot();
        $this->artisan('shifts:auto-end-overdue')->assertSuccessful();
        $this->assertSame($before, $this->financialSnapshot());
        $this->assertSame(ShiftStatus::IN_PROGRESS, $this->shift->fresh()->status);
        $this->assertNull($this->shift->fresh()->actual_end_time);
        \Illuminate\Support\Facades\Event::assertNotDispatched(\Modules\Shift\Events\ShiftEndedEvent::class);
        \Illuminate\Support\Facades\Notification::assertSentTo($this->manager, \Modules\Notification\Notifications\BaseNotification::class);
        $this->actingAs($this->outgoing, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->shift->id}/end", [
            'total_sales' => '100.00', 'counted_cash' => '100.00',
        ])->assertOk();
    }

    public function test_predecessor_can_report_later_and_only_receipt_confirms_incoming_opening(): void
    {
        $this->reassign(['new_cashier_id' => $this->incoming->id, 'handover_amount' => '30.00'])->assertOk();
        $incomingId = $this->incomingShift->id;
        $this->actingAs($this->incoming, 'sanctum')->postJson("/api/v1/cashier/shifts/{$incomingId}/start")->assertOk();
        $this->actingAs($this->outgoing, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->shift->id}/end-with-handover", [
            'total_sales' => '100.00', 'cash_collected' => '100.00', 'counted_cash' => '90.00',
            'shortage_allocations' => [['responsible_type' => 'cashier', 'responsible_id' => $this->outgoing->id, 'amount' => '10.00']],
            'handover_to_type' => 'cashier', 'handover_to_id' => $this->incoming->id,
            'next_cashier_id' => $this->incoming->id, 'handover_amount' => '90.00',
        ])->assertOk();
        $this->assertSame($this->outgoing->id, $this->shift->fresh()->cashier_id);
        $this->assertSame($this->outgoing->id, ShiftLiabilityAllocation::where('cashier_shift_id', $this->shift->id)->sole()->shares()->sole()->responsible_id);
        $this->assertSame('0.00', (string) $this->incomingShift->fresh()->opening_balance);
        $this->assertSame(0, DB::table('cashier_shift_handover_receipts')->count());
        $handover = \Modules\Shift\Models\CashierShiftHandover::where('cashier_shift_id', $this->shift->id)->sole();
        app(\Modules\Shift\Services\ShiftTransferReceiptService::class)->confirmHandover($handover->id, $this->incoming, '90.00', $incomingId);
        $this->assertSame('90.00', (string) $this->incomingShift->fresh()->opening_balance);
        $this->assertSame(1, DB::table('cashier_shift_handover_receipts')->count());
        $this->assertSame(0, ShiftLiabilityAllocation::where('cashier_shift_id', $incomingId)->count());
    }

    public function test_separation_failure_rolls_back_both_owners_and_all_evidence(): void
    {
        CashierShiftHistory::creating(static function ($history) {
            if ($history->action === 'reassignment_work_assigned') {
                throw new \RuntimeException('injected separation history failure');
            }
        });
        try {
            $this->reassign($this->report())->assertStatus(500);
        } finally {
            CashierShiftHistory::flushEventListeners();
        }
        $this->assertNothingWritten();
        $this->assertSame(1, CashierShift::count());
    }

    public function test_pending_predecessor_transfer_does_not_block_work_or_change_the_request(): void
    {
        app(\Modules\Shift\Services\HandoverService::class)->recordHandover($this->shift, [
            'handover_to_type' => 'cashier', 'handover_to_id' => $this->incoming->id,
            'next_cashier_id' => $this->incoming->id, 'handover_amount' => '30.00',
        ], $this->outgoing);
        $request = \Modules\Shift\Models\CashierShiftHandover::where('cashier_shift_id', $this->shift->id)->sole();
        $before = $request->getAttributes();
        $this->reassign(['new_cashier_id' => $this->incoming->id, 'handover_amount' => '30.00'])->assertOk();
        $this->actingAs($this->incoming, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->incomingShift->id}/start")->assertOk();
        $this->assertSame($before, $request->fresh()->getAttributes());
        $this->assertSame('0.00', (string) $this->incomingShift->fresh()->opening_balance);
        $this->assertSame(0, DB::table('cashier_shift_handover_receipts')->count());
        $this->assertSame(0, ShiftLiabilityAllocation::count());
    }

    public function test_consecutive_reassignments_preserve_each_started_cashier_report_owner(): void
    {
        $this->reassign($this->report())->assertOk();
        $middleId = $this->incomingShift->id;
        $this->actingAs($this->incoming, 'sanctum')->postJson("/api/v1/cashier/shifts/{$middleId}/start")->assertOk();
        $third = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);
        $response = $this->actingAs($this->manager, 'sanctum')->postJson("/api/branch-manager/shifts/{$middleId}/reassign-with-handover", [
            'new_cashier_id' => $third->id, 'handover_amount' => '80.00',
            'current_sales' => '80.00', 'cash_collected' => '80.00', 'counted_cash' => '80.00',
        ])->assertOk();
        $thirdId = $response->json('data.shift.id');
        $this->assertNotSame($middleId, $thirdId);
        $this->assertSame($this->incoming->id, CashierShift::findOrFail($middleId)->cashier_id);
        $before = ShiftReportCashCount::where('cashier_shift_id', $middleId)->sole()->getAttributes();
        $this->actingAs($third, 'sanctum')->postJson("/api/v1/cashier/shifts/{$thirdId}/start")->assertOk();
        $this->actingAs($third, 'sanctum')->postJson("/api/v1/cashier/shifts/{$thirdId}/end-with-handover", [
            'total_sales' => '50.00', 'cash_collected' => '50.00', 'counted_cash' => '50.00',
            'handover_to_type' => 'branch_manager', 'handover_amount' => '50.00',
        ])->assertOk();
        $this->assertSame($before, ShiftReportCashCount::where('cashier_shift_id', $middleId)->sole()->getAttributes());
        $this->assertSame($this->outgoing->id, $this->shift->fresh()->cashier_id);
        $this->assertSame(3, CashierShift::count());
        $this->assertSame(0, DB::table('cashier_shift_handover_receipts')->count());
    }

    private function legacySharedCountedReassignment(): void
    {
        $this->actingAs($this->outgoing, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->shift->id}/end", [
            'total_sales' => '100.00', 'cash_collected' => '100.00', 'counted_cash' => '100.00',
        ])->assertOk();
        $this->shift->fresh()->update(['cashier_id' => $this->incoming->id,
            'original_cashier_id' => $this->outgoing->id, 'status' => ShiftStatus::REASSIGNED]);
        ShiftHandoverStatus::create(['cashier_shift_id' => $this->shift->id]);
        $this->shift->recordHistory('reassigned_with_handover', null, ['cashier_id' => $this->incoming->id]);
    }

    public function test_legacy_counted_accept_returns_the_new_work_id_and_preserves_old_report(): void
    {
        $this->legacySharedCountedReassignment();
        $before = ShiftReportCashCount::where('cashier_shift_id', $this->shift->id)->sole()->getAttributes();
        $response = $this->actingAs($this->incoming, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->shift->id}/reassign/accept")->assertOk();
        $incomingId = $response->json('data.shift.id');
        $this->assertNotSame($this->shift->id, $incomingId);
        $this->assertSame($this->shift->id, $response->json('data.source_cashier_shift_id'));
        $this->assertSame($this->outgoing->id, $this->shift->fresh()->cashier_id);
        $this->assertSame($before, ShiftReportCashCount::where('cashier_shift_id', $this->shift->id)->sole()->getAttributes());
        $this->actingAs($this->incoming, 'sanctum')->postJson("/api/v1/cashier/shifts/{$incomingId}/start")->assertOk();
        $this->assertSame('0.00', (string) CashierShift::findOrFail($incomingId)->opening_balance);
        $this->assertSame(0, DB::table('cashier_shift_handover_receipts')->count());
    }

    public function test_legacy_accept_rechecks_approval_before_creating_an_independent_row(): void
    {
        $this->legacySharedCountedReassignment();
        $this->shift->fresh()->handoverStatus->update(['manager_approval_status' => 'approved']);
        $before = $this->financialSnapshot();
        $this->actingAs($this->incoming, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->shift->id}/reassign/accept")
            ->assertStatus(409)->assertJsonPath('code', 'HANDOVER_CONFLICT');
        $this->assertSame($before, $this->financialSnapshot());
        $this->assertSame(1, CashierShift::count());
    }

    public function test_counted_report_is_calculated_allocated_and_reassigned_in_one_commit(): void
    {
        $this->reassign($this->report())->assertOk();

        $shift = $this->shift->fresh();
        $this->assertSame(ShiftStatus::REASSIGNED, $shift->status);
        $this->assertSame($this->outgoing->id, $shift->cashier_id);
        $this->assertSame($this->outgoing->id, $shift->original_cashier_id);
        $this->assertSame('100.00', (string) $shift->net_sales);

        $count = ShiftReportCashCount::where('cashier_shift_id', $shift->id)->sole();
        $this->assertSame([11500, 5000, 2500, 0, 3000, 4000, -1000], [
            $count->gross_halalas, $count->cards_halalas, $count->apps_halalas, $count->confirmed_opening_halalas,
            $count->counted_halalas, $count->expected_halalas, $count->variance_halalas,
        ]);
        $allocation = ShiftLiabilityAllocation::where('cashier_shift_id', $shift->id)->sole();
        $this->assertSame(-1000, $allocation->variance_halalas);
        $this->assertSame($count->counted_revision_id, $allocation->report_revision);
        $this->assertNull($allocation->cashier_confirmed_at, 'a manager-submitted allocation requires no cashier reapproval');
        $this->assertSame(1, ShiftHandoverStatus::where('cashier_shift_id', $shift->id)->count());
        $this->assertSame(ShiftStatus::REASSIGNED, $this->incomingShift->fresh()->status);
    }

    public function test_handover_without_a_report_stores_no_count_evidence(): void
    {
        $this->reassign(['new_cashier_id' => $this->incoming->id, 'handover_amount' => '30.00'])->assertOk();

        $this->assertSame(ShiftStatus::REASSIGNED, $this->incomingShift->fresh()->status);
        $this->assertSame(0, ShiftReportCashCount::count(), 'no report, no count: unavailable, never an implied 0');
    }

    public function test_count_is_required_with_a_report_and_forbidden_without_one(): void
    {
        $missing = $this->report();
        unset($missing['counted_cash']);
        $this->reassign($missing)->assertUnprocessable()->assertJsonValidationErrors('counted_cash');
        $this->reassign(['new_cashier_id' => $this->incoming->id, 'handover_amount' => '30.00', 'counted_cash' => '30.00'])
            ->assertUnprocessable()->assertJsonValidationErrors('counted_cash');
        $this->assertNothingWritten();
    }

    public function test_incomplete_or_unreasoned_allocation_leaves_no_partial_write(): void
    {
        $partial = $this->report(['shortage_allocations' => [
            ['responsible_type' => 'cashier', 'responsible_id' => $this->outgoing->id, 'amount' => '6.00'],
        ]]);
        $this->reassign($partial)->assertUnprocessable();
        $this->assertNothingWritten();

        $unreasoned = $this->report();
        unset($unreasoned['allocation_reason']);
        $this->reassign($unreasoned)->assertUnprocessable();
        $this->assertNothingWritten();
    }

    public function test_failure_after_the_count_rolls_back_the_report_count_allocation_and_reassignment(): void
    {
        CashierShiftHistory::creating(static function ($history) {
            if ($history->action === 'reassigned_with_handover') {
                throw new \RuntimeException('injected history failure');
            }
        });

        $this->reassign($this->report())->assertStatus(500);
        CashierShiftHistory::flushEventListeners();

        $this->assertNothingWritten();
    }

    public function test_same_key_replays_once_and_a_changed_payload_is_rejected(): void
    {
        $key = (string) Str::uuid();

        $first = $this->reassign($this->report(), $key);
        $retry = $this->reassign($this->report(), $key);
        $first->assertOk();
        $this->assertSame($first->getContent(), $retry->getContent());
        $this->assertSame(1, ShiftReportCashCount::count());
        $this->assertSame(1, ShiftLiabilityAllocation::count());
        $this->assertSame(1, ShiftHandoverStatus::where('cashier_shift_id', $this->shift->id)->count());
        $this->assertSame(1, CashierShiftHistory::where('cashier_shift_id', $this->shift->id)->where('action', 'reassigned_with_handover')->count());

        $this->reassign($this->report(['handover_amount' => '31.00']), $key)
            ->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
        $this->assertSame('completed', DB::table('asab_command_idempotency_keys')->sole()->status);
    }

    /** Current D16: accepting work preserves the predecessor report and does not confirm cash. */
    public function test_incoming_cashier_can_accept_without_changing_predecessor_report(): void
    {
        $this->reassign($this->report())->assertOk();
        $count = ShiftReportCashCount::where('cashier_shift_id', $this->shift->id)->sole()->getAttributes();
        $allocation = ShiftLiabilityAllocation::where('cashier_shift_id', $this->shift->id)->sole()->getAttributes();
        $this->actingAs($this->incoming, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->incomingShift->id}/reassign/accept")->assertOk();
        $this->assertSame(ShiftStatus::NOT_STARTED, $this->incomingShift->fresh()->status);
        $this->assertSame($this->outgoing->id, $this->shift->fresh()->cashier_id);
        $this->assertSame($count, ShiftReportCashCount::where('cashier_shift_id', $this->shift->id)->sole()->getAttributes());
        $this->assertSame($allocation, ShiftLiabilityAllocation::where('cashier_shift_id', $this->shift->id)->sole()->getAttributes());
        $this->assertSame(0, DB::table('cashier_shift_handover_receipts')->count());
    }

    /** Work rejection is separate from rejection/correction of the predecessor financial report. */
    public function test_rejecting_independent_work_preserves_the_predecessor_report_and_shortage(): void
    {
        $this->reassign($this->report())->assertOk();
        $beforeCount = ShiftReportCashCount::where('cashier_shift_id', $this->shift->id)->sole()->getAttributes();
        $beforeAllocation = ShiftLiabilityAllocation::where('cashier_shift_id', $this->shift->id)->sole()->getAttributes();
        $this->actingAs($this->incoming, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->incomingShift->id}/reassign/reject", ['reason' => 'not ready'])->assertOk();
        $this->assertSame(ShiftStatus::CANCELED, $this->incomingShift->fresh()->status);
        $this->assertSame($this->outgoing->id, $this->shift->fresh()->cashier_id);
        $this->assertSame($beforeCount, ShiftReportCashCount::where('cashier_shift_id', $this->shift->id)->sole()->getAttributes());
        $this->assertSame($beforeAllocation, ShiftLiabilityAllocation::where('cashier_shift_id', $this->shift->id)->sole()->getAttributes());
        $this->assertSame(1, ShiftLiabilityAllocation::count());
        $this->assertSame(0, DB::table('cashier_shift_handover_receipts')->count());
    }

    /** D16: a reassignment without a report keeps the existing accept flow. */
    public function test_reassignment_without_a_report_can_still_be_accepted(): void
    {
        $this->reassign([
            'new_cashier_id' => $this->incoming->id, 'handover_amount' => '30.00',
        ])->assertOk();
        $this->assertNull(app(\Modules\Shift\Services\ShiftCashCountService::class)->currentFor($this->incomingShift->id));

        $this->actingAs($this->incoming, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->incomingShift->id}/reassign/accept")->assertOk();
        $this->assertSame(ShiftStatus::NOT_STARTED, $this->incomingShift->fresh()->status);
        $before = $this->financialSnapshot();
        $this->actingAs($this->incoming, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->incomingShift->id}/reassign/accept")
            ->assertStatus(409)->assertJsonPath('code', 'HANDOVER_CONFLICT');
        $this->assertSame($before, $this->financialSnapshot());
        $this->actingAs($this->incoming, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->incomingShift->id}/start")->assertOk();
        $this->actingAs($this->incoming, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->incomingShift->id}/end", [
            'total_sales' => '100.00', 'counted_cash' => '100.00',
        ])->assertOk();
    }

    /** F6: a shift already ended cannot be reassigned afterwards; nothing is written. */
    public function test_reassigning_an_already_ended_shift_is_refused_without_writes(): void
    {
        $this->actingAs($this->outgoing, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->shift->id}/end", [
            'total_sales' => '100.00', 'cash_collected' => '100.00', 'counted_cash' => '100.00',
        ])->assertOk();
        $counts = ShiftReportCashCount::count();

        $this->reassign($this->report())->assertStatus(400);

        $this->assertSame(ShiftStatus::COMPLETED, $this->shift->fresh()->status);
        $this->assertSame($counts, ShiftReportCashCount::count());
        $this->assertSame(0, ShiftLiabilityAllocation::count());
    }

    public function test_a_failed_domain_response_releases_the_key_and_writes_nothing(): void
    {
        $key = (string) Str::uuid();
        $otherBranch = Branch::factory()->create();
        $stranger = Cashier::factory()->create(['branch_id' => $otherBranch->id, 'created_by' => $this->manager->id]);

        $this->reassign($this->report(['new_cashier_id' => $stranger->id]), $key)->assertForbidden();
        $this->assertNothingWritten();
        $this->assertSame(0, DB::table('asab_command_idempotency_keys')->count(), 'a rejected command does not hold its key');

        $this->reassign($this->report(), $key)->assertOk();
    }

    private function financialSnapshot(): array
    {
        $snapshot = [];
        foreach (['cashier_shifts', 'cashier_shift_handovers', 'shift_handover_status', 'shift_transfer_rejection_evidence', 'cashier_shift_history',
            'shift_report_aggregates', 'shift_report_revisions', 'shift_report_cash_counts', 'shift_liability_allocations',
            'shift_liability_shares', 'cashier_custody_transactions', 'personal_ledger_transactions',
            'cashier_shift_handover_receipts'] as $table) {
            $snapshot[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $snapshot;
    }

    public function test_manager_can_start_independent_work_without_copying_cash_or_report(): void
    {
        $this->reassign($this->report())->assertOk();
        $count = ShiftReportCashCount::where('cashier_shift_id', $this->shift->id)->sole()->getAttributes();
        $this->actingAs($this->manager, 'sanctum')->postJson("/api/branch-manager/shifts/start-by-manager/{$this->incomingShift->id}")->assertOk();
        $this->assertSame(ShiftStatus::IN_PROGRESS, $this->incomingShift->fresh()->status);
        $this->assertSame('0.00', (string) $this->incomingShift->fresh()->opening_balance);
        $this->assertSame($count, ShiftReportCashCount::where('cashier_shift_id', $this->shift->id)->sole()->getAttributes());
        $this->assertSame(0, DB::table('cashier_shift_handover_receipts')->count());
        $this->assertSame(0, DB::table('cashier_custody_transactions')->count());
        $this->assertSame(1, ShiftLiabilityAllocation::count());
    }

    public function test_incoming_cashier_cannot_start_handover_on_predecessor_report(): void
    {
        $this->reassign($this->report())->assertOk();
        $before = $this->financialSnapshot();
        $this->actingAs($this->incoming, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->shift->id}/start-handover", [
            'handover_to_type' => 'branch_manager', 'handover_amount' => '30.00',
        ])->assertStatus(404);
        $this->assertSame($before, $this->financialSnapshot());
    }

    public function test_start_handover_remains_available_without_a_counted_reassignment(): void
    {
        $this->shift->update([
            'status' => ShiftStatus::COMPLETED,
            'total_sales' => '100.00',
            'cash_collected' => '100.00',
            'actual_end_time' => now(),
        ]);

        $this->actingAs($this->outgoing, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$this->shift->id}/start-handover", [
                'handover_to_type' => 'cashier',
                'next_cashier_id' => $this->incoming->id,
                'handover_amount' => '100.00',
            ])
            ->assertOk();

        $this->assertSame(1, \Modules\Shift\Models\CashierShiftHandover::where('cashier_shift_id', $this->shift->id)->where('status', 'pending')->count());
        $this->assertNull(app(\Modules\Shift\Services\ShiftCashCountService::class)->currentFor($this->shift->id));
    }

    public function test_financial_writer_rechecks_report_owner_even_when_called_directly(): void
    {
        $this->reassign($this->report())->assertOk();
        $before = $this->financialSnapshot();
        try {
            app(\Modules\Shift\Services\ShiftEndService::class)->endShiftOnly($this->shift->fresh(), [
                'total_sales' => '100.00', 'counted_cash' => '100.00',
            ], $this->incoming);
            $this->fail('The incoming cashier cannot write a predecessor report.');
        } catch (\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException $e) {
            $this->assertSame('ONLY_REPORT_OWNER', $e->getMessage());
        }
        $this->assertSame($before, $this->financialSnapshot());
    }

    public function test_no_report_reassignment_can_still_start_directly_by_cashier(): void
    {
        $this->reassign(['new_cashier_id' => $this->incoming->id, 'handover_amount' => '30.00'])->assertOk();
        $this->actingAs($this->incoming, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->incomingShift->id}/start")->assertOk();
        $this->assertSame(ShiftStatus::IN_PROGRESS, $this->incomingShift->fresh()->status);
    }

    public function test_no_report_reassignment_can_still_start_by_manager(): void
    {
        $this->reassign(['new_cashier_id' => $this->incoming->id, 'handover_amount' => '30.00'])->assertOk();
        $this->actingAs($this->manager, 'sanctum')->postJson("/api/branch-manager/shifts/start-by-manager/{$this->incomingShift->id}")->assertOk();
        $this->assertSame(ShiftStatus::IN_PROGRESS, $this->incomingShift->fresh()->status);
    }

    public function test_stale_reassignment_cannot_be_rejected_after_acceptance(): void
    {
        $this->reassign(['new_cashier_id' => $this->incoming->id, 'handover_amount' => '30.00'])->assertOk();
        $stale = $this->incomingShift->fresh(['handoverStatus']);
        $service = app(\Modules\Shift\Services\HandoverService::class);
        $service->acceptReassignedShift($this->incomingShift->fresh(), $this->incoming->id);
        $before = $this->financialSnapshot();
        try {
            $service->rejectReassignedShift($stale, $this->incoming->id, 'stale reject');
            $this->fail('A stale reject must conflict.');
        } catch (\Modules\Shift\Exceptions\HandoverException $e) {
            $this->assertSame(409, $e->statusCode());
        }
        $this->assertSame($before, $this->financialSnapshot());
    }

    public function test_stale_reassignment_cannot_be_accepted_after_rejection(): void
    {
        $this->reassign(['new_cashier_id' => $this->incoming->id, 'handover_amount' => '30.00'])->assertOk();
        $stale = $this->incomingShift->fresh(['handoverStatus']);
        $service = app(\Modules\Shift\Services\HandoverService::class);
        $service->rejectReassignedShift($this->incomingShift->fresh(), $this->incoming->id, 'rejected');
        $before = $this->financialSnapshot();
        try {
            $service->acceptReassignedShift($stale, $this->incoming->id);
            $this->fail('A stale accept must conflict.');
        } catch (\Modules\Shift\Exceptions\HandoverException $e) {
            $this->assertSame(409, $e->statusCode());
        }
        $this->assertSame($before, $this->financialSnapshot());
    }

    public function test_stale_reassignment_rechecks_current_recipient_and_approval(): void
    {
        $this->reassign(['new_cashier_id' => $this->incoming->id, 'handover_amount' => '30.00'])->assertOk();
        $stale = $this->incomingShift->fresh(['handoverStatus']);
        $service = app(\Modules\Shift\Services\HandoverService::class);
        $stale->handoverStatus->fresh()->update(['manager_approval_status' => 'approved']);
        $before = $this->financialSnapshot();
        foreach (['accept', 'reject'] as $action) {
            try {
                $action === 'accept'
                    ? $service->acceptReassignedShift($stale, $this->incoming->id)
                    : $service->rejectReassignedShift($stale, $this->incoming->id, 'stale');
                $this->fail('The current approval state must be rechecked.');
            } catch (\Modules\Shift\Exceptions\HandoverException $e) {
                $this->assertSame(409, $e->statusCode());
            }
            $this->assertSame($before, $this->financialSnapshot());
        }
        $replacement = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);
        $this->incomingShift->fresh()->update(['cashier_id' => $replacement->id]);
        $before = $this->financialSnapshot();
        foreach (['accept', 'reject'] as $action) {
            try {
                $action === 'accept'
                    ? $service->acceptReassignedShift($stale, $this->incoming->id)
                    : $service->rejectReassignedShift($stale, $this->incoming->id, 'stale');
                $this->fail('The current recipient identity must be rechecked.');
            } catch (\Modules\Shift\Exceptions\HandoverException $e) {
                $this->assertSame(403, $e->statusCode());
            }
            $this->assertSame($before, $this->financialSnapshot());
        }
    }
}
