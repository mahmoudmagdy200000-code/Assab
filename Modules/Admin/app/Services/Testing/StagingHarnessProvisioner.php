<?php

namespace Modules\Admin\Services\Testing;

use Illuminate\Support\Facades\DB;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabRole;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\PermissionMatrixEntry;
use Modules\Admin\Services\MobileCashierMirrorService;
use Modules\Admin\Services\Provisioning\BranchManagerProvisioner;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use RuntimeException;

/**
 * Fixture-only staging bootstrap. It never creates financial transactions and
 * never updates a row that cannot be proven to belong to the requested fixture
 * namespace. The same service is used by isolated tests against disposable DBs.
 */
final class StagingHarnessProvisioner
{
    public const SCHEMA_VERSION = 1;

    private const REQUIRED_ROLES = ['company-admin', 'branch', 'accountant', 'head'];

    public function __construct(
        private readonly BranchManagerProvisioner $branchManagers,
        private readonly MobileCashierMirrorService $cashierMirror,
    ) {}

    /** @return array<string, mixed> */
    public function plan(string $namespace, ?array $profile = null): array
    {
        $this->assertNamespace($namespace);

        $companyId = $this->fixtureId($namespace, 'company');
        $brandId = $this->fixtureId($namespace, 'brand');
        $restaurantId = $this->fixtureId($namespace, 'restaurant');
        $branchId = $this->fixtureId($namespace, 'branch');
        $branchManagerId = $this->fixtureId($namespace, 'branch-manager');
        $shiftTemplateId = $this->fixtureId($namespace, 'shift-template');

        if ($profile === null) {
            $accounts = [
                'company_admin' => ['label' => 'Company Admin', 'roleKeys' => ['company-admin'], 'membershipRole' => 'company-admin'],
                'branch_manager' => ['label' => 'Branch Manager', 'roleKeys' => ['branch'], 'membershipRole' => 'branch'],
                'accountant' => ['label' => 'Accountant', 'roleKeys' => ['accountant'], 'membershipRole' => 'accountant'],
                'head_accountant' => ['label' => 'Head Accountant', 'roleKeys' => ['head'], 'membershipRole' => 'head'],
                'multi_role_finance' => ['label' => 'Multi-role Finance', 'roleKeys' => ['accountant', 'head'], 'membershipRole' => 'accountant'],
                'restricted' => ['label' => 'Restricted User', 'roleKeys' => [], 'membershipRole' => 'restricted'],
            ];

            foreach ($accounts as $key => &$account) {
                $account['id'] = $this->fixtureId($namespace, 'user:'.$key);
                $account['email'] = $namespace.'.'.str_replace('_', '-', $key).'@fixture.invalid';
                $account['authSurface'] = '/api/v1/auth/login';
            }
            unset($account);

            $cashier = [
                'id' => $this->fixtureId($namespace, 'cashier'),
                'label' => 'Cashier',
                'email' => $namespace.'.cashier@fixture.invalid',
                'roleKeys' => ['cashier-legacy-guard'],
                'authSurface' => '/api/v1/cashier/auth/login',
            ];

            $stableDefinition = [
                'schemaVersion' => self::SCHEMA_VERSION,
                'namespace' => $namespace,
                'marker' => $namespace.'@fixture.invalid',
                'companyId' => $companyId,
                'brandId' => $brandId,
                'restaurantId' => $restaurantId,
                'branchId' => $branchId,
                'branchManagerId' => $branchManagerId,
                'shiftTemplateId' => $shiftTemplateId,
                'accounts' => $accounts,
                'cashier' => $cashier,
            ];

            return $stableDefinition + [
                'definitionSha256' => hash('sha256', json_encode($stableDefinition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
                'safety' => [
                    'fixtureOnlyUpsert' => true,
                    'financialTransactionsCreated' => false,
                    'broadScopeAllGranted' => false,
                    'automaticCleanup' => false,
                    'plaintextCredentialIncluded' => false,
                ],
            ];
        }

        $roleMapping = [
            'company_admin' => ['roleKeys' => ['company-admin'], 'membershipRole' => 'company-admin'],
            'branch_manager' => ['roleKeys' => ['branch'], 'membershipRole' => 'branch'],
            'accountant' => ['roleKeys' => ['accountant'], 'membershipRole' => 'accountant'],
            'head_accountant' => ['roleKeys' => ['head'], 'membershipRole' => 'head'],
        ];

        $accounts = [];
        foreach ($profile['accounts'] ?? [] as $key => $accountDef) {
            if (! isset($roleMapping[$key])) {
                continue;
            }
            $accounts[$key] = [
                'id' => $this->fixtureId($namespace, 'user:'.$key),
                'label' => $accountDef['name'] ?? $key,
                'name' => $accountDef['name'] ?? $key,
                'email' => $accountDef['email'],
                'phone' => $accountDef['phone'] ?? null,
                'roleKeys' => $roleMapping[$key]['roleKeys'],
                'membershipRole' => $roleMapping[$key]['membershipRole'],
                'authSurface' => '/api/v1/auth/login',
            ];
        }

        $cashierDef = $profile['cashier'] ?? [];
        $cashier = [
            'id' => $this->fixtureId($namespace, 'cashier'),
            'label' => $cashierDef['name'] ?? 'Cashier',
            'name' => $cashierDef['name'] ?? 'Cashier',
            'email' => $cashierDef['email'] ?? ($namespace.'.cashier@fixture.invalid'),
            'phone' => $cashierDef['phone'] ?? null,
            'roleKeys' => ['cashier-legacy-guard'],
            'authSurface' => '/api/v1/cashier/auth/login',
        ];

        $marker = $profile['company']['contact_email'] ?? ($namespace.'@fixture.invalid');

        $stableDefinition = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'namespace' => $namespace,
            'marker' => $marker,
            'companyId' => $companyId,
            'brandId' => $brandId,
            'restaurantId' => $restaurantId,
            'branchId' => $branchId,
            'branchManagerId' => $branchManagerId,
            'shiftTemplateId' => $shiftTemplateId,
            'accounts' => $accounts,
            'cashier' => $cashier,
            'profile' => $profile,
        ];

        return $stableDefinition + [
            'definitionSha256' => hash('sha256', json_encode($stableDefinition, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES)),
            'safety' => [
                'fixtureOnlyUpsert' => true,
                'financialTransactionsCreated' => false,
                'broadScopeAllGranted' => false,
                'automaticCleanup' => false,
                'plaintextCredentialIncluded' => false,
            ],
        ];
    }

    /**
     * @return array<string, mixed> secret-free fixture manifest
     */
    public function provision(string $namespace, string $password, ?array $profile = null): array
    {
        if (strlen($password) < 12) {
            throw new RuntimeException('The runtime fixture password must contain at least 12 characters.');
        }

        $plan = $this->plan($namespace, $profile);
        $lockName = null;
        if (DB::connection()->getDriverName() === 'mysql') {
            $lockName = 'assab-harness-'.substr($plan['definitionSha256'], 0, 40);
            $lock = DB::selectOne('SELECT GET_LOCK(?, 15) AS acquired', [$lockName]);
            if ((int) ($lock->acquired ?? 0) !== 1) {
                throw new RuntimeException('Could not acquire the fixture namespace lock; no rows were changed.');
            }
        }

        try {
            return DB::transaction(function () use ($plan, $password): array {
                $this->assertRoleDefinitionsExist();
                $this->assertNoCollisions($plan);

                $company = $this->upsertCompany($plan);
                $brand = $this->upsertBrand($plan, $company);
                $restaurant = $this->upsertRestaurant($plan, $company, $brand);
                $branch = $this->upsertBranch($plan, $company, $brand, $restaurant);

                $users = [];
                foreach ($plan['accounts'] as $key => $definition) {
                    $users[$key] = $this->upsertUser($definition, $company, $password);
                    $this->upsertMembershipAndRoles($users[$key], $definition, $company, $brand, $restaurant, $branch);
                }

                $branchUser = $users['branch_manager'];
                $this->prepareBranchManager($plan, $branchUser, $branch, $password);
                $this->branchManagers->provision($branchUser, ['branches' => [$branch->id]], $password);
                $manager = BranchManager::withTrashed()
                    ->whereKey($plan['branchManagerId'])->where('email', $branchUser->email)->firstOrFail();
                $rosterEmployee = Employee::withoutGlobalScopes()
                    ->where('asab_user_id', $branchUser->id)
                    ->where('company_id', $company->id)
                    ->where('branch_id', $branch->id)
                    ->first();
                if ($rosterEmployee === null) {
                    throw new RuntimeException('Branch-manager roster provisioning did not complete; all fixture writes were rolled back.');
                }
                $branch->forceFill(['asab_manager_user_id' => $branchUser->id, 'manager' => $branchUser->name])->save();

                $cashier = $this->upsertCashier($plan['cashier'], $branch, $manager, $password);
                if (isset($plan['profile'])) {
                    $this->cashierMirror->mirror($cashier);
                }

                $shift = $this->upsertShiftTemplate($plan, $branch);

                $unstartedShift = null;
                if (! empty($plan['profile']['seed_unstarted_shift'])) {
                    $unstartedShift = $this->upsertUnstartedCashierShift($plan, $cashier, $shift);
                }

                $missingPermissionRows = collect(self::REQUIRED_ROLES)
                    ->filter(fn (string $role): bool => ! PermissionMatrixEntry::query()
                        ->whereNull('company_id')->where('role_key', $role)->exists())
                    ->values()->all();

                return $plan + [
                    'observedAt' => now()->toIso8601String(),
                    'legacyDependencies' => array_filter([
                        'branchManagerId' => $manager->id,
                        'branchManagerRosterEmployeeId' => $rosterEmployee->id,
                        'cashierId' => $cashier->id,
                        'shiftTemplateId' => $shift->id,
                        'unstartedCashierShiftId' => $unstartedShift?->id,
                    ]),
                    'permissionPolicy' => [
                        'createdRows' => 0,
                        'source' => 'existing platform/company permission matrix only',
                        'rolesWithoutPlatformDefaults' => $missingPermissionRows,
                    ],
                ];
            }, 3);
        } finally {
            if ($lockName !== null) {
                DB::selectOne('SELECT RELEASE_LOCK(?) AS released', [$lockName]);
            }
        }
    }

    private function assertNoCollisions(array $plan): void
    {
        foreach ($plan['accounts'] as $account) {
            $byEmail = AsabUser::withTrashed()->where('email', $account['email'])->first();
            if ($byEmail !== null && $byEmail->id !== $account['id']) {
                throw new RuntimeException("Fixture user email collision: refusing to modify non-fixture user {$account['email']}.");
            }
            if (! empty($account['phone'])) {
                $byPhone = AsabUser::withTrashed()->where('phone', $account['phone'])->first();
                if ($byPhone !== null && $byPhone->id !== $account['id']) {
                    throw new RuntimeException("Fixture user phone collision: refusing to modify non-fixture user phone {$account['phone']}.");
                }
            }
        }

        if (isset($plan['accounts']['branch_manager'])) {
            $bmDef = $plan['accounts']['branch_manager'];
            $byEmail = BranchManager::withTrashed()->where('email', $bmDef['email'])->first();
            if ($byEmail !== null && $byEmail->id !== $plan['branchManagerId']) {
                throw new RuntimeException("Fixture branch manager email collision: refusing to modify non-fixture branch manager {$bmDef['email']}.");
            }
            if (! empty($bmDef['phone'])) {
                $byPhone = BranchManager::withTrashed()->where('phone', $bmDef['phone'])->first();
                if ($byPhone !== null && $byPhone->id !== $plan['branchManagerId']) {
                    throw new RuntimeException("Fixture branch manager phone collision: refusing to modify non-fixture branch manager phone {$bmDef['phone']}.");
                }
            }
        }

        if (isset($plan['cashier'])) {
            $cashierDef = $plan['cashier'];
            $byEmail = Cashier::withTrashed()->where('email', $cashierDef['email'])->first();
            if ($byEmail !== null && $byEmail->id !== $cashierDef['id']) {
                throw new RuntimeException("Fixture cashier email collision: refusing to modify non-fixture cashier {$cashierDef['email']}.");
            }
            if (! empty($cashierDef['phone'])) {
                $byPhone = Cashier::withTrashed()->where('phone', $cashierDef['phone'])->first();
                if ($byPhone !== null && $byPhone->id !== $cashierDef['id']) {
                    throw new RuntimeException("Fixture cashier phone collision: refusing to modify non-fixture cashier phone {$cashierDef['phone']}.");
                }
            }
        }
    }

    /** @param array<string, mixed> $plan */
    private function prepareBranchManager(
        array $plan,
        AsabUser $user,
        Branch $branch,
        string $password,
    ): BranchManager {
        $byId = BranchManager::withTrashed()->whereKey($plan['branchManagerId'])->lockForUpdate()->first();
        $byEmail = BranchManager::withTrashed()->where('email', $user->email)->lockForUpdate()->first();
        if ($byId !== null && $byEmail !== null && ! $byId->is($byEmail)) {
            throw new RuntimeException('Fixture branch-manager namespace collision.');
        }
        $manager = $byId ?? $byEmail;
        if ($manager !== null && ($manager->id !== $plan['branchManagerId']
            || $manager->email !== $user->email || $manager->branch_id !== $branch->id)) {
            throw new RuntimeException('Refusing to modify a non-fixture branch manager.');
        }
        if ($manager === null) {
            $manager = new BranchManager;
            $manager->id = $plan['branchManagerId'];
        }
        if ($manager->trashed()) {
            $manager->restore();
        }
        $managerData = [
            'name' => $user->name,
            'email' => $user->email,
            'password' => $password,
            'branch_id' => $branch->id,
            'status' => 'active',
            'is_active' => true,
            'is_first_login' => true,
        ];
        if (! empty($user->phone)) {
            $managerData['phone'] = $user->phone;
            $managerData['phone_verified_at'] = now();
        }
        $manager->forceFill($managerData)->save();

        return $manager;
    }

    /** @param array<string, mixed> $plan */
    private function upsertCompany(array $plan): AsabCompany
    {
        $companyName = $plan['profile']['company']['name'] ?? ('[ASSAB HARNESS] '.$plan['namespace']);
        $contactName = $plan['profile']['company']['contact_name'] ?? 'ASSAB Harness Operator';
        $city = $plan['profile']['company']['city'] ?? 'Riyadh';

        $byId = AsabCompany::withTrashed()->whereKey($plan['companyId'])->lockForUpdate()->first();
        $markers = AsabCompany::withTrashed()->where('contact_email', $plan['marker'])->lockForUpdate()->get();
        if ($markers->count() > 1 || ($byId !== null && $markers->isNotEmpty() && ! $markers->first()->is($byId))) {
            throw new RuntimeException('Fixture company namespace is ambiguous; refusing to modify any company.');
        }

        $company = $byId ?? $markers->first();
        if ($company !== null && ($company->id !== $plan['companyId'] || $company->contact_email !== $plan['marker'])) {
            throw new RuntimeException('Fixture company identifier collides with a non-fixture record.');
        }

        if ($company !== null && $company->name !== $companyName) {
            throw new RuntimeException('Fixture company name differs from profile; refusing to modify under different values.');
        }

        if ($company === null) {
            $company = new AsabCompany;
            $company->id = $plan['companyId'];
        }
        if ($company->trashed()) {
            $company->restore();
        }
        $company->forceFill([
            'name' => $companyName,
            'contact_name' => $contactName,
            'contact_email' => $plan['marker'],
            'admin_email' => $plan['accounts']['company_admin']['email'],
            'city' => $city,
            'plan' => 'Basic',
            'status' => 'active',
            'max_branches' => 1,
            'max_users' => count($plan['accounts']),
            'monthly_revenue' => 0,
            'modules' => ['shifts'],
        ])->save();

        return $company;
    }

    /** @param array<string, mixed> $plan */
    private function upsertBrand(array $plan, AsabCompany $company): AsabBrand
    {
        $brandName = $plan['profile']['brand']['name'] ?? ('[HARNESS] '.$plan['namespace']);
        $brandAbbr = $plan['profile']['brand']['abbr'] ?? 'HARN';

        $brand = AsabBrand::withoutGlobalScopes()->withTrashed()->whereKey($plan['brandId'])->lockForUpdate()->first();
        if ($brand !== null && ($brand->company_id !== $company->id || $brand->name !== $brandName)) {
            throw new RuntimeException('Fixture brand identifier collides with a non-fixture record.');
        }
        if ($brand === null) {
            $brand = new AsabBrand;
            $brand->id = $plan['brandId'];
        }
        if ($brand->trashed()) {
            $brand->restore();
        }
        $brand->forceFill([
            'company_id' => $company->id,
            'name' => $brandName,
            'abbr' => $brandAbbr,
            'sub_status' => 'active',
            'status' => 'active',
            'modules' => ['shifts'],
        ])->save();

        return $brand;
    }

    /** @param array<string, mixed> $plan */
    private function upsertRestaurant(array $plan, AsabCompany $company, AsabBrand $brand): AsabRestaurant
    {
        $restaurantName = $plan['profile']['restaurant']['name'] ?? '[HARNESS] Restaurant';
        $city = $plan['profile']['restaurant']['city'] ?? 'Riyadh';

        $restaurant = AsabRestaurant::withoutGlobalScopes()->withTrashed()->whereKey($plan['restaurantId'])->lockForUpdate()->first();
        if ($restaurant !== null && ($restaurant->company_id !== $company->id || $restaurant->brand_id !== $brand->id
            || $restaurant->name !== $restaurantName)) {
            throw new RuntimeException('Fixture restaurant identifier collides with a non-fixture record.');
        }
        if ($restaurant === null) {
            $restaurant = new AsabRestaurant;
            $restaurant->id = $plan['restaurantId'];
        }
        if ($restaurant->trashed()) {
            $restaurant->restore();
        }
        $restaurant->forceFill([
            'company_id' => $company->id,
            'brand_id' => $brand->id,
            'name' => $restaurantName,
            'city' => $city,
            'accountant_count' => 2,
            'status' => 'active',
        ])->save();

        return $restaurant;
    }

    /** @param array<string, mixed> $plan */
    private function upsertBranch(array $plan, AsabCompany $company, AsabBrand $brand, AsabRestaurant $restaurant): Branch
    {
        $branchName = $plan['profile']['branch']['name'] ?? '[HARNESS] Branch';
        $branchLocation = $plan['profile']['branch']['location'] ?? 'Synthetic fixture only';
        $city = $plan['profile']['branch']['city'] ?? 'Riyadh';

        $branch = Branch::query()->whereKey($plan['branchId'])->lockForUpdate()->first();
        if ($branch !== null && ($branch->asab_company_id !== $company->id || $branch->asab_brand_id !== $brand->id
            || $branch->asab_restaurant_id !== $restaurant->id || $branch->name !== $branchName)) {
            throw new RuntimeException('Fixture branch identifier collides with a non-fixture record.');
        }
        if ($branch === null) {
            $branch = new Branch;
            $branch->id = $plan['branchId'];
        }
        $branch->forceFill([
            'name' => $branchName,
            'location' => $branchLocation,
            'city' => $city,
            'status' => 'active',
            'is_active' => true,
            'asab_company_id' => $company->id,
            'asab_brand_id' => $brand->id,
            'asab_restaurant_id' => $restaurant->id,
        ])->save();

        return $branch;
    }

    /** @param array<string, mixed> $definition */
    private function upsertUser(array $definition, AsabCompany $company, string $password): AsabUser
    {
        $byId = AsabUser::withTrashed()->whereKey($definition['id'])->lockForUpdate()->first();
        $byEmail = AsabUser::withTrashed()->where('email', $definition['email'])->lockForUpdate()->first();
        if ($byId !== null && $byEmail !== null && ! $byId->is($byEmail)) {
            throw new RuntimeException("Fixture user namespace collision for {$definition['email']}.");
        }
        $user = $byId ?? $byEmail;
        if ($user !== null && ($user->id !== $definition['id'] || $user->company_id !== $company->id
            || $user->email !== $definition['email'])) {
            throw new RuntimeException("Refusing to modify non-fixture user {$definition['email']}.");
        }

        if ($user === null) {
            $user = new AsabUser;
            $user->id = $definition['id'];
        }
        if ($user->trashed()) {
            $user->restore();
        }
        $userName = $definition['name'] ?? ('[HARNESS] '.$definition['label']);
        $userData = [
            'company_id' => $company->id,
            'name' => $userName,
            'email' => $definition['email'],
            'password' => $password,
            'status' => 'active',
            'default_page' => $this->defaultPage($definition['membershipRole']),
        ];
        if (! empty($definition['phone'])) {
            $userData['phone'] = $definition['phone'];
        }
        $user->forceFill($userData)->save();
        $user->tokens()->delete();

        return $user;
    }

    /**
     * @param  array<string, mixed>  $definition
     */
    private function upsertMembershipAndRoles(
        AsabUser $user,
        array $definition,
        AsabCompany $company,
        AsabBrand $brand,
        AsabRestaurant $restaurant,
        Branch $branch,
    ): void {
        $membership = CompanyUser::withoutGlobalScopes()
            ->where('company_id', $company->id)->where('user_id', $user->id)->lockForUpdate()->first();
        if ($membership === null) {
            $membership = new CompanyUser;
            $membership->id = $this->fixtureId($company->contact_email, 'membership:'.$user->id);
        }
        $membership->forceFill([
            'company_id' => $company->id,
            'user_id' => $user->id,
            'role_key' => $definition['membershipRole'],
            'brand_id' => $brand->id,
            'branch_id' => $definition['membershipRole'] === 'branch' ? $branch->id : null,
            'status' => 'active',
            'accepted_at' => $membership->accepted_at ?? now(),
        ])->save();

        if ($definition['roleKeys'] === []) {
            if ($user->roleAssignments()->exists()) {
                throw new RuntimeException('Restricted fixture user unexpectedly has a role assignment; refusing implicit revocation.');
            }

            return;
        }

        if ($user->roleAssignments()->whereNotIn('role_key', $definition['roleKeys'])->exists()) {
            throw new RuntimeException("Fixture user {$definition['email']} has an unexpected role; refusing implicit revocation.");
        }

        foreach ($definition['roleKeys'] as $roleKey) {
            $matches = AsabUserRole::query()->where('user_id', $user->id)->where('role_key', $roleKey)->lockForUpdate()->get();
            if ($matches->count() > 1) {
                throw new RuntimeException("Duplicate fixture role assignment detected for {$definition['email']} / {$roleKey}.");
            }
            $assignment = $matches->first();
            if ($assignment === null) {
                $assignment = new AsabUserRole;
                $assignment->id = $this->fixtureId($company->contact_email, 'role:'.$user->id.':'.$roleKey);
            }
            $scope = $this->scopeFor($roleKey, $brand, $restaurant, $branch);
            $assignment->forceFill(['user_id' => $user->id, 'role_key' => $roleKey] + $scope)->save();
        }
    }

    /** @param array<string, mixed> $definition */
    private function upsertCashier(array $definition, Branch $branch, BranchManager $manager, string $password): Cashier
    {
        $byId = Cashier::withTrashed()->whereKey($definition['id'])->lockForUpdate()->first();
        $byEmail = Cashier::withTrashed()->where('email', $definition['email'])->lockForUpdate()->first();
        if ($byId !== null && $byEmail !== null && ! $byId->is($byEmail)) {
            throw new RuntimeException('Fixture cashier namespace collision.');
        }
        $cashier = $byId ?? $byEmail;
        if ($cashier !== null && ($cashier->id !== $definition['id'] || $cashier->branch_id !== $branch->id
            || $cashier->email !== $definition['email'])) {
            throw new RuntimeException('Refusing to modify a non-fixture cashier.');
        }
        if ($cashier === null) {
            $cashier = new Cashier;
            $cashier->id = $definition['id'];
        }
        if ($cashier->trashed()) {
            $cashier->restore();
        }
        $cashierName = $definition['name'] ?? '[HARNESS] Cashier';
        $cashierData = [
            'name' => $cashierName,
            'email' => $definition['email'],
            'password' => $password,
            'branch_id' => $branch->id,
            'created_by' => $manager->id,
            'status' => 'active',
            'activated_at' => $cashier->activated_at ?? now(),
            'deactivated_at' => null,
        ];
        if (! empty($definition['phone'])) {
            $cashierData['phone'] = $definition['phone'];
        }
        $cashier->forceFill($cashierData)->save();
        $cashier->tokens()->delete();

        return $cashier;
    }

    /** @param array<string, mixed> $plan */
    private function upsertShiftTemplate(array $plan, Branch $branch): Shift
    {
        $shiftName = $plan['profile']['shift_template']['name'] ?? '[HARNESS] 08:00-16:00';
        $startTime = $plan['profile']['shift_template']['start_time'] ?? '08:00:00';
        $endTime = $plan['profile']['shift_template']['end_time'] ?? '16:00:00';

        $byId = Shift::query()->whereKey($plan['shiftTemplateId'])->lockForUpdate()->first();
        $timeConflict = Shift::query()->where('branch_id', $branch->id)
            ->where('start_time', $startTime)->where('end_time', $endTime)->lockForUpdate()->first();
        if ($byId !== null && $timeConflict !== null && ! $byId->is($timeConflict)) {
            throw new RuntimeException('Fixture shift schedule collides with an existing row; refusing to modify it.');
        }
        $shift = $byId ?? $timeConflict;
        if ($shift !== null && ($shift->id !== $plan['shiftTemplateId'] || $shift->branch_id !== $branch->id
            || $shift->name !== $shiftName)) {
            throw new RuntimeException('Refusing to modify a non-fixture shift schedule.');
        }
        if ($shift === null) {
            $shift = new Shift;
            $shift->id = $plan['shiftTemplateId'];
        }
        $shift->forceFill([
            'name' => $shiftName,
            'start_time' => $startTime,
            'end_time' => $endTime,
            'branch_id' => $branch->id,
            'is_active' => true,
            'opening_float' => '0.00',
        ])->save();

        return $shift;
    }

    /** @param array<string, mixed> $plan */
    private function upsertUnstartedCashierShift(array $plan, Cashier $cashier, Shift $shift): CashierShift
    {
        $shiftDate = $plan['profile']['shift_date'] ?? now()->toDateString();
        $shiftId = $this->fixtureId($plan['namespace'], 'cashier-shift:'.$shiftDate);

        $existing = CashierShift::query()->whereKey($shiftId)->lockForUpdate()->first();
        $conflict = CashierShift::query()
            ->where('cashier_id', $cashier->id)
            ->where('shift_id', $shift->id)
            ->whereDate('shift_date', $shiftDate)
            ->lockForUpdate()
            ->first();

        if ($existing !== null && $conflict !== null && ! $existing->is($conflict)) {
            throw new RuntimeException('Fixture cashier shift collides with an existing shift record.');
        }

        $cashierShift = $existing ?? $conflict;
        if ($cashierShift !== null && ($cashierShift->id !== $shiftId || $cashierShift->cashier_id !== $cashier->id)) {
            throw new RuntimeException('Refusing to modify a non-fixture cashier shift.');
        }

        if ($cashierShift === null) {
            $cashierShift = new CashierShift;
            $cashierShift->id = $shiftId;
        }

        if ($cashierShift->exists && $cashierShift->status !== ShiftStatus::NOT_STARTED && $cashierShift->status !== 'not_started') {
            return $cashierShift;
        }

        $cashierShift->forceFill([
            'cashier_id' => $cashier->id,
            'shift_id' => $shift->id,
            'shift_date' => $shiftDate,
            'status' => 'not_started',
            'opening_balance' => '0.00',
            'closing_balance' => '0.00',
            'expected_balance' => '0.00',
            'variance' => '0.00',
            'total_sales' => '0.00',
            'net_sales' => '0.00',
            'vat_amount' => '0.00',
            'cash_collected' => '0.00',
            'card_payments' => '0.00',
        ])->save();

        return $cashierShift;
    }

    private function assertRoleDefinitionsExist(): void
    {
        $present = AsabRole::query()->whereIn('key', self::REQUIRED_ROLES)->pluck('key')->all();
        $missing = array_values(array_diff(self::REQUIRED_ROLES, $present));
        if ($missing !== []) {
            throw new RuntimeException('Required canonical role definitions are missing: '.implode(', ', $missing).'. No seeder was run automatically.');
        }
    }

    /** @return array<string, mixed> */
    private function scopeFor(string $roleKey, AsabBrand $brand, AsabRestaurant $restaurant, Branch $branch): array
    {
        return match ($roleKey) {
            'company-admin', 'head' => [
                'scope' => 'brand', 'brand_ids' => [$brand->id],
                'restaurant_ids' => [$restaurant->id], 'branch_ids' => [$branch->id], 'module_keys' => [],
            ],
            'accountant' => [
                'scope' => 'restaurant', 'brand_ids' => [$brand->id],
                'restaurant_ids' => [$restaurant->id], 'branch_ids' => [$branch->id], 'module_keys' => [],
            ],
            'branch' => [
                'scope' => 'branch', 'brand_ids' => [],
                'restaurant_ids' => [], 'branch_ids' => [$branch->id], 'module_keys' => [],
            ],
            default => throw new RuntimeException("Unsupported fixture role '{$roleKey}'."),
        };
    }

    private function defaultPage(string $role): string
    {
        return match ($role) {
            'company-admin' => '/company/me/dashboard',
            'head' => '/company/me/head/dashboard',
            'accountant' => '/company/me/accountant/dashboard',
            'branch' => '/company/me/branch/overview',
            default => '/auth/me',
        };
    }

    private function assertNamespace(string $namespace): void
    {
        if (preg_match('/^[a-z0-9][a-z0-9-]{2,47}$/', $namespace) !== 1) {
            throw new RuntimeException('Fixture namespace must be 3-48 lowercase alphanumeric/hyphen characters.');
        }
    }

    private function fixtureId(string $namespace, string $entity): string
    {
        $hex = sha1('https://assab.example/fixture/'.$namespace.'/'.$entity);
        $hex[12] = '5';
        $hex[16] = dechex((hexdec($hex[16]) & 0x3) | 0x8);

        return sprintf('%s-%s-%s-%s-%s',
            substr($hex, 0, 8), substr($hex, 8, 4), substr($hex, 12, 4),
            substr($hex, 16, 4), substr($hex, 20, 12),
        );
    }
}
