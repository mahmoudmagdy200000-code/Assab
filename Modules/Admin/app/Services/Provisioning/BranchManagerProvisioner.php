<?php

namespace Modules\Admin\Services\Provisioning;

use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AsabIdentityMap;
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
    public function __construct(
        private readonly IdentityMapService $identity,
        private readonly \Modules\Admin\Services\ManagerRosterService $roster,
    ) {}

    public function roleKey(): string
    {
        return 'branch';
    }

    public function provision(AsabUser $user, array $data, string $temporaryPassword): void
    {
        $branchId = $this->tenantBranchId($user, $data['branches'][0]);
        $existing = $this->existingManager($user->email);

        $this->assertReusable($user, $existing);
        $this->assertUnclaimed($user, $existing);

        $manager = $this->ensureManager($user, $existing, $branchId, $temporaryPassword);

        $this->identity->linkBranchManager($user->id, $manager->id, $user->company_id, $user->email);

        // …and put them on the accountant's roster from day one. Before this the
        // only way a manager reached «كشف حساب الموظفين» was a manual
        // `asab:repair-employees` run (2026-08-10).
        $this->roster->sync($user->id, $branchId);
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
            // Two distinct causes share one error code (the published contract
            // names it), so details says which — the wizard omitting companyId
            // is by far the common one and has no field to highlight otherwise.
            $field = $user->company_id === null ? 'companyId' : 'branches';
            $reason = $user->company_id === null
                ? ['The user has no company; a branch manager must belong to one', 'يجب تحديد الشركة أولاً لإنشاء مدير فرع']
                : ['Branch does not belong to this user\'s company', 'الفرع لا ينتمي إلى شركة هذا المستخدم'];

            throw new AsabException(
                'BRANCH_NOT_IN_COMPANY',
                $reason[0],
                $reason[1],
                422,
                [$field => [$reason[1]]],
            );
        }

        return $branchId;
    }

    /**
     * withTrashed: branch_managers.email is unique regardless of deleted_at, so
     * a soft-deleted row still owns the address and create() would hit the index
     * instead of finding it.
     */
    private function existingManager(?string $email): ?BranchManager
    {
        return BranchManager::withTrashed()->where('email', $email)->first();
    }

    /**
     * Reusing a legacy row means overwriting its branch_id and password. Doing
     * that across companies hands one tenant's manager to another and locks the
     * original owner out — so an email that already belongs to a manager outside
     * this user's company is a 422, not a silent repoint.
     *
     * A manager whose branch is unassigned (branch_id null) or whose branch
     * carries no asab_company_id predates the dashboard and belongs to nobody;
     * adopting it is the intended migration path, so it passes.
     */
    private function assertReusable(AsabUser $user, ?BranchManager $manager): void
    {
        if ($manager === null || $manager->branch_id === null) {
            return;
        }

        $companyId = Branch::whereKey($manager->branch_id)->value('asab_company_id');

        if ($companyId !== null && $companyId !== $user->company_id) {
            throw new AsabException(
                'BRANCH_MANAGER_IN_OTHER_COMPANY',
                'This email already belongs to a branch manager in another company',
                'هذا البريد مسجّل بالفعل لمدير فرع في شركة أخرى',
                422,
            );
        }
    }

    /**
     * unique(entity_type, legacy_id) on asab_identity_map allows exactly one
     * dashboard login per legacy manager. Without this check the second login
     * for the same mobile row reaches the index as an uncaught QueryException —
     * a bare 500 the frontend renders as "cannot reach the server" — and
     * repointing the link instead would leave the first user's password
     * tracking nothing. Mirrors SupplierUserProvisioner::assertUnclaimed.
     */
    private function assertUnclaimed(AsabUser $user, ?BranchManager $manager): void
    {
        if ($manager === null) {
            return;
        }

        if ($this->identity->legacyClaimedByOther(AsabIdentityMap::ENTITY_BRANCH_MANAGER, $manager->id, $user->id)) {
            throw new AsabException(
                'BRANCH_MANAGER_LOGIN_AMBIGUOUS',
                'Another user already owns this branch manager\'s mobile login',
                'مستخدم آخر يملك بالفعل حساب الدخول لمدير الفرع هذا',
                422,
            );
        }
    }

    /**
     * The legacy row takes the emailed password outright — see
     * SupplierUserProvisioner::applyCredential for why copying a surviving hash
     * instead would email a password that opens the dashboard only.
     */
    private function ensureManager(AsabUser $user, ?BranchManager $manager, string $branchId, string $temporaryPassword): BranchManager
    {
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
