<?php

namespace Modules\Expense\Enums;

use Modules\BrandOwner\Models\BrandManager;
use Modules\BrandOwner\Models\BrandOwner;

/**
 * Who made a mobile expense decision (`expenses.decided_by_role`, string(32)).
 *
 * `BrandManager` is a subclass of `BrandOwner`, so `instanceof BrandOwner` alone can
 * never tell the two apart — the role must be derived from the concrete class, and
 * recorded, so the audit trail does not attribute a manager's decision to an owner.
 *
 * The accountant / head roles (`accountant`, `head`) belong to the dashboard cycle and
 * are intentionally not cases here: they are produced by `ExpenseApprovalStage::actorRole()`.
 */
enum ExpenseDecisionRole: string
{
    case BRAND_OWNER = 'brand_owner';
    case BRAND_MANAGER = 'brand_manager';

    public static function forActor(object $actor): self
    {
        return $actor instanceof BrandManager ? self::BRAND_MANAGER : self::BRAND_OWNER;
    }

    /** Stored value -> role; anything unknown (legacy / dashboard roles) falls back to the owner. */
    public static function fromStored(?string $value): self
    {
        return self::tryFrom((string) $value) ?? self::BRAND_OWNER;
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::BRAND_OWNER => 'مالك العلامة التجارية',
            self::BRAND_MANAGER => 'مدير العلامة التجارية',
        };
    }

    /** The mobile account model that holds an actor of this role. */
    public function actorModel(): string
    {
        return match ($this) {
            self::BRAND_OWNER => BrandOwner::class,
            self::BRAND_MANAGER => BrandManager::class,
        };
    }
}
