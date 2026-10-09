<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Aggregator\Models\Aggregator;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHistory;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftHandoverStatus;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Modules\Shift\Models\ShiftReportAggregate;
use Modules\Shift\Models\ShiftReportCashCount;
use Modules\Shift\Models\ShiftSalesBreakdown;
use Tests\TestCase;

/** S1-10: reassign-with-handover uses the shared count rule and the S1-09 replay protection, atomically. */
class ShiftReassignHandoverCountTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private BranchManager $manager;

    private Cashier $outgoing;

    private Cashier $incoming;

    private CashierShift $shift;

    private Aggregator $aggregator;

    protected function setUp(): void
    {
        parent::setUp();

        $company = AsabCompany::create(['name' => 'Reassign Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => 'B', 'sub_status' => 'active', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['asab_brand_id' => $brand->id, 'asab_company_id' => $company->id]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true, 'status' => 'active']);
        $this->outgoing = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);
        $this->incoming = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);
        $template = Shift::factory()->create(['branch_id' => $this->branch->id, 'is_active' => true]);
        $this->shift = CashierShift::create([
            'cashier_id' => $this->outgoing->id, 'shift_id' => $template->id,
            'shift_date' => today()->toDateString(), 'status' => ShiftStatus::NOT_STARTED->value,
        ]);
        $this->shift->update(['status' => ShiftStatus::IN_PROGRESS, 'actual_start_time' => now()]);
        $this->aggregator = Aggregator::factory()->create();
    }

    /** gross 115, cards 50, apps 25 → expected 40; counted 30 → shortage 10, split 6 + 4. */
    private function report(array $override = []): array
    {
        return array_merge([
            'new_cashier_id' => $this->incoming->id, 'handover_amount' => '30.00',
            'current_sales' => '115.00', 'cash_collected' => '40.00', 'card_payments' => '50.00',
            'aggregators' => [['aggregator_id' => $this->aggregator->id, 'amount' => '25.00']],
            'counted_cash' => '30.00',
            'shortage_allocations' => [
                ['responsible_type' => 'cashier', 'responsible_id' => $this->outgoing->id, 'amount' => '6.00'],
                ['responsible_type' => 'branch_manager', 'responsible_id' => $this->manager->id, 'amount' => '4.00'],
            ],
            'allocation_reason' => 'Manager reassigned the shift mid-day',
        ], $override);
    }

    private function reassign(array $payload, ?string $key = null)
    {
        $request = $this->actingAs($this->manager, 'sanctum');
        if ($key !== null) {
            $request = $request->withHeaders(['Idempotency-Key' => $key]);
        }

        return $request->postJson("/api/branch-manager/shifts/{$this->shift->id}/reassign-with-handover", $payload);
    }

    private function assertNothingWritten(): void
    {
        $shift = $this->shift->fresh();
        $this->assertSame(ShiftStatus::IN_PROGRESS, $shift->status);
        $this->assertSame($this->outgoing->id, $shift->cashier_id);
        $this->assertSame('0.00', (string) $shift->total_sales);
        $this->assertSame(0, ShiftHandoverStatus::where('cashier_shift_id', $shift->id)->count());
        $this->assertSame(0, ShiftSalesBreakdown::where('cashier_shift_id', $shift->id)->count());
        $this->assertSame(0, ShiftReportCashCount::count());
        $this->assertSame(0, ShiftLiabilityAllocation::count());
        $this->assertNull(ShiftReportAggregate::where('source_id', $shift->id)->first());
        $this->assertSame(0, CashierShiftHistory::where('cashier_shift_id', $shift->id)->where('action', 'reassigned_with_handover')->count());
    }

    public function test_counted_report_is_calculated_allocated_and_reassigned_in_one_commit(): void
    {
        $this->reassign($this->report())->assertOk();

        $shift = $this->shift->fresh();
        $this->assertSame(ShiftStatus::REASSIGNED, $shift->status);
        $this->assertSame($this->incoming->id, $shift->cashier_id);
        $this->assertSame($this->outgoing->id, $shift->original_cashier_id);
        $this->assertSame('100.00', (string) $shift->net_sales);

        $count = ShiftReportCashCount::where('cashier_shift_id', $shift->id)->sole();
        $this->assertSame([11500, 5000, 2500, 0, 3000, 4000, -1000], [
            $count->gross_halalas, $count->cards_halalas, $count->apps_halalas, $count->confirmed_opening_halalas,
            $count->counted_halalas, $count->expected_halalas, $count->variance_halalas,
        ]);
        $allocation = ShiftLiabilityAllocation::where('cashier_shift_id', $shift->id)->sole();
        $this->assertSame(-1000, $allocation->variance_halalas);
        $this->assertSame($count->counted_revision_id, $allocation->report_revision);
        $this->assertNull($allocation->cashier_confirmed_at, 'a manager-submitted allocation awaits the cashier (S1-11)');
        $this->assertSame(1, ShiftHandoverStatus::where('cashier_shift_id', $shift->id)->count());
    }

    public function test_handover_without_a_report_stores_no_count_evidence(): void
    {
        $this->reassign(['new_cashier_id' => $this->incoming->id, 'handover_amount' => '30.00'])->assertOk();

        $this->assertSame(ShiftStatus::REASSIGNED, $this->shift->fresh()->status);
        $this->assertSame(0, ShiftReportCashCount::count(), 'no report, no count: unavailable, never an implied 0');
    }

    public function test_count_is_required_with_a_report_and_forbidden_without_one(): void
    {
        $missing = $this->report();
        unset($missing['counted_cash']);
        $this->reassign($missing)->assertUnprocessable()->assertJsonValidationErrors('counted_cash');
        $this->reassign(['new_cashier_id' => $this->incoming->id, 'handover_amount' => '30.00', 'counted_cash' => '30.00'])
            ->assertUnprocessable()->assertJsonValidationErrors('counted_cash');
        $this->assertNothingWritten();
    }

    public function test_incomplete_or_unreasoned_allocation_leaves_no_partial_write(): void
    {
        $partial = $this->report(['shortage_allocations' => [
            ['responsible_type' => 'cashier', 'responsible_id' => $this->outgoing->id, 'amount' => '6.00'],
        ]]);
        $this->reassign($partial)->assertUnprocessable();
        $this->assertNothingWritten();

        $unreasoned = $this->report();
        unset($unreasoned['allocation_reason']);
        $this->reassign($unreasoned)->assertUnprocessable();
        $this->assertNothingWritten();
    }

    public function test_failure_after_the_count_rolls_back_the_report_count_allocation_and_reassignment(): void
    {
        CashierShiftHistory::creating(static function ($history) {
            if ($history->action === 'reassigned_with_handover') {
                throw new \RuntimeException('injected history failure');
            }
        });

        $this->reassign($this->report())->assertStatus(500);
        CashierShiftHistory::flushEventListeners();

        $this->assertNothingWritten();
    }

    public function test_same_key_replays_once_and_a_changed_payload_is_rejected(): void
    {
        $key = (string) Str::uuid();

        $first = $this->reassign($this->report(), $key);
        $retry = $this->reassign($this->report(), $key);
        $first->assertOk();
        $this->assertSame($first->getContent(), $retry->getContent());
        $this->assertSame(1, ShiftReportCashCount::count());
        $this->assertSame(1, ShiftLiabilityAllocation::count());
        $this->assertSame(1, ShiftHandoverStatus::where('cashier_shift_id', $this->shift->id)->count());
        $this->assertSame(1, CashierShiftHistory::where('cashier_shift_id', $this->shift->id)->where('action', 'reassigned_with_handover')->count());

        $this->reassign($this->report(['handover_amount' => '31.00']), $key)
            ->assertStatus(409)->assertJsonPath('code', 'IDEMPOTENCY_KEY_REUSED');
        $this->assertSame('completed', DB::table('asab_command_idempotency_keys')->sole()->status);
    }

    public function test_a_failed_domain_response_releases_the_key_and_writes_nothing(): void
    {
        $key = (string) Str::uuid();
        $otherBranch = Branch::factory()->create();
        $stranger = Cashier::factory()->create(['branch_id' => $otherBranch->id, 'created_by' => $this->manager->id]);

        $this->reassign($this->report(['new_cashier_id' => $stranger->id]), $key)->assertForbidden();
        $this->assertNothingWritten();
        $this->assertSame(0, DB::table('asab_command_idempotency_keys')->count(), 'a rejected command does not hold its key');

        $this->reassign($this->report(), $key)->assertOk();
    }
}
