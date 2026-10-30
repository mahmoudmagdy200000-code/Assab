<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftReportAggregate;
use Modules\Shift\Models\ShiftReportCashCount;
use Modules\Shift\Models\ShiftReportCorrection;
use Modules\Shift\Services\ShiftReportRevisionService;
use Tests\TestCase;

class ShiftManagerCorrectionIdempotencyTest extends TestCase
{
    use RefreshDatabase;

    private string $managerWorkdayId;

    private Branch $branch;

    private BranchManager $manager;

    private Cashier $cashier;

    private CashierShift $cashierShift;

    protected function setUp(): void
    {
        parent::setUp();

        $company = AsabCompany::create(['name' => 'Manager idempotency', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => 'Brand', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['asab_company_id' => $company->id, 'asab_brand_id' => $brand->id]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active', 'is_active' => true]);
        $this->cashier = Cashier::factory()->create(['branch_id' => $this->branch->id]);
        $template = Shift::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashierShift = CashierShift::factory()->completed()->create([
            'shift_id' => $template->id,
            'cashier_id' => $this->cashier->id,
            'shift_date' => today(),
            'total_sales' => '100.00',
            'card_payments' => '40.00',
            'cash_collected' => '60.00',
            'closing_balance' => '60.00',
        ]);
        $managerShift = BranchManagerShift::where('branch_manager_id', $this->manager->id)
            ->whereDate('shift_date', today())->firstOrFail();
        $this->managerWorkdayId = (string) $managerShift->id;
        $managerShift->update([
            'status' => 'completed', 'total_sales' => '100.00', 'cash_collected' => '60.00',
            'card_payments' => '40.00', 'handover_amount' => '60.00',
        ]);

        $revision = app(ShiftReportRevisionService::class)->recordCashierRevision($this->cashierShift, 'cashier', $this->cashier->id, 0);
        ShiftReportCashCount::create([
            'report_revision_id' => $revision->id,
            'counted_revision_id' => $revision->id,
            'evidence_revision_id' => $revision->id,
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
        $this->assertNotNull($managerShift->id);
    }

    public function test_public_manager_financial_correction_replays_exact_response_once(): void
    {
        $key = (string) Str::uuid();
        $payload = $this->payload('50.00');

        $first = $this->withHeader('Idempotency-Key', $key)
            ->actingAs($this->manager, 'sanctum')
            ->putJson('/api/v1/branch-manager/workday/daily-close', $payload);
        $retry = $this->withHeader('Idempotency-Key', $key)
            ->actingAs($this->manager, 'sanctum')
            ->putJson('/api/v1/branch-manager/workday/daily-close', $payload);

        $first->assertOk();
        $this->assertSame($first->getContent(), $retry->getContent());
        $this->assertSame(2, ShiftReportAggregate::where('source_type', 'cashier_shift')
            ->where('source_id', $this->cashierShift->id)->value('current_revision_number'));
        $this->assertSame(1, ShiftReportCorrection::where('field_name', 'card_payments')->count());
        $this->assertSame('completed', \Illuminate\Support\Facades\DB::table('asab_command_idempotency_keys')->sole()->status);
    }

    public function test_public_manager_financial_correction_requires_a_key_only_when_a_correction_will_run(): void
    {
        $this->actingAs($this->manager, 'sanctum')
            ->putJson('/api/v1/branch-manager/workday/daily-close', $this->payload('50.00'))
            ->assertStatus(409)
            ->assertJsonPath('code', 'IDEMPOTENCY_KEY_REQUIRED_FOR_REPORT_CORRECTION');

        $this->assertSame(1, ShiftReportAggregate::where('source_type', 'cashier_shift')
            ->where('source_id', $this->cashierShift->id)->value('current_revision_number'));
        $this->assertSame(0, ShiftReportCorrection::count());
        $this->assertDatabaseCount('asab_command_idempotency_keys', 0);

        $this->actingAs($this->manager, 'sanctum')
            ->putJson('/api/v1/branch-manager/workday/daily-close', $this->payload('40.00'))
            ->assertOk();
        $this->assertSame(1, ShiftReportAggregate::where('source_type', 'cashier_shift')
            ->where('source_id', $this->cashierShift->id)->value('current_revision_number'));
    }

    public function test_same_idempotency_key_with_a_changed_correction_payload_is_rejected(): void
    {
        $key = (string) Str::uuid();
        $this->withHeader('Idempotency-Key', $key)
            ->actingAs($this->manager, 'sanctum')
            ->putJson('/api/v1/branch-manager/workday/daily-close', $this->payload('50.00'))
            ->assertOk();

        $this->withHeader('Idempotency-Key', $key)
            ->actingAs($this->manager, 'sanctum')
            ->putJson('/api/v1/branch-manager/workday/daily-close', $this->payload('55.00'))
            ->assertStatus(409)
            ->assertJsonPath('code', 'IDEMPOTENCY_PAYLOAD_MISMATCH');

        $this->assertSame(2, ShiftReportAggregate::where('source_type', 'cashier_shift')
            ->where('source_id', $this->cashierShift->id)->value('current_revision_number'));
        $this->assertSame(1, ShiftReportCorrection::where('field_name', 'card_payments')->count());
    }

    private function payload(string $cards): array
    {
        return [
            'manager_workday_id' => $this->managerWorkdayId,
            'cashier_breakdown' => [[
                'cashier_id' => $this->cashier->id,
                'cashier_shift_id' => $this->cashierShift->id,
                'card_payments' => $cards,
                'expected_revision' => 1,
                'reason' => 'Corrected against card terminal statement',
            ]],
        ];
    }
}
