<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BranchManagers\Services\BranchManagerService;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Enums\TransactionType;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Custody\Services\PersonalLedgerService;
use Modules\Shift\Enums\HandoverStatus;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Listeners\BranchManagerShiftListener;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftHandoverStatus;
use Modules\Shift\Models\ShiftReportAggregate;
use Modules\Shift\Services\BranchManagerShiftService;
use Modules\Shift\Services\HandoverService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Modules\Shift\Services\ShiftTransferReceiptService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ShiftTransferReceiptTest extends TestCase
{
    use RefreshDatabase;

    public function test_manager_transfer_request_has_destination_identity_and_no_receipt_until_confirmation(): void
    {
        [$branch, $manager, $cashier, $source, $destination] = $this->managerTransferFixture();
        $this->fundManagerLedger($manager, $source);

        $transfer = app(ShiftTransferReceiptService::class)->requestManagerCashTransfer(
            $source,
            $cashier,
            $destination,
            '61.50',
            $manager
        );

        $this->assertSame('pending', $transfer->status);
        $this->assertSame($destination->id, $transfer->destination_cashier_shift_id);
        $this->assertNotNull($transfer->report_revision_id);
        $this->assertSame(0, CashierShiftHandoverReceipt::count());
    }

    public function test_manager_cash_handover_posts_one_named_cash_out_and_no_legacy_transfer_type(): void
    {
        [, $manager, $cashier, $source, $destination] = $this->managerTransferFixture();
        $this->fundManagerLedger($manager, $source);
        $service = app(ShiftTransferReceiptService::class);
        $transfer = $service->requestManagerCashTransfer($source, $cashier, $destination, '10.00', $manager);

        $receipt = $service->confirmManagerCashTransfer($transfer->id, $cashier, '10.00');
        $ledger = PersonalLedgerTransaction::where('receipt_id', $receipt->id)->sole();
        $custody = CashierCustodyTransaction::where('receipt_id', $receipt->id)->sole();

        $this->assertSame(TransactionType::HANDOVER_TO_CASHIER->value, $ledger->transaction_type);
        $this->assertFalse((bool) $ledger->is_cash_in);
        $this->assertSame('10.00', $ledger->amount);
        $this->assertSame($cashier->name, $ledger->cashier_name);
        $this->assertSame($receipt->id, $ledger->receipt_id);
        $this->assertSame('Handover Received', $custody->transaction_type);
        $this->assertSame('10.00', $custody->amount);
        $this->assertSame($receipt->id, $custody->receipt_id);
        $this->assertSame($manager->name, $custody->counterpart_name);
        $this->assertSame(90.0, app(PersonalLedgerService::class)->getPersonalBalanceOnly($manager->id));
        $this->assertSame(0, PersonalLedgerTransaction::where('transaction_type', 'Transfer to Custody')->count());

        $history = app(PersonalLedgerService::class)->getTransactionHistory($manager->id)['transactions'];
        $entry = $history->firstWhere('transactionType', TransactionType::HANDOVER_TO_CASHIER->value);
        $this->assertSame($cashier->name, $entry['cashierName']);
        $activity = app(PersonalLedgerService::class)->getPersonalCustodyBalance($manager->id)['recentActivity'];
        $this->assertSame($cashier->name, $activity->firstWhere('transactionType', TransactionType::HANDOVER_TO_CASHIER->value)['cashierName']);

        try {
            $service->confirmManagerCashTransfer($transfer->id, $cashier, '10.00');
            $this->fail('A confirmed transfer cannot post a second cash-out.');
        } catch (ConflictHttpException $e) {
            $this->assertSame('TRANSFER_NOT_PENDING', $e->getMessage());
        }
        $this->assertSame(1, PersonalLedgerTransaction::where('transaction_type', TransactionType::HANDOVER_TO_CASHIER->value)->count());
        $this->assertSame(1, CashierShiftHandoverReceipt::count());
    }

    public function test_recipient_confirmation_rejects_any_amount_mismatch_without_effects(): void
    {
        [$source, $recipient, $destination, $handover, $revision] = $this->handoverFixture('61.50');

        try {
            app(ShiftTransferReceiptService::class)->confirmHandover($handover->id, $recipient, '61.00');
            $this->fail('A partial physical amount requires rejection and request correction first.');
        } catch (ConflictHttpException $e) {
            $this->assertSame('HANDOVER_AMOUNT_MISMATCH_CORRECTION_REQUIRED', $e->getMessage());
        }

        $this->assertSame(0, CashierShiftHandoverReceipt::count());
        $this->assertSame(0, CashierCustodyTransaction::count());
        $this->assertSame('pending', $handover->fresh()->status);
        $this->assertSame('61.50', $handover->fresh()->handover_amount);
        $this->assertSame($revision->id, $handover->fresh()->report_revision_id);
        $this->assertSame(0.0, (float) $destination->fresh()->opening_balance);
    }

    public function test_in_progress_receiving_shift_accepts_exact_confirmation(): void
    {
        [, $recipient, $destination, $handover] = $this->handoverFixture('10.00');
        $destination->update(['status' => ShiftStatus::IN_PROGRESS]);

        $receipt = app(ShiftTransferReceiptService::class)->confirmHandover($handover->id, $recipient, '10.00', $destination->id);

        $this->assertSame($destination->id, $receipt->receiving_cashier_shift_id);
        $this->assertSame('10.00', $destination->fresh()->opening_balance);
    }

    public function test_completed_and_canceled_receiving_shifts_cannot_confirm(): void
    {
        foreach ([ShiftStatus::COMPLETED, ShiftStatus::CANCELED] as $status) {
            [, $recipient, $destination, $handover] = $this->handoverFixture('10.00');
            $destination->update(['status' => $status]);

            try {
                app(ShiftTransferReceiptService::class)->confirmHandover($handover->id, $recipient, '10.00', $destination->id);
                $this->fail('A finalized or canceled receiving shift cannot take a receipt.');
            } catch (ConflictHttpException $e) {
                $this->assertSame('RECEIVING_SHIFT_NOT_AVAILABLE', $e->getMessage());
            }
            $this->assertSame(0, CashierShiftHandoverReceipt::count());
        }
    }

    public function test_configured_float_is_not_opening_and_late_receipt_applies_once(): void
    {
        [$source, $recipient, $destination, $handover] = $this->handoverFixture('10.00');
        $destination->shift->update(['opening_float' => '500.00']);
        $newShift = CashierShift::factory()->create([
            'cashier_id' => $recipient->id,
            'shift_id' => $destination->shift_id,
            'shift_date' => today()->addDay(),
            'opening_balance' => '500.00',
        ]);
        $this->assertSame('0.00', $newShift->fresh()->opening_balance);
        $this->assertSame('0.00', $destination->fresh()->opening_balance);
        $destination->update(['status' => ShiftStatus::IN_PROGRESS]);

        app(ShiftTransferReceiptService::class)->confirmHandover($handover->id, $recipient, '10.00', $destination->id);

        $this->assertSame('10.00', $destination->fresh()->opening_balance);
        $this->assertSame(1, CashierShiftHandoverReceipt::count());
        $this->assertSame(2, CashierCustodyTransaction::where('receipt_id', CashierShiftHandoverReceipt::query()->firstOrFail()->id)->count());
    }

    public function test_zero_confirmation_for_nonzero_request_is_a_mismatch(): void
    {
        [, $recipient, , $handover] = $this->handoverFixture('10.00');

        try {
            app(ShiftTransferReceiptService::class)->confirmHandover($handover->id, $recipient, '0.00');
            $this->fail('Zero cannot confirm a nonzero request.');
        } catch (ConflictHttpException $e) {
            $this->assertSame('HANDOVER_AMOUNT_MISMATCH_CORRECTION_REQUIRED', $e->getMessage());
        }

        $this->assertSame(0, CashierShiftHandoverReceipt::count());
        $this->assertSame('pending', $handover->fresh()->status);
    }

    public function test_accept_route_maps_mismatch_scope_state_revision_and_validation(): void
    {
        [$source, $recipient, $destination, $handover, , $otherShift] = $this->handoverFixture('10.00');
        $route = "/api/cashier/shifts/{$source->id}/handover/accept";

        $this->actingAs($recipient, 'sanctum')
            ->postJson($route, ['confirmed_amount' => '9.00', 'receiving_shift_id' => $destination->id])
            ->assertStatus(409)
            ->assertJsonPath('code', 'HANDOVER_AMOUNT_MISMATCH_CORRECTION_REQUIRED');
        $this->actingAs($otherShift->cashier, 'sanctum')
            ->postJson($route, ['confirmed_amount' => '10.00', 'receiving_shift_id' => $otherShift->id])
            ->assertStatus(403)->assertJsonPath('code', 'ONLY_ADDRESSED_CASHIER_RECIPIENT');
        $this->actingAs($recipient, 'sanctum')
            ->postJson($route, ['confirmed_amount' => '10.001', 'receiving_shift_id' => $destination->id])
            ->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR');
        $this->assertSame(0, CashierShiftHandoverReceipt::count());

        app(ShiftReportRevisionService::class)->recordCashierRevision($source, 'cashier', $source->cashier_id, 1);
        $this->postJson($route, ['confirmed_amount' => '10.00', 'receiving_shift_id' => $destination->id])
            ->assertStatus(409)
            ->assertJsonPath('code', 'STALE_REPORT_REVISION');
        $this->assertSame('pending', $handover->fresh()->status);
        $this->assertSame(0, CashierCustodyTransaction::count());
    }

    public function test_record_handover_rejects_other_branch_recipient_before_writing_request(): void
    {
        $branch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $sender = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $recipient = Cashier::factory()->create(['branch_id' => $otherBranch->id, 'created_by' => $manager->id]);
        $template = Shift::factory()->create(['branch_id' => $branch->id]);
        $source = CashierShift::factory()->completed()->create([
            'cashier_id' => $sender->id,
            'shift_id' => $template->id,
            'shift_date' => today(),
        ]);

        $this->actingAs($sender, 'sanctum')
            ->postJson("/api/cashier/shifts/{$source->id}/handover", [
                'next_cashier_id' => $recipient->id,
                'handover_amount' => '10.00',
            ])->assertStatus(403);

        $this->assertSame(0, CashierShiftHandover::where('cashier_shift_id', $source->id)->count());
        $this->assertSame(0, ShiftReportAggregate::where('source_id', $source->id)->count());
    }

    public function test_accept_route_returns_generic_500_for_injected_database_failure(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Failure trigger is SQLite-specific.');
        }
        [$source, $recipient, $destination, $handover] = $this->handoverFixture('10.00');
        DB::statement("CREATE TRIGGER fail_public_receipt BEFORE INSERT ON cashier_shift_handover_receipts BEGIN SELECT RAISE(ABORT, 'SQL_SECRET_DO_NOT_LEAK'); END");

        $response = $this->actingAs($recipient, 'sanctum')
            ->postJson("/api/cashier/shifts/{$source->id}/handover/accept", [
                'confirmed_amount' => '10.00',
                'receiving_shift_id' => $destination->id,
            ]);
        $response->assertStatus(500)->assertJsonPath('message', 'Internal server error')->assertJsonPath('code', 'INTERNAL_ERROR');
        $this->assertStringNotContainsString('SQL_SECRET_DO_NOT_LEAK', $response->getContent());
        $this->assertStringNotContainsString('SQLSTATE', $response->getContent());
        $this->assertSame(0, CashierShiftHandoverReceipt::count());
        $this->assertSame('pending', $handover->fresh()->status);
    }

    public function test_accept_route_rejects_already_confirmed_request_as_conflict(): void
    {
        [$source, $recipient, $destination, $handover] = $this->handoverFixture('10.00');
        app(ShiftTransferReceiptService::class)->confirmHandover($handover->id, $recipient, '10.00', $destination->id);

        $this->actingAs($recipient, 'sanctum')
            ->postJson("/api/cashier/shifts/{$source->id}/handover/accept", [
                'confirmed_amount' => '10.00',
                'receiving_shift_id' => $destination->id,
            ])->assertStatus(409)->assertJsonPath('code', 'HANDOVER_NOT_PENDING');
        $this->assertSame(1, CashierShiftHandoverReceipt::count());
    }

    public function test_manager_route_correction_preserves_completed_report_and_prior_request(): void
    {
        [$source, , , $handover] = $this->handoverFixture('500.00');
        $manager = BranchManager::query()->findOrFail($source->cashier->created_by);

        $response = $this->actingAs($manager, 'sanctum')
            ->postJson("/api/branch-manager/shifts/{$source->id}/handover/reject", [
                'rejection_reason' => 'Count was 480',
                'correction_reason' => 'input_error',
                'confirmed_amount' => '480.00',
            ]);

        $response->assertOk()->assertJsonPath('data.rejection_details.status', 'rejected');
        $this->assertSame(ShiftStatus::COMPLETED, $source->fresh()->status);
        $this->assertSame($handover->id, $handover->fresh()->id);
        $this->assertSame('500.00', $handover->fresh()->handover_amount);
        $this->assertSame('rejected', $handover->fresh()->status);
        $history = \Modules\Shift\Models\CashierShiftHistory::query()
            ->where('action', 'handover_amount_correction_rejected')->sole();
        $this->assertSame('500.00', $history->new_value['requested_amount']);
        $this->assertSame('480.00', $history->new_value['attempted_confirmed_amount']);
        $this->assertSame('input_error', $history->new_value['correction_reason']);
        $this->assertSame($manager->id, $history->performed_by);
        $this->assertTrue($source->fresh('handoverStatus')->handoverStatus->canCashierEdit());
    }

    public function test_cashier_request_manager_review_response_does_not_claim_receipt(): void
    {
        [$source, , , $handover] = $this->handoverFixture('10.00');
        $manager = BranchManager::query()->findOrFail($source->cashier->created_by);

        $response = $this->actingAs($manager, 'sanctum')
            ->postJson("/api/branch-manager/shifts/{$source->id}/handover/approve", []);

        $response->assertOk()->assertJsonPath('message', 'Manager review recorded; recipient confirmation is still pending');
        $this->assertSame('pending', $handover->fresh()->status);
        $this->assertSame(0, CashierShiftHandoverReceipt::count());
        $this->assertSame(0, CashierCustodyTransaction::count());
    }

    public function test_recipient_rejection_after_receipt_is_controlled_conflict_and_keeps_evidence(): void
    {
        [$source, $recipient, $destination, $handover] = $this->handoverFixture('10.00');
        app(ShiftTransferReceiptService::class)->confirmHandover($handover->id, $recipient, '10.00', $destination->id);

        $this->actingAs($recipient, 'sanctum')
            ->postJson("/api/cashier/shifts/{$source->id}/handover/reject", ['rejection_reason' => 'too late'])
            ->assertStatus(409);
        $this->assertSame(ShiftStatus::COMPLETED, $source->fresh()->status);
        $this->assertSame('approved', $handover->fresh()->status);
        $this->assertSame(1, CashierShiftHandoverReceipt::count());
        $this->assertSame(2, CashierCustodyTransaction::where('related_handover_id', $handover->id)->count());
    }

    public function test_workday_handoff_routes_map_amount_scope_and_validation_errors(): void
    {
        [$branch, $manager, , $source, , $handover] = $this->managerHandoverFixture('61.50');
        $otherManager = BranchManager::factory()->create(['branch_id' => $branch->id, 'status' => 'inactive', 'is_active' => false]);
        $approve = '/api/branch-manager/workday/handoffs/approve';

        $this->actingAs($manager, 'sanctum')
            ->postJson($approve, ['handover_id' => $handover->id, 'confirmed_amount' => '61.00'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'HANDOVER_AMOUNT_MISMATCH_CORRECTION_REQUIRED');
        $this->actingAs($otherManager, 'sanctum')
            ->postJson($approve, ['handover_id' => $handover->id, 'confirmed_amount' => '61.50'])
            ->assertStatus(403);
        $this->actingAs($manager, 'sanctum')
            ->postJson($approve, ['handover_id' => $handover->id, 'confirmed_amount' => '61.501'])
            ->assertStatus(422);

        $this->assertSame('pending', $handover->fresh()->status);
        $this->assertSame(0, CashierShiftHandoverReceipt::count());
        $this->assertSame(0, PersonalLedgerTransaction::count());
    }

    public function test_manager_assignment_guard_rejects_second_active_create_activate_and_transfer(): void
    {
        $branch = Branch::factory()->create();
        $otherBranch = Branch::factory()->create();
        BranchManager::factory()->create(['branch_id' => $branch->id]);

        try {
            BranchManager::factory()->create(['branch_id' => $branch->id]);
            $this->fail('Second active manager creation must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('branch_id', $e->errors());
        }

        $inactive = BranchManager::factory()->create(['branch_id' => $branch->id, 'status' => 'inactive', 'is_active' => false]);
        try {
            $inactive->activate();
            $this->fail('Second active manager activation must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('branch_id', $e->errors());
        }

        $moving = BranchManager::factory()->create(['branch_id' => $otherBranch->id]);
        try {
            $moving->update(['branch_id' => $branch->id]);
            $this->fail('Transfer into an occupied branch must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('branch_id', $e->errors());
        }
        $this->assertSame($otherBranch->id, $moving->fresh()->branch_id);
    }

    public function test_manager_assignment_resolution_fails_closed_for_zero_or_legacy_duplicate_active_managers(): void
    {
        $branch = Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $resolver = app(BranchManagerService::class);
        $this->assertSame($manager->id, $resolver->assignedActiveManager($branch->id)->id);

        $manager->deactivate();
        try {
            $resolver->assignedActiveManager($branch->id);
            $this->fail('No assigned manager must fail closed.');
        } catch (ConflictHttpException $e) {
            $this->assertSame('BRANCH_MANAGER_RECIPIENT_UNAVAILABLE', $e->getMessage());
        }

        $manager->activate();
        $legacyDuplicate = BranchManager::factory()->create(['branch_id' => $branch->id, 'status' => 'inactive', 'is_active' => false]);
        DB::table('branch_managers')->where('id', $legacyDuplicate->id)->update(['status' => 'active', 'is_active' => true]);
        try {
            $resolver->assignedActiveManager($branch->id);
            $this->fail('Legacy duplicate active managers must fail closed.');
        } catch (ConflictHttpException $e) {
            $this->assertSame('BRANCH_MANAGER_RECIPIENT_AMBIGUOUS', $e->getMessage());
        }
    }

    public function test_manager_recipient_routes_reject_nonassigned_manager_and_mismatched_explicit_id(): void
    {
        [$branch, $manager, $sender, $source, , $handover] = $this->managerHandoverFixture('10.00');
        $otherManager = BranchManager::factory()->create(['branch_id' => $branch->id, 'status' => 'inactive', 'is_active' => false]);

        $this->actingAs($otherManager, 'sanctum')
            ->postJson('/api/branch-manager/workday/handoffs/approve', [
                'handover_id' => $handover->id, 'confirmed_amount' => '10.00',
            ])->assertStatus(403);
        $this->actingAs($otherManager, 'sanctum')
            ->postJson('/api/branch-manager/workday/handoffs/reject', [
                'handover_id' => $handover->id, 'rejection_reason' => 'wrong manager',
            ])->assertStatus(403);
        $this->assertSame('pending', $handover->fresh()->status);

        $source->update(['total_sales' => '10.00']);
        $this->actingAs($sender, 'sanctum')
            ->postJson("/api/cashier/shifts/{$source->id}/start-handover", [
                'branch_manager_id' => $otherManager->id,
                'handover_amount' => '10.00',
            ])->assertStatus(403);
        $this->assertSame(0, CashierShiftHandoverReceipt::count());
        $this->assertSame($manager->id, $handover->fresh()->handover_to_id);
    }

    public function test_manager_receipt_requires_current_workday_with_conflict_code(): void
    {
        [, $manager, , $source, , $handover] = $this->managerHandoverFixture('10.00');
        BranchManagerShift::where('branch_manager_id', $manager->id)->delete();

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/branch-manager/shifts/{$source->id}/handover/approve", [
                'confirmed_amount' => '10.00',
            ])->assertStatus(409)->assertJsonPath('code', 'RECEIVING_MANAGER_WORKDAY_REQUIRED');
        $this->assertSame('pending', $handover->fresh()->status);
        $this->assertSame(0, CashierShiftHandoverReceipt::count());
    }

    public function test_manager_can_end_checks_only_handovers_addressed_to_that_manager(): void
    {
        [$branch, $manager, , , , $handover] = $this->managerHandoverFixture('10.00');
        $workday = BranchManagerShift::where('branch_manager_id', $manager->id)->sole();
        $workday->update(['status' => 'in_progress']);
        $this->assertFalse($workday->fresh()->canEnd());

        $legacyOther = BranchManager::factory()->create(['branch_id' => $branch->id, 'status' => 'inactive', 'is_active' => false]);
        $handover->update(['handover_to_id' => $legacyOther->id]);
        $this->assertTrue($workday->fresh()->canEnd());
    }

    public function test_manager_recipient_selection_rejects_zero_and_multiple_active_assignments(): void
    {
        $branch = Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $sender = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $template = Shift::factory()->create(['branch_id' => $branch->id]);
        $source = CashierShift::factory()->completed()->create([
            'cashier_id' => $sender->id,
            'shift_id' => $template->id,
            'shift_date' => today(),
            'total_sales' => '10.00',
        ]);
        $route = "/api/cashier/shifts/{$source->id}/start-handover";
        $payload = ['handover_to_type' => 'branch_manager', 'handover_amount' => '10.00'];

        $manager->deactivate();
        $this->actingAs($sender, 'sanctum')->postJson($route, $payload)
            ->assertStatus(409)->assertJsonPath('code', 'BRANCH_MANAGER_RECIPIENT_UNAVAILABLE');

        $manager->activate();
        $duplicate = BranchManager::factory()->create(['branch_id' => $branch->id, 'status' => 'inactive', 'is_active' => false]);
        DB::table('branch_managers')->where('id', $duplicate->id)->update(['status' => 'active', 'is_active' => true]);
        $this->postJson($route, $payload)
            ->assertStatus(409)->assertJsonPath('code', 'BRANCH_MANAGER_RECIPIENT_AMBIGUOUS');
        $this->assertSame(0, CashierShiftHandover::where('cashier_shift_id', $source->id)->count());
    }

    public function test_submitted_receiving_shift_is_untouched_and_cannot_confirm(): void
    {
        [$source, $recipient, $destination, $handover] = $this->handoverFixture('10.00');
        DB::table('cashier_shifts')->where('id', $destination->id)->update(['status' => 'submitted']);

        try {
            app(ShiftTransferReceiptService::class)->confirmHandover($handover->id, $recipient, '10.00', $destination->id);
            $this->fail('Submitted receiving report must not be mutated.');
        } catch (ConflictHttpException $e) {
            $this->assertSame('RECEIVING_SHIFT_NOT_AVAILABLE', $e->getMessage());
        }
        $this->assertSame('0.00', (string) $destination->fresh()->opening_balance);
        $this->assertSame(0, CashierShiftHandoverReceipt::count());
        $this->assertSame('pending', $handover->fresh()->status);
        $this->assertSame(ShiftStatus::COMPLETED, $source->fresh()->status);
    }

    public function test_multiple_pending_handover_rows_return_controlled_conflict(): void
    {
        [$source, , , $handover] = $this->handoverFixture('10.00');
        $duplicate = $handover->replicate();
        $duplicate->id = (string) \Illuminate\Support\Str::uuid();
        $duplicate->save();
        $manager = BranchManager::query()->findOrFail($source->cashier->created_by);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/branch-manager/shifts/{$source->id}/handover/approve", [])
            ->assertStatus(409)
            ->assertJsonPath('message', 'HANDOVER_CURRENT_REQUEST_AMBIGUOUS');
        $this->assertSame(0, CashierShiftHandoverReceipt::count());
    }

    public function test_manager_handover_revision_records_actual_manager_actor(): void
    {
        [$branch, $manager, , , $destination] = $this->managerTransferFixture();
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $source = CashierShift::factory()->completed()->create([
            'cashier_id' => $cashier->id,
            'shift_id' => $destination->shift_id,
            'shift_date' => today(),
            'total_sales' => '10.00',
        ]);

        $this->actingAs($manager, 'sanctum');
        app(HandoverService::class)->recordHandover($source, [
            'handover_to_type' => 'cashier',
            'handover_to_id' => $destination->cashier_id,
            'handover_amount' => '10.00',
        ], $manager);

        $revision = app(ShiftReportRevisionService::class)->currentCashierRevision($source);
        $this->assertSame('branch_manager', $revision->created_by_type);
        $this->assertSame($manager->id, $revision->created_by_id);
    }

    public function test_identical_bulk_breakdown_does_not_advance_report_revision(): void
    {
        [, $manager, $cashier, $managerShift, $cashierShift] = $this->managerTransferFixture();
        $split = \App\Support\ShiftFinancialCalculator::calculateVatInclusiveSales('10.00');
        $cashierShift->update([
            'total_sales' => '10.00',
            'net_sales' => $split['net'],
            'vat_amount' => $split['vat'],
        ]);
        $first = app(ShiftReportRevisionService::class)->recordCashierRevision($cashierShift, 'cashier', $cashier->id, 0);
        $service = app(BranchManagerShiftService::class);

        $service->bulkUpdateCashierShifts([['cashier_id' => $cashier->id, 'sales' => '10.00']], $managerShift);
        $this->assertSame($first->id, app(ShiftReportRevisionService::class)->currentCashierRevision($cashierShift)->id);

        $service->bulkUpdateCashierShifts([['cashier_id' => $cashier->id, 'sales' => '11.00']], $managerShift);
        $this->assertSame(2, app(ShiftReportRevisionService::class)->currentCashierRevision($cashierShift)->revision_number);
    }

    public function test_rejected_cashier_handover_can_be_corrected_and_then_confirmed_exactly(): void
    {
        [$source, $recipient, $destination, $handover] = $this->handoverFixture('61.50');
        $service = app(HandoverService::class);
        $service->rejectHandoverForAmountCorrection($source, $recipient->id, get_class($recipient), 'Physical amount was 61.00', '61.00', 'actual_shortage');
        $this->actingAs($source->cashier, 'sanctum');
        $service->recordHandoverEdit($source, [
            'handover_amount' => '61.00',
            'handover_notes' => 'Corrected after recipient count',
            'correction_reason' => 'actual_shortage',
        ]);
        $receipt = app(ShiftTransferReceiptService::class)->confirmHandover($handover->id, $recipient, '61.00', $destination->id);

        $history = \Modules\Shift\Models\CashierShiftHistory::query()
            ->where('cashier_shift_id', $source->id)
            ->where('action', 'handover_request_corrected')
            ->sole();
        $this->assertSame('61.50', $history->new_value['old_requested_amount']);
        $this->assertSame('61.00', $history->new_value['new_requested_amount']);
        $this->assertSame('actual_shortage', $history->new_value['correction_reason']);
        $rejectionHistory = \Modules\Shift\Models\CashierShiftHistory::query()
            ->where('cashier_shift_id', $source->id)
            ->where('action', 'handover_amount_correction_rejected')
            ->sole();
        $this->assertSame('61.00', $rejectionHistory->new_value['attempted_confirmed_amount']);
        $this->assertSame($recipient->id, $rejectionHistory->new_value['actor_id']);
        $this->assertNotNull($history->created_at);
        $this->assertSame($source->cashier_id, $history->new_value['actor_id']);
        $this->assertSame('61.00', $receipt->confirmed_amount);
        $this->assertSame(2, CashierCustodyTransaction::where('receipt_id', $receipt->id)->count());
    }

    public function test_input_error_correction_records_old_and_new_amount_before_exact_confirmation(): void
    {
        [$source, $recipient, $destination, $handover] = $this->handoverFixture('61.50');
        $service = app(HandoverService::class);
        $service->rejectHandoverForAmountCorrection($source, $recipient->id, get_class($recipient), 'Entered amount was wrong', '61.00', 'input_error');
        $this->actingAs($source->cashier, 'sanctum');
        $service->recordHandoverEdit($source, [
            'handover_amount' => '61.00',
            'correction_reason' => 'input_error',
        ]);

        $history = \Modules\Shift\Models\CashierShiftHistory::query()
            ->where('cashier_shift_id', $source->id)
            ->where('action', 'handover_request_corrected')
            ->sole();
        $this->assertSame('61.50', $history->new_value['old_requested_amount']);
        $this->assertSame('61.00', $history->new_value['new_requested_amount']);
        $this->assertSame('input_error', $history->new_value['correction_reason']);
        $this->assertSame($source->cashier_id, $history->new_value['actor_id']);
        $receipt = app(ShiftTransferReceiptService::class)->confirmHandover($handover->id, $recipient, '61.00', $destination->id);
        $this->assertSame('61.00', $receipt->confirmed_amount);
    }

    public function test_manager_confirmation_creates_manager_bound_receipt_and_effects(): void
    {
        [$branch, $manager, $sender, $source, $destination, $handover, $revision] = $this->managerHandoverFixture('61.50');
        app(HandoverService::class)->approveHandover($source, $manager->id, get_class($manager), null, '61.50');

        $receipt = CashierShiftHandoverReceipt::query()->sole();
        $this->assertNull($receipt->receiving_cashier_shift_id);
        $this->assertSame($manager->id, $receipt->receiving_branch_manager_id);
        $this->assertNotNull($receipt->receiving_branch_manager_shift_id);
        $this->assertSame($manager->id, $receipt->confirmed_by_branch_manager_id);
        $this->assertSame($revision->id, $receipt->report_revision_id);
        $this->assertSame('61.50', $receipt->confirmed_amount);
        $this->assertSame('approved', $handover->fresh()->status);
        $this->assertDatabaseHas('cashier_custody_transactions', [
            'receipt_id' => $receipt->id,
            'cashier_id' => $sender->id,
            'transaction_type' => 'Handover Sent',
            'amount' => '61.50',
            'is_cash_in' => 0,
        ]);
        $ledger = PersonalLedgerTransaction::where('receipt_id', $receipt->id)->sole();
        $this->assertSame('Total Sales', $ledger->transaction_type);
        $this->assertSame('61.50', $ledger->amount);
        $this->assertSame($manager->id, $ledger->branch_manager_id);
        $this->assertSame($receipt->confirmed_at->toDateString(), $ledger->transaction_date->toDateString());
        $this->assertSame(0, CashierCustodyTransaction::where('receipt_id', $receipt->id)->where('transaction_type', 'Handover Received')->count());
    }

    public function test_manager_receipt_audit_failure_rolls_back_receipt_custody_ledger_and_state(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Failure trigger is SQLite-specific; deployment-equivalent DB coverage is separate.');
        }

        [, $manager, $sender, $source, $destination, $handover] = $this->managerHandoverFixture('61.50');
        DB::statement("CREATE TRIGGER fail_manager_receipt_audit BEFORE INSERT ON cashier_shift_history WHEN NEW.action = 'cash_transfer_received' BEGIN SELECT RAISE(ABORT, 'injected manager receipt audit failure'); END");

        try {
            app(HandoverService::class)->approveHandover($source, $manager->id, get_class($manager), null, '61.50');
            $this->fail('Required manager receipt audit must fail.');
        } catch (QueryException) {
            $this->assertSame(0, CashierShiftHandoverReceipt::count());
            $this->assertSame(0, CashierCustodyTransaction::count());
            $this->assertSame(0, PersonalLedgerTransaction::count());
            $this->assertSame('pending', $handover->fresh()->status);
            $this->assertSame('pending', $source->handoverStatus()->firstOrFail()->status->value);
            $this->assertSame(0, DB::table('cashier_shift_history')->where('action', 'cash_transfer_received')->count());
        }
    }

    public function test_manager_review_does_not_approve_cashier_request_and_named_cashier_still_confirms(): void
    {
        [$source, $recipient, $destination, $handover] = $this->handoverFixture('10.00');

        app(HandoverService::class)->approveHandover(
            $source,
            '00000000-0000-0000-0000-000000000001',
            'branch_manager',
            'Reviewed'
        );

        $this->assertSame('pending', $handover->fresh()->status);
        $this->assertSame(0, CashierShiftHandoverReceipt::count());
        $this->assertSame('10.00', app(ShiftTransferReceiptService::class)
            ->confirmHandover($handover->id, $recipient, '10.00', $destination->id)->confirmed_amount);
        $this->assertSame(1, CashierShiftHandoverReceipt::count());
    }

    public function test_receipt_is_immutable_and_duplicate_confirmation_cannot_create_another(): void
    {
        [, $recipient, $destination, $handover] = $this->handoverFixture('10.00');
        $service = app(ShiftTransferReceiptService::class);
        $receipt = $service->confirmHandover($handover->id, $recipient, '10.00', $destination->id);

        try {
            $service->confirmHandover($handover->id, $recipient, '10.00', $destination->id);
            $this->fail('A second confirmation must be rejected.');
        } catch (ConflictHttpException) {
            $this->assertSame(1, CashierShiftHandoverReceipt::count());
            $this->assertSame(2, CashierCustodyTransaction::where('related_handover_id', $handover->id)->count());
            $this->assertSame(1, DB::table('cashier_shift_history')->where('action', 'cash_transfer_received')->count());
            $this->assertSame('10.00', $destination->fresh()->opening_balance);
        }

        $this->expectException(\LogicException::class);
        $receipt->update(['confirmed_amount' => '1.00']);
    }

    public function test_duplicate_manager_projection_listener_activity_does_not_create_another_receipt(): void
    {
        [$source, $recipient, $destination, $handover] = $this->handoverFixture('10.00');
        $receipt = app(ShiftTransferReceiptService::class)->confirmHandover(
            $handover->id,
            $recipient,
            '10.00',
            $destination->id
        );

        $listener = app(BranchManagerShiftListener::class);
        $listener->handle($source);
        $listener->handle($source->fresh());

        $this->assertSame(1, CashierShiftHandoverReceipt::count());
        $this->assertSame($receipt->id, CashierShiftHandoverReceipt::query()->sole()->id);
    }

    public function test_stale_report_revision_and_wrong_receiving_shift_are_rejected(): void
    {
        [$source, $recipient, $destination, $handover, $revision, $otherShift] = $this->handoverFixture('10.00', true);
        $revisions = app(ShiftReportRevisionService::class);
        DB::transaction(fn () => $revisions->recordCashierRevision($source, 'branch_manager', '00000000-0000-0000-0000-000000000001'));

        try {
            app(ShiftTransferReceiptService::class)->confirmHandover($handover->id, $recipient, '10.00', $destination->id);
            $this->fail('A request from an older report revision must be stale.');
        } catch (ConflictHttpException $e) {
            $this->assertSame('STALE_REPORT_REVISION', $e->getMessage());
        }
        $this->assertSame(0, CashierShiftHandoverReceipt::count());

        // Restore the source request to a current revision, then prove the shift owner check.
        $current = $revisions->currentCashierRevision($source);
        $handover->update(['report_revision_id' => $current->id]);
        try {
            app(ShiftTransferReceiptService::class)->confirmHandover($handover->id, $recipient, '10.00', $otherShift->id);
            $this->fail('A shift belonging to another cashier must be refused.');
        } catch (ConflictHttpException $e) {
            $this->assertSame('RECEIVING_SHIFT_NOT_AVAILABLE', $e->getMessage());
        }
        $this->assertSame(0, CashierShiftHandoverReceipt::count());
    }

    public function test_receipt_and_both_custody_rows_roll_back_when_destination_write_fails(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Failure trigger is SQLite-specific; deployment-equivalent DB coverage is separate.');
        }

        [, $recipient, $destination, $handover] = $this->handoverFixture('10.00');
        DB::statement("CREATE TRIGGER fail_receiving_custody BEFORE INSERT ON cashier_custody_transactions WHEN NEW.transaction_type = 'Handover Received' BEGIN SELECT RAISE(ABORT, 'injected receiving failure'); END");

        try {
            app(ShiftTransferReceiptService::class)->confirmHandover($handover->id, $recipient, '10.00', $destination->id);
            $this->fail('Required recipient custody write must fail.');
        } catch (QueryException) {
            $this->assertSame(0, CashierShiftHandoverReceipt::count());
            $this->assertSame(0, CashierCustodyTransaction::count());
            $this->assertSame('pending', $handover->fresh()->status);
            $this->assertSame(0.0, (float) $destination->fresh()->opening_balance);
        }
    }

    public function test_source_custody_failure_rolls_back_receipt_and_all_other_effects(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Failure trigger is SQLite-specific; deployment-equivalent DB coverage is separate.');
        }

        [, $recipient, $destination, $handover] = $this->handoverFixture('10.00');
        DB::statement("CREATE TRIGGER fail_sending_custody BEFORE INSERT ON cashier_custody_transactions WHEN NEW.transaction_type = 'Handover Sent' BEGIN SELECT RAISE(ABORT, 'injected sending failure'); END");

        try {
            app(ShiftTransferReceiptService::class)->confirmHandover($handover->id, $recipient, '10.00', $destination->id);
            $this->fail('Required sender custody write must fail.');
        } catch (QueryException) {
            $this->assertSame(0, CashierShiftHandoverReceipt::count());
            $this->assertSame(0, CashierCustodyTransaction::count());
            $this->assertSame('pending', $handover->fresh()->status);
            $this->assertSame(0.0, (float) $destination->fresh()->opening_balance);
        }
    }

    public function test_required_audit_failure_rolls_back_receipt_state_and_custody(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Failure trigger is SQLite-specific; deployment-equivalent DB coverage is separate.');
        }

        [, $recipient, $destination, $handover] = $this->handoverFixture('10.00');
        DB::statement("CREATE TRIGGER fail_transfer_audit BEFORE INSERT ON cashier_shift_history WHEN NEW.action = 'cash_transfer_received' BEGIN SELECT RAISE(ABORT, 'injected audit failure'); END");

        try {
            app(ShiftTransferReceiptService::class)->confirmHandover($handover->id, $recipient, '10.00', $destination->id);
            $this->fail('Required audit write must fail.');
        } catch (QueryException) {
            $this->assertSame(0, CashierShiftHandoverReceipt::count());
            $this->assertSame(0, CashierCustodyTransaction::count());
            $this->assertSame('pending', $handover->fresh()->status);
            $this->assertSame(0.0, (float) $destination->fresh()->opening_balance);
        }
    }

    public function test_manager_transfer_requires_correction_then_posts_exactly_the_corrected_amount(): void
    {
        [, $manager, $cashier, $source, $destination] = $this->managerTransferFixture();
        $this->fundManagerLedger($manager, $source);
        $service = app(ShiftTransferReceiptService::class);
        $transfer = $service->requestManagerCashTransfer($source, $cashier, $destination, '61.50', $manager);

        try {
            $service->confirmManagerCashTransfer($transfer->id, $cashier, '61.00');
            $this->fail('Manager transfer confirmation must match the request exactly.');
        } catch (ConflictHttpException $e) {
            $this->assertSame('HANDOVER_AMOUNT_MISMATCH_CORRECTION_REQUIRED', $e->getMessage());
        }
        $this->assertSame(0, CashierShiftHandoverReceipt::count());

        $service->rejectManagerCashTransfer($transfer->id, $cashier, '61.00', 'Actual cash was short by 0.50', 'actual_shortage');
        $service->correctManagerCashTransfer($transfer->id, $manager, '61.00', 'actual_shortage');
        $receipt = $service->confirmManagerCashTransfer($transfer->id, $cashier, '61.00');

        $this->assertSame($destination->id, $receipt->receiving_cashier_shift_id);
        $this->assertSame('61.00', $receipt->confirmed_amount);
        $this->assertSame('confirmed', $transfer->fresh()->status);
        $this->assertSame('61.00', PersonalLedgerTransaction::where('receipt_id', $receipt->id)->sole()->amount);
        $this->assertSame('61.00', CashierCustodyTransaction::where('receipt_id', $receipt->id)->sole()->amount);
        $history = \Modules\Shift\Models\CashierShiftHistory::query()
            ->where('cashier_shift_id', $destination->id)
            ->where('action', 'manager_transfer_request_corrected')
            ->sole();
        $this->assertSame('61.50', $history->new_value['old_requested_amount']);
        $this->assertSame('61.00', $history->new_value['new_requested_amount']);
        $this->assertSame('actual_shortage', $history->new_value['correction_reason']);
        $rejection = \Modules\Shift\Models\CashierShiftHistory::query()
            ->where('cashier_shift_id', $destination->id)
            ->where('action', 'manager_transfer_amount_correction_rejected')
            ->sole();
        $this->assertSame('61.00', $rejection->new_value['attempted_confirmed_amount']);
        $this->assertSame($cashier->id, $rejection->new_value['actor_id']);
        $this->assertStringContainsString('no liability allocation inferred', $history->new_value['evidence_note']);
        $this->assertSame($manager->id, $history->new_value['actor_id']);
        $this->assertNotNull($history->created_at);
        $this->assertSame(0, CashierShiftHandover::count());
    }

    public function test_manager_ledger_failure_rolls_back_receipt_transfer_and_recipient_custody(): void
    {
        if (DB::getDriverName() !== 'sqlite') {
            $this->markTestSkipped('Failure trigger is SQLite-specific; deployment-equivalent DB coverage is separate.');
        }

        [, $manager, $cashier, $source, $destination] = $this->managerTransferFixture();
        $this->fundManagerLedger($manager, $source);
        $service = app(ShiftTransferReceiptService::class);
        $transfer = $service->requestManagerCashTransfer($source, $cashier, $destination, '61.00', $manager);
        DB::statement("CREATE TRIGGER fail_manager_ledger BEFORE INSERT ON personal_ledger_transactions BEGIN SELECT RAISE(ABORT, 'injected ledger failure'); END");

        try {
            $service->confirmManagerCashTransfer($transfer->id, $cashier, '61.00');
            $this->fail('Required manager ledger write must fail.');
        } catch (QueryException) {
            $this->assertSame(0, CashierShiftHandoverReceipt::count());
            $this->assertSame(1, PersonalLedgerTransaction::count());
            $this->assertSame(0, PersonalLedgerTransaction::whereNotNull('receipt_id')->count());
            $this->assertSame(0, CashierCustodyTransaction::count());
            $this->assertSame('pending', $transfer->fresh()->status);
            $this->assertSame(0.0, (float) $destination->fresh()->opening_balance);
        }
    }

    public function test_manager_transfer_uses_actual_personal_ledger_cash_and_reserves_pending_requests(): void
    {
        [, $manager, $cashier, $source, $destination] = $this->managerTransferFixture();
        $source->update(['cash_collected' => '0.00']);
        $this->fundManagerLedger($manager, $source, '100.00');
        $service = app(ShiftTransferReceiptService::class);

        $first = $service->requestManagerCashTransfer($source, $cashier, $destination, '60.00', $manager);
        $this->assertSame('pending', $first->status);
        try {
            $service->requestManagerCashTransfer($source, $cashier, $destination, '41.00', $manager);
            $this->fail('Pending obligations must reserve personal-ledger cash.');
        } catch (ConflictHttpException $e) {
            $this->assertSame('INSUFFICIENT_RECORDED_SALES_CASH', $e->getMessage());
        }

        $receipt = $service->confirmManagerCashTransfer($first->id, $cashier, '60.00');
        $this->assertSame('60.00', $receipt->confirmed_amount);
        $this->assertSame('40.00', (string) number_format((float) (PersonalLedgerTransaction::where('branch_manager_id', $manager->id)->where('is_cash_in', true)->sum('amount') - PersonalLedgerTransaction::where('branch_manager_id', $manager->id)->where('is_cash_in', false)->sum('amount')), 2, '.', ''));
        $second = $service->requestManagerCashTransfer($source, $cashier, $destination, '40.00', $manager);
        $this->assertSame('pending', $second->status);
    }

    public function test_manager_transfer_accepts_in_progress_receiving_shift(): void
    {
        [, $manager, $cashier, $source, $destination] = $this->managerTransferFixture();
        $this->fundManagerLedger($manager, $source);
        $destination->update(['status' => ShiftStatus::IN_PROGRESS]);
        $service = app(ShiftTransferReceiptService::class);

        $transfer = $service->requestManagerCashTransfer($source, $cashier, $destination, '10.00', $manager);
        $receipt = $service->confirmManagerCashTransfer($transfer->id, $cashier, '10.00');

        $this->assertSame($destination->id, $receipt->receiving_cashier_shift_id);
        $this->assertSame('10.00', $destination->fresh()->opening_balance);
    }

    public function test_workday_cash_without_personal_ledger_cash_cannot_fund_transfer(): void
    {
        [, $manager, $cashier, $source, $destination] = $this->managerTransferFixture();
        $this->assertSame('100.00', $source->cash_collected);

        try {
            app(ShiftTransferReceiptService::class)->requestManagerCashTransfer($source, $cashier, $destination, '1.00', $manager);
            $this->fail('Workday figures without an authoritative ledger credit are unavailable.');
        } catch (ConflictHttpException $e) {
            $this->assertSame('INSUFFICIENT_RECORDED_SALES_CASH', $e->getMessage());
        }
        $this->assertSame(0, CashierShiftHandoverReceipt::count());
    }

    public function test_variance_claim_credit_cannot_fund_manager_cash_transfer(): void
    {
        [, $manager, $cashier, $source, $destination] = $this->managerTransferFixture();
        PersonalLedgerTransaction::create([
            'branch_manager_id' => $manager->id,
            'transaction_type' => 'Variance from Cashier',
            'amount' => '100.00',
            'is_cash_in' => true,
            'related_shift_id' => $source->id,
            'transaction_date' => now(),
        ]);

        try {
            app(ShiftTransferReceiptService::class)->requestManagerCashTransfer($source, $cashier, $destination, '1.00', $manager);
            $this->fail('A liability claim is not available physical sales cash.');
        } catch (ConflictHttpException $e) {
            $this->assertSame('INSUFFICIENT_RECORDED_SALES_CASH', $e->getMessage());
        }
    }

    private function handoverFixture(string $amount, bool $includeOtherShift = false): array
    {
        $branch = Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $sender = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $recipient = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $otherCashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $template = Shift::factory()->create(['branch_id' => $branch->id]);
        $source = CashierShift::factory()->completed()->create([
            'cashier_id' => $sender->id,
            'shift_id' => $template->id,
            'shift_date' => today(),
        ]);
        $destination = CashierShift::factory()->create([
            'cashier_id' => $recipient->id,
            'shift_id' => $template->id,
            'shift_date' => today(),
            'status' => ShiftStatus::NOT_STARTED,
        ]);
        $otherShift = CashierShift::factory()->create([
            'cashier_id' => $otherCashier->id,
            'shift_id' => $template->id,
            'shift_date' => today(),
            'status' => ShiftStatus::NOT_STARTED,
        ]);
        $revision = app(ShiftReportRevisionService::class)->recordCashierRevision($source, 'cashier', $sender->id, 0);
        ShiftHandoverStatus::create([
            'cashier_shift_id' => $source->id,
            'status' => HandoverStatus::PENDING,
            'manager_approval_status' => 'pending',
        ]);
        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $source->id,
            'handover_to_id' => $recipient->id,
            'handover_to_type' => 'cashier',
            'handover_amount' => $amount,
            'variance_amount' => '0.00',
            'handover_date' => today()->toDateString(),
            'handover_time' => now(),
            'status' => 'pending',
            'report_revision_id' => $revision->id,
        ]);

        return [$source, $recipient, $destination, $handover, $revision, $otherShift];
    }

    private function managerTransferFixture(): array
    {
        $branch = Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $template = Shift::factory()->create(['branch_id' => $branch->id]);
        $source = BranchManagerShift::create([
            'branch_manager_id' => $manager->id,
            'branch_id' => $branch->id,
            'shift_date' => today()->toDateString(),
            'status' => 'completed',
            'cash_collected' => '100.00',
        ]);
        $destination = CashierShift::factory()->create([
            'cashier_id' => $cashier->id,
            'shift_id' => $template->id,
            'shift_date' => today(),
            'status' => ShiftStatus::NOT_STARTED,
        ]);

        return [$branch, $manager, $cashier, $source, $destination];
    }

    private function fundManagerLedger(BranchManager $manager, BranchManagerShift $source, string $amount = '100.00'): void
    {
        PersonalLedgerTransaction::create([
            'branch_manager_id' => $manager->id,
            'transaction_type' => 'Total Sales',
            'amount' => $amount,
            'is_cash_in' => true,
            'related_shift_id' => $source->id,
            'transaction_date' => now(),
        ]);
    }

    private function managerHandoverFixture(string $amount): array
    {
        [$branch, $manager, $cashier, $managerWorkday, $destination] = $this->managerTransferFixture();
        $sourceCashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $sourceShift = CashierShift::factory()->completed()->create([
            'cashier_id' => $sourceCashier->id,
            'shift_id' => $destination->shift_id,
            'shift_date' => today(),
        ]);
        $sender = $sourceShift->cashier;
        $revision = app(ShiftReportRevisionService::class)->recordCashierRevision($sourceShift, 'cashier', $sender->id, 0);
        $managerWorkday->update(['status' => 'active', 'cash_collected' => $amount]);
        ShiftHandoverStatus::create([
            'cashier_shift_id' => $sourceShift->id,
            'status' => HandoverStatus::PENDING,
            'manager_approval_status' => 'pending',
        ]);
        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $sourceShift->id,
            'handover_to_id' => $manager->id,
            'handover_to_type' => 'branch_manager',
            'handover_amount' => $amount,
            'variance_amount' => '0.00',
            'handover_date' => today()->toDateString(),
            'handover_time' => now(),
            'status' => 'pending',
            'report_revision_id' => $revision->id,
        ]);

        return [$branch, $manager, $sender, $sourceShift, $destination, $handover, $revision];
    }
}
