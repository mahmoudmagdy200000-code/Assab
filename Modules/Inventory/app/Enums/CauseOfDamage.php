<?php

namespace Modules\Inventory\Enums;

enum CauseOfDamage: string
{
    case I_WAS_RESPONSIBLE = 'i_was_responsible';
    case ME_AND_OR_OTHER_EMPLOYEES = 'me_and_or_other_employees';
    case OTHER_FACTOR = 'other_factor';
    case MIXED_FACTORS = 'mixed_factors';

    public function label(): string
    {
        return match ($this) {
            self::I_WAS_RESPONSIBLE => 'I Was Responsible',
            self::ME_AND_OR_OTHER_EMPLOYEES => 'Me And/Or Other Employee(s)',
            self::OTHER_FACTOR => 'Other Factor',
            self::MIXED_FACTORS => 'Mixed Factors',
        };
    }

    /**
     * Whether this cause requires responsible employee entries.
     */
    public function requiresResponsibleEmployees(): bool
    {
        return in_array($this, [
            self::I_WAS_RESPONSIBLE,
            self::ME_AND_OR_OTHER_EMPLOYEES,
        ], true);
    }
}
