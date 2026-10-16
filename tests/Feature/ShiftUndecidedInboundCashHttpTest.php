<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Employee;
use Modules\Aggregator\Models\Aggregator;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Modules\Shift\Models\ShiftReportCashCount;
use Modules\Shift\Models\ShiftTransferRejectionEvidence;
use Modules\Shift\Services\ShiftCashCountService;
use Tests\TestCase;

/**
 * BRC v3.4 CASH-08 / AC-13 (F-03): cash that is physically in the recipient's drawer for an inbound
 * request that is neither confirmed nor rejected is an UNCONFIRMED RECEIPT. It is not a surplus, it is
 * not the recipient's own cash, and the sender stays responsible until a valid confirmation.
 *
 * Evidence model under test: the recipient DECLARES how much of the counted drawer belongs to undecided
 * inbound requests (`pending_incoming_cash`, SAR, optional). The server bounds the declaration by the
 * requests actually addressed to this shift. A pending request alone never proves physical possession,
 * and an absent declaration keeps the previous behaviour.
 */
class ShiftUndecidedInboundCashHttpTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private BranchManager $manager;

    private Cashier $sender;

    private Cashier $sender2;

    private Cashier $recipient;

    private CashierShift $senderShift;

    private CashierShift $sender2Shift;

    private CashierShift $recipientShift;

    private Aggregator $aggregator;

    protected function setUp(): void
    {
        parent::setUp();

        $company = AsabCompany::create(['name' => 'F03 Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => 'B', 'sub_status' => 'active', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['asab_brand_id' => $brand->id, 'asab_company_id' => $company->id]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true, 'status' => 'active']);
        $this->sender = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);
        $this->sender2 = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);
        $this->recipient = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);
        $this->senderShift = $this->liveShift($this->sender, '06:00:00', '14:00:00');
        $this->sender2Shift = $this->liveShift($this->sender2, '07:00:00', '15:00:00');
        $this->recipientShift = $this->liveShift($this->recipient, '14:00:00', '22:00:00');
        $this->aggregator = Aggregator::factory()->create();

        $accountant = AsabUser::create(['company_id' => $company->id, 'name' => 'محاسب', 'email' => 'acc@f03.test', 'password' => 'secret-password', 'status' => 'active']);
        AsabUserRole::create(['user_id' => $accountant->id, 'role_key' => 'accountant', 'scope' => 'all']);
        Employee::create(['company_id' => $company->id, 'branch_id' => $this->branch->id, 'emp_number' => '3001', 'name' => 'مستلم', 'role' => 'كاشير', 'status' => 'active'])
            ->forceFill(['legacy_cashier_id' => $this->recipient->id])->save();
    }

    private function liveShift(Cashier $cashier, string $start, string $end): CashierShift
    {
        $template = Shift::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true, 'start_time' => $start, 'end_time' => $end]);
        $shift = CashierShift::create([
            'cashier_id' => $cashier->id, 'shift_id' => $template->id,
            'shift_date' => today()->toDateString(), 'status' => ShiftStatus::NOT_STARTED->value,
        ]);
        $shift->update(['status' => ShiftStatus::IN_PROGRESS, 'actual_start_time' => now()]);

        return $shift->fresh();
    }

    /** The sender ends the shift handing `$amount` to the recipient. The request stays pending (undecided). */
    private function sendUndecided(Cashier $from, CashierShift $fromShift, string $amount): CashierShiftHandover
    {
        $this->actingAs($from, 'sanctum')->postJson("/api/v1/cashier/shifts/{$fromShift->id}/end-with-handover", [
            'total_sales' => $amount, 'cash_collected' => $amount, 'counted_cash' => $amount,
            'next_cashier_id' => $this->recipient->id, 'handover_amount' => $amount,
        ])->assertOk();

        return CashierShiftHandover::where('cashier_shift_id', $fromShift->id)->sole();
    }

    private function present(Cashier $from, CashierShiftHandover $handover): string
    {
        return $this->actingAs($from, 'sanctum')->postJson("/api/shift-transfers/handover/{$handover->id}/present", [
            'presented_halalas' => (int) round((float) $handover->handover_amount * 100),
            'idempotency_key' => (string) Str::uuid(),
        ])->assertOk()->json('data.id');
    }

    private function end(array $payload, ?string $key = null, ?CashierShift $shift = null, ?Cashier $as = null)
    {
        $request = $this->actingAs($as ?? $this->recipient, 'sanctum');
        if ($key !== null) {
            $request = $request->withHeaders(['Idempotency-Key' => $key]);
        }

        return $request->postJson('/api/v1/cashier/shifts/'.($shift ?? $this->recipientShift)->id.'/end', $payload);
    }

    /** The CURRENT revision's count of the shift (a handover request carries the count forward to new revisions). */
    private function cashCount(?CashierShift $shift = null): ShiftReportCashCount
    {
        return app(ShiftCashCountService::class)->currentFor(($shift ?? $this->recipientShift)->id) ?? $this->fail('no current count');
    }

    /** @return array<string,int> every money movement table the undecided request must NOT touch */
    private function movements(): array
    {
        return [
            'receipts' => CashierShiftHandoverReceipt::count(),
            'personal_ledger' => PersonalLedgerTransaction::count(),
            // a shift's own 'Total Sales' posting is its own sales; any other custody movement would be a transfer
            'custody_transfers' => DB::table('cashier_custody_transactions')->where('transaction_type', '!=', 'Total Sales')->count(),
            'postings' => DB::table('shift_liability_postings')->count(),
        ];
    }

    /** Nothing about the undecided request moved: no receipt, no ledger/custody movement, still pending. */
    private function assertSenderStillResponsible(array $movementsBefore, CashierShiftHandover ...$requests): void
    {
        $this->assertSame($movementsBefore, $this->movements(), 'ending the recipient shift must not move any money');
        foreach ($requests as $request) {
            $this->assertSame('pending', $request->fresh()->status);
            $this->assertNull($request->fresh()->approved_at);
            $this->assertNull($request->fresh()->cancelled_at);
        }
    }

    private function assertNothingWritten(): void
    {
        $this->assertSame(ShiftStatus::IN_PROGRESS, $this->recipientShift->fresh()->status);
        $this->assertSame(0, ShiftReportCashCount::where('cashier_shift_id', $this->recipientShift->id)->count());
        $this->assertSame(0, ShiftLiabilityAllocation::where('cashier_shift_id', $this->recipientShift->id)->count());
    }

    // ---------------------------------------------------------------------------------------------
    // AC-13 core: full amount

    public function test_ac13_undecided_full_amount_is_pending_incoming_not_surplus_and_sender_stays_responsible(): void
    {
        $request = $this->sendUndecided($this->sender, $this->senderShift, '500.00');
        $senderCountBefore = $this->cashCount($this->senderShift)->only(['counted_halalas', 'expected_halalas', 'variance_halalas']);
        $moved = $this->movements();

        // The recipient sold 100 in cash and physically holds 100 + the 500 of the undecided request.
        $response = $this->end([
            'total_sales' => '100.00', 'cash_collected' => '100.00', 'counted_cash' => '600.00', 'pending_incoming_cash' => '500.00',
        ])->assertOk();

        $count = $this->cashCount();
        $this->assertSame(60000, $count->counted_halalas);
        $this->assertSame(50000, $count->pending_incoming_counted_halalas, 'the 500 is identified separately from the recipient own cash');
        $this->assertSame(0, $count->confirmed_opening_halalas, 'an undecided request is not a confirmed opening');
        $this->assertSame(10000, $count->expected_halalas);
        $this->assertSame(0, $count->variance_halalas, 'no artificial surplus and no artificial shortage');
        $this->assertSame(0, ShiftLiabilityAllocation::where('cashier_shift_id', $this->recipientShift->id)->count());

        $block = $response->json('data.summary.cash_reconciliation');
        $this->assertEquals(500.0, $block['pending_incoming_cash']);
        $this->assertSame('balanced', $block['cash_variance_type']);

        // Responsibility stays with the sender: nothing was received, moved or relabelled.
        $this->assertSenderStillResponsible($moved, $request);
        $this->assertSame(100.0, (float) DB::table('cashier_custody_transactions')->where('related_shift_id', $this->recipientShift->id)->sum('amount'), 'only the recipient own sales are posted, never the inbound 500');
        $this->assertSame($senderCountBefore, $this->cashCount($this->senderShift)->only(['counted_halalas', 'expected_halalas', 'variance_halalas']));
    }

    public function test_ac13_same_result_with_a_tracked_physical_presentation(): void
    {
        $request = $this->sendUndecided($this->sender, $this->senderShift, '500.00');
        $this->present($this->sender, $request);
        $moved = $this->movements();

        $this->end([
            'total_sales' => '100.00', 'cash_collected' => '100.00', 'counted_cash' => '600.00', 'pending_incoming_cash' => '500.00',
        ])->assertOk();

        $count = $this->cashCount();
        $this->assertSame(50000, $count->pending_incoming_counted_halalas);
        $this->assertSame(0, $count->variance_halalas);
        $this->assertSenderStillResponsible($moved, $request);
    }

    // ---------------------------------------------------------------------------------------------
    // Partial physical presence

    public function test_ac13_partial_presence_attributes_only_the_declared_amount(): void
    {
        $request = $this->sendUndecided($this->sender, $this->senderShift, '500.00');
        $moved = $this->movements();

        // Only 300 of the 500 request is actually in the drawer next to the recipient's own 100.
        $this->end([
            'total_sales' => '100.00', 'cash_collected' => '100.00', 'counted_cash' => '400.00', 'pending_incoming_cash' => '300.00',
        ])->assertOk();

        $count = $this->cashCount();
        $this->assertSame(30000, $count->pending_incoming_counted_halalas);
        $this->assertSame(0, $count->variance_halalas);
        $this->assertSenderStillResponsible($moved, $request);
    }

    public function test_ac13_a_real_surplus_beyond_the_declared_inbound_stays_a_surplus(): void
    {
        $this->sendUndecided($this->sender, $this->senderShift, '500.00');

        $this->end([
            'total_sales' => '100.00', 'cash_collected' => '100.00', 'counted_cash' => '650.00', 'pending_incoming_cash' => '500.00',
        ])->assertOk();

        $count = $this->cashCount();
        $this->assertSame(50000, $count->pending_incoming_counted_halalas);
        $this->assertSame(5000, $count->variance_halalas, 'only the cash beyond the identified inbound is a branch surplus');
        $this->assertSame(0, ShiftLiabilityAllocation::where('cashier_shift_id', $this->recipientShift->id)->count());
    }

    // ---------------------------------------------------------------------------------------------
    // Absent physical evidence: a pending request alone proves nothing

    public function test_ac13_pending_request_alone_neither_creates_pending_incoming_nor_a_shortage(): void
    {
        $request = $this->sendUndecided($this->sender, $this->senderShift, '500.00');
        $moved = $this->movements();

        // No declaration: the drawer holds only the recipient's own 100.
        $this->end(['total_sales' => '100.00', 'cash_collected' => '100.00', 'counted_cash' => '100.00'])->assertOk();

        $count = $this->cashCount();
        $this->assertSame(0, $count->pending_incoming_counted_halalas);
        $this->assertSame(0, $count->variance_halalas, 'the 500 that is not in the drawer is not charged to the recipient');
        $this->assertSenderStillResponsible($moved, $request);
    }

    public function test_ac13_without_a_declaration_the_previous_behaviour_is_unchanged_no_guessing(): void
    {
        $this->sendUndecided($this->sender, $this->senderShift, '500.00');

        // The same drawer as the full case but nothing declared: the server must not guess a split.
        $this->end(['total_sales' => '100.00', 'cash_collected' => '100.00', 'counted_cash' => '600.00'])->assertOk();

        $count = $this->cashCount();
        $this->assertSame(0, $count->pending_incoming_counted_halalas);
        $this->assertSame(50000, $count->variance_halalas);
    }

    public function test_ac13_declaration_without_any_undecided_request_is_refused_without_writes(): void
    {
        $this->end([
            'total_sales' => '100.00', 'cash_collected' => '100.00', 'counted_cash' => '600.00', 'pending_incoming_cash' => '500.00',
        ])->assertUnprocessable()->assertJsonValidationErrors('pending_incoming_cash');
        $this->assertNothingWritten();
    }

    public function test_ac13_declaration_above_the_request_or_the_count_is_refused_without_writes(): void
    {
        $this->sendUndecided($this->sender, $this->senderShift, '500.00');

        $this->end([
            'total_sales' => '100.00', 'cash_collected' => '100.00', 'counted_cash' => '700.00', 'pending_incoming_cash' => '600.00',
        ])->assertUnprocessable()->assertJsonValidationErrors('pending_incoming_cash');

        // Declared 500 but the whole drawer holds only 450.
        $this->end([
            'total_sales' => '100.00', 'cash_collected' => '100.00', 'counted_cash' => '450.00', 'pending_incoming_cash' => '500.00',
        ])->assertUnprocessable()->assertJsonValidationErrors('counted_cash');

        foreach (['-1', 'abc', '500.001'] as $bad) {
            $this->end([
                'total_sales' => '100.00', 'cash_collected' => '100.00', 'counted_cash' => '600.00', 'pending_incoming_cash' => $bad,
            ])->assertUnprocessable()->assertJsonValidationErrors('pending_incoming_cash');
        }
        $this->assertNothingWritten();
    }

    public function test_ac13_cancelled_superseded_and_decided_requests_are_not_undecided_inbound(): void
    {
        $cancelled = $this->sendUndecided($this->sender, $this->senderShift, '500.00');
        $cancelled->forceFill(['cancelled_at' => now()])->saveQuietly();

        $payload = ['total_sales' => '100.00', 'cash_collected' => '100.00', 'counted_cash' => '600.00', 'pending_incoming_cash' => '500.00'];
        $this->end($payload)->assertUnprocessable()->assertJsonValidationErrors('pending_incoming_cash');

        $cancelled->forceFill(['cancelled_at' => null, 'superseded_at' => now()])->saveQuietly();
        $this->end($payload)->assertUnprocessable()->assertJsonValidationErrors('pending_incoming_cash');

        // A confirmed receipt is an opening, never pending inbound.
        $cancelled->forceFill(['superseded_at' => null])->saveQuietly();
        $this->actingAs($this->recipient, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/handover/accept", [
            'confirmed_amount' => '500.00', 'receiving_shift_id' => $this->recipientShift->id,
        ])->assertOk();
        $this->end($payload)->assertUnprocessable()->assertJsonValidationErrors('pending_incoming_cash');
        $this->assertNothingWritten();

        // The confirmed 500 is the opening: expected 100 + 500, so a 600 count balances with nothing pending.
        $this->end(['total_sales' => '100.00', 'cash_collected' => '100.00', 'counted_cash' => '600.00'])->assertOk();
        $count = $this->cashCount();
        $this->assertSame(50000, $count->confirmed_opening_halalas);
        $this->assertSame(0, $count->pending_incoming_counted_halalas);
        $this->assertSame(0, $count->variance_halalas);
    }

    // ---------------------------------------------------------------------------------------------
    // The declaration never hides a real shortage (FIN-01 + an undecided inbound request)

    public function test_fin01_shortage_is_not_masked_by_an_undecided_inbound_request(): void
    {
        // Confirmed opening 10 (sender 1) and an UNDECIDED 300 from sender 2.
        $this->actingAs($this->sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/end-with-handover", [
            'total_sales' => '10.00', 'cash_collected' => '10.00', 'counted_cash' => '10.00',
            'next_cashier_id' => $this->recipient->id, 'handover_amount' => '10.00',
        ])->assertOk();
        $this->actingAs($this->recipient, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/handover/accept", [
            'confirmed_amount' => '10.00', 'receiving_shift_id' => $this->recipientShift->id,
        ])->assertOk();
        $undecided = $this->sendUndecided($this->sender2, $this->sender2Shift, '300.00');
        $moved = $this->movements();

        // FIN-01: gross 115, cards 50, apps 25, opening 10 -> expected 50; the own cash is 30 (shortage 20)
        // and the drawer also holds the 300 undecided inbound: counted 330.
        $payload = [
            'total_sales' => '115.00', 'cash_collected' => '40.00', 'card_payments' => '50.00',
            'aggregators' => [['aggregator_id' => $this->aggregator->id, 'amount' => '25.00']],
            'counted_cash' => '330.00', 'pending_incoming_cash' => '300.00',
            'shortage_allocations' => [
                ['responsible_type' => 'cashier', 'responsible_id' => $this->recipient->id, 'amount' => '12.00'],
                ['responsible_type' => 'branch_manager', 'responsible_id' => $this->manager->id, 'amount' => '8.00'],
            ],
        ];
        $this->end($payload)->assertOk();

        $count = $this->cashCount();
        $this->assertSame(1000, $count->confirmed_opening_halalas);
        $this->assertSame(30000, $count->pending_incoming_counted_halalas);
        $this->assertSame(5000, $count->expected_halalas);
        $this->assertSame(-2000, $count->variance_halalas, 'the recipient own 20 shortage is still a shortage');
        $this->assertSame(1, ShiftLiabilityAllocation::where('cashier_shift_id', $this->recipientShift->id)->count());
        $this->assertSenderStillResponsible($moved, $undecided);
    }

    // ---------------------------------------------------------------------------------------------
    // Repeated requests, retries and duplicate attribution

    public function test_ac13_repeated_requests_are_summed_and_never_exceed_the_requested_total(): void
    {
        $first = $this->sendUndecided($this->sender, $this->senderShift, '300.00');
        $second = $this->sendUndecided($this->sender2, $this->sender2Shift, '200.00');
        $moved = $this->movements();

        $payload = ['total_sales' => '100.00', 'cash_collected' => '100.00'];
        $this->end($payload + ['counted_cash' => '700.00', 'pending_incoming_cash' => '500.01'])->assertUnprocessable()->assertJsonValidationErrors('pending_incoming_cash');
        $this->assertNothingWritten();

        $this->end($payload + ['counted_cash' => '600.00', 'pending_incoming_cash' => '500.00'])->assertOk();

        $count = $this->cashCount();
        $this->assertSame(50000, $count->pending_incoming_counted_halalas);
        $this->assertSame(0, $count->variance_halalas);
        $this->assertSenderStillResponsible($moved, $first, $second);
    }

    public function test_ac13_retry_with_the_same_idempotency_key_does_not_attribute_twice(): void
    {
        $this->sendUndecided($this->sender, $this->senderShift, '500.00');
        $payload = ['total_sales' => '100.00', 'cash_collected' => '100.00', 'counted_cash' => '600.00', 'pending_incoming_cash' => '500.00'];
        $key = (string) Str::uuid();

        $first = $this->end($payload, $key)->assertOk();
        $replay = $this->end($payload, $key);
        $this->assertSame($first->json('data.summary.cash_reconciliation'), $replay->json('data.summary.cash_reconciliation'));
        $this->assertSame(1, ShiftReportCashCount::where('cashier_shift_id', $this->recipientShift->id)->count());

        // Same key with a different declaration is not a replay.
        $conflict = $this->end(array_merge($payload, ['pending_incoming_cash' => '400.00']), $key);
        $this->assertFalse($conflict->isSuccessful());
        // A new key after completion cannot write a second count for the same shift.
        $this->assertFalse($this->end($payload, (string) Str::uuid())->isSuccessful());
        $this->assertSame(1, ShiftReportCashCount::where('cashier_shift_id', $this->recipientShift->id)->count());
        $this->assertSame(50000, $this->cashCount()->pending_incoming_counted_halalas);
    }

    public function test_ac13_one_request_is_attributed_to_one_shift_only(): void
    {
        $request = $this->sendUndecided($this->sender, $this->senderShift, '500.00');
        $secondShift = $this->liveShift($this->recipient, '22:00:00', '23:00:00');
        $secondShift->forceFill(['actual_start_time' => now()->addHours(2)])->saveQuietly();
        $moved = $this->movements();

        $payload = ['total_sales' => '100.00', 'cash_collected' => '100.00', 'counted_cash' => '600.00', 'pending_incoming_cash' => '500.00'];

        // The later shift of the same cashier cannot claim the request that belongs to the earlier shift.
        $this->end($payload, null, $secondShift)->assertUnprocessable()->assertJsonValidationErrors('pending_incoming_cash');
        $this->assertSame(0, ShiftReportCashCount::where('cashier_shift_id', $secondShift->id)->count());

        $this->end($payload)->assertOk();
        $this->assertSame(50000, $this->cashCount()->pending_incoming_counted_halalas);
        $this->assertSenderStillResponsible($moved, $request);
    }

    // ---------------------------------------------------------------------------------------------
    // Existing rejected behaviour (D-11) stays and is not counted twice

    public function test_d11_rejected_cash_and_undecided_cash_are_both_pending_and_not_duplicated(): void
    {
        $rejected = $this->sendUndecided($this->sender, $this->senderShift, '500.00');
        $attempt = $this->present($this->sender, $rejected);
        $this->actingAs($this->recipient, 'sanctum')->postJson("/api/v1/cashier/shifts/{$this->senderShift->id}/handover/reject", [
            'rejection_reason' => 'Counted 480', 'confirmed_amount' => '480.00', 'correction_reason' => 'actual_shortage',
            'transfer_attempt_id' => $attempt,
        ])->assertOk();
        $this->assertSame(1, ShiftTransferRejectionEvidence::count());
        $undecided = $this->sendUndecided($this->sender2, $this->sender2Shift, '300.00');

        $payload = ['total_sales' => '100.00', 'cash_collected' => '100.00', 'counted_cash' => '880.00'];

        // The rejected request is covered by its own evidence: it cannot be declared a second time.
        $this->end($payload + ['pending_incoming_cash' => '780.00'])->assertUnprocessable()->assertJsonValidationErrors('pending_incoming_cash');
        $this->assertNothingWritten();

        $this->end($payload + ['pending_incoming_cash' => '300.00'])->assertOk();

        $count = $this->cashCount();
        $this->assertSame(78000, $count->pending_incoming_counted_halalas, '480 rejection evidence + 300 declared undecided');
        $this->assertSame(10000, $count->expected_halalas);
        $this->assertSame(0, $count->variance_halalas);
        $this->assertSame(0, CashierShiftHandoverReceipt::count());
        $this->assertSame('rejected', $rejected->fresh()->status);
        $this->assertSame('pending', $undecided->fresh()->status);
    }

    // ---------------------------------------------------------------------------------------------
    // Preview parity and corrected evidence

    public function test_preview_with_a_declaration_equals_the_end_result_and_writes_nothing(): void
    {
        $this->sendUndecided($this->sender, $this->senderShift, '500.00');
        $payload = ['total_sales' => '100.00', 'counted_cash' => '600.00', 'pending_incoming_cash' => '500.00'];
        $before = [DB::table('shift_report_cash_counts')->count(), DB::table('shift_report_revisions')->count()];

        $preview = $this->actingAs($this->recipient, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$this->recipientShift->id}/cash-reconciliation/preview", $payload)->assertOk();

        $this->assertSame($before, [DB::table('shift_report_cash_counts')->count(), DB::table('shift_report_revisions')->count()]);
        $this->assertEquals(500.0, $preview->json('data.cash_reconciliation.pending_incoming_cash'));
        $this->assertEquals(0.0, $preview->json('data.cash_reconciliation.cash_variance'));
        $this->assertFalse($preview->json('data.allocation_required'));

        $end = $this->end(['cash_collected' => '100.00'] + $payload)->assertOk();
        $this->assertEquals($end->json('data.summary.cash_reconciliation'), $preview->json('data.cash_reconciliation'));

        $this->actingAs($this->recipient, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$this->recipientShift->id}/cash-reconciliation/preview", ['pending_incoming_cash' => '900.00'] + $payload)
            ->assertStatus(400); // completed shift: preview is closed; the declaration is validated on open shifts
    }

    public function test_corrected_financial_inputs_hold_the_declared_physical_observation_constant(): void
    {
        $this->sendUndecided($this->sender, $this->senderShift, '500.00');
        $this->end(['total_sales' => '100.00', 'cash_collected' => '100.00', 'counted_cash' => '600.00', 'pending_incoming_cash' => '500.00'])->assertOk();
        $source = $this->cashCount();
        $this->assertSame(0, $source->variance_halalas);
        $shift = $this->recipientShift->fresh();

        // A sales-input correction (gross 100 -> 150) must not re-split the drawer: the physical
        // observation (600 counted, 500 of it undecided inbound) is constant, so the variance is -50.
        DB::beginTransaction();
        try {
            $revision = app(\Modules\Shift\Services\ShiftReportRevisionService::class)->recordCashierRevision($shift, 'cashier', (string) $this->recipient->id);
            $corrected = app(ShiftCashCountService::class)->recordCorrectedEvidence(
                $shift, $revision, $source, ['gross_halalas' => 15000, 'cards_halalas' => 0, 'apps_halalas' => 0],
            );
            $this->assertSame(50000, $corrected->pending_incoming_counted_halalas);
            $this->assertSame(60000, $corrected->counted_halalas);
            $this->assertSame(15000, $corrected->expected_halalas);
            $this->assertSame(-5000, $corrected->variance_halalas);
        } finally {
            DB::rollBack();
        }
    }

    public function test_corrected_evidence_requires_a_recount_when_the_undecided_request_is_no_longer_undecided(): void
    {
        $request = $this->sendUndecided($this->sender, $this->senderShift, '500.00');
        $this->end(['total_sales' => '100.00', 'cash_collected' => '100.00', 'counted_cash' => '600.00', 'pending_incoming_cash' => '500.00'])->assertOk();
        $source = $this->cashCount();
        $shift = $this->recipientShift->fresh();
        $request->forceFill(['cancelled_at' => now()])->saveQuietly();

        DB::beginTransaction();
        try {
            $revision = app(\Modules\Shift\Services\ShiftReportRevisionService::class)->recordCashierRevision($shift, 'cashier', (string) $this->recipient->id);
            $this->expectException(\Symfony\Component\HttpKernel\Exception\ConflictHttpException::class);
            $this->expectExceptionMessage('PHYSICAL_RECOUNT_REQUIRED');
            app(ShiftCashCountService::class)->recordCorrectedEvidence(
                $shift, $revision, $source, ['gross_halalas' => 10000, 'cards_halalas' => 0, 'apps_halalas' => 0],
            );
        } finally {
            DB::rollBack();
        }
    }
}
