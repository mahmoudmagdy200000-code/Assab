<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\BranchShiftConfig;
use Modules\Admin\Models\BrandShiftConfig;
use Modules\Branch\Models\Branch;

/**
 * The «Regenerate» bridge (FR-SHF-1 / §15.12 shifts-config gap): a shift config
 * saved on the dashboard only lived in `asab_brand_shift_configs`; the mobile
 * `shifts` template table — the one the app's supervisor/cashier flows open
 * shifts against — was never seeded. This projects the computed windows into
 * that table.
 *
 * Two entry points, matching the two ways a schedule is assigned:
 *  - {@see regenerateForBrand()} — every branch of the brand EXCEPT the ones
 *    holding their own override (those follow {@see regenerateForBranch()});
 *  - {@see regenerateForBranch()} — one branch, from its own config if it has
 *    one, otherwise from its brand's.
 *
 * Idempotent by the schedule's natural key (branch_id, start_time, end_time)
 * (the table's `unique_shift_time` index): a re-run activates/renames in place
 * instead of duplicating. Templates that fall out of the new schedule are
 * DEACTIVATED (is_active=false), never deleted — `shifts.cashier_shifts`
 * cascade-delete, so pruning would erase live shift history, but leaving them
 * active duplicated the mobile shift picker after every schedule change.
 *
 * Writes go through the query builder on purpose: the Shift model casts the time
 * columns `datetime:H:i`, which on SQLite would persist a full date-stamped
 * datetime and break the natural-key match. Raw `H:i:s` strings match uniformly
 * on both SQLite (tests) and MySQL TIME (prod).
 */
class ShiftScheduleBridgeService
{
    public function __construct(
        private readonly ShiftConfigService $config,
        private readonly BrandBranchResolver $brandBranches,
    ) {}

    /**
     * Seed/refresh the mobile shift template rows for a brand from its saved
     * config. No config, or no branches yet → a harmless no-op (returns 0).
     *
     * @return int the number of (branch × window) template rows ensured
     */
    public function regenerateForBrand(string $brandId): int
    {
        $cfg = BrandShiftConfig::where('brand_id', $brandId)->first();
        if ($cfg === null) {
            return 0;
        }

        $windows = $this->windowsFor($cfg);
        // Brand branches are linked directly OR through a restaurant — matching
        // `asab_brand_id` alone silently skipped every restaurant-linked branch,
        // which then had NO shift templates at all (2026-08-03 rule).
        $branchIds = $this->brandBranches->branchIds($brandId);
        // A branch that carries its own schedule is NOT dragged back onto the
        // brand's on the next brand save.
        $overridden = BranchShiftConfig::whereIn('branch_id', $branchIds)->pluck('branch_id')->all();
        $branchIds = array_values(array_diff($branchIds, $overridden));

        if ($branchIds === [] || $windows === []) {
            return 0;
        }

        return DB::transaction(fn () => $this->seed($branchIds, $windows, $this->floatSarOf($cfg)));
    }

    /**
     * Seed/refresh one branch from its own config, falling back to its brand's
     * when the branch has no override of its own.
     */
    public function regenerateForBranch(string $branchId): int
    {
        $cfg = BranchShiftConfig::where('branch_id', $branchId)->first();

        if ($cfg === null) {
            $brandId = Branch::where('id', $branchId)->value('asab_brand_id');
            $cfg = $brandId ? BrandShiftConfig::where('brand_id', $brandId)->first() : null;
        }
        if ($cfg === null) {
            return 0;
        }

        $windows = $this->windowsFor($cfg);
        if ($windows === []) {
            return 0;
        }

        return DB::transaction(fn () => $this->seed([$branchId], $windows, $this->floatSarOf($cfg)));
    }

    /**
     * «الرصيد الافتتاحي» of this schedule, in SAR — the mobile column's unit.
     * Projected onto the templates so a cashier shift can default its
     * `opening_balance` from the schedule it was created against; before this
     * the setting never left the dashboard (2026-08-10).
     */
    private function floatSarOf(BrandShiftConfig|BranchShiftConfig $cfg): float
    {
        $settings = is_array($cfg->shifts) ? $cfg->shifts : [];

        return round(((int) ($settings['openingFloatHalalas'] ?? 0)) / 100, 2);
    }

    /** @return array<int, array<string, mixed>> */
    private function windowsFor(BrandShiftConfig|BranchShiftConfig $cfg): array
    {
        $settings = is_array($cfg->shifts) ? $cfg->shifts : [];

        return $this->config->windowsFromMinutes(
            (int) $cfg->num_shifts,
            $this->config->durationMinutes($cfg),
            $cfg->first_shift_start,
            $this->config->overrides($settings),
        );
    }

    /**
     * @param  string[]  $branchIds
     * @param  array<int, array<string, mixed>>  $windows
     */
    private function seed(array $branchIds, array $windows, float $openingFloat = 0.0): int
    {
        $now = now();
        $count = 0;

        foreach ($branchIds as $branchId) {
            foreach ($windows as $window) {
                $key = [
                    'branch_id' => $branchId,
                    'start_time' => $window['start'].':00',
                    'end_time' => $window['end'].':00',
                ];

                $matched = DB::table('shifts')->where($key)->exists();
                if ($matched) {
                    DB::table('shifts')->where($key)->update([
                        'name' => $window['name'], 'is_active' => true,
                        'opening_float' => $openingFloat, 'updated_at' => $now,
                    ]);
                } else {
                    DB::table('shifts')->insert($key + [
                        'id' => (string) Str::uuid(),
                        'name' => $window['name'], 'is_active' => true,
                        'opening_float' => $openingFloat,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
                $count++;
            }

            // Templates outside the new schedule: deactivate, keep history.
            $deactivate = DB::table('shifts')
                ->where('branch_id', $branchId)
                ->where('is_active', true);
            foreach ($windows as $window) {
                $deactivate->whereNot(function ($q) use ($branchId, $window) {
                    $q->where('branch_id', $branchId)
                        ->where('start_time', $window['start'].':00')
                        ->where('end_time', $window['end'].':00');
                });
            }
            $deactivate->update(['is_active' => false, 'updated_at' => $now]);
        }

        return $count;
    }
}
