<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Modules\Admin\Events\OperationRejected;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\Shift;
use Modules\Admin\Services\OperationService;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Notification\Enums\NotificationType;
use Modules\Notification\Notifications\BaseNotification;
use Modules\Shift\Events\ShiftEndedEvent;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Tests\TestCase;

/**
 * I-02 (acceptance review): PKG-6 replaced the baseline test "rejecting a bridged shift notifies the mobile cashier"
 * and kept the cashier notification only for inherited non-cycle decisions in
 * ShiftFeedbackBridgeService::syncDecision. That retained branch had no test of its own.
 *
 *  - an inherited rejection (operation rejected WITHOUT a PKG-6 return cycle) still notifies the cashier exactly once;
 *  - an official return cycle writes only the manager's durable outbox intent and never the legacy cashier
 *    notification, even if the decision is synchronised again.
 */
class Pkg6LegacyRejectionNotificationTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branch;

    private AsabUser $accountant;

    private AsabUser $head;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = AsabCompany::create(['name' => 'I02 Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'I02', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['asab_brand_id' => $brand->id, 'asab_company_id' => $this->company->id]);
        $this->accountant = AsabUser::create(['company_id' => $this->company->id, 'name' => 'Accountant', 'email' => 'acc@i02.test', 'password' => 'secret-password', 'status' => 'active']);
        AsabUserRole::create(['user_id' => $this->accountant->id, 'role_key' => 'accountant', 'scope' => 'all']);
        $this->head = AsabUser::create(['company_id' => $this->company->id, 'name' => 'Head', 'email' => 'head@i02.test', 'password' => 'secret-password', 'status' => 'active']);
        AsabUserRole::create(['user_id' => $this->head->id, 'role_key' => 'head', 'scope' => 'all']);
    }

    /** A submitted, counted legacy shift bridged into a pending SHF operation (same shape as TwoWorldFeedbackLoopTest). */
    private function bridgeLegacyShift(): array
    {
        $manager = BranchManager::where('branch_id', $this->branch->id)->first()
            ?? BranchManager::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true, 'status' => 'active']);
        $cashier = \Modules\Cashier\Models\Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $manager->id]);
        $template = \Modules\Shift\Models\Shift::factory()->create(['branch_id' => $this->branch->id]);
        $legacy = CashierShift::withoutEvents(fn () => CashierShift::factory()->completed()->create([
            'cashier_id' => $cashier->id, 'shift_id' => $template->id, 'shift_date' => today(),
            'total_sales' => '500.00', 'card_payments' => '0.00', 'cash_collected' => '500.00', 'opening_balance' => '0.00']));
        $count = DB::transaction(function () use ($legacy, $cashier) {
            $revision = app(\Modules\Shift\Services\ShiftReportRevisionService::class)->recordCashierRevision($legacy, 'cashier', $cashier->id);

            return app(\Modules\Shift\Services\ShiftCashCountService::class)->record($legacy, $revision, 50000, 0, 0, 50000);
        });
        $employee = Employee::create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'emp_number' => '2001', 'name' => 'محمد', 'role' => 'كاشير', 'status' => 'active']);
        $employee->forceFill(['legacy_cashier_id' => $legacy->cashier_id])->save();
        event(new ShiftEndedEvent($legacy, false));
        $shift = Shift::where('legacy_shift_id', $legacy->id)->firstOrFail();
        $op = Operation::where('module_key', 'shifts')->where('payload->shiftId', $shift->id)->firstOrFail();
        $day = BranchManagerShift::where('branch_id', $this->branch->id)->whereDate('shift_date', today())->first()
            ?? BranchManagerShift::create(['branch_manager_id' => $manager->id, 'branch_id' => $this->branch->id, 'shift_date' => today(), 'status' => 'completed']);
        \Modules\Shift\Models\ShiftLiabilityDailyLock::create(['cashier_shift_id' => $legacy->id, 'branch_manager_shift_id' => $day->id,
            'report_revision' => $count->evidence_revision_id, 'locked_by_id' => $manager->id, 'locked_at' => now()]);
        $day->update(['daily_report_submitted' => true, 'daily_report_submitted_at' => now()]);

        return [$legacy, $op, $cashier];
    }

    private function sentRejections($cashier): int
    {
        return Notification::sent($cashier, BaseNotification::class)
            ->filter(fn (BaseNotification $n) => $n->type === NotificationType::SHIFT_SALES_REJECTED)->count();
    }

    public function test_inherited_non_cycle_rejection_still_notifies_the_mobile_cashier_exactly_once(): void
    {
        Notification::fake();
        [$legacy, $op, $cashier] = $this->bridgeLegacyShift();
        $this->assertArrayNotHasKey('return_cycle', $op->payload['pkg6Review'] ?? [], 'fixture must be a pre-cycle (inherited) operation');

        // An inherited decision: terminal rejection recorded without a PKG-6 return cycle.
        $op->update(['status' => Operation::STATUS_REJECTED, 'reject_reason' => 'بيانات غير مكتملة',
            'rejected_by_id' => $this->accountant->id, 'rejected_at' => now()]);
        event(new OperationRejected($op->fresh(), $this->accountant, 'بيانات غير مكتملة'));

        $legacy->refresh();
        $this->assertSame('rejected', $legacy->review_status);
        $this->assertSame('بيانات غير مكتملة', $legacy->review_reason);
        $this->assertSame(1, $this->sentRejections($cashier));
        Notification::assertSentTo($cashier, BaseNotification::class,
            fn (BaseNotification $n) => $n->type === NotificationType::SHIFT_SALES_REJECTED && ($n->data['shift_id'] ?? null) === $legacy->id);

        // Re-synchronising the same decision is idempotent: no second cashier notification.
        event(new OperationRejected($op->fresh(), $this->accountant, 'بيانات غير مكتملة'));
        $this->assertSame(1, $this->sentRejections($cashier));
    }

    public function test_official_return_cycle_writes_only_the_manager_outbox_and_never_the_legacy_cashier_notification(): void
    {
        Notification::fake();
        [$legacy, $op, $cashier] = $this->bridgeLegacyShift();
        $service = app(OperationService::class);
        $approved = $service->approve($op, $this->accountant);
        $returned = $service->returnForReview($approved, $this->head, 'بيانات غير مكتملة');
        $returned = $service->reject($returned, $this->accountant, 'incomplete_data');
        $cycle = $returned->payload['pkg6Review']['return_cycle'];

        // Synchronise the same rejected decision again, as a replayed event would.
        event(new OperationRejected($returned->fresh(), $this->accountant, 'بيانات غير مكتملة'));

        $this->assertSame('rejected', $legacy->fresh()->review_status);
        $this->assertSame(0, $this->sentRejections($cashier), 'A return cycle must not notify the cashier through the legacy path.');
        Notification::assertNotSentTo($cashier, BaseNotification::class);
        $this->assertSame(1, DB::table('shift_report_correction_notifications')->where('event_type', 'report_returned:'.$cycle)->count(),
            'Exactly one durable manager outbox intent per return cycle.');
        $this->assertSame($cashier->id, $legacy->fresh()->cashier_id);
    }
}
