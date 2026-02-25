<?php

namespace Modules\Inventory\Enums;

enum WasteDamageReason: string
{
    // Wasted Reasons
    case PRODUCTION_ERROR = 'production_error';
    case PORTION_HANDLING_ISSUE = 'portion_handling_issue';
    case ORDER_CANCELLATION = 'order_cancellation';
    case OPERATIONAL_MISMANAGEMENT = 'operational_mismanagement';

    // Damage Reasons
    case EXPIRED_PRODUCT = 'expired_product';
    case STORAGE_DAMAGE = 'storage_damage';
    case SUPPLIER_ISSUE = 'supplier_issue';
    case EQUIPMENT_FAILURE = 'equipment_failure';

    case OTHER = 'other';

    public function label(): string
    {
        return match ($this) {
            self::PRODUCTION_ERROR => 'Production Error',
            self::PORTION_HANDLING_ISSUE => 'Portion Handling Issue',
            self::ORDER_CANCELLATION => 'Order Cancellation',
            self::OPERATIONAL_MISMANAGEMENT => 'Operational Mismanagement',
            self::EXPIRED_PRODUCT => 'Expired Product',
            self::STORAGE_DAMAGE => 'Storage Damage',
            self::SUPPLIER_ISSUE => 'Supplier Issue',
            self::EQUIPMENT_FAILURE => 'Equipment Failure',
            self::OTHER => 'Other',
        };
    }

    /**
     * All values for validation and dropdowns.
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
