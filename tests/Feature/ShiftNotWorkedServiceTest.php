<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHistory;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftReportAggregate;
use Modules\Shift\Services\ShiftDailyCloseScopeService;
use Modules\Shift\Services\ShiftNotWorkedService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ShiftNotWorkedServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        if (getenv('DB_CONNECTION') !== 'sqlite' || getenv('DB_DATABASE') !== ':memory:') {
            throw new \LogicException('PKG-7 functional tests require explicit disposable SQLite :memory:.');
        }
        parent::setUp();
        $this->travelTo(today()->setTime(15, 0));
    }

    protected function fixture(): array
    {
        $branch = Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $slot = Shift::factory()->create(['branch_id' => $branch->id, 'start_time' => '08:00:00', 'end_time' => '14:00:00']);
        $shift = CashierShift::factory()->create(['cashier_id' => $cashier->id, 'shift_id' => $slot->id, 'shift_date' => today()]);

        return [$branch, $manager, $cashier, $shift];
    }

    public static function orphanedReassignmentFields(): array
    {
        return array_map(fn ($field) => [$field], ['original_cashier_id', 'reassigned_by', 'reassigned_at', 'reassignment_reason']);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('orphanedReassignmentFields')]
    public function test_orphaned_reassignment_field_fails_closed(string $field): void
    {
        [, $manager, $cashier, $shift] = $this->fixture();
        $value = match ($field) {
            'original_cashier_id' => $cashier->id,
            'reassigned_by' => $manager->id,
            'reassigned_at' => now()->toDateTimeString(),
            default => 'Administrative replacement',
        };
        DB::table('cashier_shifts')->where('id', $shift->id)->update([$field => $value]);
        $before = $shift->fresh()->getRawOriginal();
        try {
            app(ShiftNotWorkedService::class)->mark($shift, $manager, 'Closed');
            $this->fail('Expected ambiguity');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('SHIFT_NOT_WORKED_REASSIGNMENT_AMBIGUOUS', $exception->getMessage());
        }
        $this->assertSame($before, $shift->fresh()->getRawOriginal());
        $this->assertSame(0, $shift->notWorkedHistory()->count());
        $this->assertSame(0, DB::table('shift_report_aggregates')->count());
    }

    public function test_reassignment_history_without_matching_fields_is_preserved_and_rejected(): void
    {
        [, $manager, $cashier, $shift] = $this->fixture();
        $fact = $shift->history()->create(['action' => 'reassigned', 'performed_by' => $manager->id,
            'performed_by_type' => 'branch_manager', 'created_at' => now(),
            'old_value' => ['cashier_id' => $cashier->id], 'new_value' => ['cashier_id' => $cashier->id, 'reason' => null]]);
        $before = $fact->fresh()->getRawOriginal();
        try {
            app(ShiftNotWorkedService::class)->mark($shift, $manager, 'Closed');
            $this->fail('Expected ambiguity');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('SHIFT_NOT_WORKED_REASSIGNMENT_AMBIGUOUS', $exception->getMessage());
        }
        $this->assertSame($before, $fact->fresh()->getRawOriginal());
        $this->assertSame(ShiftStatus::NOT_STARTED, $shift->fresh()->status);
        $this->assertSame(0, $shift->notWorkedHistory()->count());
    }

    public function test_mark_preserves_fields_and_creates_only_an_explicit_audit_fact(): void
    {
        [, $manager, , $shift] = $this->fixture();
        $before = $shift->fresh()->getRawOriginal();
        $tables = ['branch_manager_shifts', 'cashier_custody_transactions', 'personal_ledger_transactions',
            'shift_report_aggregates', 'shift_report_revisions', 'shift_report_cash_counts',
            'cashier_shift_handovers', 'cashier_shift_handover_receipts', 'shift_liability_allocations', 'asab_operations'];
        $counts = [];
        foreach ($tables as $table) {
            $counts[$table] = DB::table($table)->count();
        }
        $result = app(ShiftNotWorkedService::class)->mark($shift, $manager, '  المطعم مغلق  ');
        $this->assertSame(ShiftStatus::CANCELED, $result->status);
        $after = $result->getRawOriginal();
        unset($before['status'], $before['updated_at'], $after['status'], $after['updated_at']);
        $this->assertSame($before, $after);
        $fact = $result->notWorkedHistory()->sole();
        $this->assertSame('المطعم مغلق', $fact->notes);
        $this->assertSame((string) $manager->id, (string) $fact->performed_by);
        $this->assertSame('branch_manager', $fact->performed_by_type);
        $this->assertNotNull($fact->created_at);
        $this->assertSame($fact->created_at->toIso8601String(), $fact->new_value['recorded_at']);
        foreach ($counts as $table => $count) {
            $this->assertSame($count, DB::table($table)->count(), $table);
        }
    }

    public function test_daily_scope_excludes_only_explicitly_marked_records(): void
    {
        [$branch, $manager, , $shift] = $this->fixture();
        $day = BranchManagerShift::firstOrCreate(['branch_manager_id' => $manager->id, 'shift_date' => today()], ['branch_id' => $branch->id]);
        app(ShiftNotWorkedService::class)->mark($shift, $manager, 'Closed');
        $otherCashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $ordinary = CashierShift::factory()->create(['cashier_id' => $otherCashier->id, 'shift_id' => $shift->shift_id, 'shift_date' => today(), 'status' => ShiftStatus::CANCELED]);
        $scope = app(ShiftDailyCloseScopeService::class)->resolve($day);
        $this->assertNotContains($shift->id, $scope['cashier_shift_ids']);
        $this->assertContains($ordinary->id, $scope['cashier_shift_ids']);
        $summary = app(\Modules\Shift\Services\ShiftFinancialService::class)->prepareDailyCloseSummary($day);
        $this->assertNotContains($shift->id, array_column($summary['cashier_breakdown'], 'cashier_shift_id'));
    }

    public function test_contradictory_financial_evidence_fails_closed_in_daily_scope(): void
    {
        [$branch, $manager, , $shift] = $this->fixture();
        $day = BranchManagerShift::firstOrCreate(['branch_manager_id' => $manager->id, 'shift_date' => today()], ['branch_id' => $branch->id]);
        app(ShiftNotWorkedService::class)->mark($shift, $manager, 'Closed');
        // Corruption fixture bypasses domain APIs, never normalizes the inconsistency away.
        ShiftReportAggregate::create(['source_type' => 'cashier_shift', 'source_id' => $shift->id, 'current_revision_number' => 0]);
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('SHIFT_NOT_WORKED_EVIDENCE_CONFLICT');
        app(ShiftDailyCloseScopeService::class)->resolve($day);
    }

    public static function evidenceFields(): array
    {
        $cases = [];
        foreach (['opening_balance', 'closing_balance', 'expected_balance', 'variance', 'total_sales',
            'net_sales', 'vat_amount', 'cash_collected', 'card_payments'] as $field) {
            $cases[$field] = [$field, '0.01'];
        }
        foreach (['actual_start_time', 'actual_end_time', 'operational_ended_at', 'handed_over_at'] as $field) {
            $cases[$field] = [$field, '2026-10-13 01:00:00'];
        }
        $cases['operational_chain_id'] = ['operational_chain_id', '12345678-1234-4234-8234-123456789abc'];
        $cases['pos_receipt'] = ['pos_receipt', 'existing.pdf'];
        $cases['review_status'] = ['review_status', 'pending_review'];
        $cases['reviewed_at'] = ['reviewed_at', '2026-10-13 01:00:00'];
        $cases['review_reason'] = ['review_reason', 'existing review'];
        $cases['handover_notes'] = ['handover_notes', 'existing handover'];

        return $cases;
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('evidenceFields')]
    public function test_each_operational_or_monetary_field_rejects_without_mutation(string $field, string $value): void
    {
        [, $manager, , $shift] = $this->fixture();
        DB::table('cashier_shifts')->where('id', $shift->id)->update([$field => $value]);
        $before = $shift->fresh()->getRawOriginal();
        try {
            app(ShiftNotWorkedService::class)->mark($shift, $manager, 'Closed');
            $this->fail('Expected rejection');
        } catch (ConflictHttpException $e) {
            $this->assertSame('SHIFT_NOT_WORKED_HAS_EVIDENCE', $e->getMessage());
        }
        $this->assertSame($before, $shift->fresh()->getRawOriginal());
        $this->assertSame(0, $shift->notWorkedHistory()->count());
    }

    public function test_pending_incoming_manager_transfer_blocks_no_work(): void
    {
        [$branch, $manager, $cashier, $shift] = $this->fixture();
        $day = BranchManagerShift::firstOrCreate(['branch_manager_id' => $manager->id, 'shift_date' => today()], ['branch_id' => $branch->id]);
        $request = \Modules\Shift\Models\BranchManagerCashTransfer::create([
            'branch_manager_shift_id' => $day->id, 'destination_cashier_id' => $cashier->id,
            'destination_cashier_shift_id' => $shift->id, 'created_by_id' => $manager->id,
            'requested_amount' => '10.00', 'status' => 'pending',
        ]);
        try {
            app(ShiftNotWorkedService::class)->mark($shift, $manager, 'Closed');
            $this->fail('Expected rejection');
        } catch (ConflictHttpException $e) {
            $this->assertSame('SHIFT_NOT_WORKED_HAS_EVIDENCE', $e->getMessage());
        }
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame(ShiftStatus::NOT_STARTED, $shift->fresh()->status);
    }

    public function test_new_manager_transfer_cannot_target_marked_assignment(): void
    {
        [$branch, $manager, $cashier, $shift] = $this->fixture();
        $day = BranchManagerShift::firstOrCreate(['branch_manager_id' => $manager->id, 'shift_date' => today()], ['branch_id' => $branch->id]);
        app(ShiftNotWorkedService::class)->mark($shift, $manager, 'Closed');
        try {
            app(\Modules\Shift\Services\ShiftTransferReceiptService::class)->requestManagerCashTransfer($day, $cashier, $shift, '10.00', $manager);
            $this->fail('Expected unavailable receiving shift');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('RECEIVING_SHIFT_NOT_AVAILABLE', $exception->getMessage());
        }
        $this->assertSame(0, DB::table('branch_manager_cash_transfers')->count());
        $this->assertSame(0, DB::table('cashier_shift_handover_receipts')->count());
        $this->assertSame(ShiftStatus::CANCELED, $shift->fresh()->status);
    }

    public function test_daily_evidence_does_not_require_a_fake_revision_or_count_for_no_work(): void
    {
        [$branch, $manager, , $shift] = $this->fixture();
        $company = \Modules\Admin\Models\AsabCompany::create(['name' => 'PKG7', 'plan' => 'Professional', 'status' => 'active']);
        $branch->update(['asab_company_id' => $company->id]);
        $day = BranchManagerShift::firstOrCreate(['branch_manager_id' => $manager->id, 'shift_date' => today()], ['branch_id' => $branch->id]);
        app(ShiftNotWorkedService::class)->mark($shift, $manager, 'Closed');
        $evidence = app(\Modules\Shift\Liability\CashCountLiabilityEvidence::class)->dailyClose($day->id);
        $this->assertSame([], $evidence->reports);
        $this->assertSame(0, DB::table('shift_report_revisions')->count());
        $this->assertSame(0, DB::table('shift_report_cash_counts')->count());
    }

    public function test_report_mutation_guard_rejects_marked_rows(): void
    {
        [, $manager, , $shift] = $this->fixture();
        app(ShiftNotWorkedService::class)->mark($shift, $manager, 'Closed');
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('SHIFT_MARKED_NOT_WORKED');
        DB::transaction(fn () => app(\Modules\Shift\Services\ShiftReportMutationGuard::class)->lockEditable($shift, $manager));
    }

    public function test_marked_row_with_corrupted_pending_status_is_not_silently_omitted(): void
    {
        [$branch, $manager, , $shift] = $this->fixture();
        $day = BranchManagerShift::firstOrCreate(['branch_manager_id' => $manager->id, 'shift_date' => today()], ['branch_id' => $branch->id]);
        app(ShiftNotWorkedService::class)->mark($shift, $manager, 'Closed');
        DB::table('cashier_shifts')->where('id', $shift->id)->update(['status' => 'not_started']);
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('SHIFT_NOT_WORKED_EVIDENCE_CONFLICT');
        app(ShiftDailyCloseScopeService::class)->resolve($day);
    }

    public function test_report_evidence_blocks_mark_without_losing_history(): void
    {
        [, $manager, , $shift] = $this->fixture();
        $aggregate = ShiftReportAggregate::create(['source_type' => 'cashier_shift', 'source_id' => $shift->id, 'current_revision_number' => 0]);
        try {
            app(ShiftNotWorkedService::class)->mark($shift, $manager, 'Closed');
            $this->fail('Expected evidence rejection');
        } catch (ConflictHttpException $e) {
            $this->assertSame('SHIFT_NOT_WORKED_HAS_EVIDENCE', $e->getMessage());
        }
        $this->assertSame(ShiftStatus::NOT_STARTED, $shift->fresh()->status);
        $this->assertTrue($aggregate->fresh()->exists);
        $this->assertSame(0, $shift->notWorkedHistory()->count());
    }

    public function test_started_shift_uses_normal_lifecycle_and_cannot_be_marked(): void
    {
        [, $manager, , $shift] = $this->fixture();
        $shift->startShift();
        try {
            app(ShiftNotWorkedService::class)->mark($shift->fresh(), $manager, 'Partial work');
            $this->fail('Expected rejection');
        } catch (ConflictHttpException $e) {
            $this->assertSame('SHIFT_NOT_WORKED_NOT_ELIGIBLE', $e->getMessage());
        }
        $this->assertSame(ShiftStatus::IN_PROGRESS, $shift->fresh()->status);
        $this->assertNotNull($shift->fresh()->actual_start_time);
    }

    public function test_audit_failure_rolls_back_the_status_change(): void
    {
        [, $manager, , $shift] = $this->fixture();
        CashierShiftHistory::creating(static function ($fact): void {
            if ($fact->action === 'marked_not_worked') {
                throw new \RuntimeException('audit failure');
            }
        });
        try {
            app(ShiftNotWorkedService::class)->mark($shift, $manager, 'Closed');
            $this->fail('Expected rollback');
        } catch (\RuntimeException $e) {
            $this->assertSame('audit failure', $e->getMessage());
        }
        $this->assertSame(ShiftStatus::NOT_STARTED, $shift->fresh()->status);
        $this->assertSame(0, $shift->notWorkedHistory()->count());
    }

    public function test_nonzero_previous_account_balance_is_preserved(): void
    {
        [, $manager, $cashier, $shift] = $this->fixture();
        $ledger = \Modules\Custody\Models\CashierCustodyTransaction::create(['cashier_id' => $cashier->id,
            'transaction_type' => 'Total Sales', 'amount' => '300.00', 'is_cash_in' => true,
            'related_shift_id' => null, 'transaction_date' => now()]);
        $before = $ledger->fresh()->getRawOriginal();
        app(ShiftNotWorkedService::class)->mark($shift, $manager, 'Closed');
        $this->assertSame($before, $ledger->fresh()->getRawOriginal());
        $this->assertSame(1, DB::table('cashier_custody_transactions')->count());
    }
}
