<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Aggregator\Models\Aggregator;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftReportAggregate;
use Modules\Shift\Models\ShiftReportCashCount;
use Modules\Shift\Models\ShiftReportCorrection;
use Modules\Shift\Models\ShiftSalesBreakdown;
use Modules\Shift\Services\BranchManagerShiftService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Tests\TestCase;

class ManagerWorkdayCloseContractTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private BranchManager $manager;

    private BranchManagerShift $day;

    private Cashier $cashier;

    private string $originalTimezone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalTimezone = date_default_timezone_get();
        date_default_timezone_set('UTC');
        config(['app.timezone' => 'UTC']);
        $this->travelTo(Carbon::parse('2026-10-29 23:50:00', 'UTC'));
        $company = AsabCompany::create(['name' => 'DC Local', 'status' => 'active', 'plan' => 'Professional']);
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => 'DC brand', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['asab_company_id' => $company->id, 'asab_brand_id' => $brand->id]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier = Cashier::factory()->create(['branch_id' => $this->branch->id]);
        $this->day = BranchManagerShift::firstOrCreate(['branch_manager_id' => $this->manager->id, 'branch_id' => $this->branch->id, 'shift_date' => '2026-10-29'], ['status' => 'in_progress']);
        $this->day->update(['status' => 'in_progress']);
        $this->actingAs($this->manager, 'sanctum');
    }

    protected function tearDown(): void
    {
        $this->travelBack();
        date_default_timezone_set($this->originalTimezone);
        parent::tearDown();
    }

    private function source(string $date = '2026-10-29', ?Branch $branch = null): CashierShift
    {
        $branch ??= $this->branch;
        $cashier = $branch->is($this->branch) ? $this->cashier : Cashier::factory()->create(['branch_id' => $branch->id]);
        $hour = 4 + Shift::where('branch_id', $branch->id)->count();
        $template = Shift::factory()->create(['branch_id' => $branch->id, 'start_time' => sprintf('%02d:00:00', $hour), 'end_time' => sprintf('%02d:00:00', $hour + 1)]);
        $source = CashierShift::factory()->completed()->create([
            'cashier_id' => $cashier->id, 'shift_id' => $template->id, 'shift_date' => $date,
            'total_sales' => '115.00', 'net_sales' => '100.00', 'vat_amount' => '15.00',
            'cash_collected' => '40.00', 'card_payments' => '50.00', 'closing_balance' => '40.00', 'variance' => '0.00',
        ]);
        $app = Aggregator::factory()->create();
        $app->enabledBranches()->attach($branch->id, ['id' => (string) Str::uuid(), 'is_enabled' => true]);
        ShiftSalesBreakdown::create(['cashier_shift_id' => $source->id, 'aggregator_id' => $app->id, 'amount' => '25.00']);
        $revision = app(ShiftReportRevisionService::class)->recordCashierRevision($source, 'cashier', $cashier->id, 0);
        ShiftReportCashCount::create([
            'report_revision_id' => $revision->id, 'counted_revision_id' => $revision->id, 'evidence_revision_id' => $revision->id,
            'cashier_shift_id' => $source->id, 'gross_halalas' => 11500, 'cards_halalas' => 5000, 'apps_halalas' => 2500,
            'confirmed_opening_halalas' => 0, 'pending_incoming_counted_halalas' => 0,
            'counted_halalas' => 4000, 'expected_halalas' => 4000, 'variance_halalas' => 0,
        ]);

        return $source;
    }

    private function row(CashierShift $source): array
    {
        return ['cashier_shift_id' => $source->id, 'cashier_id' => $source->cashier_id, 'sales' => '115.00',
            'cash_collected' => '40.00', 'card_payments' => '50.00', 'delivery_app_payments' => '25.00'];
    }

    private function payload(array $rows = []): array
    {
        return ['manager_workday_id' => $this->day->id, 'handover_timing' => 'yesterday', 'cashier_breakdown' => $rows,
            'total_sales' => '115.00', 'cash_collected' => '40.00', 'card_payments' => '50.00', 'aggregator_payments' => '25.00'];
    }

    public function test_same_day_close_preserves_gross_vat_and_source_identity(): void
    {
        $source = $this->source();
        $this->getJson('/api/v1/branch-manager/workday/daily-close?manager_workday_id='.$this->day->id)
            ->assertOk()->assertJsonPath('data.manager_workday_id', $this->day->id)
            ->assertJsonPath('data.cashier_breakdown.0.cashier_shift_id', $source->id);
        $this->postJson('/api/v1/branch-manager/workday/end', $this->payload([$this->row($source)]))->assertOk();
        $this->day->refresh();
        $this->assertSame('completed', $this->day->status);
        $this->assertSame('115.00', $this->day->total_sales);
        $this->assertSame('100.00', $this->day->net_sales);
        $this->assertSame('15.00', $this->day->vat_amount);
        $this->assertSame(0, ShiftReportCorrection::count());
        $this->assertSame(1, ShiftReportAggregate::where('source_id', $source->id)->value('current_revision_number'));
    }

    public function test_selected_workday_remains_pinned_across_midnight_without_backdating_event_time(): void
    {
        $source = $this->source();
        $this->travelTo(Carbon::parse('2026-10-30 00:27:00', 'UTC'));
        $other = BranchManagerShift::create(['branch_manager_id' => $this->manager->id, 'branch_id' => $this->branch->id,
            'shift_date' => '2026-10-30', 'status' => 'in_progress']);
        $this->getJson('/api/v1/branch-manager/workday/daily-close?workday=yesterday')
            ->assertOk()->assertJsonPath('data.manager_workday_id', $this->day->id)
            ->assertJsonPath('data.business_date', '2026-10-29');
        $this->postJson('/api/v1/branch-manager/workday/end', $this->payload([$this->row($source)]))->assertOk();
        $this->day->refresh();
        $this->assertSame('2026-10-29', $this->day->shift_date->toDateString());
        $this->assertSame('2026-10-30 00:27:00', $this->day->handover_time->format('Y-m-d H:i:s'));
        $this->assertSame('2026-10-30 00:27:00', $this->day->actual_end_time->format('Y-m-d H:i:s'));
        $this->assertSame('in_progress', $other->fresh()->status);
        $this->putJson('/api/v1/branch-manager/workday/daily-close', $this->payload([$this->row($source)]))->assertOk();
        $this->assertSame('in_progress', $other->fresh()->status);
    }

    public function test_server_timezone_selects_workday_without_device_clock_input_or_row_creation(): void
    {
        config(['app.timezone' => 'Asia/Riyadh']);
        date_default_timezone_set('Asia/Riyadh');
        $this->travelTo(Carbon::parse('2026-10-30 00:20:00', 'Asia/Riyadh'));
        $count = BranchManagerShift::count();
        $this->getJson('/api/v1/branch-manager/workday/daily-close?workday=yesterday&device_date=2026-10-28')
            ->assertOk()->assertJsonPath('data.business_date', '2026-10-29')
            ->assertJsonPath('data.timezone', 'Asia/Riyadh')->assertJsonPath('data.workday_dates.today', '2026-10-30');
        $this->assertSame($count, BranchManagerShift::count());
    }

    public function test_commands_require_canonical_id_and_never_fall_back_from_an_invalid_id(): void
    {
        $this->postJson('/api/v1/branch-manager/workday/end', ['handover_timing' => 'today'])->assertUnprocessable();
        $this->putJson('/api/v1/branch-manager/workday/daily-close', [])->assertUnprocessable();
        $this->postJson('/api/v1/branch-manager/workday/end', array_replace($this->payload(), ['manager_workday_id' => (string) Str::uuid()]))->assertNotFound();
        $this->assertSame('in_progress', $this->day->fresh()->status);
    }

    public function test_get_alias_is_compatible_but_conflicting_selectors_are_rejected(): void
    {
        $this->getJson('/api/v1/branch-manager/workday/daily-close?shift_id='.$this->day->id)->assertOk();
        $this->getJson('/api/v1/branch-manager/workday/daily-close?manager_workday_id='.$this->day->id.'&shift_id='.Str::uuid())->assertUnprocessable();
        $this->getJson('/api/v1/branch-manager/workday/daily-close?manager_workday_id='.$this->day->id.'&workday=today')->assertUnprocessable();
    }

    public function test_other_manager_branch_and_company_workdays_are_inaccessible(): void
    {
        $company = AsabCompany::create(['name' => 'Other tenant', 'status' => 'active', 'plan' => 'Professional']);
        $branch = Branch::factory()->create(['asab_company_id' => $company->id]);
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $foreign = BranchManagerShift::create(['branch_manager_id' => $manager->id, 'branch_id' => $branch->id, 'shift_date' => '2026-10-29', 'status' => 'not_started']);
        $this->getJson('/api/v1/branch-manager/workday/daily-close?manager_workday_id='.$foreign->id)->assertNotFound();
        $this->postJson('/api/v1/branch-manager/workday/end', array_replace($this->payload(), ['manager_workday_id' => $foreign->id]))->assertNotFound();
        $this->assertSame('not_started', $foreign->fresh()->status);
    }

    public function test_excluded_october_24_shift_is_rejected_even_with_matching_branch_and_gross(): void
    {
        $valid = $this->source();
        $old = $this->source('2026-10-24');
        $this->getJson('/api/v1/branch-manager/workday/daily-close?manager_workday_id='.$this->day->id)
            ->assertOk()->assertJsonCount(1, 'data.cashier_breakdown')->assertJsonPath('data.cashier_breakdown.0.cashier_shift_id', $valid->id);
        $this->postJson('/api/v1/branch-manager/workday/end', $this->payload([$this->row($old)]))->assertUnprocessable();
        $this->assertSame('in_progress', $this->day->fresh()->status);
        $this->assertSame(0, ShiftReportCorrection::count());
    }

    public function test_eligible_carryover_has_identical_read_and_write_membership(): void
    {
        $source = $this->source('2026-10-28');
        CashierShiftHandover::create(['cashier_shift_id' => $source->id, 'handover_to_type' => 'branch_manager',
            'handover_to_id' => $this->manager->id, 'handover_amount' => '40.00', 'handover_date' => '2026-10-28',
            'handover_time' => now(), 'status' => 'approved']);
        $this->getJson('/api/v1/branch-manager/workday/daily-close?manager_workday_id='.$this->day->id)
            ->assertOk()->assertJsonPath('data.cashier_breakdown.0.cashier_shift_id', $source->id);
        $this->postJson('/api/v1/branch-manager/workday/end', $this->payload([$this->row($source)]))->assertOk();
        $this->assertSame(0, ShiftReportCorrection::count());
    }

    public function test_consumed_source_cannot_be_written_into_another_workday(): void
    {
        $source = $this->source();
        $other = BranchManagerShift::create(['branch_manager_id' => $this->manager->id, 'branch_id' => $this->branch->id,
            'shift_date' => '2026-10-30', 'status' => 'completed']);
        DB::table('shift_liability_daily_locks')->insert(['id' => (string) Str::uuid(), 'cashier_shift_id' => $source->id,
            'branch_manager_shift_id' => $other->id, 'report_revision' => '1', 'locked_by_id' => $this->manager->id, 'locked_at' => now()]);
        $this->postJson('/api/v1/branch-manager/workday/end', $this->payload([$this->row($source)]))->assertUnprocessable();
        $this->assertSame('in_progress', $this->day->fresh()->status);
    }

    public function test_cashier_identity_mismatch_duplicate_targets_and_client_variance_roll_back(): void
    {
        $source = $this->source();
        $another = Cashier::factory()->create(['branch_id' => $this->branch->id]);
        foreach ([
            [array_replace($this->row($source), ['cashier_id' => $another->id])],
            [$this->row($source), $this->row($source)],
            [array_replace($this->row($source), ['variance' => '0.00'])],
        ] as $rows) {
            $this->postJson('/api/v1/branch-manager/workday/end', $this->payload($rows))->assertUnprocessable();
            $this->assertSame('in_progress', $this->day->fresh()->status);
        }
        $this->assertSame(0, ShiftReportCorrection::count());
    }

    public function test_multi_aggregator_scalar_and_explicit_noops_do_not_create_a_correction(): void
    {
        $source = $this->source();
        $first = $source->salesBreakdown()->firstOrFail();
        $first->update(['amount' => '10.00']);
        $app = Aggregator::factory()->create();
        $app->enabledBranches()->attach($this->branch->id, ['id' => (string) Str::uuid(), 'is_enabled' => true]);
        $second = ShiftSalesBreakdown::create(['cashier_shift_id' => $source->id, 'aggregator_id' => $app->id, 'amount' => '15.00']);
        $row = $this->row($source);
        app(BranchManagerShiftService::class)->bulkUpdateCashierShifts([$row], $this->day, $this->manager);
        $row['aggregators'] = [['aggregator_id' => $second->aggregator_id, 'amount' => '15.00'], ['aggregator_id' => $first->aggregator_id, 'amount' => '10.00']];
        app(BranchManagerShiftService::class)->bulkUpdateCashierShifts([$row], $this->day, $this->manager);
        $this->assertSame(1, ShiftReportAggregate::where('source_id', $source->id)->value('current_revision_number'));
        $this->assertSame(0, ShiftReportCorrection::count());
        $this->postJson('/api/v1/branch-manager/workday/end', $this->payload([$this->row($source)]))->assertOk();
        $this->assertSame(2, $source->salesBreakdown()->count());
    }

    public function test_real_multi_aggregator_change_requires_explicit_amounts_and_preserves_other_shift(): void
    {
        $source = $this->source();
        $untouched = $this->source('2026-10-29');
        $first = $source->salesBreakdown()->firstOrFail();
        $first->update(['amount' => '10.00']);
        $app = Aggregator::factory()->create();
        $app->enabledBranches()->attach($this->branch->id, ['id' => (string) Str::uuid(), 'is_enabled' => true]);
        ShiftSalesBreakdown::create(['cashier_shift_id' => $source->id, 'aggregator_id' => $app->id, 'amount' => '15.00']);
        $row = array_replace($this->row($source), ['delivery_app_payments' => '30.00', 'expected_revision' => 1, 'reason' => 'POS app allocation corrected']);
        $this->day->update(['status' => 'completed']);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->putJson('/api/v1/branch-manager/workday/daily-close', $this->payload([$row]))->assertUnprocessable();
        $row['aggregators'] = [['aggregator_id' => $first->aggregator_id, 'amount' => '15.00'], ['aggregator_id' => $app->id, 'amount' => '15.00']];
        $payload = $this->payload([$row]);
        $payload['aggregator_payments'] = '55.00';
        $key = (string) Str::uuid();
        $firstResponse = $this->withHeader('Idempotency-Key', $key)->putJson('/api/v1/branch-manager/workday/daily-close', $payload)->assertOk();
        $retry = $this->withHeader('Idempotency-Key', $key)->putJson('/api/v1/branch-manager/workday/daily-close', $payload)->assertOk();
        $this->assertSame($firstResponse->getContent(), $retry->getContent());
        $this->assertSame(30.0, (float) $source->salesBreakdown()->sum('amount'));
        $this->assertSame(25.0, (float) $untouched->salesBreakdown()->sum('amount'));
        $this->assertSame(1, ShiftReportAggregate::where('source_id', $untouched->id)->value('current_revision_number'));
    }

    public function test_scalar_without_an_enabled_aggregator_is_never_silently_ignored(): void
    {
        $source = $this->source();
        $source->salesBreakdown()->delete();
        DB::table('branch_aggregators')->where('branch_id', $this->branch->id)->delete();
        $this->day->update(['status' => 'completed']);
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->putJson('/api/v1/branch-manager/workday/daily-close',
            $this->payload([array_replace($this->row($source), ['expected_revision' => 1, 'reason' => 'Missing allocation'])]))->assertUnprocessable();
        $this->assertSame(0, $source->salesBreakdown()->count());
    }

    public function test_batch_rolls_back_first_correction_when_second_revision_is_stale(): void
    {
        $first = $this->source();
        $second = $this->source();
        $this->day->update(['status' => 'completed']);
        $rows = [];
        foreach ([$first, $second] as $i => $source) {
            $rows[] = array_replace($this->row($source), [
                'card_payments' => '55.00', 'expected_revision' => $i === 0 ? 1 : 99, 'reason' => 'Atomic batch correction']);
        }
        $this->withHeader('Idempotency-Key', (string) Str::uuid())->putJson('/api/v1/branch-manager/workday/daily-close', $this->payload($rows))->assertStatus(409);
        foreach ([$first, $second] as $source) {
            $this->assertSame('50.00', $source->fresh()->card_payments);
            $this->assertSame(1, ShiftReportAggregate::where('source_id', $source->id)->value('current_revision_number'));
        }
        $this->assertSame(0, ShiftReportCorrection::count());
    }

    public function test_revisions_reasons_and_durable_keys_remain_required_for_real_changes(): void
    {
        $source = $this->source();
        $this->day->update(['status' => 'completed']);
        foreach ([[], ['expected_revision' => 1], ['reason' => 'Reason only']] as $extra) {
            $row = array_replace($this->row($source), ['card_payments' => '55.00'], $extra);
            $this->putJson('/api/v1/branch-manager/workday/daily-close', $this->payload([$row]))->assertUnprocessable();
        }
        $row = array_replace($this->row($source), ['card_payments' => '55.00', 'expected_revision' => 1, 'reason' => 'POS correction']);
        $this->putJson('/api/v1/branch-manager/workday/daily-close', $this->payload([$row]))->assertStatus(409);
        $this->assertSame('50.00', $source->fresh()->card_payments);
    }

    public function test_daily_close_aggregator_rows_use_shift_identity_for_the_same_cashier(): void
    {
        $first = $this->source();
        $second = $this->source();
        $this->getJson('/api/v1/branch-manager/workday/daily-close?manager_workday_id='.$this->day->id)
            ->assertOk()->assertJsonCount(2, 'data.cashier_breakdown')
            ->assertJsonCount(2, 'data.aggregators.0.cashier_breakdown');
        $summary = app(BranchManagerShiftService::class)->prepareDailyCloseSummary($this->day);
        $ids = array_column($summary['aggregators'][0]['cashier_breakdown'], 'cashier_shift_id');
        $this->assertEqualsCanonicalizing([$first->id, $second->id], $ids);
    }

    public function test_duplicate_end_with_same_key_replays_without_new_revisions(): void
    {
        $source = $this->source();
        $payload = $this->payload([$this->row($source)]);
        $key = (string) Str::uuid();
        $first = $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/branch-manager/workday/end', $payload)->assertOk();
        $retry = $this->withHeader('Idempotency-Key', $key)->postJson('/api/v1/branch-manager/workday/end', $payload)->assertOk();
        $this->assertSame($first->getContent(), $retry->getContent());
        $this->assertSame(1, ShiftReportAggregate::where('source_id', $this->day->id)->value('current_revision_number'));
    }

    public function test_head_finality_and_fresh_count_guards_apply_to_the_exact_selected_day(): void
    {
        $source = $this->source();
        ShiftReportAggregate::where('source_id', $source->id)->update(['fresh_count_required' => true]);
        $this->postJson('/api/v1/branch-manager/workday/end', $this->payload([$this->row($source)]))
            ->assertStatus(409)->assertJsonPath('code', 'PHYSICAL_RECOUNT_REQUIRED');
        ShiftReportAggregate::where('source_id', $source->id)->update(['fresh_count_required' => false]);
        \Modules\Admin\Models\Operation::create(['public_id' => 'DCF-'.substr((string) Str::uuid(), 0, 8), 'company_id' => $this->branch->asab_company_id,
            'module_key' => 'sales', 'origin' => 'mobile', 'source_id' => $this->day->id,
            'payload' => ['managerShiftId' => $this->day->id], 'status' => 'final-approved']);
        $this->postJson('/api/v1/branch-manager/workday/end', $this->payload([$this->row($source)]))
            ->assertStatus(409)->assertJsonPath('code', 'REPORT_FINAL_APPROVED');
        $this->assertSame('in_progress', $this->day->fresh()->status);
        $this->assertSame(0, ShiftReportCorrection::count());
    }

    public function test_invalid_duplicate_or_foreign_app_allocations_roll_back_without_corrections(): void
    {
        $source = $this->source();
        $app = $source->salesBreakdown()->firstOrFail()->aggregator_id;
        $foreign = Aggregator::factory()->create();
        $this->day->update(['status' => 'completed']);
        foreach ([
            [['aggregator_id' => $app, 'amount' => '10.00']],
            [['aggregator_id' => $app, 'amount' => '15.00'], ['aggregator_id' => $app, 'amount' => '15.00']],
            [['aggregator_id' => $foreign->id, 'amount' => '30.00']],
        ] as $breakdown) {
            $row = array_replace($this->row($source), ['delivery_app_payments' => '30.00', 'aggregators' => $breakdown,
                'expected_revision' => 1, 'reason' => 'Validate app authority']);
            $this->withHeader('Idempotency-Key', (string) Str::uuid())->putJson('/api/v1/branch-manager/workday/daily-close', $this->payload([$row]))->assertUnprocessable();
        }
        $this->assertSame(25.0, (float) $source->salesBreakdown()->sum('amount'));
        $this->assertSame(0, ShiftReportCorrection::count());
    }
}
