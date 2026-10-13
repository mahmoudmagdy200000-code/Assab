<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Services\CashierShiftStartService;
use Modules\Shift\Transformers\ShiftDetailResource;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ShiftAssignmentAcceptanceGateTest extends TestCase
{
    use RefreshDatabase;

    private BranchManager $manager;

    private Cashier $outgoing;

    private Cashier $incoming;

    private CashierShift $source;

    protected function setUp(): void
    {
        parent::setUp();

        $company = AsabCompany::create(['name' => 'BRC-06', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => 'BRC-06', 'sub_status' => 'active', 'status' => 'active']);
        $branch = Branch::factory()->create(['asab_company_id' => $company->id, 'asab_brand_id' => $brand->id]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $branch->id, 'is_active' => true, 'status' => 'active']);
        $this->outgoing = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $this->manager->id]);
        $this->incoming = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $this->manager->id]);
        $template = Shift::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $this->source = CashierShift::create([
            'cashier_id' => $this->outgoing->id, 'shift_id' => $template->id,
            'shift_date' => today()->toDateString(), 'status' => ShiftStatus::IN_PROGRESS,
            'actual_start_time' => now()->subHour(),
        ]);
    }

    private function assign(): CashierShift
    {
        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/branch-manager/shifts/{$this->source->id}/reassign-with-handover", [
                'new_cashier_id' => $this->incoming->id, 'handover_amount' => '30.00',
            ])->assertOk();

        $incoming = CashierShift::findOrFail($response->json('data.shift.id'));
        $this->assertSame(ShiftStatus::REASSIGNED, $incoming->status);
        $this->assertNull($incoming->actual_start_time);

        return $incoming;
    }

    private function snapshot(): array
    {
        $rows = [];
        foreach (['cashier_shifts', 'cashier_shift_history', 'cashier_shift_handover_receipts',
            'cashier_custody_transactions', 'personal_ledger_transactions', 'shift_report_cash_counts',
            'shift_liability_allocations', 'shift_liability_shares'] as $table) {
            $rows[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }

        return $rows;
    }

    public function test_cashier_start_before_acceptance_conflicts_without_any_writes(): void
    {
        $incoming = $this->assign();
        $before = $this->snapshot();

        $this->actingAs($this->incoming, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$incoming->id}/start")
            ->assertStatus(409)->assertJsonPath('code', 'ASSIGNMENT_ACCEPTANCE_REQUIRED');

        $this->assertSame($before, $this->snapshot());
        $this->assertNull($incoming->fresh()->actual_start_time);
        $this->assertNull($this->source->fresh()->operational_ended_at);
    }

    public function test_manager_start_before_acceptance_conflicts_without_any_writes(): void
    {
        $incoming = $this->assign();
        $before = $this->snapshot();

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/branch-manager/shifts/start-by-manager/{$incoming->id}")
            ->assertStatus(409)->assertJsonPath('code', 'ASSIGNMENT_ACCEPTANCE_REQUIRED');

        $this->assertSame($before, $this->snapshot());
        $this->assertNull($incoming->fresh()->actual_start_time);
        $this->assertNull($this->source->fresh()->operational_ended_at);
    }

    public function test_direct_start_service_rechecks_acceptance_without_writes(): void
    {
        $incoming = $this->assign();
        $before = $this->snapshot();

        try {
            app(CashierShiftStartService::class)->startShift($incoming);
            $this->fail('The service must reject a pending reassignment.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('ASSIGNMENT_ACCEPTANCE_REQUIRED', $exception->getMessage());
        }

        $this->assertSame($before, $this->snapshot());
    }

    public function test_acceptance_is_separate_from_start_and_cash_receipt(): void
    {
        $incoming = $this->assign();
        $before = $this->snapshot();

        $this->actingAs($this->incoming, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$incoming->id}/reassign/accept")
            ->assertOk()
            ->assertJsonPath('data.shift.available_actions.can_start', true)
            ->assertJsonPath('data.shift.available_actions.can_accept_assignment', false);

        $incoming->refresh();
        $this->assertSame(ShiftStatus::NOT_STARTED, $incoming->status);
        $this->assertNull($incoming->actual_start_time);
        $this->assertNull($this->source->fresh()->operational_ended_at);
        foreach (['cashier_shift_handover_receipts', 'cashier_custody_transactions', 'personal_ledger_transactions'] as $table) {
            $this->assertSame($before[$table], $this->snapshot()[$table]);
        }

        $this->actingAs($this->incoming, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$incoming->id}/start")
            ->assertOk()->assertJsonPath('data.shift.available_actions.can_start', false);

        $this->assertNotNull($incoming->fresh()->actual_start_time);
        $this->assertNotNull($this->source->fresh()->operational_ended_at);
        $this->assertSame(0, DB::table('cashier_shift_handover_receipts')->count());
        $this->assertSame(0, DB::table('cashier_custody_transactions')->count());
        $this->assertSame(0, DB::table('personal_ledger_transactions')->count());
    }

    public function test_available_actions_offer_acceptance_not_start_while_reassigned(): void
    {
        $incoming = $this->assign();
        $actions = (new ShiftDetailResource($incoming->fresh(['handoverStatus', 'shift'])))
            ->resolve(request())['available_actions'];

        $this->assertTrue($actions['can_accept_assignment']);
        $this->assertFalse($actions['can_start']);
        $this->assertArrayHasKey('can_end', $actions);
        $this->assertArrayHasKey('can_handover', $actions);
    }

    public function test_cashier_pending_list_matches_the_acceptance_gate(): void
    {
        $incoming = $this->assign();

        $pending = $this->actingAs($this->incoming, 'sanctum')
            ->getJson('/api/v1/cashier/my-shifts/pending')->assertOk()->json('data');
        $this->assertFalse($pending[0]['can_start']);
        $this->assertTrue($pending[0]['can_accept_assignment']);

        $this->postJson("/api/v1/cashier/shifts/{$incoming->id}/reassign/accept")->assertOk();
        $pending = $this->getJson('/api/v1/cashier/my-shifts/pending')->assertOk()->json('data');
        $this->assertTrue($pending[0]['can_start']);
        $this->assertFalse($pending[0]['can_accept_assignment']);
    }

    public function test_manager_can_start_only_after_cashier_accepts(): void
    {
        $incoming = $this->assign();
        $this->actingAs($this->incoming, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$incoming->id}/reassign/accept")->assertOk();

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/branch-manager/shifts/start-by-manager/{$incoming->id}")->assertOk();

        $this->assertSame(ShiftStatus::IN_PROGRESS, $incoming->fresh()->status);
        $this->assertSame(0, DB::table('cashier_shift_handover_receipts')->count());
    }

    public function test_future_cashier_hint_is_false_while_existing_manager_route_can_start(): void
    {
        $future = CashierShift::create([
            'cashier_id' => $this->incoming->id,
            'shift_id' => $this->source->shift_id,
            'shift_date' => today()->addDay()->toDateString(),
            'status' => ShiftStatus::NOT_STARTED,
        ]);

        $actions = (new ShiftDetailResource($future->fresh(['handoverStatus', 'shift'])))
            ->resolve(request())['available_actions'];
        $this->assertFalse($actions['can_start']);
        $this->assertFalse($actions['can_accept_assignment']);

        $this->actingAs($this->incoming, 'sanctum')
            ->postJson("/api/v1/cashier/shifts/{$future->id}/start")
            ->assertStatus(400)->assertJsonPath('success', false);
        $this->assertSame(ShiftStatus::NOT_STARTED, $future->fresh()->status);
        $this->assertNull($future->fresh()->actual_start_time);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/branch-manager/shifts/start-by-manager/{$future->id}")
            ->assertOk()->assertJsonPath('success', true);

        $this->assertSame(ShiftStatus::IN_PROGRESS, $future->fresh()->status);
        $this->assertNotNull($future->fresh()->actual_start_time);
        $this->assertSame(0, DB::table('cashier_shift_handover_receipts')->count());
        $this->assertSame(0, DB::table('cashier_custody_transactions')->count());
        $this->assertSame(0, DB::table('personal_ledger_transactions')->count());
    }
}
