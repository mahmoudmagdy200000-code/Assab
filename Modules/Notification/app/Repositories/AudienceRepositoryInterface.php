<?php

namespace Modules\Notification\Repositories;

use Illuminate\Support\LazyCollection;

interface AudienceRepositoryInterface
{
    /**
     * Active recipients holding a role.
     *
     * Roles are namespaced because the two worlds overlap: `supplier` is a
     * legacy supplier login, `asab:supplier` is the dashboard role. Streamed
     * lazily — a company-wide broadcast must not materialise every user.
     *
     * @return LazyCollection<int, object>
     */
    public function withRole(string $role, ?string $branchId = null): LazyCollection;

    /**
     * Every active recipient attached to a branch, across all models.
     *
     * @return LazyCollection<int, object>
     */
    public function inBranch(string $branchId): LazyCollection;

    /**
     * The same human's accounts in the other world, resolved through the ASAB
     * identity map, so one notification reaches every device they own.
     *
     * @return array<int, object>
     */
    public function linkedIdentities(object $notifiable): array;
}
