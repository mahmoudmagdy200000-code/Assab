<?php

namespace Modules\Purchase\Services;

use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Services\MobileBranchScopeService;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\Expense\Services\SupplierBrandScopeService;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Enums\ReturnStatus;
use Modules\Purchase\Models\GoodsReceipt;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseVariance;
use Modules\Purchase\Models\ReturnOrder;
use Modules\Supplier\Models\Supplier;

/**
 * Action-specific authorization and tenant/branch ownership checks for Purchase
 * and RecurringOrder endpoints.
 *
 * Fail-closed: unlinked, inactive, suspended, terminated, or cross-role actors
 * resolve to empty branch sets and `false` authorization results.
 */
class PurchaseAuthorizationService
{
    private const BLOCKED_STATUSES = ['suspended', 'inactive', 'terminated'];

    private const ASAB_BRANCH_ROLE_KEYS = ['branch', 'branch_manager', 'branch-manager'];

    public function __construct(
        private readonly MobileBranchScopeService $branchScope,
        private readonly SupplierBrandScopeService $supplierScope,
    ) {}

    /**
     * Active branch-level actor: a BranchManager, or an AsabUser with an explicit
     * branch-manager role whose assigned `branch_id` is inside its authorized set.
     */
    public function ensureActiveBranchManager(mixed $user): ?object
    {
        if ($user instanceof AsabUser) {
            return ! empty($user->branch_id)
                && in_array((string) $user->branch_id, $this->resolveAsabBranchIds($user), true)
                ? $user
                : null;
        }

        if (! $user instanceof BranchManager) {
            return null;
        }

        if (! $user->is_active || (method_exists($user, 'isSuspended') && $user->isSuspended())) {
            return null;
        }

        if (in_array($user->status, self::BLOCKED_STATUSES, true) || empty($user->branch_id)) {
            return null;
        }

        return $user;
    }

    public function ensureActiveSupplier(mixed $user): ?Supplier
    {
        if (! $user instanceof Supplier) {
            return null;
        }

        if (! $user->is_active || in_array($user->status, self::BLOCKED_STATUSES, true)) {
            return null;
        }

        return $user;
    }

    public function ensureActiveBrandOwner(mixed $user): ?BrandOwner
    {
        if (! $user instanceof BrandOwner) {
            return null;
        }

        if (! $user->is_active || (method_exists($user, 'isSuspended') && $user->isSuspended())) {
            return null;
        }

        if (in_array($user->status, self::BLOCKED_STATUSES, true)) {
            return null;
        }

        return $user;
    }

    /**
     * Branch IDs the authenticated actor may operate on.
     *
     * @return string[]
     */
    public function resolveActingBranchIds(mixed $user, bool $allowAsabBranchRole = false): array
    {
        if ($user instanceof BranchManager) {
            $manager = $this->ensureActiveBranchManager($user);
            if ($manager === null || ! Branch::whereKey($manager->branch_id)->exists()) {
                return [];
            }

            return [(string) $manager->branch_id];
        }

        if ($user instanceof AsabUser) {
            return $this->resolveAsabBranchIds($user);
        }

        return [];
    }

    /**
     * Branches an AsabUser may act on: active account, own company, active company
     * membership, explicit branch-manager role, branches inside that company.
     *
     * @return string[]
     */
    private function resolveAsabBranchIds(AsabUser $user): array
    {
        if ($user->status !== 'active' || empty($user->company_id)) {
            return [];
        }

        $hasActiveMembership = CompanyUser::where('user_id', $user->id)
            ->where('company_id', $user->company_id)
            ->where('status', 'active')
            ->exists();
        if (! $hasActiveMembership) {
            return [];
        }

        $candidate = AsabUserRole::where('user_id', $user->id)
            ->whereIn('role_key', self::ASAB_BRANCH_ROLE_KEYS)
            ->pluck('branch_ids')
            ->flatten()
            ->filter()
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->values()
            ->all();

        if ($candidate === []) {
            return [];
        }

        // Only branches that belong to the actor's own company.
        return Branch::whereIn('id', $candidate)
            ->where('asab_company_id', $user->company_id)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->values()
            ->all();
    }

    public function resolvePrimaryBranchId(mixed $user, bool $allowAsabBranchRole = false): ?string
    {
        return $this->resolveActingBranchIds($user, $allowAsabBranchRole)[0] ?? null;
    }

    public function canAccessPurchaseOrder(mixed $user, PurchaseOrder $order, bool $allowAsabBranchRole = false): bool
    {
        $branchIds = $this->resolveActingBranchIds($user, $allowAsabBranchRole);
        if ($branchIds === []) {
            return false;
        }

        if (in_array((string) $order->branch_id, $branchIds, true)) {
            return true;
        }

        return $order->order_type === OrderType::INTERNAL_TRANSFER
            && $order->from_branch_id !== null
            && in_array((string) $order->from_branch_id, $branchIds, true);
    }

    /**
     * Only the receiving branch may start receiving or receive a purchase order / transfer.
     */
    public function canReceivePurchaseOrder(mixed $user, PurchaseOrder $order, bool $allowAsabBranchRole = true): bool
    {
        $branchIds = $this->resolveActingBranchIds($user, $allowAsabBranchRole);

        return $branchIds !== [] && in_array((string) $order->branch_id, $branchIds, true);
    }

    /**
     * Only the sending branch (`from_branch_id`) manager may approve/partial-approve/reject an Internal Transfer.
     */
    public function canDecideInternalTransfer(mixed $user, PurchaseOrder $order): bool
    {
        $manager = $this->ensureActiveBranchManager($user);
        if ($manager === null) {
            return false;
        }

        return $order->order_type === OrderType::INTERNAL_TRANSFER
            && $order->from_branch_id !== null
            && (string) $manager->branch_id === (string) $order->from_branch_id;
    }

    public function canReceiveInternalTransfer(mixed $user, PurchaseOrder $order, bool $allowAsabBranchRole = true): bool
    {
        if ($order->order_type !== OrderType::INTERNAL_TRANSFER) {
            return false;
        }

        return $this->canReceivePurchaseOrder($user, $order, $allowAsabBranchRole);
    }

    public function canAccessGoodsReceipt(mixed $user, GoodsReceipt $receipt, bool $allowAsabBranchRole = false): bool
    {
        $branchIds = $this->resolveActingBranchIds($user, $allowAsabBranchRole);

        return $branchIds !== [] && in_array((string) $receipt->branch_id, $branchIds, true);
    }

    public function canAccessVariance(mixed $user, PurchaseVariance $variance): bool
    {
        $branchIds = $this->resolveActingBranchIds($user);
        if ($branchIds === []) {
            return false;
        }

        $variance->loadMissing(['goodsReceipt', 'purchaseOrder']);
        $branchId = $variance->goodsReceipt?->branch_id ?? $variance->purchaseOrder?->branch_id;

        return $branchId !== null && in_array((string) $branchId, $branchIds, true);
    }

    public function canRespondToVariance(mixed $user, PurchaseVariance $variance): bool
    {
        $supplier = $this->ensureActiveSupplier($user);
        if ($supplier === null) {
            return false;
        }

        $variance->loadMissing('purchaseOrder');
        $orderSupplierId = $variance->purchaseOrder?->supplier_id;

        return $orderSupplierId !== null && (string) $orderSupplierId === (string) $supplier->id;
    }

    public function canViewReturnOrder(mixed $user, ReturnOrder $return): bool
    {
        if ($owner = $this->ensureActiveBrandOwner($user)) {
            $allowed = [
                ReturnStatus::ESCALATED,
                ReturnStatus::ESCALATED_RESOLVED,
                ReturnStatus::ESCALATED_REJECTED,
            ];
            if (! in_array($return->status, $allowed, true)) {
                return false;
            }

            return in_array((string) $return->branch_id, $this->branchScope->visibleBranchIds($owner), true);
        }

        $branchIds = $this->resolveActingBranchIds($user);

        return $branchIds !== [] && in_array((string) $return->branch_id, $branchIds, true);
    }

    public function canMutateReturnOrder(mixed $user, ReturnOrder $return): bool
    {
        $branchIds = $this->resolveActingBranchIds($user);

        return $branchIds !== [] && in_array((string) $return->branch_id, $branchIds, true);
    }

    public function canAccessSupplier(mixed $user, string $supplierId): bool
    {
        $manager = $this->ensureActiveBranchManager($user);
        if ($manager === null) {
            return false;
        }

        return in_array($supplierId, $this->supplierScope->orderableSupplierIds((string) $manager->branch_id), true);
    }

    public function canAccessPeerBranch(mixed $user, ?string $targetBranchId): bool
    {
        $manager = $this->ensureActiveBranchManager($user);
        if ($manager === null || ! $targetBranchId) {
            return false;
        }

        $callerBranch = Branch::whereKey($manager->branch_id)->first(['id', 'asab_brand_id', 'asab_company_id']);
        $targetBranch = Branch::whereKey($targetBranchId)->first(['id', 'asab_brand_id', 'asab_company_id']);

        if ($callerBranch === null || $targetBranch === null) {
            return false;
        }

        if ($callerBranch->asab_brand_id !== null) {
            return (string) $targetBranch->asab_brand_id === (string) $callerBranch->asab_brand_id;
        }

        if ($callerBranch->asab_company_id !== null) {
            return (string) $targetBranch->asab_company_id === (string) $callerBranch->asab_company_id;
        }

        return false;
    }

    /**
     * BrandOwner escalation decision: active owner, return branch inside the owner's
     * scope, and (when a real BrandOwner recipient was recorded) that owner is the recipient.
     * A legacy placeholder/unresolvable recipient falls back to branch scope only.
     */
    public function canDecideEscalation(mixed $user, ReturnOrder $return): bool
    {
        $owner = $this->ensureActiveBrandOwner($user);
        if ($owner === null) {
            return false;
        }

        if (! in_array((string) $return->branch_id, $this->branchScope->visibleBranchIds($owner), true)) {
            return false;
        }

        $recipient = (string) ($return->escalated_to ?? '');
        if ($recipient !== '' && $recipient === (string) $owner->id) {
            return true;
        }

        return $recipient === '' || ! BrandOwner::whereKey($recipient)->exists();
    }

    /**
     * Whether `$recipientId` is an active BrandOwner whose scope covers `$branchId`.
     */
    public function isValidEscalationRecipient(string $recipientId, string $branchId): bool
    {
        $owner = BrandOwner::whereKey($recipientId)->first();
        if ($owner === null || $this->ensureActiveBrandOwner($owner) === null) {
            return false;
        }

        return in_array($branchId, $this->branchScope->visibleBranchIds($owner), true);
    }

    /**
     * T3.5 / PUR-F15: Resolve the default active BrandOwner covering $branchId.
     */
    public function resolveDefaultBrandOwner(string $branchId): ?string
    {
        $branch = Branch::whereKey($branchId)->first();
        if ($branch === null) {
            return null;
        }

        $activeOwners = BrandOwner::where('status', 'active')
            ->where('is_active', true)
            ->get();
        foreach ($activeOwners as $owner) {
            if ($this->isValidEscalationRecipient((string) $owner->id, $branchId)) {
                return (string) $owner->id;
            }
        }

        return null;
    }

    /**
     * Validate that a purchasing officer belongs to a branch visible to `$branchId`.
     */
    public function canUsePurchasingOfficer(string $branchId, string $officerId): bool
    {
        $branch = Branch::whereKey($branchId)->first();
        if ($branch === null) {
            return false;
        }

        $officer = \Modules\BranchManagers\Models\BranchManager::whereKey($officerId)->first();
        if ($officer === null || ! $officer->is_active || empty($officer->branch_id)) {
            return false;
        }

        $officerBranch = Branch::whereKey($officer->branch_id)->first(['id', 'asab_brand_id', 'asab_company_id']);
        if ($officerBranch === null) {
            return false;
        }

        if ($branch->asab_brand_id !== null) {
            return (string) $officerBranch->asab_brand_id === (string) $branch->asab_brand_id;
        }

        return $branch->asab_company_id !== null
            && (string) $officerBranch->asab_company_id === (string) $branch->asab_company_id;
    }

    /**
     * Active `Item` IDs allowed for a target branch (optionally scoped to a supplier catalog).
     *
     * @param  string[]  $itemIds
     * @throws \Illuminate\Validation\ValidationException
     */
    public function assertItemsAllowedForWrite(string $branchId, array $itemIds, ?string $supplierId = null): void
    {
        $ids = array_values(array_unique(array_filter(array_map('strval', $itemIds))));
        if ($ids === []) {
            return;
        }

        $activeIds = \Modules\Purchase\Models\Item::whereIn('id', $ids)
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn ($id) => (string) $id)
            ->all();

        if (count($activeIds) !== count($ids)) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'items' => ['One or more selected items are inactive or do not exist.'],
            ]);
        }

        if ($supplierId !== null && $supplierId !== '') {
            $hasSupplierCatalog = \Modules\Purchase\Models\SupplierItem::where('supplier_id', $supplierId)->exists()
                || \Modules\Supplier\Models\SupplierProduct::where('supplier_id', $supplierId)->exists();

            if ($hasSupplierCatalog) {
                $allowed = collect()
                    ->merge(\Modules\Purchase\Models\SupplierItem::where('supplier_id', $supplierId)->whereIn('item_id', $ids)->pluck('item_id'))
                    ->merge(\Modules\Supplier\Models\SupplierProduct::where('supplier_id', $supplierId)->whereIn('item_id', $ids)->pluck('item_id'))
                    ->map(fn ($id) => (string) $id)
                    ->unique()
                    ->all();

                if (array_diff($ids, $allowed) !== []) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'items' => ['One or more items do not belong to the selected supplier catalog.'],
                    ]);
                }

                return;
            }
        }

        $branchAllowed = collect()
            ->merge(\Modules\Purchase\Models\BranchItem::where('branch_id', $branchId)->whereIn('item_id', $ids)->pluck('item_id'))
            ->merge(\Modules\Purchase\Models\BranchInventory::where('branch_id', $branchId)->whereIn('item_id', $ids)->pluck('item_id'))
            ->map(fn ($id) => (string) $id)
            ->unique()
            ->all();

        if (array_diff($ids, $branchAllowed) !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'items' => ['One or more items are not available in the selected branch catalog.'],
            ]);
        }
    }

    public function canAccessVisibleSupplier(mixed $user, string $supplierId): bool
    {
        $branchIds = $this->resolveActingBranchIds($user, true);
        if ($branchIds === []) {
            return false;
        }

        foreach ($branchIds as $branchId) {
            if (in_array($supplierId, $this->supplierScope->visibleSupplierIds($branchId), true)) {
                return true;
            }
        }

        return false;
    }
}
