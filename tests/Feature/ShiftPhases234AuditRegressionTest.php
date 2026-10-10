<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Aggregator\Models\Aggregator;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Liability\DailyLiabilityGuard;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftHandoverStatus;
use Modules\Shift\Models\ShiftLiabilityDailyLock;
use Modules\Shift\Models\ShiftReportAggregate;
use Modules\Shift\Models\ShiftReportRevisionSnapshot;
use Modules\Shift\Services\HandoverService;
use Modules\Shift\Services\ShiftCashCountService;
use Modules\Shift\Services\ShiftReportCorrectionService;
use Modules\Shift\Services\ShiftReportReopenService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Modules\Shift\Services\ShiftTransferReceiptService;
use Modules\Shift\Services\TransferRequestLifecycleService;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class ShiftPhases234AuditRegressionTest extends \Tests\TestCase
{
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    private function hf(): array
    {
        return $this->handoverFixture('100.00');
    }

    private function mf(): array
    {
        return $this->managerTransferFixture('60.00');
    }

    private function destination(array $fixture): array
    {
        [$branch, $manager, , , , $oldDest] = $fixture;
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $shift = CashierShift::factory()->create(['cashier_id' => $cashier->id, 'shift_id' => $oldDest->shift_id, 'status' => 'not_started', 'shift_date' => today()]);

        return ['recipient_type' => 'cashier', 'recipient_id' => $cashier->id, 'receiving_shift_id' => $shift->id, 'destination_cashier_shift_id' => $shift->id];
    }

    private function correctionFixture(): array
    {
        $f = $this->hf();
        $f[4]->update(['total_sales' => '100.00', 'net_sales' => '86.96', 'vat_amount' => '13.04', 'card_payments' => '0.00', 'cash_collected' => '100.00']);

        return $f;
    }

    private function rejectConflict(callable $run, string $code): void
    {
        try {
            $run();
        } catch (ConflictHttpException $e) {
            $this->assertSame($code, $e->getMessage());

            return;
        }
        $this->fail("Required conflict {$code} was not raised; prohibited write succeeded.");
    }

    public function test_audit_current_request_excludes_cancelled_pending_predecessor(): void
    {
        $f = $this->hf();
        $new = app(TransferRequestLifecycleService::class)->replaceRecipient('handover', $f[6]->id, $f[2], $this->destination($f), '100.00', 1, 'Change recipient', Str::uuid());
        $this->assertNotNull($f[6]->fresh()->cancelled_at);
        try {
            $current = app(HandoverService::class)->currentHandover($f[4]);
        } catch (ConflictHttpException $e) {
            $this->fail('Cancelled predecessor blocks active replacement: '.$e->getMessage());
        }
        $this->assertSame($new->id, $current->id);
    }

    public function test_audit_manager_replacement_rejects_wrong_cashier_shift(): void
    {
        [$manager, , $day, $oldDest, $request] = $this->mf();
        $newCashier = Cashier::factory()->create(['branch_id' => $day->branch_id, 'created_by' => $manager->id]);
        $this->rejectConflict(fn () => app(TransferRequestLifecycleService::class)->replaceRecipient('manager_transfer', $request->id, $manager, ['recipient_id' => $newCashier->id, 'destination_cashier_shift_id' => $oldDest->id], '80.00', 1, 'Change recipient', Str::uuid()), 'RECEIVING_SHIFT_NOT_AVAILABLE');
    }

    public function test_audit_handover_replacement_rejects_cross_branch_explicit_shift(): void
    {
        $f = $this->hf();
        $destination = $this->destination($f);
        $foreignTemplate = Shift::factory()->create(['branch_id' => Branch::factory()->create()->id]);
        CashierShift::findOrFail($destination['receiving_shift_id'])->update(['shift_id' => $foreignTemplate->id]);
        $this->rejectConflict(fn () => app(TransferRequestLifecycleService::class)->replaceRecipient('handover', $f[6]->id, $f[2], $destination, '100.00', 1, 'Cross branch explicit shift', Str::uuid()), 'RECEIVING_SHIFT_NOT_AVAILABLE');
    }

    public function test_audit_legacy_retained_cash_blocks_handover_replacement(): void
    {
        $f = $this->hf();
        $f[6]->update(['status' => 'rejected']);
        app(ShiftCashCountService::class)->recordRejectionEvidence($f[6]->id, null, 'cashier', $f[3]->id, $f[5]->id, '100.00', '50.00', 'input_error');
        $this->assertSame(5000, app(ShiftCashCountService::class)->pendingIncomingHalalas($f[5]));
        $this->rejectConflict(fn () => app(TransferRequestLifecycleService::class)->replaceRecipient('handover', $f[6]->id, $f[2], $this->destination($f), '100.00', 1, 'Legacy rejected cash still retained', Str::uuid()), 'PHYSICAL_RETURN_REQUIRED');
    }

    public function test_audit_legacy_retained_cash_blocks_manager_replacement(): void
    {
        [$manager, $cashier, $day, $dest, $request] = $this->mf();
        $request->update(['status' => 'rejected']);
        app(ShiftCashCountService::class)->recordRejectionEvidence(null, $request->id, 'cashier', $cashier->id, $dest->id, '60.00', '50.00', 'input_error');
        $newCashier = Cashier::factory()->create(['branch_id' => $day->branch_id, 'created_by' => $manager->id]);
        $newShift = CashierShift::factory()->create(['cashier_id' => $newCashier->id, 'shift_id' => $dest->shift_id, 'shift_date' => today(), 'status' => 'not_started']);
        $this->assertSame(5000, app(ShiftCashCountService::class)->pendingIncomingHalalas($dest));
        $this->rejectConflict(fn () => app(TransferRequestLifecycleService::class)->replaceRecipient('manager_transfer', $request->id, $manager, ['recipient_id' => $newCashier->id, 'destination_cashier_shift_id' => $newShift->id], '80.00', 1, 'Legacy cash retained', Str::uuid()), 'PHYSICAL_RETURN_REQUIRED');
    }

    public function test_audit_correction_rejects_foreign_cashier_actor(): void
    {
        $f = $this->correctionFixture();
        $foreign = Cashier::factory()->create(['branch_id' => Branch::factory()->create()->id]);
        $this->expectException(AccessDeniedHttpException::class);
        app(ShiftReportCorrectionService::class)->correctCashierReport($f[4], $foreign, ['card_payments' => '1.00'], 1, 'Unauthorized actor', Str::uuid());
    }

    public function test_audit_correction_blocks_submitted_day_without_daily_lock(): void
    {
        $f = $this->correctionFixture();
        BranchManagerShift::where('branch_id', $f[0]->id)->whereDate('shift_date', today())->update(['daily_report_submitted' => true, 'status' => 'completed']);
        $this->assertTrue(BranchManagerShift::where('branch_id', $f[0]->id)->where('daily_report_submitted', true)->exists());
        $this->rejectConflict(fn () => app(ShiftReportCorrectionService::class)->correctCashierReport($f[4], $f[2], ['card_payments' => '1.00'], 1, 'Correction after day submission', Str::uuid()), 'REPORT_REOPEN_REQUIRED');
    }

    public function test_audit_reopen_blocks_admin_closed_projection(): void
    {
        $f = $this->hf();
        $existing = DB::table('asab_shifts')->where('legacy_shift_id', $f[4]->id)->first();
        if ($existing) {
            DB::table('asab_shifts')->where('id', $existing->id)->update(['status' => 'closed']);
        } else {
            DB::table('asab_shifts')->insert(['id' => (string) Str::uuid(), 'company_id' => $f[0]->asab_company_id ?? (string) Str::uuid(), 'branch_id' => $f[0]->id, 'legacy_shift_id' => $f[4]->id, 'status' => 'closed']);
        }
        $this->rejectConflict(fn () => app(ShiftReportReopenService::class)->reopenCashierReport($f[4], $f[2], 1, 'Admin closed shift', Str::uuid()), 'REPORT_REOPEN_REQUIRED');
    }

    public function test_audit_reopen_blocks_submitted_carry_over_receiving_workday(): void
    {
        $f = $this->hf();
        $f[4]->update(['shift_date' => today()->subDays(3)]);
        $day = BranchManagerShift::where('branch_id', $f[0]->id)->whereDate('shift_date', today())->firstOrFail();
        $day->update(['daily_report_submitted' => true, 'status' => 'completed']);
        $f[6]->update(['handover_to_type' => 'branch_manager', 'handover_to_id' => $f[1]->id, 'status' => 'approved']);
        CashierShiftHandoverReceipt::create(['cashier_shift_handover_id' => $f[6]->id, 'report_revision_id' => $f[6]->report_revision_id, 'receiving_branch_manager_shift_id' => $day->id, 'receiving_branch_manager_id' => $f[1]->id, 'confirmed_by_id' => null, 'confirmed_amount' => '100.00', 'confirmed_at' => now()]);
        $this->rejectConflict(fn () => app(ShiftReportReopenService::class)->reopenCashierReport($f[4], $f[2], 1, 'Carry over in submitted receiving day', Str::uuid()), 'REPORT_REOPEN_REQUIRED');
    }

    public function test_audit_correction_cannot_bypass_fresh_count_after_reopen(): void
    {
        $f = $this->correctionFixture();
        ShiftReportAggregate::where('source_type', 'cashier_shift')->where('source_id', $f[4]->id)->update(['fresh_count_required' => true]);
        $revision = app(ShiftReportReopenService::class)->reopenCashierReport($f[4], $f[2], 1, 'Physical return needs recount', Str::uuid());
        $this->assertNull(app(ShiftCashCountService::class)->currentFor($f[4]->id));
        $this->rejectConflict(fn () => app(ShiftReportCorrectionService::class)->correctCashierReport($f[4], $f[2], ['card_payments' => '1.00'], $revision->revision_number, 'Attempt correction before recount', Str::uuid()), 'PHYSICAL_RECOUNT_REQUIRED');
    }

    public function test_audit_replacement_preserves_previous_snapshot(): void
    {
        $f = $this->hf();
        $previous = $f[6]->report_revision_id;
        app(TransferRequestLifecycleService::class)->replaceRecipient('handover', $f[6]->id, $f[2], $this->destination($f), '100.00', 1, 'Change recipient', Str::uuid());
        $this->assertNotNull(ShiftReportRevisionSnapshot::where('report_revision_id', $previous)->first(), 'Previous report must be snapshotted before mutating request/workflow state.');
    }

    public function test_audit_replacement_does_not_erase_rejection_count(): void
    {
        $f = $this->hf();
        $f[4]->handoverStatus->update(['rejection_count' => 2]);
        app(TransferRequestLifecycleService::class)->replaceRecipient('handover', $f[6]->id, $f[2], $this->destination($f), '100.00', 1, 'Change recipient', Str::uuid());
        $history = $f[4]->history()->where('action', 'handover_recipient_replaced')->firstOrFail();
        $before = is_array($history->old_value) ? $history->old_value : json_decode($history->old_value, true);
        $this->assertSame(2, $before['workflow']['rejection_count'] ?? null);
        $this->assertSame(0, $f[4]->fresh()->handoverStatus->rejection_count);
        $this->assertSame($f[2]->id, $history->performed_by);
        $this->assertSame('cashier', $history->performed_by_type);
    }

    public function test_audit_manager_replacement_rejects_negative_amount(): void
    {
        [$manager, , $day, $dest, $request] = $this->mf();
        $newCashier = Cashier::factory()->create(['branch_id' => $day->branch_id, 'created_by' => $manager->id]);
        $newShift = CashierShift::factory()->create(['cashier_id' => $newCashier->id, 'shift_id' => $dest->shift_id, 'shift_date' => today(), 'status' => 'not_started']);
        $this->expectException(ValidationException::class);
        app(TransferRequestLifecycleService::class)->replaceRecipient('manager_transfer', $request->id, $manager, ['recipient_id' => $newCashier->id, 'destination_cashier_shift_id' => $newShift->id], '-80.00', 1, 'Negative request must be refused', Str::uuid());
    }

    public function test_audit_correction_rejects_aggregator_not_enabled_for_branch(): void
    {
        $f = $this->correctionFixture();
        $aggregator = Aggregator::factory()->create();
        $this->assertFalse($aggregator->enabledBranches()->where('branches.id', $f[0]->id)->exists());
        $this->expectException(ValidationException::class);
        app(ShiftReportCorrectionService::class)->correctCashierReport($f[4], $f[2], ['aggregators' => [['aggregator_id' => $aggregator->id, 'amount' => '10.00']]], 1, 'Unassigned aggregator', Str::uuid());
    }

    public function test_audit_reopen_replays_same_operation_after_lost_response(): void
    {
        $f = $this->hf();
        $op = (string) Str::uuid();
        $this->registerReopenCommand();
        $url = '/test-api/audit/shifts/'.$f[4]->id.'/reopen';
        $payload = ['expected_revision' => 1, 'reason' => 'Reopen retry'];
        $one = $this->actingAs($f[2], 'sanctum')->withHeader('Idempotency-Key', $op)->postJson($url, $payload)->assertOk();
        $two = $this->postJson($url, $payload)->assertOk();
        $this->assertSame($one->getContent(), $two->getContent());
        $this->assertSame(2, app(ShiftReportRevisionService::class)->currentCashierRevision($f[4])->revision_number);
        $this->assertSame(1, $f[4]->history()->where('action', 'report_reopened')->count());
        $this->postJson($url, ['expected_revision' => 1, 'reason' => 'Different reason'])
            ->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
    }

    public function test_audit_foreign_actor_cannot_supersede_daily_locks(): void
    {
        $f = $this->hf();
        $day = BranchManagerShift::where('branch_id', $f[0]->id)->whereDate('shift_date', today())->firstOrFail();
        ShiftLiabilityDailyLock::create(['cashier_shift_id' => $f[4]->id, 'branch_manager_shift_id' => $day->id, 'report_revision' => $f[6]->report_revision_id, 'locked_by_id' => $f[1]->id, 'locked_at' => now()]);
        $foreign = Cashier::factory()->create(['branch_id' => Branch::factory()->create()->id]);
        $this->expectException(AccessDeniedHttpException::class);
        app(DailyLiabilityGuard::class)->supersedeLocksForShift($f[4]->id, $foreign, 'Foreign actor cannot unlock');
    }

    public function test_audit_pending_scope_excludes_cancelled_replaced_request(): void
    {
        $f = $this->hf();
        app(TransferRequestLifecycleService::class)->replaceRecipient('handover', $f[6]->id, $f[2], $this->destination($f), '100.00', 1, 'Change recipient', Str::uuid());
        $this->assertSame(1, \Modules\Shift\Models\CashierShiftHandover::pending()->where('cashier_shift_id', $f[4]->id)->count());
    }

    public function test_audit_replacement_synchronizes_next_cashier_projection(): void
    {
        $f = $this->hf();
        $f[4]->update(['next_cashier_id' => $f[3]->id]);
        $destination = $this->destination($f);
        app(TransferRequestLifecycleService::class)->replaceRecipient('handover', $f[6]->id, $f[2], $destination, '100.00', 1, 'Change recipient', Str::uuid());
        $this->assertSame($destination['recipient_id'], $f[4]->fresh()->next_cashier_id, 'next_cashier_id still points to the cancelled recipient.');
    }

    public function test_audit_new_recipient_can_read_active_handover_status(): void
    {
        $f = $this->hf();
        $f[4]->update(['next_cashier_id' => $f[3]->id]);
        $d = $this->destination($f);
        app(TransferRequestLifecycleService::class)->replaceRecipient('handover', $f[6]->id, $f[2], $d, '100.00', 1, 'Change recipient', Str::uuid());
        $recipient = Cashier::findOrFail($d['recipient_id']);
        $this->actingAs($recipient, 'sanctum')->getJson('/api/cashier/shifts/'.$f[4]->id.'/handover/status')->assertOk();
    }

    public function test_audit_manager_revision_actor_fits_database_varchar_40(): void
    {
        $f = $this->correctionFixture();
        $revision = app(ShiftReportCorrectionService::class)->correctCashierReport($f[4], $f[1], ['card_payments' => '1.00'], 1, 'Manager-authorized report correction', Str::uuid());
        $this->assertLessThanOrEqual(40, strlen($revision->created_by_type), 'Manager class name exceeds shift_report_revisions.created_by_type VARCHAR(40).');
    }

    public function test_audit_supersession_preserves_other_workday_lock(): void
    {
        $f = $this->hf();
        $day = BranchManagerShift::where('branch_id', $f[0]->id)->firstOrFail();
        $other = BranchManagerShift::create(['branch_manager_id' => $f[1]->id, 'branch_id' => $f[0]->id, 'shift_date' => today()->subDay(), 'status' => 'completed']);
        $locks = [];
        foreach ([$day, $other] as $workday) {
            $locks[] = ShiftLiabilityDailyLock::create(['cashier_shift_id' => $f[4]->id, 'branch_manager_shift_id' => $workday->id, 'report_revision' => '1', 'locked_by_id' => $f[1]->id, 'locked_at' => now()]);
        }
        $changed = app(DailyLiabilityGuard::class)->supersedeLocksForShift($f[4]->id, $f[1], 'Scoped recount', $day->id);
        $this->assertSame(1, $changed);
        $this->assertNotNull($locks[0]->fresh()->superseded_at);
        $this->assertNull($locks[1]->fresh()->superseded_at);
    }

    public function test_audit_supersession_cannot_unlock_submitted_day(): void
    {
        $f = $this->hf();
        $day = BranchManagerShift::where('branch_id', $f[0]->id)->firstOrFail();
        $day->update(['daily_report_submitted' => true]);
        $lock = ShiftLiabilityDailyLock::create(['cashier_shift_id' => $f[4]->id, 'branch_manager_shift_id' => $day->id, 'report_revision' => '1', 'locked_by_id' => $f[1]->id, 'locked_at' => now()]);
        $this->rejectConflict(fn () => app(DailyLiabilityGuard::class)->supersedeLocksForShift($f[4]->id, $f[1], 'Blocked recount', $day->id), 'REPORT_REOPEN_REQUIRED');
        $this->assertNull($lock->fresh()->superseded_at);
    }

    public function test_audit_correction_history_identifies_internal_actor(): void
    {
        $f = $this->correctionFixture();
        app(ShiftReportCorrectionService::class)->correctCashierReport($f[4], $f[1], ['card_payments' => '1.00'], 1, 'Internal correction', Str::uuid());
        $history = $f[4]->history()->where('action', 'report_corrected')->firstOrFail();
        $this->assertSame($f[1]->id, $history->performed_by);
        $this->assertSame('branch_manager', $history->performed_by_type);
    }

    private function registerReopenCommand(): void
    {
        \Illuminate\Support\Facades\Route::middleware(['auth:sanctum', 'asab.idempotency:required,transaction,legacy'])
            ->post('/test-api/audit/shifts/{shiftId}/reopen', function (string $shiftId) {
                $revision = app(ShiftReportReopenService::class)->reopenCashierReport(
                    CashierShift::findOrFail($shiftId), auth()->user(), (int) request('expected_revision'),
                    (string) request('reason'), (string) request()->header('Idempotency-Key')
                );

                return response()->json(['id' => $revision->id, 'revision' => $revision->revision_number]);
            });
    }

    public function test_audit_correction_rejects_unsupported_field_mixed_with_valid_change(): void
    {
        $f = $this->correctionFixture();
        $this->expectException(ValidationException::class);
        app(ShiftReportCorrectionService::class)->correctCashierReport(
            $f[4], $f[2], ['card_payments' => '1.00', 'cashier_id' => $f[3]->id], 1, 'Unsupported field', Str::uuid()
        );
    }

    public function test_audit_correction_rejects_amount_outside_decimal_schema(): void
    {
        $f = $this->correctionFixture();
        $this->expectException(ValidationException::class);
        app(ShiftReportCorrectionService::class)->correctCashierReport(
            $f[4], $f[2], ['total_sales' => '10000000000.00'], 1, 'Outside DECIMAL 12 2', Str::uuid()
        );
    }

    public function test_audit_reopen_rejects_overlong_operation_id(): void
    {
        $f = $this->hf();
        $this->expectException(ValidationException::class);
        app(ShiftReportReopenService::class)->reopenCashierReport($f[4], $f[2], 1, 'Operation ID boundary', str_repeat('x', 101));
    }

    public function test_audit_reopen_rejects_unended_report(): void
    {
        $f = $this->hf();
        $f[4]->update(['status' => 'in_progress', 'actual_end_time' => null]);
        $this->rejectConflict(
            fn () => app(ShiftReportReopenService::class)->reopenCashierReport($f[4], $f[2], 1, 'Not an ended report', Str::uuid()),
            'REPORT_NOT_ENDED'
        );
    }

    public function test_audit_snapshot_preserves_historical_allocation_on_actual_revision_column(): void
    {
        $f = $this->correctionFixture();
        $revision = app(ShiftReportRevisionService::class)->currentCashierRevision($f[4]);
        // Even a stale legacy allocation must survive in its revision's immutable before-state.
        $allocation = \Modules\Shift\Models\ShiftLiabilityAllocation::create([
            'cashier_shift_id' => $f[4]->id, 'version' => 1, 'report_revision' => $revision->id,
            'company_id' => (string) Str::uuid(), 'branch_id' => $f[0]->id,
            'variance_halalas' => -1000, 'created_by_type' => 'cashier', 'created_by_id' => $f[2]->id,
        ]);
        app(ShiftReportCorrectionService::class)->correctCashierReport($f[4], $f[2], ['card_payments' => '1.00'], 1, 'Preserve allocation history', Str::uuid());
        $snapshot = ShiftReportRevisionSnapshot::where('report_revision_id', $revision->id)->firstOrFail();
        $this->assertSame($allocation->id, $snapshot->snapshot_data['allocations'][0]['id'] ?? null);
        $this->assertNotNull($allocation->fresh()->superseded_at);
    }

    public function test_audit_correction_accepts_enabled_branch_aggregator(): void
    {
        $f = $this->correctionFixture();
        $aggregator = Aggregator::factory()->create(['is_active' => true]);
        $aggregator->branches()->attach($f[0]->id, ['id' => (string) Str::uuid(), 'is_enabled' => true]);
        $revision = app(ShiftReportCorrectionService::class)->correctCashierReport(
            $f[4], $f[2], ['aggregators' => [['aggregator_id' => $aggregator->id, 'amount' => '10.00']]],
            1, 'Correct enabled application sales', Str::uuid()
        );
        $this->assertSame(2, $revision->revision_number);
        $count = app(ShiftCashCountService::class)->currentFor($f[4]->id);
        $this->assertSame(1000, $count->apps_halalas);
        $this->assertSame(10000, $count->counted_halalas);
    }

    public function test_audit_manager_replacement_requires_explicit_shift_if_ambiguous(): void
    {
        [$manager, , $day, $destination, $request] = $this->mf();
        $cashier = Cashier::factory()->create(['branch_id' => $day->branch_id, 'created_by' => $manager->id]);
        foreach ([1, 2] as $index) {
            $template = Shift::factory()->create(['branch_id' => $day->branch_id, 'start_time' => sprintf('%02d:00:00', 12 + $index), 'end_time' => sprintf('%02d:00:00', 20 + $index)]);
            CashierShift::factory()->create(['cashier_id' => $cashier->id, 'shift_id' => $template->id, 'shift_date' => $day->shift_date, 'status' => 'not_started']);
        }
        $this->rejectConflict(
            fn () => app(TransferRequestLifecycleService::class)->replaceRecipient('manager_transfer', $request->id, $manager, ['recipient_id' => $cashier->id], '60.00', 1, 'Ambiguous destination', Str::uuid()),
            'RECEIVING_SHIFT_ID_REQUIRED'
        );
        $this->assertNull($request->fresh()->cancelled_at);
        $this->assertSame(0, \Modules\Shift\Models\BranchManagerCashTransfer::where('supersedes_id', $request->id)->count());
    }

    public function test_audit_cancelled_rejected_predecessor_does_not_block_current_request(): void
    {
        $f = $this->hf();
        $f[6]->update(['status' => 'rejected']);
        app(ShiftCashCountService::class)->recordRejectionEvidence(
            $f[6]->id, null, 'cashier', $f[3]->id, $f[5]->id, '100.00', '0.00', 'No physical cash retained'
        );
        $replacement = app(TransferRequestLifecycleService::class)->replaceRecipient(
            'handover', $f[6]->id, $f[2], $this->destination($f), '100.00', 1, 'Replace after rejection', Str::uuid()
        );
        app(HandoverService::class)->assertNoCorrectionPending($f[4]);
        $this->assertSame($replacement->id, $f[4]->fresh()->handover->id);
        $withoutHandover = CashierShift::withoutEagerLoads()->findOrFail($f[4]->id);
        $withoutHandover->load('handoverStatus');
        $resource = (new \Modules\Shift\Transformers\CashierShiftResource($withoutHandover))->resolve();
        $this->assertSame($replacement->handover_to_id, $resource['handover_to']['id']);
        $this->assertSame(1, \Modules\Shift\Models\ShiftTransferRejectionEvidence::where('cashier_shift_handover_id', $f[6]->id)->count());
    }

    public function test_audit_new_report_never_relabels_cancelled_pending_predecessor(): void
    {
        $f = $this->correctionFixture();
        $originalRevisionId = $f[6]->report_revision_id;
        $replacement = app(TransferRequestLifecycleService::class)->replaceRecipient(
            'handover', $f[6]->id, $f[2], $this->destination($f), '100.00', 1, 'Replace pending request', Str::uuid()
        );
        $f[4]->update(['status' => 'in_progress', 'actual_end_time' => null]);
        app(\Modules\Shift\Services\ShiftEndService::class)->endShiftOnly(
            $f[4], ['total_sales' => '100.00', 'card_payments' => '0.00', 'cash_collected' => '100.00', 'counted_cash' => '100.00'], $f[2]
        );
        $this->assertSame($originalRevisionId, $f[6]->fresh()->report_revision_id);
        $this->assertNotSame($originalRevisionId, $replacement->fresh()->report_revision_id);
    }

    private function handoverFixture(string $amount): array
    {
        $branch = Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $cashier1 = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $cashier2 = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $template = Shift::factory()->create(['branch_id' => $branch->id]);

        $sourceShift = CashierShift::factory()->completed()->create([
            'cashier_id' => $cashier1->id,
            'shift_id' => $template->id,
            'shift_date' => today(),
        ]);
        $destShift = CashierShift::factory()->create([
            'cashier_id' => $cashier2->id,
            'shift_id' => $template->id,
            'shift_date' => today(),
            'status' => ShiftStatus::NOT_STARTED,
        ]);

        $revision = app(ShiftReportRevisionService::class)->recordCashierRevision($sourceShift, 'cashier', $cashier1->id, 0);
        $minor = (int) round((float) $amount * 100);
        app(ShiftCashCountService::class)->record($sourceShift, $revision, $minor, 0, 0, $minor);

        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $sourceShift->id,
            'handover_to_type' => 'cashier',
            'handover_to_id' => $cashier2->id,
            'handover_amount' => $amount,
            'status' => 'pending',
            'report_revision_id' => $revision->id,
            'handover_date' => today(),
            'handover_time' => now(),
        ]);

        ShiftHandoverStatus::create([
            'cashier_shift_id' => $sourceShift->id,
            'handover_id' => $handover->id,
            'status' => 'pending',
            'rejection_count' => 0,
        ]);

        return [$branch, $manager, $cashier1, $cashier2, $sourceShift, $destShift, $handover];
    }

    private function managerTransferFixture(string $amount): array
    {
        $branch = Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $template = Shift::factory()->create(['branch_id' => $branch->id]);

        $sourceDay = BranchManagerShift::create([
            'branch_manager_id' => $manager->id,
            'branch_id' => $branch->id,
            'shift_date' => today()->toDateString(),
            'status' => 'completed',
            'cash_collected' => '200.00',
        ]);
        PersonalLedgerTransaction::create([
            'branch_manager_id' => $manager->id,
            'transaction_type' => 'Total Sales',
            'amount' => '200.00',
            'is_cash_in' => true,
            'related_shift_id' => $sourceDay->id,
            'transaction_date' => now(),
        ]);

        $destShift = CashierShift::factory()->create([
            'cashier_id' => $cashier->id,
            'shift_id' => $template->id,
            'shift_date' => today(),
            'status' => ShiftStatus::NOT_STARTED,
        ]);

        $service = app(ShiftTransferReceiptService::class);
        $transfer = $service->requestManagerCashTransfer($sourceDay, $cashier, $destShift, $amount, $manager);

        return [$manager, $cashier, $sourceDay, $destShift, $transfer];
    }
}
