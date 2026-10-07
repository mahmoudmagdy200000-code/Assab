<?php

namespace Tests\Unit;

use App\Support\ShiftFinancialCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class ShiftFinancialCalculatorTest extends TestCase
{
    public function test_pending_incoming_cash_physically_present_is_not_surplus(): void
    {
        $result = ShiftFinancialCalculator::calculate(11500, 5000, 2500, 0, 5000, 1000);

        $this->assertSame(4000, $result['expected']);
        $this->assertSame(4000, $result['reconciledCounted']);
        $this->assertSame(0, $result['variance']);
        $this->assertSame(0, $result['shortage']);
        $this->assertSame(0, $result['surplus']);
    }

    public function test_confirmation_reclassifies_the_same_cash_without_counting_it_twice(): void
    {
        $before = ShiftFinancialCalculator::calculate(11500, 5000, 2500, 0, 5000, 1000);
        $after = ShiftFinancialCalculator::calculate(11500, 5000, 2500, 1000, 5000, 0);

        $this->assertSame(5000, $after['expected']);
        $this->assertSame(5000, $after['reconciledCounted']);
        $this->assertSame(0, $after['variance']);
        $this->assertSame($before['variance'], $after['variance']);
        $this->assertSame(0, $after['surplus']);
    }

    public function test_a_pending_request_without_physical_cash_does_not_adjust_the_count(): void
    {
        // A 10 SAR request exists outside the calculator, but no cash arrived.
        $result = ShiftFinancialCalculator::calculate(11500, 5000, 2500, 0, 4000, 0);

        $this->assertSame(4000, $result['reconciledCounted']);
        $this->assertSame(4000, $result['expected']);
        $this->assertSame(0, $result['variance']);
    }

    public function test_explicit_zero_pending_cash_preserves_the_original_exact_vector(): void
    {
        $result = ShiftFinancialCalculator::calculate(11500, 5000, 2500, 1000, 3000, 0);

        $this->assertSame(ShiftFinancialCalculator::calculate(11500, 5000, 2500, 1000, 3000), $result);
        $this->assertSame([10000, 1500, 5000, -2000, 2000], [$result['net'], $result['vat'], $result['expected'], $result['variance'], $result['shortage']]);
    }

    public function test_pending_cash_cannot_exceed_the_physical_count(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ShiftFinancialCalculator::calculate(11500, 5000, 2500, 0, 500, 1000);
    }

    public function test_pending_cash_cannot_be_negative(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ShiftFinancialCalculator::calculate(11500, 5000, 2500, 0, 5000, -1);
    }

    public function test_it_calculates_the_approved_exact_shift_vector(): void
    {
        $result = ShiftFinancialCalculator::calculate(11500, 5000, 2500, 1000, 3000);

        $this->assertSame(10000, $result['net']);
        $this->assertSame(1500, $result['vat']);
        $this->assertSame(5000, $result['expected']);
        $this->assertSame(-2000, $result['variance']);
        $this->assertSame(2000, $result['shortage']);
        $this->assertSame(0, $result['surplus']);
        $this->assertSame('shortage', $result['varianceType']);
        $this->assertFalse($result['roundingPendingD5']);
    }

    public function test_it_derives_balanced_and_surplus_results_from_signed_variance(): void
    {
        $balanced = ShiftFinancialCalculator::calculate(5000, 1000, 1000, 500, 3500);
        $surplus = ShiftFinancialCalculator::calculate(5000, 1000, 1000, 500, 4000);

        $this->assertSame(0, $balanced['variance']);
        $this->assertSame('balanced', $balanced['varianceType']);
        $this->assertSame(0, $balanced['shortage']);
        $this->assertSame(0, $balanced['surplus']);
        $this->assertSame(500, $surplus['variance']);
        $this->assertSame('surplus', $surplus['varianceType']);
        $this->assertSame(0, $surplus['shortage']);
        $this->assertSame(500, $surplus['surplus']);
    }

    public function test_cash_inputs_remain_independent_of_channel_sales_and_opening_defaults(): void
    {
        // Count 30 SAR is supplied directly; cash sales are intentionally not
        // an input to the calculator. No receipt means confirmed opening zero.
        $result = ShiftFinancialCalculator::calculate(11500, 5000, 2500, 0, 3000);

        $this->assertSame(4000, $result['expected']);
        $this->assertNotSame(3000, $result['expected']);
        $this->assertSame(-1000, $result['variance']);
    }

    public function test_configured_opening_is_not_substituted_for_confirmed_opening(): void
    {
        $configuredOpening = 1000;
        $withoutReceipt = ShiftFinancialCalculator::calculate(11500, 5000, 2500, 0, 3000);
        $incorrectlyUsingConfiguredFloat = ShiftFinancialCalculator::calculate(11500, 5000, 2500, $configuredOpening, 3000);

        $this->assertSame(4000, $withoutReceipt['expected']);
        $this->assertSame(5000, $incorrectlyUsingConfiguredFloat['expected']);
        $this->assertNotSame($withoutReceipt['expected'], $incorrectlyUsingConfiguredFloat['expected']);
    }

    public function test_commission_and_branch_expenses_do_not_change_expected_cash(): void
    {
        $withGrossAppSales = ShiftFinancialCalculator::calculate(11500, 5000, 2500, 1000, 3000);
        $this->assertSame(5000, $withGrossAppSales['expected']);
        $this->assertNotSame(5300, $withGrossAppSales['expected'], 'App commission must not be deducted from customer app sales.');
        $this->assertNotSame(4200, $withGrossAppSales['expected'], 'Branch expense custody must not reduce expected shift cash.');
    }

    public function test_manager_card_correction_recalculates_without_double_effect(): void
    {
        $before = ShiftFinancialCalculator::calculate(100000, 50000, 0, 0, 50000);
        $after = ShiftFinancialCalculator::calculate(100000, 40000, 0, 0, 50000);

        $this->assertSame(50000, $before['expected']);
        $this->assertSame(0, $before['variance']);
        $this->assertSame(60000, $after['expected']);
        $this->assertSame(-10000, $after['variance']);
        $this->assertSame(10000, $after['shortage']);
    }

    public function test_it_converts_exact_legacy_sar_values_to_halalas_once(): void
    {
        $this->assertSame(11500, ShiftFinancialCalculator::sarToHalalas('115.00'));
        $this->assertSame(11500, ShiftFinancialCalculator::sarToHalalas(115));
        $this->assertSame(11537, ShiftFinancialCalculator::sarToHalalas('115.37'));
        $this->assertSame('100.00', ShiftFinancialCalculator::calculateVatInclusiveSales('115.00')['net']);
        $this->assertSame('15.00', ShiftFinancialCalculator::calculateVatInclusiveSales('115.00')['vat']);
    }

    public function test_fractional_tax_rounding_is_explicitly_marked_pending_d5(): void
    {
        $result = ShiftFinancialCalculator::calculate(10000, 0, 0, 0, 0);

        $this->assertTrue($result['roundingPendingD5']);
    }

    public function test_it_rejects_unsupported_legacy_precision(): void
    {
        $this->expectException(InvalidArgumentException::class);
        ShiftFinancialCalculator::sarToHalalas('1.001');
    }
}
