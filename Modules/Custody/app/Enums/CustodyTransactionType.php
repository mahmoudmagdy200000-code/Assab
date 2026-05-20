<?php

namespace Modules\Custody\Enums;

/**
 * Enum representing transaction types for Custody Transactions
 */
enum CustodyTransactionType: string
{
    case CASH_TRANSFER = 'Cash Transfer';
    case CASH_HANDOVER = 'Cash Handover';
    case BANK_TRANSFER = 'Bank Transfer';
    case EXPENSES_DEDUCTION = 'Expenses Deduction';

    /**
     * Get human-readable label for the transaction type
     */
    public function label(): string
    {
        return match ($this) {
            self::CASH_TRANSFER => 'Cash Transfer',
            self::CASH_HANDOVER => 'Cash Handover',
            self::BANK_TRANSFER => 'Bank Transfer',
            self::EXPENSES_DEDUCTION => 'Expenses Deduction',
        };
    }

    /**
     * Check if this transaction type is cash in (money coming in)
     */
    public function isCashIn(): bool
    {
        return in_array($this, [
            self::CASH_TRANSFER,
            self::CASH_HANDOVER,
            self::BANK_TRANSFER,
        ]);
    }

    /**
     * Check if this transaction type is cash out (money going out)
     */
    public function isCashOut(): bool
    {
        return ! $this->isCashIn();
    }

    /**
     * Check if this transaction type requires handover details
     */
    public function requiresHandoverDetails(): bool
    {
        return in_array($this, [
            self::CASH_HANDOVER,
            self::BANK_TRANSFER,
        ]);
    }

    /**
     * Check if this transaction type can be linked to an expense
     */
    public function canLinkToExpense(): bool
    {
        return $this === self::EXPENSES_DEDUCTION;
    }

    /**
     * Check if this transaction type can be linked to a custody request
     */
    public function canLinkToCustodyRequest(): bool
    {
        return in_array($this, [
            self::CASH_HANDOVER,
            self::BANK_TRANSFER,
        ]);
    }

    /**
     * Get all transaction types as array
     */
    public static function all(): array
    {
        return array_column(self::cases(), 'value');
    }

    /**
     * Get all cash in transaction types
     */
    public static function cashInTypes(): array
    {
        return array_filter(
            self::cases(),
            fn ($type) => $type->isCashIn()
        );
    }

    /**
     * Get all cash out transaction types
     */
    public static function cashOutTypes(): array
    {
        return array_filter(
            self::cases(),
            fn ($type) => $type->isCashOut()
        );
    }
}
