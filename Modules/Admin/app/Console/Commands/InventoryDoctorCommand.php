<?php

namespace Modules\Admin\Console\Commands;

use Illuminate\Console\Command;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\BranchInventoryList;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Inventory\Models\DailyInventorySchedule;
use Modules\Inventory\Models\DailyInventoryScheduleItem;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\Item as PurchaseItem;

/**
 * «حددنا الأصناف من المحاسب والجرد اليومي لسه بيقول 0 products».
 *
 * The accountant's selection has to cross FOUR joints before the manager's
 * phone shows it, and a break in any one of them looks identical from the app:
 *
 *   asab_branch_inventory_list   (the accountant's choice)
 *        ↓ DailyInventoryListBridgeService
 *   items + branch_item          (the legacy catalog the app reads)
 *        ↓
 *   daily_inventory_schedules    (must EXIST and be is_active)
 *        ↓
 *   daily_inventory_schedule_items
 *        ↓
 *   branch_managers.branch_id    (the phone's own branch — a different column
 *                                 from asab_manager_user_id on the branch)
 *
 * This prints each joint per branch so the broken one is named instead of
 * guessed. Read-only.
 */
class InventoryDoctorCommand extends Command
{
    protected $signature = 'asab:inventory-doctor
        {--branch= : A single branch id}
        {--brand= : Every branch of this brand id (or name)}
        {--phone= : The LOGIN phone of a branch manager — shows which branch that phone actually opens}';

    protected $description = 'Explain why a branch shows no items on the mobile daily-inventory screen';

    public function handle(): int
    {
        if ($phone = $this->option('phone')) {
            return $this->diagnosePhone($phone);
        }

        $branches = $this->targetBranches();

        if ($branches->isEmpty()) {
            $this->error('No branch matched. Pass --branch=<id> or --brand=<id|name>.');

            return self::FAILURE;
        }

        foreach ($branches as $branch) {
            $this->diagnose($branch);
        }

        return self::SUCCESS;
    }

    /**
     * The decisive check when the data looks healthy but the phone still shows
     * nothing: WHICH branch does that login actually open? Duplicate manager
     * rows («فلان» and «فلان 2») are the usual answer — the app scopes by
     * `branch_managers.branch_id`, not by anything the dashboard shows.
     */
    private function diagnosePhone(string $phone): int
    {
        $managers = BranchManager::where('phone', $phone)
            ->orWhere('phone', 'like', '%'.ltrim($phone, '0').'%')
            ->get(['id', 'name', 'phone', 'branch_id']);

        if ($managers->isEmpty()) {
            $this->error("No branch manager logs in with {$phone}.");

            return self::FAILURE;
        }

        if ($managers->count() > 1) {
            $this->warn('⚠ '.$managers->count().' حساب مدير بنفس الرقم — التطبيق يفتح الحساب الذي يسجّل الدخول به فقط:');
        }

        foreach ($managers as $manager) {
            $branch = $manager->branch_id ? Branch::find($manager->branch_id) : null;
            $this->newLine();
            $this->info("━━ {$manager->name}  ({$manager->phone})");
            $this->row('يفتح الفرع', $branch?->name ?? '✗ غير مربوط بأي فرع');

            if ($branch !== null) {
                $this->diagnose($branch);
            }
        }

        return self::SUCCESS;
    }

    /** @return \Illuminate\Support\Collection<int, Branch> */
    private function targetBranches()
    {
        if ($id = $this->option('branch')) {
            return Branch::where('id', $id)->get();
        }

        if ($brand = $this->option('brand')) {
            $brandRow = AsabBrand::withoutGlobalScopes()
                ->where('id', $brand)->orWhere('name', $brand)->first();

            if ($brandRow === null) {
                return collect();
            }

            $restaurantIds = \Modules\Admin\Models\AsabRestaurant::withoutGlobalScopes()
                ->where('brand_id', $brandRow->id)->pluck('id')->all();

            return Branch::where('asab_brand_id', $brandRow->id)
                ->when($restaurantIds !== [], fn ($q) => $q->orWhereIn('asab_restaurant_id', $restaurantIds))
                ->get();
        }

        return collect();
    }

    private function diagnose(Branch $branch): void
    {
        $this->newLine();
        $this->info("━━ {$branch->name}  ({$branch->id})");

        // 1. what the accountant selected
        $selected = BranchInventoryList::where('branch_id', $branch->id)->count();
        $this->row('اختيار المحاسب (asab_branch_inventory_list)', $selected);

        // 2. the legacy catalog assigned to the branch
        $pivot = BranchItem::where('branch_id', $branch->id)->count();
        $blankUnits = BranchItem::where('branch_id', $branch->id)
            ->whereIn('item_id', PurchaseItem::whereNull('unit')->orWhere('unit', '')->pluck('id'))
            ->count();
        $this->row('أصناف الفرع (branch_item)', $pivot.($blankUnits ? "  ⚠ {$blankUnits} بلا وحدة قياس" : ''));

        // 3. the schedule the app actually reads
        $schedules = DailyInventorySchedule::where('branch_id', $branch->id)->get();
        if ($schedules->isEmpty()) {
            $this->row('جدول الجرد', '✗ لا يوجد — التطبيق سيعرض 0 مهما اختار المحاسب');
        }

        foreach ($schedules as $schedule) {
            $items = DailyInventoryScheduleItem::where('daily_inventory_schedule_id', $schedule->id)->count();
            $flag = $schedule->is_active ? 'نشط' : '✗ معطّل (التطبيق يقرأ النشط فقط)';
            $this->row("جدول {$schedule->id}", "{$flag} — {$items} صنف");
        }

        // 4. the phone's own branch column
        $managers = BranchManager::where('branch_id', $branch->id)->get(['id', 'name', 'phone']);
        if ($managers->isEmpty()) {
            $this->row('مدير الفرع (branch_managers.branch_id)', '✗ لا يوجد مدير مربوط بهذا الفرع — شغّل asab:sync-manager-branches');
        }

        foreach ($managers as $manager) {
            $this->row('مدير الفرع', "{$manager->name} ({$manager->phone})");

            // A duplicate login with the same phone/name pointing somewhere else
            // is what makes a healthy branch look empty on the phone: the app
            // scopes by branch_managers.branch_id of whoever signed in.
            $twins = BranchManager::where('id', '!=', $manager->id)
                ->where(fn ($q) => $q->where('phone', $manager->phone)->orWhere('name', $manager->name))
                ->get(['id', 'name', 'phone', 'branch_id']);

            foreach ($twins as $twin) {
                $twinBranch = $twin->branch_id ? Branch::find($twin->branch_id)?->name : null;
                $this->row('  ⚠ حساب مكرر بنفس الرقم/الاسم', "{$twin->name} → ".($twinBranch ?? 'بلا فرع'));
            }
        }

        // …and the dashboard-side assignment, which is a DIFFERENT column: the
        // two disagreeing is the classic «المدير يفتح فرعه القديم».
        if ($branch->asab_manager_user_id) {
            $assigned = \Modules\Admin\Models\AsabUser::withoutGlobalScopes()
                ->whereKey($branch->asab_manager_user_id)->value('name');
            $this->row('المعيَّن من الداشبورد (asab_manager_user_id)', $assigned ?? $branch->asab_manager_user_id);

            // Where the identity map says that dashboard user logs in — if that
            // legacy row points at another branch, the manager opens it instead.
            $legacyId = app(\Modules\Admin\Services\IdentityMapService::class)->legacyIdFor(
                \Modules\Admin\Models\AsabIdentityMap::ENTITY_BRANCH_MANAGER,
                $branch->asab_manager_user_id,
            );
            $legacy = $legacyId ? BranchManager::find($legacyId) : null;

            if ($legacy === null) {
                $this->row('  ⚠ ربط الدخول (asab_identity_map)', '✗ لا يوجد — الحساب لم يُنشأ في العالم القديم');
            } elseif ((string) $legacy->branch_id !== (string) $branch->id) {
                $openedBranch = $legacy->branch_id ? Branch::find($legacy->branch_id)?->name : null;
                $this->row('  ⚠ حساب الدخول يفتح', ($openedBranch ?? 'بلا فرع').' — شغّل asab:sync-manager-branches');
            }
        }

        $this->verdict($selected, $schedules, $managers->count());
    }

    private function verdict(int $selected, $schedules, int $managers): void
    {
        $active = $schedules->firstWhere('is_active', true);
        $activeItems = $active
            ? DailyInventoryScheduleItem::where('daily_inventory_schedule_id', $active->id)->count()
            : 0;

        $fix = match (true) {
            $selected === 0 => 'المحاسب لم يحفظ أي أصناف لهذا الفرع بعد.',
            $schedules->isEmpty() => 'شغّل: php artisan asab:bridge-backfill  (سينشئ جدول الجرد ويملأه).',
            $active === null => 'كل جداول الفرع معطّلة — شغّل asab:bridge-backfill أو أعد الحفظ من الداشبورد (الحفظ يعيد التفعيل).',
            $activeItems === 0 => 'الجدول النشط فارغ — شغّل: php artisan asab:bridge-backfill',
            $managers === 0 => 'لا مدير مربوط بالفرع في العالم القديم — شغّل: php artisan asab:sync-manager-branches',
            default => "سليم: {$activeItems} صنف على الجدول النشط. لو الشاشة لسه 0 فالمستخدم يفتح فرعاً آخر — راجع سطر «مدير الفرع» أعلاه.",
        };

        $this->line('  → '.$fix);
    }

    private function row(string $label, string|int $value): void
    {
        $this->line(sprintf('  %-48s %s', $label, $value));
    }
}
