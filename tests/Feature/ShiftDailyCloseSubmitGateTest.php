<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftLiabilityDailyLock;
use Modules\Shift\Services\ShiftCashCountService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Tests\TestCase;

class ShiftDailyCloseSubmitGateTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branch;

    private BranchManager $manager;

    private Cashier $cashier;

    private Shift $template;

    private BranchManagerShift $workday;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'Submit Gate Company',
            'plan' => 'Professional',
            'status' => 'active',
        ]);
        $brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'Submit Gate Brand',
            'status' => 'active',
        ]);
        $this->branch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $brand->id,
        ]);
        $this->manager = BranchManager::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
            'status' => 'active',
        ]);
        $this->cashier = Cashier::factory()->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
        ]);
        $this->template = Shift::factory()->create(['branch_id' => $this->branch->id]);
        $this->workday = BranchManagerShift::create([
            'branch_manager_id' => $this->manager->id,
            'branch_id' => $this->branch->id,
            'shift_date' => today(),
            'status' => 'completed',
        ]);
    }

    public function test_f09_real_start_counted_end_approval_posting_and_daily_replay(): void
    {
        $source = CashierShift::create(['cashier_id' => $this->cashier->id, 'shift_id' => $this->template->id,
            'shift_date' => today(), 'status' => 'not_started']);
        app(\Modules\Shift\Services\CashierShiftStartService::class)->startShift($source);
        $this->actingAs($this->cashier, 'sanctum')->withHeader('Idempotency-Key', (string) \Illuminate\Support\Str::uuid())
            ->postJson('/api/v1/cashier/shifts/'.$source->id.'/end', [
                'total_sales' => '100.00', 'cash_collected' => '100.00', 'card_payments' => '0.00', 'counted_cash' => '0.00',
                'shortage_allocations' => [['responsible_type' => 'cashier', 'responsible_id' => $this->cashier->id, 'amount' => '100.00']],
            ])->assertOk();
        $this->assertDatabaseHas('shift_liability_allocations', ['cashier_shift_id' => $source->id, 'variance_halalas' => -10000]);
        $key = (string) \Illuminate\Support\Str::uuid();
        $uri = '/api/v1/branch-manager/shifts/'.$source->id.'/liability/approve';
        $body = ['version' => 1, 'expected_revision' => 1];
        $approved = $this->actingAs($this->manager, 'sanctum')->withHeader('Idempotency-Key', $key)->postJson($uri, $body);
        $approved->assertOk();
        $this->postJson($uri, $body)->assertOk()->assertContent($approved->getContent());
        $this->assertSame(1, \Modules\Shift\Models\ShiftLiabilityPosting::where('cashier_shift_id', $source->id)->count());
        app(ShiftReportRevisionService::class)->recordManagerRevision($this->workday, 'branch_manager', $this->manager->id, 0);
        $key = (string) \Illuminate\Support\Str::uuid();
        $body = ['branch_manager_shift_id' => $this->workday->id, 'expected_revision' => 1];
        $uri = '/api/v1/branch-manager/workday/daily-close/submit';
        $first = $this->withHeader('Idempotency-Key', $key)->postJson($uri, $body);
        $first->assertOk();
        $this->postJson($uri, $body)->assertOk()->assertContent($first->getContent());
        $this->assertSame(1, ShiftLiabilityDailyLock::where('branch_manager_shift_id', $this->workday->id)->count());
        $this->assertTrue((bool) $this->workday->fresh()->daily_report_submitted);
    }

    public function test_f01_bridge_failure_rolls_back_day_and_allows_safe_retry(): void
    {
        app(ShiftReportRevisionService::class)->recordManagerRevision($this->workday, 'branch_manager', $this->manager->id, 0);
        $armed = true;
        \Illuminate\Support\Facades\Event::listen(\Modules\Shift\Events\DailyReportSubmittedEvent::class, function () use (&$armed) {
            if ($armed) {
                throw new \RuntimeException('Injected bridge failure');
            }
        });
        $payload = ['branch_manager_shift_id' => $this->workday->id, 'expected_revision' => 1];
        $key = (string) \Illuminate\Support\Str::uuid();
        $this->actingAs($this->manager, 'sanctum')->postJson('/api/v1/branch-manager/workday/daily-close/submit', $payload, ['Idempotency-Key' => $key])
            ->assertStatus(500);
        $this->assertFalse((bool) $this->workday->fresh()->daily_report_submitted);
        $this->assertNull($this->workday->fresh()->daily_report_submitted_at);
        $this->assertSame(0, ShiftLiabilityDailyLock::count());
        $armed = false;
        $this->actingAs($this->manager, 'sanctum')->postJson('/api/v1/branch-manager/workday/daily-close/submit', $payload, ['Idempotency-Key' => $key])
            ->assertOk();
    }

    public function test_f02_required_completed_report_without_revision_or_count_is_not_omitted(): void
    {
        $shift = CashierShift::create([
            'cashier_id' => $this->cashier->id, 'shift_id' => $this->template->id,
            'shift_date' => today(), 'status' => ShiftStatus::COMPLETED,
        ]);
        app(ShiftReportRevisionService::class)->recordManagerRevision($this->workday, 'branch_manager', $this->manager->id, 0);
        $this->actingAs($this->manager, 'sanctum')->postJson('/api/v1/branch-manager/workday/daily-close/submit', [
            'branch_manager_shift_id' => $this->workday->id, 'expected_revision' => 1,
        ], ['Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()])
            ->assertStatus(409)->assertJsonPath('code', 'LIABILITY_EVIDENCE_UNAVAILABLE');
        $this->assertFalse((bool) $this->workday->fresh()->daily_report_submitted);
        $this->assertSame(0, ShiftLiabilityDailyLock::count());
    }

    public function test_f01_missing_key_revision_and_missing_company_mapping_fail_closed(): void
    {
        app(ShiftReportRevisionService::class)->recordManagerRevision($this->workday, 'branch_manager', $this->manager->id, 0);
        $payload = ['branch_manager_shift_id' => $this->workday->id, 'expected_revision' => 1];
        $this->actingAs($this->manager, 'sanctum')->postJson('/api/v1/branch-manager/workday/daily-close/submit', $payload)
            ->assertStatus(422);
        $this->postJson('/api/v1/branch-manager/workday/daily-close/submit', ['branch_manager_shift_id' => $this->workday->id],
            ['Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()])->assertStatus(422);
        $this->branch->update(['asab_company_id' => null]);
        $this->postJson('/api/v1/branch-manager/workday/daily-close/submit', $payload,
            ['Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()])->assertStatus(409)
            ->assertJsonPath('code', 'LIABILITY_COMPANY_MAPPING_REQUIRED');
        $this->assertFalse((bool) $this->workday->fresh()->daily_report_submitted);
    }

    public function test_submit_fails_when_required_transfer_receipt_is_pending(): void
    {
        $shift = CashierShift::create([
            'cashier_id' => $this->cashier->id,
            'shift_id' => $this->template->id,
            'shift_date' => today(),
            'status' => ShiftStatus::COMPLETED,
        ]);
        $rev = app(ShiftReportRevisionService::class)->recordCashierRevision($shift, 'cashier', $this->cashier->id, 0);
        app(ShiftCashCountService::class)->record($shift, $rev, 10000, 0, 0, 10000);

        // Handover to manager is approved, but has NO confirmed receipt
        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $shift->id,
            'handover_to_type' => 'branch_manager',
            'handover_to_id' => $this->manager->id,
            'handover_amount' => '100.00',
            'variance_amount' => '0.00',
            'handover_date' => today(),
            'handover_time' => now(),
            'status' => 'approved',
            'daily_closed_at' => null,
            'report_revision_id' => $rev->id,
        ]);

        app(ShiftReportRevisionService::class)->recordManagerRevision($this->workday, 'branch_manager', $this->manager->id, 0);

        $response = $this->actingAs($this->manager, 'sanctum')->postJson(
            '/api/v1/branch-manager/workday/daily-close/submit',
            [
                'branch_manager_shift_id' => $this->workday->id,
                'expected_revision' => 1,
            ],
            ['Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()]
        );

        $response->assertStatus(409)
            ->assertJsonPath('code', 'REQUIRED_TRANSFER_RECEIPT_PENDING');

        $this->assertFalse((bool) $this->workday->fresh()->daily_report_submitted);
        $this->assertNull($handover->fresh()->daily_closed_at);
    }

    public function test_submit_fails_when_stale_expected_revision_passed(): void
    {
        app(ShiftReportRevisionService::class)->recordManagerRevision($this->workday, 'branch_manager', $this->manager->id, 0);
        app(ShiftReportRevisionService::class)->recordManagerRevision($this->workday, 'branch_manager', $this->manager->id, 1);

        $response = $this->actingAs($this->manager, 'sanctum')->postJson(
            '/api/v1/branch-manager/workday/daily-close/submit',
            [
                'branch_manager_shift_id' => $this->workday->id,
                'expected_revision' => 1,
            ],
            ['Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()]
        );

        $response->assertStatus(409)
            ->assertJsonPath('code', 'STALE_REPORT_REVISION');

        $this->assertFalse((bool) $this->workday->fresh()->daily_report_submitted);
    }

    public function test_submit_succeeds_with_explicit_branch_manager_shift_id_and_locks_liability(): void
    {
        $shift = CashierShift::create([
            'cashier_id' => $this->cashier->id,
            'shift_id' => $this->template->id,
            'shift_date' => today(),
            'status' => ShiftStatus::COMPLETED,
        ]);
        $rev = app(ShiftReportRevisionService::class)->recordCashierRevision($shift, 'cashier', $this->cashier->id, 0);
        app(ShiftCashCountService::class)->record($shift, $rev, 10000, 0, 0, 10000);

        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $shift->id,
            'handover_to_type' => 'branch_manager',
            'handover_to_id' => $this->manager->id,
            'handover_amount' => '100.00',
            'variance_amount' => '0.00',
            'handover_date' => today(),
            'handover_time' => now(),
            'status' => 'approved',
            'daily_closed_at' => null,
            'report_revision_id' => $rev->id,
        ]);

        $receipt = CashierShiftHandoverReceipt::create([
            'cashier_shift_handover_id' => $handover->id,
            'receiving_branch_manager_id' => $this->manager->id,
            'receiving_branch_manager_shift_id' => $this->workday->id,
            'report_revision_id' => $rev->id,
            'confirmed_amount' => '100.00',
            'confirmed_at' => now(),
        ]);

        app(ShiftReportRevisionService::class)->recordManagerRevision($this->workday, 'branch_manager', $this->manager->id, 0);

        $response = $this->actingAs($this->manager, 'sanctum')->postJson(
            '/api/v1/branch-manager/workday/daily-close/submit',
            [
                'branch_manager_shift_id' => $this->workday->id,
                'final_notes' => 'Audited daily close submission',
                'expected_revision' => 1,
            ],
            ['Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()]
        );

        $response->assertOk()
            ->assertJsonPath('success', true);

        $this->assertTrue((bool) $this->workday->fresh()->daily_report_submitted);
        $this->assertFalse((bool) $this->workday->fresh()->can_reopen);
        $this->assertNotNull($handover->fresh()->daily_closed_at);

        // Verify Gate C-DS created ShiftLiabilityDailyLock row
        $lock = ShiftLiabilityDailyLock::where('branch_manager_shift_id', $this->workday->id)
            ->where('cashier_shift_id', $shift->id)
            ->first();
        $this->assertNotNull($lock, 'DailyLiabilityGuard must create ShiftLiabilityDailyLock on submit.');
        $this->assertSame((string) $this->manager->id, (string) $lock->locked_by_id);

        // Subsequent submission is rejected
        $repeatResponse = $this->actingAs($this->manager, 'sanctum')->postJson(
            '/api/v1/branch-manager/workday/daily-close/submit',
            [
                'branch_manager_shift_id' => $this->workday->id,
                'expected_revision' => 1,
            ],
            ['Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()]
        );
        $this->assertContains($repeatResponse->status(), [400, 409]);
    }
}
