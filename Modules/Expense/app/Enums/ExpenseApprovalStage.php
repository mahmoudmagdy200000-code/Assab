<?php

namespace Modules\Expense\Enums;

/**
 * Where an expense sits in the approval chain (expenses.approval_stage).
 *
 * NULL = fresh «pending»: nobody has acted, so BOTH the brand owner (mobile)
 * and the accountant (dashboard) may take it. The first actor decides which of
 * the two cycles the record follows:
 *
 *   brand owner   → BRAND_OWNER_APPROVED | BRAND_OWNER_REJECTED   (terminal)
 *   accountant    → ACCOUNTANT_APPROVED  → head → HEAD_APPROVED   (terminal)
 *                                                → RETURNED_TO_ACCOUNTANT
 *                 → ACCOUNTANT_REJECTED                           (terminal)
 *
 * A terminal stage locks the record for everyone; RETURNED_TO_ACCOUNTANT puts
 * it back in the accountant's queue («pending» again on the mobile side).
 */
enum ExpenseApprovalStage: string
{
    case BRAND_OWNER_APPROVED = 'brand_owner_approved';
    case BRAND_OWNER_REJECTED = 'brand_owner_rejected';
    case ACCOUNTANT_APPROVED = 'accountant_approved';
    case ACCOUNTANT_REJECTED = 'accountant_rejected';
    case HEAD_APPROVED = 'head_approved';
    case HEAD_REJECTED = 'head_rejected';
    case RETURNED_TO_ACCOUNTANT = 'returned_to_accountant';

    public function label(): string
    {
        return match ($this) {
            self::BRAND_OWNER_APPROVED => 'Approved by the brand owner',
            self::BRAND_OWNER_REJECTED => 'Rejected by the brand owner',
            self::ACCOUNTANT_APPROVED => 'Approved by the accountant — awaiting the head of accounts',
            self::ACCOUNTANT_REJECTED => 'Rejected by the accountant',
            self::HEAD_APPROVED => 'Final-approved by the head of accounts',
            self::HEAD_REJECTED => 'Rejected by the head of accounts',
            self::RETURNED_TO_ACCOUNTANT => 'Returned by the head of accounts for the accountant to review',
        };
    }

    public function labelAr(): string
    {
        return match ($this) {
            self::BRAND_OWNER_APPROVED => 'موافق عليه من مالك العلامة التجارية',
            self::BRAND_OWNER_REJECTED => 'مرفوض من مالك العلامة التجارية',
            self::ACCOUNTANT_APPROVED => 'موافق عليه من المحاسب — بانتظار رئيس الحسابات',
            self::ACCOUNTANT_REJECTED => 'مرفوض من المحاسب',
            self::HEAD_APPROVED => 'معتمد نهائياً من رئيس الحسابات',
            self::HEAD_REJECTED => 'مرفوض من رئيس الحسابات',
            self::RETURNED_TO_ACCOUNTANT => 'أعادها رئيس الحسابات لمراجعة المحاسب',
        };
    }

    /** The role that produced the stage (`decided_by_role`). */
    public function actorRole(): string
    {
        return match ($this) {
            self::BRAND_OWNER_APPROVED, self::BRAND_OWNER_REJECTED => 'brand_owner',
            self::ACCOUNTANT_APPROVED, self::ACCOUNTANT_REJECTED => 'accountant',
            self::HEAD_APPROVED, self::HEAD_REJECTED, self::RETURNED_TO_ACCOUNTANT => 'head',
        };
    }

    /** No further action is possible by anyone. */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::BRAND_OWNER_APPROVED,
            self::BRAND_OWNER_REJECTED,
            self::ACCOUNTANT_REJECTED,
            self::HEAD_APPROVED,
            self::HEAD_REJECTED,
        ], true);
    }

    /** The dashboard (accountant/head) owns the record from here on. */
    public function isAccountingCycle(): bool
    {
        return $this->actorRole() !== 'brand_owner';
    }

    public static function tryFromValue(?string $value): ?self
    {
        return $value === null ? null : self::tryFrom($value);
    }

    /** @return array<int, array{value: string, label: string, labelAr: string}> */
    public static function forApi(): array
    {
        $out = [];
        foreach (self::cases() as $case) {
            $out[] = ['value' => $case->value, 'label' => $case->label(), 'labelAr' => $case->labelAr()];
        }

        return $out;
    }
}
