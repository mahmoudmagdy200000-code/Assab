<?php

namespace Tests\Unit;

use App\Support\ShiftFinancialCalculator;
use InvalidArgumentException;
use Modules\Shift\Liability\AllocationRules;
use PHPUnit\Framework\TestCase;

/**
 * BRC v3.4 AC-44 / DIFF-07 — a shortage of 0.01 SAR (1 halala) is recorded in full and must be assigned in full:
 * no tolerance, no rounding away. Unit level (integer halalas); the HTTP path is covered for 20.00 SAR by AC-01.
 */
class ShiftOneHalalaShortageTest extends TestCase
{
    public function test_a_one_halala_shortage_is_a_shortage_not_balanced(): void
    {
        // The approved vector (expected cash 50.00) with 49.99 counted.
        $r = ShiftFinancialCalculator::calculate(11500, 5000, 2500, 1000, 4999);

        $this->assertSame(5000, $r['expected']);
        $this->assertSame(-1, $r['variance']);
        $this->assertSame(1, $r['shortage']);
        $this->assertSame(0, $r['surplus']);
        $this->assertSame('shortage', $r['varianceType']);
    }

    public function test_it_must_be_fully_assigned_and_nothing_less_is_tolerated(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('ALLOCATION_INCOMPLETE');
        AllocationRules::validate(-1, []);
    }

    public function test_exactly_one_halala_assigned_to_one_actor_is_accepted(): void
    {
        $shares = AllocationRules::validate(-1, [['type' => 'cashier', 'id' => 'c-1', 'amount' => 1]]);

        $this->assertSame([['type' => 'cashier', 'id' => 'c-1', 'amount' => 1]], $shares);
    }

    public function test_more_than_the_shortage_and_a_zero_share_are_refused(): void
    {
        foreach ([[['type' => 'cashier', 'id' => 'c-1', 'amount' => 2]], [['type' => 'cashier', 'id' => 'c-1', 'amount' => 0]]] as $shares) {
            try {
                AllocationRules::validate(-1, $shares);
                $this->fail('expected a refusal');
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }
}
