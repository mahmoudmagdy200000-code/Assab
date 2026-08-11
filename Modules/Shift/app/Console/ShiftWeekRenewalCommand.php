<?php

namespace Modules\Shift\Console;

use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Helpers\ShiftHelper;
use Modules\Shift\Models\CashierShift;

class ShiftWeekRenewalCommand extends Command
{
    protected $signature = 'shifts:renew-week
                            {--dry-run : Log what would be created without creating}';

    protected $description = 'Create next work week shifts from current week pattern (Sun–Thu), excluding holidays';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('Dry run: no shifts will be created.');
        }

        [$currentStart, $currentEnd] = ShiftHelper::currentWorkWeekDates();
        [$nextStart] = ShiftHelper::nextWorkWeekDates(Carbon::today());
        $nextDates = ShiftHelper::workWeekDatesExcludingHolidays($nextStart);

        if (empty($nextDates)) {
            $this->info('Next work week has no work days (all holidays). Skipping.');

            return Command::SUCCESS;
        }

        $pattern = $this->getCashierShiftPattern($currentStart, $currentEnd);
        if (empty($pattern)) {
            $this->info('No cashiers with shifts in current week. Nothing to renew.');

            return Command::SUCCESS;
        }

        $created = 0;

        foreach ($pattern as $cashierId => $shiftIds) {
            foreach ($shiftIds as $shiftId) {
                foreach ($nextDates as $date) {
                    $exists = CashierShift::where('cashier_id', $cashierId)
                        ->where('shift_id', $shiftId)
                        ->whereDate('shift_date', $date)
                        ->exists();

                    if ($exists) {
                        continue;
                    }

                    if ($dryRun) {
                        $this->line("Would create: cashier={$cashierId} shift={$shiftId} date=".$date->format('Y-m-d'));
                        $created++;

                        continue;
                    }

                    try {
                        $cs = CashierShift::create([
                            'cashier_id' => $cashierId,
                            'shift_id' => $shiftId,
                            'shift_date' => $date->format('Y-m-d'),
                            'status' => ShiftStatus::NOT_STARTED->value,
                            'opening_balance' => 0,
                            'assigned_by' => null,
                        ]);
                        $this->setNextCashierFor($cs);
                        $created++;
                    } catch (\Throwable $e) {
                        Log::error('Shift week renewal: create failed', [
                            'cashier_id' => $cashierId,
                            'shift_id' => $shiftId,
                            'date' => $date->format('Y-m-d'),
                            'error' => $e->getMessage(),
                        ]);
                        $this->error("Failed to create shift: {$e->getMessage()}");
                    }
                }
            }
        }

        $this->info("Renewal complete. Created {$created} shift(s) for next work week.");

        return Command::SUCCESS;
    }

    /**
     * Cashiers with shifts in [start, end] and their distinct shift_ids.
     *
     * @return array<string, array<int, string>>
     */
    private function getCashierShiftPattern(Carbon $start, Carbon $end): array
    {
        $rows = CashierShift::whereDate('shift_date', '>=', $start)
            ->whereDate('shift_date', '<=', $end)
            ->select('cashier_id', 'shift_id')
            ->distinct()
            ->get();

        // A schedule change on the dashboard DEACTIVATES the templates that fell
        // out of it (ShiftScheduleBridgeService) instead of deleting them, so
        // their history survives. Renewing this week's pattern blindly would
        // keep minting next week's shifts on those dead windows — the app would
        // show the OLD times forever, whatever the accountant saved.
        $active = \Modules\Shift\Models\Shift::whereIn('id', $rows->pluck('shift_id')->unique()->all())
            ->where('is_active', true)
            ->pluck('id')
            ->all();
        $activeSet = array_flip($active);

        $pattern = [];
        $skipped = 0;
        foreach ($rows as $r) {
            if (! isset($activeSet[$r->shift_id])) {
                $skipped++;

                continue;
            }
            $pattern[$r->cashier_id][$r->shift_id] = true;
        }

        if ($skipped > 0) {
            $this->warn("Skipped {$skipped} cashier/shift pair(s) on deactivated templates — "
                .'their schedule changed; reassign those cashiers to the new shifts.');
            Log::warning('Shift week renewal: pairs on deactivated templates skipped', ['pairs' => $skipped]);
        }

        return array_map(fn ($ids) => array_keys($ids), $pattern);
    }

    private function setNextCashierFor(CashierShift $cs): void
    {
        $service = app(\Modules\Shift\Services\ShiftService::class);
        $next = $service->getNextShiftCashier($cs);
        if ($next) {
            $cs->update(['next_cashier_id' => $next->id]);
        }
    }
}
