<?php

namespace Modules\Purchase\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Purchase\Enums\ReturnStatus;
use Modules\Purchase\Models\ReturnOrder;
use Modules\Purchase\Services\PurchaseAuthorizationService;

class ReturnOrderPolicy
{
    use HandlesAuthorization;

    private function authService(): PurchaseAuthorizationService
    {
        return app(PurchaseAuthorizationService::class);
    }

    /**
     * Determine whether the user can view any returns.
     */
    public function viewAny(Authenticatable $user): bool
    {
        return $this->authService()->ensureActiveBranchManager($user) !== null
            || $this->authService()->ensureActiveBrandOwner($user) !== null;
    }

    /**
     * Determine whether the user can view the return.
     */
    public function view(Authenticatable $user, ReturnOrder $return): bool
    {
        return $this->authService()->canViewReturnOrder($user, $return);
    }

    /**
     * Determine whether the user can create returns.
     */
    public function create(Authenticatable $user): bool
    {
        return $this->authService()->ensureActiveBranchManager($user) !== null;
    }

    /**
     * Determine whether the user can update the return.
     */
    public function update(Authenticatable $user, ReturnOrder $return): bool
    {
        return $this->authService()->canMutateReturnOrder($user, $return)
            && $return->status === ReturnStatus::DRAFT;
    }

    /**
     * Determine whether the user can delete the return.
     */
    public function delete(Authenticatable $user, ReturnOrder $return): bool
    {
        return $this->authService()->canMutateReturnOrder($user, $return)
            && $return->status === ReturnStatus::DRAFT;
    }

    /**
     * Determine whether the user can submit the return.
     */
    public function submit(Authenticatable $user, ReturnOrder $return): bool
    {
        return $this->authService()->canMutateReturnOrder($user, $return)
            && $return->status === ReturnStatus::DRAFT;
    }

    /**
     * Determine whether the user can escalate the return.
     */
    public function escalate(Authenticatable $user, ReturnOrder $return): bool
    {
        return $this->authService()->canMutateReturnOrder($user, $return)
            && $return->status === ReturnStatus::REJECTED;
    }

    /**
     * Branch-side mutation rights on a return regardless of its status (state rules stay in the service).
     */
    public function mutate(Authenticatable $user, ReturnOrder $return): bool
    {
        return $this->authService()->canMutateReturnOrder($user, $return);
    }

    /**
     * BrandOwner decision on an escalated return.
     */
    public function decideEscalation(Authenticatable $user, ReturnOrder $return): bool
    {
        return $this->authService()->canDecideEscalation($user, $return);
    }

    /**
     * Determine whether the user can accept rejection.
     */
    public function acceptRejection(Authenticatable $user, ReturnOrder $return): bool
    {
        return $this->authService()->canMutateReturnOrder($user, $return)
            && $return->status === ReturnStatus::REJECTED;
    }
}
