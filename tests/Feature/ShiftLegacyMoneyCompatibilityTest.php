<?php

namespace Tests\Feature;

use App\Support\ShiftMoneyValidation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Modules\Aggregator\Models\Aggregator;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Services\ShiftFinancialService;
use Modules\Shift\Transformers\BranchManagerShiftResource;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Audit 2026-10-08 N-01 / N-02 / N-03 / reassign VAT: legacy client compatibility and historical reads,
 * exercised through registered routes with real authentication and a migrated database.
 */
class ShiftLegacyMoneyCompatibilityTest extends TestCase
{
    use RefreshDatabase;

    private function world(): array
    {
        $branch = Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $template = Shift::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $shift = CashierShift::create([
            'cashier_id' => $cashier->id, 'shift_id' => $template->id,
            'shift_date' => today()->toDateString(), 'status' => ShiftStatus::NOT_STARTED->value,
        ]);
        $shift->update(['status' => ShiftStatus::IN_PROGRESS, 'actual_start_time' => now()]);

        return [$branch, $manager, $cashier, $shift, Aggregator::factory()->create()];
    }

    /** Exactly what AssabAPP sends: multipart text, `variance[current_cashier_amount]` = Dart double total − payments. */
    private function appEndPayload(Aggregator $app, string $currentCashierAmount): array
    {
        return [
            'total_sales' => '115.5', 'cash_collected' => '40.1', 'card_payments' => '50.2',
            'aggregators' => [['aggregator_id' => $app->id, 'amount' => '25.1']],
            'variance' => ['responsibility_type' => 'self', 'current_cashier_amount' => $currentCashierAmount],
        ];
    }

    public static function appNoise(): array
    {
        return [
            'double subtraction' => ['0.09999999999999432'],
            'balanced but not bit-equal' => ['1.4210854715202004e-14'],
        ];
    }

    #[DataProvider('appNoise')]
    public function test_app_computed_amount_with_representation_noise_ends_the_shift(string $noise): void
    {
        [, , $cashier, $shift, $app] = $this->world();

        $this->actingAs($cashier, 'sanctum')
            ->post("/api/branch-manager/shifts/{$shift->id}/end", $this->appEndPayload($app, $noise), ['Accept' => 'application/json'])
            ->assertOk();

        $fresh = $shift->fresh();
        $this->assertSame(ShiftStatus::COMPLETED, $fresh->status);
        $this->assertSame('115.50', (string) $fresh->total_sales);
        $this->assertSame('100.43', (string) $fresh->net_sales);   // 115.50 / 1.15 = 100.4347… → 100.43
        $this->assertSame('15.07', (string) $fresh->vat_amount);   // residual
    }

    public static function realExtraDecimals(): array
    {
        return [['1.001'], ['0.009'], ['10.999'], ['123.456'], ['0.0015'], ['1.5e-3']];
    }

    #[DataProvider('realExtraDecimals')]
    public function test_a_real_extra_decimal_is_still_rejected_and_the_shift_stays_open(string $amount): void
    {
        [, , $cashier, $shift, $app] = $this->world();

        $this->actingAs($cashier, 'sanctum')
            ->post("/api/branch-manager/shifts/{$shift->id}/end", $this->appEndPayload($app, $amount), ['Accept' => 'application/json'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('variance.current_cashier_amount');

        $this->assertSame(ShiftStatus::IN_PROGRESS, $shift->fresh()->status);
    }

    public function test_noise_normalization_unit_cases(): void
    {
        $this->assertSame('0.10', ShiftMoneyValidation::withoutRepresentationNoise('0.09999999999999432'));
        $this->assertSame('9.40', ShiftMoneyValidation::withoutRepresentationNoise('9.400000000000006'));
        $this->assertSame('0.00', ShiftMoneyValidation::withoutRepresentationNoise('1.4210854715202004e-14'));
        $this->assertSame('-0.30', ShiftMoneyValidation::withoutRepresentationNoise('-0.30000000000000004'));
        foreach (['115', '115.5', '115.50', '1.001', '0.009', '1e2', '1e-2', '5E-1', '1.5e-3', '+1', '1.', 'abc', 'NaN', 'INF'] as $unchanged) {
            $this->assertNull(ShiftMoneyValidation::withoutRepresentationNoise($unchanged), $unchanged);
        }
        $this->assertNull(ShiftMoneyValidation::withoutRepresentationNoise(0.30000000000000004));
    }

    public function test_reassign_with_handover_stores_vat_inclusive_split(): void
    {
        [$branch, $manager, , $shift, $app] = $this->world();
        $next = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);

        $this->actingAs($manager, 'sanctum')
            ->post("/api/branch-manager/shifts/{$shift->id}/reassign-with-handover", [
                'new_cashier_id' => $next->id, 'handover_amount' => '40.00',
                'current_sales' => '115.00', 'cash_collected' => '40.00', 'card_payments' => '50.00',
                'aggregators' => [['aggregator_id' => $app->id, 'amount' => '25.00']],
            ], ['Accept' => 'application/json'])
            ->assertSuccessful();

        $fresh = $shift->fresh();
        $this->assertSame('115.00', (string) $fresh->total_sales);
        $this->assertSame('100.00', (string) $fresh->net_sales);
        $this->assertSame('15.00', (string) $fresh->vat_amount);
    }

    private function managerRow(BranchManager $manager): BranchManagerShift
    {
        return BranchManagerShift::where('branch_manager_id', $manager->id)->whereDate('shift_date', today())->first()
            ?? BranchManagerShift::create(['branch_manager_id' => $manager->id, 'branch_id' => $manager->branch_id, 'shift_date' => today()->toDateString()]);
    }

    public function test_manager_read_keeps_stored_historical_split(): void
    {
        [, $manager] = $this->world();
        $row = $this->managerRow($manager);
        $row->forceFill(['total_sales' => '115.00', 'net_sales' => '97.75', 'vat_amount' => '17.25', 'cash_collected' => '40.00'])->saveQuietly();

        $summary = (new BranchManagerShiftResource($row->fresh()))->toArray(Request::create('/'))['financial_summary'];
        $this->assertSame(97.75, $summary['net_sales']);
        $this->assertSame(17.25, $summary['vat_amount']);

        $service = app(ShiftFinancialService::class)->computeFinancialSummaryFromHandovers($row->fresh(), collect());
        $this->assertSame(97.75, $service['net_sales']);
        $this->assertSame(17.25, $service['vat_amount']);
    }

    public function test_manager_summary_from_handovers_is_the_sum_of_stored_cashier_splits(): void
    {
        [, $manager] = $this->world();
        $row = $this->managerRow($manager);
        $old = new CashierShift(['total_sales' => '115.00', 'net_sales' => '97.75', 'vat_amount' => '17.25']);
        $new = new CashierShift(['total_sales' => '115.00', 'net_sales' => '100.00', 'vat_amount' => '15.00']);
        $handovers = collect([$old, $new])->map(fn ($s) => (object) ['cashierShift' => $s, 'variance_amount' => 0]);

        $summary = app(ShiftFinancialService::class)->computeFinancialSummaryFromHandovers($row->fresh(), $handovers);

        $this->assertSame(230.0, (float) $summary['total_sales']);
        $this->assertSame(197.75, $summary['net_sales']);
        $this->assertSame(32.25, $summary['vat_amount']);
    }

    public function test_negative_legacy_manager_total_does_not_break_reads(): void
    {
        [, $manager] = $this->world();
        $row = $this->managerRow($manager);
        $row->forceFill(['total_sales' => '-5.00', 'cash_collected' => '10.00'])->saveQuietly();

        $summary = (new BranchManagerShiftResource($row->fresh()))->toArray(Request::create('/'))['financial_summary'];

        $this->assertSame(-5.0, $summary['total_sales']);
        $this->assertEquals(0, $summary['net_sales']);
        $this->assertEquals(0, $summary['vat_amount']);
    }

    /** Endpoints that moved from numeric|min:0 to the D5 SAR rules in this correction. */
    private function newlyValidatedRequests(array $w, string $amount): array
    {
        [$branch, $manager, $cashier, $shift] = $w;
        $other = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);

        return [
            'record handover' => [$cashier, "/api/cashier/shifts/{$shift->id}/handover", ['next_cashier_id' => $other->id, 'handover_amount' => $amount], 'handover_amount'],
            'edit after rejection' => [$cashier, "/api/cashier/shifts/{$shift->id}/handover/edit", ['handover_amount' => $amount, 'correction_reason' => 'input_error'], 'handover_amount'],
            'record variance' => [$manager, "/api/branch-manager/shifts/{$shift->id}/variance", ['responsibility_type' => 'self_and_others', 'other_cashiers' => [['cashier_id' => $other->id, 'amount' => $amount]]], 'other_cashiers.0.amount'],
            'reassign with handover' => [$manager, "/api/branch-manager/shifts/{$shift->id}/reassign-with-handover", ['new_cashier_id' => $other->id, 'handover_amount' => $amount], 'handover_amount'],
        ];
    }

    public function test_newly_validated_endpoints_reject_a_real_extra_decimal(): void
    {
        foreach ($this->newlyValidatedRequests($this->world(), '40.123') as $name => [$actor, $url, $body, $field]) {
            $this->actingAs($actor, 'sanctum')
                ->post($url, $body, ['Accept' => 'application/json'])
                ->assertUnprocessable()
                ->assertJsonValidationErrors($field);
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_newly_validated_endpoints_accept_representation_noise(): void
    {
        foreach ($this->newlyValidatedRequests($this->world(), '40.00000000000001') as $name => [$actor, $url, $body, $field]) {
            $response = $this->actingAs($actor, 'sanctum')->post($url, $body, ['Accept' => 'application/json']);
            $this->assertNotSame(422, $response->status(), $name.': '.$response->getContent());
            $this->app['auth']->forgetGuards();
        }
    }

    public function test_manager_end_stores_the_summed_cashier_split_when_the_total_comes_from_the_shifts(): void
    {
        [, $manager] = $this->world();
        $row = $this->managerRow($manager);
        $service = app(ShiftFinancialService::class);
        // Two cashier shifts of 115.50: each stored 100.43 / 15.07, so the day sums to 200.86 / 30.14,
        // whereas the split of the summed gross 231.00 would be 200.87 / 30.13.
        $summary = ['total_sales' => 231.0, 'cash_collected' => 0, 'card_payments' => 0, 'delivery_app_payments' => 0,
            'total_variance' => 0, 'net_sales_halalas' => 20086, 'vat_amount_halalas' => 3014];

        $fromShifts = $service->resolveFinancialValues(Request::create('/', 'POST', []), $summary, $row);
        $this->assertSame(200.86, $fromShifts['net_sales']);
        $this->assertSame(30.14, $fromShifts['vat_amount']);

        $managerTyped = $service->resolveFinancialValues(Request::create('/', 'POST', ['total_sales' => '231.00']), $summary, $row);
        $this->assertSame(200.87, $managerTyped['net_sales']);
        $this->assertSame(30.13, $managerTyped['vat_amount']);
    }
}
