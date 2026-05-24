<?php

namespace Modules\FixedAssets\Enums;

enum RequestStatus: string
{
    case PENDING = 'pending';
    case PENDING_FINAL_APPROVAL = 'pending_final_approval';
    case APPROVED = 'approved';
    case REJECTED = 'rejected';
    case SALARY_DEDUCTION = 'salary_deduction';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => 'Pending',
            self::PENDING_FINAL_APPROVAL => 'Pending Final Approval',
            self::APPROVED => 'Approved',
            self::REJECTED => 'Rejected',
            self::SALARY_DEDUCTION => 'Salary Deduction',
        };
    }

    public static function values(): array
    {
        return array_map(fn ($c) => $c->value, self::cases());
    }
}
