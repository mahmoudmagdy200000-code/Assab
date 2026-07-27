<?php

namespace Modules\Notification\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\LazyCollection;
use Modules\Admin\Models\AsabIdentityMap;
use Modules\Admin\Models\AsabUser;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\Cashier\Models\Cashier;
use Modules\Supplier\Models\SupplierUser;

class AudienceRepository implements AudienceRepositoryInterface
{
    /** ASAB dashboard roles are addressed as `asab:{role_key}`. */
    private const ASAB_PREFIX = 'asab:';

    /**
     * Legacy per-type logins, with the predicate that means "this account can
     * still receive work". The two worlds disagree on the column, so each model
     * carries its own.
     *
     * @var array<string, array{class-string<Model>, callable}>
     */
    private const LEGACY_ROLES = [
        'cashier' => [Cashier::class, 'activeByStatus'],
        'branch_manager' => [BranchManager::class, 'activeByStatus'],
        'brand_owner' => [BrandOwner::class, 'activeByFlag'],
        'supplier' => [SupplierUser::class, 'activeByFlag'],
        'supplier_user' => [SupplierUser::class, 'activeByFlag'],
    ];

    /**
     * The identity map keys both sides by a short slug, NOT by class name — see
     * IdentityMapService::link(). These two tables are the translation.
     *
     * @var array<class-string<Model>, string>
     */
    private const IDENTITY_SLUGS = [
        AsabUser::class => 'asab_user',
        Cashier::class => 'cashier',
        BranchManager::class => 'branch_manager',
        BrandOwner::class => 'brand_owner',
        \Modules\Supplier\Models\Supplier::class => 'supplier',
    ];

    /** @var array<string, class-string<Model>> */
    private const IDENTITY_MODELS = [
        'asab_user' => AsabUser::class,
        'cashier' => Cashier::class,
        'branch_manager' => BranchManager::class,
        'brand_owner' => BrandOwner::class,
        'supplier' => \Modules\Supplier\Models\Supplier::class,
    ];

    /**
     * Entity types whose two sides are both logins that can own a device.
     *
     * @var array<int, string>
     */
    private const LOGIN_ENTITY_TYPES = [
        AsabIdentityMap::ENTITY_BRAND_OWNER,
        AsabIdentityMap::ENTITY_SUPPLIER_USER,
        AsabIdentityMap::ENTITY_BRANCH_MANAGER,
    ];

    public function withRole(string $role, ?string $branchId = null): LazyCollection
    {
        if (str_starts_with($role, self::ASAB_PREFIX)) {
            return $this->asabUsersWithRole(substr($role, strlen(self::ASAB_PREFIX)), $branchId);
        }

        $definition = self::LEGACY_ROLES[$role] ?? null;

        if ($definition === null) {
            return LazyCollection::empty();
        }

        [$class, $activeScope] = $definition;

        $query = $class::query()->select($this->columnsFor($class));

        $this->{$activeScope}($query);

        if ($branchId !== null && $this->hasColumn($class, 'branch_id')) {
            $query->where('branch_id', $branchId);
        }

        return $query->cursor();
    }

    public function inBranch(string $branchId): LazyCollection
    {
        return LazyCollection::make(function () use ($branchId) {
            foreach (['cashier', 'branch_manager'] as $role) {
                foreach ($this->withRole($role, $branchId) as $recipient) {
                    yield $recipient;
                }
            }

            $query = AsabUser::query()
                ->select($this->columnsFor(AsabUser::class))
                ->where('status', 'active');

            if ($this->hasColumn(AsabUser::class, 'branch_id')) {
                $query->where('branch_id', $branchId);
            } else {
                // The unified user has no branch column; branch scoping lives in
                // the role assignment's `branch_ids` JSON array.
                $query->whereHas('roleAssignments', fn (Builder $inner) => $inner->whereJsonContains('branch_ids', $branchId));
            }

            foreach ($query->cursor() as $recipient) {
                yield $recipient;
            }
        });
    }

    public function linkedIdentities(object $notifiable): array
    {
        if (! $notifiable instanceof Model) {
            return [];
        }

        $slug = self::IDENTITY_SLUGS[$notifiable::class] ?? null;
        $key = (string) $notifiable->getKey();

        if ($slug === null || $key === '') {
            return [];
        }

        $rows = AsabIdentityMap::query()
            ->select(['entity_type', 'dashboard_type', 'dashboard_id', 'legacy_type', 'legacy_id'])
            // Only login↔login pairings. ENTITY_CASHIER maps to an
            // `asab_employee` HR record and ENTITY_SUPPLIER to the commercial
            // `asab_supplier` row — neither can hold a device, so following them
            // would just cost a lookup.
            ->whereIn('entity_type', self::LOGIN_ENTITY_TYPES)
            ->where(function (Builder $query) use ($slug, $key) {
                $query->where(fn (Builder $q) => $q->where('dashboard_type', $slug)->where('dashboard_id', $key))
                    ->orWhere(fn (Builder $q) => $q->where('legacy_type', $slug)->where('legacy_id', $key));
            })
            ->get();

        $linked = [];

        foreach ($rows as $row) {
            $isDashboardSide = $row->dashboard_type === $slug && (string) $row->dashboard_id === $key;

            $targetSlug = $isDashboardSide ? $row->legacy_type : $row->dashboard_type;
            $targetId = $isDashboardSide ? $row->legacy_id : $row->dashboard_id;
            $targetClass = self::IDENTITY_MODELS[$targetSlug] ?? null;

            if ($targetClass === null || ! $targetId) {
                continue;
            }

            $model = $targetClass::query()->find($targetId);

            // Never return the origin account itself: a self-referential row
            // would double-send to the same devices.
            if ($model !== null && ! ($model::class === $notifiable::class && (string) $model->getKey() === $key)) {
                $linked[] = $model;
            }
        }

        return $linked;
    }

    private function asabUsersWithRole(string $roleKey, ?string $branchId): LazyCollection
    {
        return AsabUser::query()
            ->select($this->columnsFor(AsabUser::class))
            ->where('status', 'active')
            ->whereHas('roleAssignments', function (Builder $query) use ($roleKey, $branchId) {
                $query->where('role_key', $roleKey);

                if ($branchId !== null) {
                    // `scope = all` grants every branch; otherwise the
                    // assignment must name this branch explicitly.
                    $query->where(function (Builder $scoped) use ($branchId) {
                        $scoped->where('scope', 'all')
                            ->orWhereJsonContains('branch_ids', $branchId);
                    });
                }
            })
            ->cursor();
    }

    private function activeByStatus(Builder $query): void
    {
        $query->where('status', 'active');
    }

    private function activeByFlag(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * Only the columns the notification pipeline reads. Selecting `*` here would
     * hydrate avatars, encrypted 2FA secrets and permission blobs for every
     * recipient of a company-wide broadcast.
     *
     * @param  class-string<Model>  $class
     * @return array<int, string>
     */
    private function columnsFor(string $class): array
    {
        $columns = ['id', 'name', 'email', 'phone'];

        foreach (['branch_id', 'company_id', 'status', 'is_active'] as $optional) {
            if ($this->hasColumn($class, $optional)) {
                $columns[] = $optional;
            }
        }

        return $columns;
    }

    /**
     * @param  class-string<Model>  $class
     */
    private function hasColumn(string $class, string $column): bool
    {
        static $cache = [];

        $model = new $class;
        $table = $model->getTable();

        $cache[$table] ??= $model->getConnection()->getSchemaBuilder()->getColumnListing($table);

        return in_array($column, $cache[$table], true);
    }
}
