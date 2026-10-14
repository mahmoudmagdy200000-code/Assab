<?php

namespace Tests\Feature;

require_once __DIR__.'/Pkg6ApprovalStageGuardTest.php';

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\Shift as AdminShift;
use Modules\Admin\Services\OperationService;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\ShiftLiabilityDailyLock;
use Modules\Shift\Services\ShiftCashCountService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Modules\Shift\Services\ShiftReportReviewService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class Pkg6FinalityAndDayMembershipTest extends TestCase
{
    use RefreshDatabase, Pkg6ReviewFixture;

    private function dayOperation(): Operation
    {
        app(ShiftReportRevisionService::class)->recordManagerRevision($this->day, 'branch_manager', $this->day->branch_manager_id);
        $payload = ['managerShiftId' => $this->day->id];
        foreach (['total_sales' => 'totalSalesHalalas', 'cash_collected' => 'cashHalalas', 'card_payments' => 'cardHalalas',
            'aggregator_payments' => 'appsHalalas', 'variance' => 'varianceHalalas'] as $field => $key) {
            $payload[$key] = \App\Support\ShiftFinancialCalculator::storedSarToHalalas($this->day->$field ?? '0.00');
        }
        return Operation::create(['public_id' => 'SAL-'.Str::uuid(), 'company_id' => $this->operation->company_id,
            'branch_id' => $this->operation->branch_id, 'module_key' => 'sales', 'origin' => 'mobile',
            'status' => 'pending', 'payload' => $payload, 'operation_date' => now()]);
    }

    private function sibling(): Operation
    {
        $cashier = Cashier::factory()->create(['branch_id' => $this->operation->branch_id]);
        $source = CashierShift::withoutEvents(fn () => CashierShift::factory()->completed()->create([
            'cashier_id' => $cashier->id, 'shift_id' => $this->source->shift_id, 'shift_date' => today(),
            'total_sales' => '100.00', 'card_payments' => '0.00', 'cash_collected' => '100.00']));
        $admin = AdminShift::findOrFail($this->operation->payload['shiftId'])->replicate();
        $admin->legacy_shift_id = $source->id;
        $admin->save();
        $operation = $this->operation->replicate();
        $operation->public_id = 'SHF-'.strtoupper(Str::random(10));
        $payload = $operation->payload;
        $payload['shiftId'] = $admin->id;
        $payload['legacyShiftId'] = $source->id;
        $operation->payload = $payload;
        $operation->save();
        $revision = app(ShiftReportRevisionService::class)->recordCashierRevision($source, 'cashier', $cashier->id);
        $count = app(ShiftCashCountService::class)->record($source, $revision, 10000, 0, 0, 10000);
        ShiftLiabilityDailyLock::create(['cashier_shift_id' => $source->id, 'branch_manager_shift_id' => $this->day->id,
            'report_revision' => $count->evidence_revision_id, 'locked_by_id' => $this->day->branch_manager_id, 'locked_at' => now()]);
        return $operation;
    }

    public function test_returned_member_preserves_sibling_and_blocks_day_approval(): void
    {
        $sibling = $this->sibling();
        $before = $sibling->fresh()->getRawOriginal();
        $dayOperation = $this->dayOperation();
        $approved = app(OperationService::class)->approve($this->operation, $this->accountant);
        app(OperationService::class)->returnForReview($approved, $this->head, 'Individual return');
        try {
            app(OperationService::class)->approve($dayOperation, $this->accountant);
            $this->fail('Day approval ignored a returned member.');
        } catch (ConflictHttpException $error) {
            $this->assertSame('DAY_MEMBER_NOT_FINAL_CURRENT', $error->getMessage());
        }
        $this->assertSame($before, $sibling->fresh()->getRawOriginal());
        $this->assertSame('pending', $dayOperation->fresh()->status);
        $this->assertTrue($this->day->fresh()->daily_report_submitted);
        $this->assertSame(2, ShiftLiabilityDailyLock::active()->where('branch_manager_shift_id', $this->day->id)->count());
    }

    public function test_day_final_approval_requires_each_member_final_and_current(): void
    {
        $dayOperation = $this->dayOperation();
        $member = app(OperationService::class)->approve($this->operation, $this->accountant);
        $approvedDay = app(OperationService::class)->approve($dayOperation, $this->accountant);
        try {
            app(OperationService::class)->finalApprove($approvedDay, $this->head);
            $this->fail('Day finalized before member final approval.');
        } catch (ConflictHttpException $error) {
            $this->assertSame('DAY_MEMBER_NOT_FINAL_CURRENT', $error->getMessage());
        }
        app(OperationService::class)->finalApprove($member, $this->head);
        $final = app(OperationService::class)->finalApprove($approvedDay, $this->head);
        $this->assertSame('final-approved', $final->status);
        try {
            app(OperationService::class)->returnForReview($approvedDay, $this->head, 'Stale request');
            $this->fail('A final day was reopened.');
        } catch (\Modules\Admin\Exceptions\AsabException $error) {
            $this->assertSame('OP_NOT_APPROVED', $error->errorCode);
        }
        $this->assertSame('final-approved', $final->fresh()->status);
    }

    public function test_stale_member_evidence_blocks_day_final_without_partial_writes(): void
    {
        $dayOperation = $this->dayOperation();
        $member = app(OperationService::class)->approve($this->operation, $this->accountant);
        app(OperationService::class)->finalApprove($member, $this->head);
        $approved = app(OperationService::class)->approve($dayOperation, $this->accountant);
        $this->membership->update(['report_revision' => (string) Str::uuid()]);
        try {
            app(OperationService::class)->finalApprove($approved, $this->head);
            $this->fail('Stale day membership finalized.');
        } catch (ConflictHttpException $error) {
            $this->assertSame('STALE_DAY_MEMBER_EVIDENCE', $error->getMessage());
        }
        $this->assertSame('approved', $approved->fresh()->status);
        $this->assertSame(0, $approved->steps()->where('stage_id', 'final')->count());
    }

    public function test_final_report_refuses_every_mutation_guard_even_from_stale_objects(): void
    {
        $approved = app(OperationService::class)->approve($this->operation, $this->accountant);
        app(OperationService::class)->finalApprove($approved, $this->head);
        try {
            DB::transaction(function () {
                app(ShiftReportReviewService::class)->lockCashier($this->source);
                app(ShiftReportReviewService::class)->assertReportEditable($this->source);
            });
            $this->fail('Final report was editable.');
        } catch (ConflictHttpException $error) {
            $this->assertSame('REPORT_FINAL_APPROVED', $error->getMessage());
        }
        $this->assertSame('final-approved', $this->operation->fresh()->status);
        $this->assertSame('completed', $this->source->fresh()->getRawOriginal('status'));
    }
}
