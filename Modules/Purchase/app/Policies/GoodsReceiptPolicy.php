<?php

namespace Modules\Purchase\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Purchase\Models\GoodsReceipt;
use Modules\Purchase\Services\PurchaseAuthorizationService;

class GoodsReceiptPolicy
{
    use HandlesAuthorization;

    private function authService(): PurchaseAuthorizationService
    {
        return app(PurchaseAuthorizationService::class);
    }

    /**
     * Determine whether the user can view any receipts.
     */
    public function viewAny(Authenticatable $user): bool
    {
        return $this->authService()->ensureActiveBranchManager($user) !== null;
    }

    /**
     * Determine whether the user can view the receipt.
     */
    public function view(Authenticatable $user, GoodsReceipt $receipt): bool
    {
        return $this->authService()->canAccessGoodsReceipt($user, $receipt);
    }

    /**
     * Determine whether the user can create receipts.
     */
    public function create(Authenticatable $user): bool
    {
        return $this->authService()->ensureActiveBranchManager($user) !== null;
    }

    /**
     * Determine whether the user can update the receipt.
     */
    public function update(Authenticatable $user, GoodsReceipt $receipt): bool
    {
        return $this->authService()->canAccessGoodsReceipt($user, $receipt)
            && in_array($receipt->status, ['draft', 'in_progress'], true);
    }

    /**
     * Determine whether the user can delete the receipt.
     */
    public function delete(Authenticatable $user, GoodsReceipt $receipt): bool
    {
        return $this->authService()->canAccessGoodsReceipt($user, $receipt)
            && $receipt->status === 'draft';
    }

    /**
     * Determine whether the user can complete the receipt.
     */
    public function complete(Authenticatable $user, GoodsReceipt $receipt): bool
    {
        return $this->authService()->canAccessGoodsReceipt($user, $receipt)
            && $receipt->status === 'in_progress';
    }
}
