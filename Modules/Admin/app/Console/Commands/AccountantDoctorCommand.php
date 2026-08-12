<?php

namespace Modules\Admin\Console\Commands;

use Illuminate\Console\Command;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Services\TenantBranchResolver;
use Modules\Admin\Services\TenantCompanyResolver;
use Modules\Admin\Support\TenantContext;
use Modules\Branch\Models\Branch;

/**
 * «خصّصنا المطعم للمحاسب ولم يظهر في داشبورده» (2026-08-11).
 *
 * A dashboard assignment reaches the accountant's screens only if EVERY link
 * in this chain holds, and a break in any one of them looks identical from the
 * portal — an empty list, no error:
 *
 *   asab_user_roles.{brand_ids, restaurant_ids, branch_ids}   (the assignment)
 *        ↓ TenantCompanyResolver
 *   the companies those ids live in  ──  «العلامة هي الشركة»: a brand added by
 *        ↓                               the admin gets a COMPANY OF ITS OWN,
 *   asab_users.company_id                so the accountant's own company_id is
 *        ↓                               not enough on its own
 *   brands ∩ those companies  →  branches  →  every scoped read
 *
 * Read-only.
 */
class AccountantDoctorCommand extends Command
{
    protected $signature = 'asab:accountant-doctor
        {--email= : The accountant login email}
        {--user= : …or their user id}';

    protected $description = 'Explain why an assigned brand/restaurant does not show on an accountant dashboard';

    public function handle(TenantCompanyResolver $companies, TenantBranchResolver $branches): int
    {
        $user = $this->target();
        if ($user === null) {
            $this->error('No user matched --email/--user.');

            return self::FAILURE;
        }

        $role = AsabUserRole::where('user_id', $user->id)->first();

        $this->newLine();
        $this->info("━━ {$user->name}  <{$user->email}>");
        $this->row('company_id على الحساب', $user->company_id ?? '✗ فارغ');

        if ($role === null) {
            $this->row('الدور', '✗ لا يوجد دور — الحساب بلا صلاحيات');
            $this->line('  → أسند دوراً للمستخدم من شاشة المستخدمين.');

            return self::SUCCESS;
        }

        $brandIds = array_values(array_filter($role->brand_ids ?? []));
        $restaurantIds = array_values(array_filter($role->restaurant_ids ?? []));
        $branchIds = array_values(array_filter($role->branch_ids ?? []));

        $this->row('الدور / النطاق', "{$role->role_key} / {$role->scope}");
        $this->row('العلامات المسنَدة (brand_ids)', $brandIds === [] ? '—' : count($brandIds).' → '.$this->brandNames($brandIds));
        $this->row('المطاعم المسنَدة (restaurant_ids)', $restaurantIds === [] ? '—' : count($restaurantIds).' → '.$this->restaurantNames($restaurantIds));
        $this->row('الفروع المسنَدة (branch_ids)', $branchIds === [] ? '—' : (string) count($branchIds));

        // The set the tenant scope filters by — NOT just $user->company_id.
        $companyIds = $companies->resolve($user->company_id, $brandIds, $restaurantIds);
        $this->row('الشركات المرئية (المحسوبة)', $companyIds === [] ? '✗ لا شيء' : implode('، ', $this->companyNames($companyIds)));

        // Per assignment: does its company actually reach the resolved set?
        foreach ($restaurantIds as $restaurantId) {
            $restaurant = AsabRestaurant::withoutGlobalScopes()->find($restaurantId);
            if ($restaurant === null) {
                $this->row('  ⚠ مطعم مسنَد غير موجود', $restaurantId);

                continue;
            }

            $brand = $restaurant->brand_id ? AsabBrand::withoutGlobalScopes()->find($restaurant->brand_id) : null;
            $inScope = $brand !== null && in_array($brand->company_id, $companyIds, true);

            $this->row("  مطعم «{$restaurant->name}»", match (true) {
                $brand === null => '✗ غير مرتبط بعلامة تجارية — اربطه بعلامة',
                $brand->company_id === null => "✗ علامة «{$brand->name}» بلا شركة — أعد إنشاءها أو اضبط شركتها",
                $inScope => "✓ علامة «{$brand->name}»",
                default => "✗ علامة «{$brand->name}» في شركة أخرى لم تُحتسب — انشر آخر إصدار (TenantCompanyResolver)",
            });
        }

        $ctx = $this->tenantContextFor($user, $role, $companyIds);

        // Mirrors AsabController::assignedBrandIds() — the method every scoped
        // read goes through.
        $companyBrandIds = AsabBrand::withoutGlobalScopes()->whereIn('company_id', $companyIds)->pluck('id')->all();
        $legacyBranchIds = $branches->legacyBranchIds($ctx) ?? [];
        $visibleBrands = $ctx->scope === 'all'
            ? $companyBrandIds
            : array_values(array_unique(array_merge(
                Branch::whereIn('id', $legacyBranchIds)->whereNotNull('asab_brand_id')->pluck('asab_brand_id')->all(),
                array_intersect(
                    AsabRestaurant::withoutGlobalScopes()->whereIn('id', $restaurantIds)->pluck('brand_id')->filter()->all(),
                    $companyBrandIds,
                ),
                array_intersect($brandIds, $companyBrandIds),
            )));

        $this->row('العلامات الظاهرة فعلاً', $visibleBrands === [] ? '✗ لا شيء' : $this->brandNames($visibleBrands));
        $this->row('الفروع الظاهرة فعلاً', $legacyBranchIds === []
            ? '✗ لا شيء'
            : count($legacyBranchIds).' → '.Branch::whereIn('id', $legacyBranchIds)->pluck('name')->implode('، '));

        $this->verdict($user, $visibleBrands, $legacyBranchIds, $companyIds);

        return self::SUCCESS;
    }

    private function target(): ?AsabUser
    {
        if ($email = $this->option('email')) {
            return AsabUser::withoutGlobalScopes()->where('email', $email)->first();
        }

        return ($id = $this->option('user'))
            ? AsabUser::withoutGlobalScopes()->find($id)
            : null;
    }

    /** @param  string[]  $companyIds */
    private function tenantContextFor(AsabUser $user, AsabUserRole $role, array $companyIds): TenantContext
    {
        $ctx = new TenantContext;
        $ctx->companyId = $user->company_id ?? ($companyIds[0] ?? null);
        $ctx->companyIds = $companyIds;
        $ctx->roleKey = $role->role_key;
        $ctx->scope = $role->scope ?? 'all';
        $ctx->brandIds = array_values(array_filter($role->brand_ids ?? []));
        $ctx->restaurantIds = array_values(array_filter($role->restaurant_ids ?? []));
        $ctx->branchIds = array_values(array_filter($role->branch_ids ?? []));
        $ctx->moduleKeys = $role->module_keys ?? [];

        return $ctx;
    }

    /**
     * @param  string[]  $brands
     * @param  string[]  $branchIds
     * @param  string[]  $companyIds
     */
    private function verdict(AsabUser $user, array $brands, array $branchIds, array $companyIds): void
    {
        $fix = match (true) {
            $companyIds === [] => 'الحساب بلا شركة ولا إسناد — شغّل: php artisan asab:repair-user-companies',
            $brands === [] => 'الإسناد لا يصل لأي علامة — راجع أسطر «مطعم» أعلاه؛ الغالب أن العلامة في شركة أخرى ولم يُنشر آخر إصدار بعد.',
            $branchIds === [] => 'العلامة ظاهرة لكن بلا فروع — أنشئ فرعاً للمطعم أو اربط الفروع الحالية به.',
            $user->company_id === null => 'كل شيء يصل، لكن عمود company_id فارغ — شغّل: php artisan asab:repair-user-companies',
            default => 'سليم: '.count($brands).' علامة و'.count($branchIds).' فرع. لو الشاشة لسه فارغة فامسح الكاش (config:cache) وأعد تسجيل الدخول.',
        };

        $this->line('  → '.$fix);
    }

    /** @param  string[]  $ids */
    private function brandNames(array $ids): string
    {
        return AsabBrand::withoutGlobalScopes()->whereIn('id', $ids)->pluck('name')->implode('، ') ?: '—';
    }

    /** @param  string[]  $ids */
    private function restaurantNames(array $ids): string
    {
        return AsabRestaurant::withoutGlobalScopes()->whereIn('id', $ids)->pluck('name')->implode('، ') ?: '—';
    }

    /**
     * @param  string[]  $ids
     * @return string[]
     */
    private function companyNames(array $ids): array
    {
        return \Modules\Admin\Models\AsabCompany::withoutGlobalScopes()
            ->whereIn('id', $ids)->pluck('name')->all() ?: $ids;
    }

    private function row(string $label, string|int $value): void
    {
        $this->line(sprintf('  %-40s %s', $label, $value));
    }
}
