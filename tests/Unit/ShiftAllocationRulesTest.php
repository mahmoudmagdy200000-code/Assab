<?php

namespace Tests\Unit;

use InvalidArgumentException;
use Modules\Shift\Liability\AllocationRules;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ShiftAllocationRulesTest extends TestCase
{
    public function test_exact_split_keeps_integer_amounts_and_typed_identities(): void
    {
        $shares = AllocationRules::validate(-2000, [
            ['type' => 'cashier', 'id' => 'same-id', 'amount' => 1200],
            ['type' => 'branch_manager', 'id' => 'same-id', 'amount' => 800],
        ]);
        $this->assertSame(2000, array_sum(array_column($shares, 'amount')));
        $this->assertCount(2, $shares);
    }

    public static function invalidSplits(): array
    {
        return [
            'missing' => [-2000, []],
            'under' => [-2000, [['type' => 'cashier', 'id' => 'c', 'amount' => 1900]]],
            'over' => [-2000, [['type' => 'cashier', 'id' => 'c', 'amount' => 2100]]],
            'negative' => [-2000, [['type' => 'cashier', 'id' => 'c', 'amount' => -2000]]],
            'float' => [-2000, [['type' => 'cashier', 'id' => 'c', 'amount' => 2000.0]]],
            'unknown type' => [-2000, [['type' => 'accountant', 'id' => 'c', 'amount' => 2000]]],
            'class injection' => [-2000, [['type' => '\\App\\Models\\User', 'id' => 'c', 'amount' => 2000]]],
            'duplicate' => [-2000, [['type' => 'cashier', 'id' => 'c', 'amount' => 1000], ['type' => 'cashier', 'id' => 'c', 'amount' => 1000]]],
            'surplus liability' => [2000, [['type' => 'cashier', 'id' => 'c', 'amount' => 2000]]],
            'zero liability' => [0, [['type' => 'cashier', 'id' => 'c', 'amount' => 2000]]],
            'overflow' => [PHP_INT_MIN, []],
        ];
    }

    #[DataProvider('invalidSplits')]
    public function test_invalid_splits_are_rejected(int $variance, array $shares): void
    {
        $this->expectException(InvalidArgumentException::class);
        AllocationRules::validate($variance, $shares);
    }

    public function test_surplus_and_zero_have_no_employee_allocation(): void
    {
        $this->assertSame([], AllocationRules::validate(500, []));
        $this->assertSame([], AllocationRules::validate(0, []));
    }
}
