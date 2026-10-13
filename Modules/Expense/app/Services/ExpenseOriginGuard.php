<?php

namespace Modules\Expense\Services;

use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Expense\Exceptions\UnresolvedExpenseOriginException;
use Modules\Expense\Models\Expense;

/**
 * The SINGLE validated origin guard for expenses entering the financially-
 * accountable cycle (pending / approved). It is invoked at every operational
 * writer path — the four create services (QuickCash / SingleInvoice /
 * GroupedInvoice / PreApproval), their draft->pending updates, and
 * submit / resubmit / approve.
 *
 * Policy (PKG-2 F-04, as directed): an expense may be CAPTURED as a draft with an
 * unresolved origin, but it may NOT become pending/approved unless its immutable
 * origin branch + brand + company are complete AND consistent. Ownership is never
 * guessed from a manager's current branch, and an invalid submission is never
 * silently converted into a draft — it is refused with a 4xx before any write,
 * event, timeline, or financial side effect. Legacy rows with a null/partial
 * origin stay fail-closed (surfaced read-only by the census, never auto-fixed).
 */
class ExpenseOriginGuard
{
    /**
     * Resolve the immutable origin triplet for a branch manager, exactly as the
     * Expense `creating` hook stamps it. Any element may be null when the branch
     * is not (fully) linked to the dashboard hierarchy.
     *
     * @return array{branch_id: ?string, brand_id: ?string, company_id: ?string}
     */
    public static function resolveForManager(?string $managerId): array
    {
        $empty = ['branch_id' => null, 'brand_id' => null, 'company_id' => null];

        if ($managerId === null) {
            return $empty;
        }

        $manager = BranchManager::withTrashed()->find($managerId);
        if ($manager === null || $manager->branch_id === null) {
            return $empty;
        }

        $branch = Branch::find($manager->branch_id);
        if ($branch === null) {
            return $empty;
        }

        $brandId = $branch->asab_brand_id;
        $companyId = $branch->asab_company_id;

        if ($brandId === null && $branch->asab_restaurant_id !== null) {
            $restaurant = AsabRestaurant::find($branch->asab_restaurant_id);
            $brandId = $restaurant?->brand_id;
            $companyId = $companyId ?? $restaurant?->company_id;
        }
        if ($companyId === null && $brandId !== null) {
            $companyId = AsabBrand::find($brandId)?->company_id;
        }

        return ['branch_id' => $branch->id, 'brand_id' => $brandId, 'company_id' => $companyId];
    }

    /**
     * Guard a NEW expense creation / a draft->pending flip at the acting manager's
     * branch. A no-op when the caller is only saving a draft. Throws before any
     * write when a submission cannot resolve a complete, consistent origin.
     */
    public static function assertResolvableForCreation(?string $managerId, bool $isDraft): void
    {
        if ($isDraft) {
            return;
        }

        $triplet = self::resolveForManager($managerId);

        if (! self::isComplete($triplet) || ! self::isConsistent($triplet)) {
            throw new UnresolvedExpenseOriginException(
                'This expense cannot be submitted: its branch is not fully linked to a brand and company '
                .'(unresolved ownership). Save it as a draft, or link the branch first.'
            );
        }
    }

    /**
     * Guard an EXISTING expense about to enter pending/approved (draft->pending
     * update on the record, submit, resubmit, approve). Validates the already
     * stamped, immutable origin for completeness AND consistency — catching
     * legacy/imported rows whose triplet is null or internally inconsistent.
     */
    public static function assertStoredOriginUsable(Expense $expense, string $action = 'advanced'): void
    {
        $triplet = [
            'branch_id' => $expense->origin_branch_id,
            'brand_id' => $expense->origin_asab_brand_id,
            'company_id' => $expense->origin_asab_company_id,
        ];

        if (! self::isComplete($triplet) || ! self::isConsistent($triplet)) {
            throw new UnresolvedExpenseOriginException(
                "This expense cannot be {$action}: its immutable origin (branch, brand, company) is "
                .'unresolved or inconsistent. Ownership is not guessed from the current branch; resolve it first.'
            );
        }
    }

    /** All three origin ids present. */
    private static function isComplete(array $triplet): bool
    {
        return $triplet['branch_id'] !== null
            && $triplet['brand_id'] !== null
            && $triplet['company_id'] !== null;
    }

    /**
     * The triplet hangs together: the recorded brand belongs to the recorded
     * company. A missing brand row or a brand/company mismatch is inconsistent.
     * Only reached once completeness holds.
     *
     * F04V3-01: this deliberately does NOT compare the branch's CURRENT
     * `asab_brand_id` to the recorded origin brand. The origin is an IMMUTABLE
     * snapshot captured at creation; a later, legitimate re-tag of the branch to
     * another brand must not retroactively invalidate an already-captured
     * expense (that would let a mutable current association block the original
     * brand owner from approving/resubmitting their own historical record). The
     * brand->company relation, by contrast, is immutable through every app path
     * (verified F-03), so it is a safe, stable internal-consistency check for the
     * stored snapshot. Creation-time validation still resolves fresh from the
     * live branch; this check runs for both and uses only the stable relation.
     */
    private static function isConsistent(array $triplet): bool
    {
        if (! self::isComplete($triplet)) {
            return false;
        }

        $brand = AsabBrand::find($triplet['brand_id']);
        if ($brand === null || (string) $brand->company_id !== (string) $triplet['company_id']) {
            return false;
        }

        return true;
    }
}
