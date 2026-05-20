<?php

namespace Modules\Inventory\Support;

use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;

/**
 * Value object representing the authenticated inventory actor (Branch Manager or Cashier).
 * Used to scope data and authorize actions across inventory controllers.
 */
final readonly class InventoryActor
{
    public function __construct(
        private BranchManager|Cashier $actor,
    ) {}

    public function getActor(): BranchManager|Cashier
    {
        return $this->actor;
    }

    public function getBranchId(): ?string
    {
        return $this->actor->branch_id ?? null;
    }

    public function isManager(): bool
    {
        return $this->actor instanceof BranchManager;
    }

    public function isCashier(): bool
    {
        return $this->actor instanceof Cashier;
    }

    public function getManager(): ?BranchManager
    {
        return $this->actor instanceof BranchManager ? $this->actor : null;
    }

    public function getCashier(): ?Cashier
    {
        return $this->actor instanceof Cashier ? $this->actor : null;
    }

    public function getActorId(): string
    {
        return $this->actor->getKey();
    }

    /**
     * Resolve the current authenticated user as an InventoryActor.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException when user is not BranchManager or Cashier
     */
    public static function resolve(): self
    {
        $user = auth()->user();
        if ($user instanceof BranchManager || $user instanceof Cashier) {
            return new self($user);
        }
        throw new \Illuminate\Auth\Access\AuthorizationException(
            'Authenticated user must be a branch manager or cashier.'
        );
    }

    /**
     * Require the actor to be a Branch Manager. Use for manager-only endpoints.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException when user is not BranchManager
     */
    public function requireManager(): BranchManager
    {
        if ($this->actor instanceof BranchManager) {
            return $this->actor;
        }
        throw new \Illuminate\Auth\Access\AuthorizationException('Branch manager required.');
    }

    /**
     * Require the actor to be a Cashier. Use for cashier-only logic.
     *
     * @throws \Illuminate\Auth\Access\AuthorizationException when user is not Cashier
     */
    public function requireCashier(): Cashier
    {
        if ($this->actor instanceof Cashier) {
            return $this->actor;
        }
        throw new \Illuminate\Auth\Access\AuthorizationException('Cashier required.');
    }
}
