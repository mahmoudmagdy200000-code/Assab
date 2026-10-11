<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Employee;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftReportCashCount;
use Modules\Shift\Models\ShiftReportCorrectionNotification;
use Modules\Shift\Services\AccountantReportCorrectionService;
use Modules\Shift\Services\ShiftReportCorrectionNotificationService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ShiftReportCorrectionNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabBrand $brand;

    private Branch $branch;

    private Cashier $cashier;

    private BranchManager $manager;

    private CashierShift $cashierShift;

    private Employee $employeeMapped;

    private Employee $employeeUnmapped;

    private AsabUser $employeeUser;

    private AsabUser $accountant;

    private ShiftReportRevisionService $revisionService;

    private AccountantReportCorrectionService $correctionService;

    private ShiftReportCorrectionNotificationService $notificationService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'شركة الإشعارات',
            'plan' => 'Professional',
            'status' => 'active',
        ]);

        $this->brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'علامة الإشعارات',
            'abbr' => 'NOTIF',
            'sub_status' => 'active',
            'status' => 'active',
        ]);

        $this->branch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
        ]);

        $template = Shift::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier = Cashier::factory()->create(['branch_id' => $this->branch->id]);
        $this->manager = BranchManager::where('branch_id', $this->branch->id)->first()
            ?? BranchManager::factory()->create(['branch_id' => $this->branch->id]);

        $this->employeeUser = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'مستخدم موظف',
            'email' => 'empuser@test.com',
            'password' => 'secret',
            'status' => 'active',
        ]);

        $this->employeeMapped = Employee::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'asab_user_id' => $this->employeeUser->id,
            'name' => 'موظف مربوط بمستخدم',
            'emp_number' => 'EMP-MAP',
            'role' => 'staff',
            'status' => 'active',
        ]);

        $this->employeeUnmapped = Employee::create([
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'asab_user_id' => null,
            'name' => 'موظف غير مربوط',
            'emp_number' => 'EMP-UNMAP',
            'role' => 'staff',
            'status' => 'active',
        ]);

        $this->accountant = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'المحاسب',
            'email' => 'accountant.notif@test.com',
            'password' => 'secret',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $this->accountant->id,
            'role_key' => 'accountant',
            'scope' => 'all',
        ]);

        $this->cashierShift = CashierShift::factory()->create([
            'shift_id' => $template->id,
            'cashier_id' => $this->cashier->id,
            'total_sales' => '100.00',
            'net_sales' => '86.96',
            'vat_amount' => '13.04',
            'card_payments' => '40.00',
            'cash_collected' => '60.00',
            'closing_balance' => '60.00',
            'variance' => '0.00',
            'status' => 'in_progress',
        ]);

        $this->revisionService = app(ShiftReportRevisionService::class);
        $this->correctionService = app(AccountantReportCorrectionService::class);
        $this->notificationService = app(ShiftReportCorrectionNotificationService::class);

        $rev1 = $this->revisionService->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id);
        ShiftReportCashCount::create([
            'report_revision_id' => $rev1->id,
            'counted_revision_id' => $rev1->id,
            'evidence_revision_id' => $rev1->id,
            'cashier_shift_id' => $this->cashierShift->id,
            'gross_halalas' => 10000,
            'cards_halalas' => 4000,
            'apps_halalas' => 0,
            'confirmed_opening_halalas' => 0,
            'pending_incoming_counted_halalas' => 0,
            'counted_halalas' => 6000,
            'expected_halalas' => 6000,
            'variance_halalas' => 0,
        ]);
    }

    public function test_correction_enqueues_outbox_intent_for_cashier_and_shareholders(): void
    {
        Queue::fake([\Modules\Shift\Jobs\DispatchShiftReportCorrectionNotificationsJob::class]);

        $shares = [
            ['type' => 'cashier', 'id' => $this->cashier->id, 'amount' => 600],
            ['type' => 'employee', 'id' => $this->employeeMapped->id, 'amount' => 400],
        ];

        $rev2 = $this->correctionService->correct(
            $this->cashierShift,
            $this->accountant,
            changes: ['card_payments' => '30.00'],
            shares: $shares,
            expectedRevision: 1,
            expectedAllocationVersion: null,
            reason: 'Correction creates outbox notifications',
            operationId: (string) Str::uuid()
        );

        $notifications = ShiftReportCorrectionNotification::where('report_revision_id', $rev2->id)->get();

        // 1 for cashier report_corrected + 1 for cashier share (old=0, new=600) + 1 for employee share (old=0, new=400)
        $this->assertCount(3, $notifications);

        $cashierReportNotif = $notifications->firstWhere('event_type', 'report_corrected');
        $this->assertNotNull($cashierReportNotif);
        $this->assertSame('cashier', $cashierReportNotif->recipient_type);
        $this->assertSame((string) $this->cashier->id, $cashierReportNotif->recipient_id);
        $this->assertSame('pending', $cashierReportNotif->status);

        $cashierShareNotif = $notifications->where('event_type', 'liability_share_changed')
            ->firstWhere('recipient_type', 'cashier');
        $this->assertNotNull($cashierShareNotif);
        $this->assertSame(0, $cashierShareNotif->payload['old_amount_halalas']);
        $this->assertSame(600, $cashierShareNotif->payload['new_amount_halalas']);

        $empShareNotif = $notifications->where('event_type', 'liability_share_changed')
            ->firstWhere('recipient_type', 'employee');
        $this->assertNotNull($empShareNotif);
        $this->assertSame(0, $empShareNotif->payload['old_amount_halalas']);
        $this->assertSame(400, $empShareNotif->payload['new_amount_halalas']);
    }

    public function test_unchanged_shareholder_receives_no_notification(): void
    {
        // 1. Initial allocation with variance -1000h: Cashier=500h, EmployeeMapped=500h
        $rev2 = $this->correctionService->correct(
            $this->cashierShift,
            $this->accountant,
            changes: ['card_payments' => '30.00'],
            shares: [
                ['type' => 'cashier', 'id' => $this->cashier->id, 'amount' => 500],
                ['type' => 'employee', 'id' => $this->employeeMapped->id, 'amount' => 500],
            ],
            expectedRevision: 1,
            expectedAllocationVersion: null,
            reason: 'First allocation',
            operationId: (string) Str::uuid()
        );

        // 2. Second revision: Reallocate so Cashier STAYS 500h, EmployeeMapped becomes 0h, EmployeeUnmapped becomes 500h
        $rev3 = $this->correctionService->correct(
            $this->cashierShift,
            $this->accountant,
            changes: [],
            shares: [
                ['type' => 'cashier', 'id' => $this->cashier->id, 'amount' => 500],
                ['type' => 'employee', 'id' => $this->employeeUnmapped->id, 'amount' => 500],
            ],
            expectedRevision: 2,
            expectedAllocationVersion: 1,
            reason: 'Reallocating from mapped to unmapped employee',
            operationId: (string) Str::uuid()
        );

        $rev3Notifs = ShiftReportCorrectionNotification::where('report_revision_id', $rev3->id)->get();

        // Cashier share did NOT change (500 -> 500): must NOT have a liability_share_changed notification for cashier!
        $cashierShareNotif = $rev3Notifs->where('event_type', 'liability_share_changed')
            ->firstWhere('recipient_type', 'cashier');
        $this->assertNull($cashierShareNotif);

        // EmployeeMapped share changed from 500 to 0: must receive notification
        $mappedNotif = $rev3Notifs->where('event_type', 'liability_share_changed')
            ->firstWhere('recipient_id', $this->employeeMapped->id);
        $this->assertNotNull($mappedNotif);
        $this->assertSame(500, $mappedNotif->payload['old_amount_halalas']);
        $this->assertSame(0, $mappedNotif->payload['new_amount_halalas']);

        // EmployeeUnmapped share changed from 0 to 500: must receive notification
        $unmappedNotif = $rev3Notifs->where('event_type', 'liability_share_changed')
            ->firstWhere('recipient_id', $this->employeeUnmapped->id);
        $this->assertNotNull($unmappedNotif);
        $this->assertSame(0, $unmappedNotif->payload['old_amount_halalas']);
        $this->assertSame(500, $unmappedNotif->payload['new_amount_halalas']);
    }

    public function test_missing_employee_user_mapping_marks_delivery_blocked(): void
    {
        Queue::fake([\Modules\Shift\Jobs\DispatchShiftReportCorrectionNotificationsJob::class]);

        $rev2 = $this->correctionService->correct(
            $this->cashierShift,
            $this->accountant,
            changes: ['card_payments' => '30.00'],
            shares: [
                ['type' => 'employee', 'id' => $this->employeeUnmapped->id, 'amount' => 1000],
            ],
            expectedRevision: 1,
            expectedAllocationVersion: null,
            reason: 'Allocated to employee without asab_user_id',
            operationId: (string) Str::uuid()
        );

        $notif = ShiftReportCorrectionNotification::where('report_revision_id', $rev2->id)
            ->where('recipient_type', 'employee')
            ->firstOrFail();

        $this->assertSame('pending', $notif->status);

        // Dispatch
        $this->notificationService->dispatchPending($rev2->id);

        $notif->refresh();
        $this->assertSame('blocked', $notif->status);
        $this->assertSame('IDENTITY_MAPPING_MISSING', $notif->last_error);
    }

    public function test_dispatch_pending_delivers_to_inbox_with_deterministic_event_id_and_idempotent_retry(): void
    {
        Queue::fake([\Modules\Shift\Jobs\DispatchShiftReportCorrectionNotificationsJob::class]);

        $rev2 = $this->correctionService->correct(
            $this->cashierShift,
            $this->accountant,
            changes: ['card_payments' => '30.00'],
            shares: [
                ['type' => 'cashier', 'id' => $this->cashier->id, 'amount' => 500],
                ['type' => 'employee', 'id' => $this->employeeMapped->id, 'amount' => 500],
            ],
            expectedRevision: 1,
            expectedAllocationVersion: null,
            reason: 'Dispatching notifications',
            operationId: (string) Str::uuid()
        );

        $dispatched = $this->notificationService->dispatchPending($rev2->id);
        $this->assertSame(3, $dispatched);

        // Check notifications table
        $inboxEntries = DB::table('notifications')->count();
        $this->assertSame(3, $inboxEntries);

        // Retry dispatchPending: must NOT duplicate records in notifications
        $dispatchedAgain = $this->notificationService->dispatchPending($rev2->id);
        $this->assertSame(0, $dispatchedAgain);
        $this->assertSame(3, DB::table('notifications')->count());
    }

    public function test_rollback_leaves_no_outbox_notifications(): void
    {
        try {
            $this->correctionService->correct(
                $this->cashierShift,
                $this->accountant,
                changes: ['card_payments' => '30.00'],
                shares: [['type' => 'cashier', 'id' => $this->cashier->id, 'amount' => 1000]],
                expectedRevision: 99, // Stale!
                expectedAllocationVersion: null,
                reason: 'Will fail and rollback',
                operationId: (string) Str::uuid()
            );
            $this->fail('Expected ConflictHttpException');
        } catch (ConflictHttpException $e) {
            $this->assertSame('STALE_REPORT_REVISION', $e->getMessage());
        }

        $this->assertSame(0, ShiftReportCorrectionNotification::count());
    }

    public function test_artisan_dispatch_command_dispatches_pending(): void
    {
        Queue::fake([\Modules\Shift\Jobs\DispatchShiftReportCorrectionNotificationsJob::class]);

        $rev2 = $this->correctionService->correct(
            $this->cashierShift,
            $this->accountant,
            changes: ['card_payments' => '30.00'],
            shares: [
                ['type' => 'cashier', 'id' => $this->cashier->id, 'amount' => 1000],
            ],
            expectedRevision: 1,
            expectedAllocationVersion: null,
            reason: 'Artisan test',
            operationId: (string) Str::uuid()
        );

        $exitCode = Artisan::call('shift:dispatch-correction-notifications', ['--revision' => $rev2->id]);
        $this->assertSame(0, $exitCode);

        $pending = ShiftReportCorrectionNotification::where('report_revision_id', $rev2->id)
            ->where('status', 'pending')
            ->count();
        $this->assertSame(0, $pending);
    }
}
