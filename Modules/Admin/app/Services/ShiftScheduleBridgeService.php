<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\BrandShiftConfig;
use Modules\Branch\Models\Branch;

/**
 * The «Regenerate» bridge (FR-SHF-1 / §15.12 shifts-config gap): a brand shift
 * config saved on the dashboard only lived in `asab_brand_shift_configs`; the
 * mobile `shifts` template table — the one the app's supervisor/cashier flows
 * open shifts against — was never seeded. This projects the computed windows
 * into that table for every branch of the brand.
 *
 * Idempotent by the schedule's natural key (branch_id, start_time, end_time)
 * (the table's `unique_shift_time` index): a re-run activates/renames in place
 * instead of duplicating. Deliberately ADDITIVE — a shift no longer in the
 * config is left untouched, because `shifts.cashier_shifts` cascade-delete and
 * pruning would erase live shift history. Trimming the schedule down is a
 * separate, explicit operation, not a side effect of Regenerate.
 *
 * Writes go through the query builder on purpose: the Shift model casts the time
 * columns `datetime:H:i`, which on SQLite would persist a full date-stamped
 * datetime and break the natural-key match. Raw `H:i:s` strings match uniformly
 * on both SQLite (tests) and MySQL TIME (prod).
 */
class ShiftScheduleBridgeService
{
    public function __construct(private readonly ShiftConfigService $config) {}

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

        $windows = $this->config->windows(
            (int) $cfg->num_shifts,
            (int) $cfg->duration_hours,
            $cfg->first_shift_start,
        );
        $branchIds = Branch::where('asab_brand_id', $brandId)->pluck('id');
        if ($branchIds->isEmpty() || $windows === []) {
            return 0;
        }

        return DB::transaction(function () use ($branchIds, $windows) {
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
                            'name' => $window['name'], 'is_active' => true, 'updated_at' => $now,
                        ]);
                    } else {
                        DB::table('shifts')->insert($key + [
                            'id' => (string) Str::uuid(),
                            'name' => $window['name'], 'is_active' => true,
                            'created_at' => $now, 'updated_at' => $now,
                        ]);
                    }
                    $count++;
                }
            }

            return $count;
        });
    }
}
