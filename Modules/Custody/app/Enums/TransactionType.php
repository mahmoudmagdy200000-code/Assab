<?php

namespace Modules\Custody\Enums;

/**
 * Enum representing transaction types for Personal Ledger Transactions
 */
enum TransactionType: string
{
    case TOTAL_SALES = 'Total Sales';
    case HANDOVER_TO_BRAND_OWNER = 'Handover to Brand Owner';
    case TRANSFER_TO_CUSTODY = 'Transfer to Custody';

    /**
     * Get human-readable label for the transaction type
     */
    public function label(): string
    {
        return match($this) {
            self::TOTAL_SALES => 'Total Sales',
            self::HANDOVER_TO_BRAND_OWNER => 'Handover to Brand Owner',
            self::TRANSFER_TO_CUSTODY => 'Transfer to Custody',
        };
    }

    /**
     * Check if this transaction type is cash in (money coming in)
     */
    public function isCashIn(): bool
    {
        return $this === self::TOTAL_SALES;
    }

    /**
     * Check if this transaction type is cash out (money going out)
     */
    public function isCashOut(): bool
    {
        return !$this->isCashIn();
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
            fn($type) => $type->isCashIn()
        );
    }

    /**
     * Get all cash out transaction types
     */
    public static function cashOutTypes(): array
    {
        return array_filter(
            self::cases(),
            fn($type) => $type->isCashOut()
        );
    }
}
