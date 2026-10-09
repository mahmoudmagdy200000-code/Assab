<?php

namespace Tests\Feature;

use App\Support\ShiftMoneyValidation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\Shift\Http\Requests\EndShiftRequest;
use Modules\Shift\Services\ShiftEndService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ShiftMoneyValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // Exercise registered HTTP routes/controllers, isolating money validation from authentication.
        $this->withoutMiddleware();
    }

    public static function invalidAmounts(): array
    {
        return array_map(fn ($value) => [$value], ['1.001', 1.001, '0.009', '10.999', '123.456', '10000000000', '-1', '1e2', '+1', '1.']);
    }

    #[DataProvider('invalidAmounts')]
    public function test_end_routes_reject_invalid_money_before_service($amount): void
    {
        $this->mock(ShiftEndService::class, function ($mock) {
            $mock->shouldNotReceive('endShiftOnly');
            $mock->shouldNotReceive('endShiftWithHandover');
            $mock->shouldNotReceive('calculateNetSales');
        });

        foreach (['cashier', 'branch-manager'] as $role) {
            foreach (['end', 'end-with-handover'] as $action) {
                $this->postJson("/api/v1/{$role}/shifts/missing/{$action}", [
                    'total_sales' => $amount,
                    'handover_amount' => 0,
                    'handover_to_type' => 'branch_manager',
                ])->assertUnprocessable()->assertJsonValidationErrors('total_sales');
            }
        }
    }

    public function test_other_money_fields_reject_excess_precision(): void
    {
        foreach (['cash_collected', 'card_payments'] as $field) {
            $this->postJson('/api/v1/cashier/shifts/missing/end', [
                'total_sales' => '115.00', $field => '0.009',
            ])->assertUnprocessable()->assertJsonValidationErrors($field);
        }

        $this->postJson('/api/v1/cashier/shifts/missing/end-with-handover', [
            'total_sales' => '115.00', 'handover_amount' => '10.999', 'handover_to_type' => 'branch_manager',
        ])->assertUnprocessable()->assertJsonValidationErrors('handover_amount');
    }

    public static function validAmounts(): array
    {
        return array_map(fn ($value) => [$value], [0, 1, '1.0', '1.00', 115, '115.25', '9999999999.99', 9999999999.99, 9999999999.03, 1234567890.09]);
    }

    #[DataProvider('validAmounts')]
    public function test_valid_amount_reaches_real_calculator_and_passes_end_validation($amount): void
    {
        $this->postJson('/api/v1/cashier/shifts/calculate-sales', ['total_sales' => $amount])
            ->assertOk()->assertJsonPath('success', true);

        // Dry-run the missing-shift lookup: 404 proves validation passed, without business writes.
        DB::connection()->pretend(function () use ($amount) {
            $this->postJson('/api/v1/cashier/shifts/missing/end', ['total_sales' => $amount, 'counted_cash' => '0.00'])
                ->assertNotFound();
        });
    }

    public function test_preview_preserves_exact_vat_and_rejects_invalid_precision(): void
    {
        $this->postJson('/api/v1/cashier/shifts/calculate-sales', ['total_sales' => 115])
            ->assertOk()->assertJsonPath('data.net_sales', 100)->assertJsonPath('data.vat_amount', 15);
        $this->postJson('/api/v1/cashier/shifts/calculate-sales', ['total_sales' => '1.001'])
            ->assertUnprocessable()->assertJsonValidationErrors('total_sales');
        $this->postJson('/api/v1/cashier/shifts/calculate-sales', ['total_sales' => '10000000000'])
            ->assertUnprocessable()->assertJsonValidationErrors('total_sales');
    }

    public function test_form_request_uses_the_same_precision_and_limit(): void
    {
        $rules = (new EndShiftRequest)->rules();
        foreach (['total_sales', 'cash_collected', 'card_payments', 'handover_amount', 'aggregators.*.amount', 'variance.other_cashiers.*.amount'] as $field) {
            foreach (['1.001', '10000000000'] as $invalid) {
                $this->assertTrue(Validator::make(['amount' => $invalid], ['amount' => $rules[$field]])->fails());
            }
            $this->assertFalse(Validator::make(['amount' => '9999999999.99'], ['amount' => $rules[$field]])->fails());
        }
    }

    public function test_manager_end_and_daily_update_reject_invalid_amounts(): void
    {
        foreach (['total_sales' => '1.001', 'card_payments' => '0.009', 'handover_amount' => '100000000'] as $field => $value) {
            $payload = ['handover_timing' => 'today', $field => $value];
            $this->postJson('/api/v1/branch-manager/workday/end', $payload)->assertUnprocessable();
            $this->putJson('/api/v1/branch-manager/workday/daily-close', $payload)->assertUnprocessable();
        }
    }

    public function test_smaller_manager_handover_boundary_and_signed_variance(): void
    {
        $this->assertFalse(Validator::make(['amount' => '99999999.99'], ['amount' => ShiftMoneyValidation::MANAGER_HANDOVER_SAR])->fails());
        $this->assertTrue(Validator::make(['amount' => '100000000.00'], ['amount' => ShiftMoneyValidation::MANAGER_HANDOVER_SAR])->fails());
        $this->assertFalse(Validator::make(['amount' => '-9999999999.99'], ['amount' => ShiftMoneyValidation::SIGNED_SAR])->fails());
        $this->assertTrue(Validator::make(['amount' => '-10000000000'], ['amount' => ShiftMoneyValidation::SIGNED_SAR])->fails());
    }

    public function test_nested_amount_validation_on_registered_routes(): void
    {
        // Isolate monetary validation from reference-table contents; no database writes.
        $presence = \Mockery::mock(\Illuminate\Validation\PresenceVerifierInterface::class);
        $presence->shouldReceive('getCount')->andReturn(1);
        Validator::setPresenceVerifier($presence);

        $this->postJson('/api/v1/cashier/shifts/missing/end', [
            'total_sales' => 115,
            'aggregators' => [['aggregator_id' => 'known-reference', 'amount' => '1.001']],
        ])->assertUnprocessable()->assertJsonValidationErrors('aggregators.0.amount');

        $this->postJson('/api/v1/cashier/shifts/missing/end', [
            'total_sales' => 115,
            'variance' => ['responsibility_type' => 'self_and_others', 'current_cashier_amount' => '0.009'],
        ])->assertUnprocessable()->assertJsonValidationErrors('variance.current_cashier_amount');

        $payload = ['handover_timing' => 'today', 'cashier_breakdown' => [['cashier_id' => 'known-reference', 'sales' => '123.456']]];
        $this->postJson('/api/v1/branch-manager/workday/end', $payload)->assertUnprocessable();
        $this->putJson('/api/v1/branch-manager/workday/daily-close', $payload)->assertUnprocessable();
    }
}
