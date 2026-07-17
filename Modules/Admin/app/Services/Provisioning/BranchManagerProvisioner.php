<?php

namespace Modules\Admin\Services\Provisioning;

use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Services\IdentityMapService;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;

/**
 * Gives a role=branch dashboard user the matching mobile login. This is the
 * first production path that creates a branch_managers row — the table was
 * login-capable but reachable only from seeders, which is why the migration
 * called branch_manager "intentionally out of v1". The client reversed that.
 *
 * No id translation is needed: there is no asab_branches table, so
 * AsabUserRole.branch_ids[0] IS a branches.id and drops straight into the
 * branch_managers.branch_id FK.
 */
class BranchManagerProvisioner implements LegacyProvisioner
{
    public function __construct(private readonly IdentityMapService $identity) {}

    public function roleKey(): string
    {
        return 'branch';
    }

    public function provision(AsabUser $user, array $data, string $temporaryPassword): void
    {
        $branchId = $this->tenantBranchId($user, $data['branches'][0]);
        $manager = $this->ensureManager($user, $branchId, $temporaryPassword);

        $this->identity->linkBranchManager($user->id, $manager->id, $user->company_id, $user->email);
    }

    /**
     * Zero-trust: store() validates `branches` for existence only, and until now
     * nothing consumed it as a foreign key. branch_managers.branch_id is a real
     * FK, so an unchecked id would be either a 500 or a genuine cross-tenant
     * assignment. A companyless user can never match (fail closed).
     */
    private function tenantBranchId(AsabUser $user, string $branchId): string
    {
        $ownsBranch = $user->company_id !== null && Branch::where('id', $branchId)
            ->where('asab_company_id', $user->company_id)
            ->exists();

        if (! $ownsBranch) {
            throw new AsabException(
                'BRANCH_NOT_IN_COMPANY',
                'Branch does not belong to this user\'s company',
                'الفرع لا ينتمي إلى شركة هذا المستخدم',
                422,
            );
        }

        return $branchId;
    }

    /**
     * The legacy row takes the emailed password outright — see
     * SupplierUserProvisioner::applyCredential for why copying a surviving hash
     * instead would email a password that opens the dashboard only.
     */
    private function ensureManager(AsabUser $user, string $branchId, string $temporaryPassword): BranchManager
    {
        // withTrashed: branch_managers.email is unique regardless of deleted_at,
        // so a soft-deleted row still owns the address and create() would hit
        // the index instead of finding it.
        $manager = BranchManager::withTrashed()->where('email', $user->email)->first();

        $attributes = [
            'name' => $user->name,
            'password' => $temporaryPassword, // hashed by the model's 'hashed' cast
            'branch_id' => $branchId,
            'status' => 'active',
            'is_active' => true,
            'is_first_login' => true,
        ];

        if ($manager === null) {
            return BranchManager::create($attributes + [
                'email' => $user->email,
                'phone' => $this->availablePhone($user->phone),
            ]);
        }

        if ($manager->trashed()) {
            $manager->restore();
        }

        $manager->forceFill($attributes + [
            'phone' => $this->availablePhone($user->phone, $manager->id),
        ])->save();
        $manager->tokens()->delete();

        return $manager;
    }

    /**
     * branch_managers.phone is UNIQUE (unlike suppliers.phone) — drop the phone
     * rather than fail the whole user creation over a duplicate contact number.
     */
    private function availablePhone(?string $phone, ?string $exceptId = null): ?string
    {
        if ($phone === null || $phone === '') {
            return null;
        }

        $taken = BranchManager::withTrashed()
            ->where('phone', $phone)
            ->when($exceptId !== null, fn ($q) => $q->where('id', '!=', $exceptId))
            ->exists();

        return $taken ? null : $phone;
    }
}
