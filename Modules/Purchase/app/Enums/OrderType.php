<?php

namespace Modules\Purchase\Enums;

enum OrderType: string
{
    case DIRECT_SUPPLIER = 'direct_supplier';
    case VIA_PURCHASING_OFFICER = 'via_purchasing_officer';
    case INTERNAL_TRANSFER = 'internal_transfer';
    case MULTIPLE_SOURCES = 'multiple_sources';
    case TRANSFER_RECEIVED = 'transfer_received';

    public function label(): string
    {
        return match ($this) {
            self::DIRECT_SUPPLIER => 'Direct Supplier Order',
            self::VIA_PURCHASING_OFFICER => 'Via Purchasing Officer',
            self::INTERNAL_TRANSFER => 'Internal Transfer from Another Branch',
            self::MULTIPLE_SOURCES => 'Multiple Orders from Different Sources',
            self::TRANSFER_RECEIVED => 'Transfer Received from Another Branch',
        };
    }

    public function shortLabel(): string
    {
        return match ($this) {
            self::DIRECT_SUPPLIER => 'Direct Supplier',
            self::VIA_PURCHASING_OFFICER => 'Via PO',
            self::INTERNAL_TRANSFER => 'Internal Transfer',
            self::MULTIPLE_SOURCES => 'Multiple Sources',
            self::TRANSFER_RECEIVED => 'Transfer Received',
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::DIRECT_SUPPLIER => 'supplier',
            self::VIA_PURCHASING_OFFICER => 'officer',
            self::INTERNAL_TRANSFER => 'transfer',
            self::MULTIPLE_SOURCES => 'multiple',
            self::TRANSFER_RECEIVED => 'received',
        };
    }

    /**
     * Check if type requires supplier
     */
    public function requiresSupplier(): bool
    {
        return in_array($this, [
            self::DIRECT_SUPPLIER,
            self::VIA_PURCHASING_OFFICER,
        ]);
    }

    /**
     * Check if type is internal transfer
     */
    public function isTransfer(): bool
    {
        return in_array($this, [
            self::INTERNAL_TRANSFER,
            self::TRANSFER_RECEIVED,
        ]);
    }

    /**
     * Check if type has cost
     */
    public function hasCost(): bool
    {
        return ! $this->isTransfer();
    }

    /**
     * Get OrderType from label (case-insensitive)
     *
     * @param  string  $label  The label to search for
     * @return OrderType|null Returns the matching OrderType or null if not found
     */
    public static function fromLabel(string $label): ?OrderType
    {
        $label = trim($label);

        // Try to match by label (case-insensitive)
        foreach (self::cases() as $case) {
            if (strcasecmp($case->label(), $label) === 0) {
                return $case;
            }
        }

        // Try to match by short label (case-insensitive)
        foreach (self::cases() as $case) {
            if (strcasecmp($case->shortLabel(), $label) === 0) {
                return $case;
            }
        }

        // Try to match by enum value (case-insensitive)
        try {
            return self::from(strtolower($label));
        } catch (\ValueError $e) {
            // Try to match common variations
            $normalizedLabel = strtolower(str_replace([' ', '-', '_'], '', $label));
            foreach (self::cases() as $case) {
                $normalizedCaseLabel = strtolower(str_replace([' ', '-', '_'], '', $case->label()));
                if ($normalizedCaseLabel === $normalizedLabel) {
                    return $case;
                }
            }
        }

        return null;
    }
}
