<?php

namespace Modules\Expense\Enums;

/**
 * Who performed the timeline action (expense_timelines.performed_by_type)
 */
enum ExpenseTimelinePerformedByType: string
{
    case BRANCH_MANAGER = 'branch_manager';
    case BRAND_OWNER = 'brand_owner';
    case SYSTEM = 'system';

    public function label(): string
    {
        return match ($this) {
            self::BRANCH_MANAGER => 'Branch Manager',
            self::BRAND_OWNER => 'Brand Owner',
            self::SYSTEM => 'System',
        };
    }

    /** @return array<int, array{value: string, label: string}> */
    public static function forApi(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[] = ['value' => $case->value, 'label' => $case->label()];
        }

        return $out;
    }
}
