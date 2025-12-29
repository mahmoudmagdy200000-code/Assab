<?php

namespace Modules\Purchase\Enums;

enum ModificationType: string
{
    case NEW_QUANTITY = 'new_quantity';
    case NEED_TIME = 'need_time';
    case ALTERNATIVE_PRODUCT = 'alternative_product';

    public function label(): string
    {
        return match ($this) {
            self::NEW_QUANTITY => 'New Quantity',
            self::NEED_TIME => 'Need Time',
            self::ALTERNATIVE_PRODUCT => 'Alternative Product',
        };
    }

    /**
     * Map to approval_type value in database
     */
    public function toApprovalType(): string
    {
        return match ($this) {
            self::NEW_QUANTITY => 'partial',
            self::NEED_TIME => 'time_change',
            self::ALTERNATIVE_PRODUCT => 'alternative',
        };
    }

    /**
     * Create from approval_type value
     */
    public static function fromApprovalType(string $approvalType): ?self
    {
        return match ($approvalType) {
            'partial' => self::NEW_QUANTITY,
            'time_change' => self::NEED_TIME,
            'alternative' => self::ALTERNATIVE_PRODUCT,
            default => null,
        };
    }
}

