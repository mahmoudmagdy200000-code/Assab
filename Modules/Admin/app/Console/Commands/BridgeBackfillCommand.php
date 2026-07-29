<?php

namespace Modules\Admin\Console\Commands;

use Illuminate\Console\Command;
use Modules\Admin\Listeners\BridgeLegacyCashierShift;
use Modules\Admin\Services\ExpenseBridgeService;
use Modules\Branch\Models\Branch;
use Modules\Expense\Models\Expense;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Events\ShiftEndedEvent;
use Modules\Shift\Models\CashierShift;

/**
 * Meeting 2026-07-29 «الفاتورة اتبعتت ومجاتش»: mobile expenses and closed
 * cashier shifts whose bridge run was silently skipped (unlinked branch,
 * unmirrored cashier, missing ASAB actor) never re-sync on their own — the
 * bridge only fires on the submit/close event. After fixing the link
 * (PATCH /admin/branches/{id}, asab:mirror-mobile-cashiers), run this to
 * re-drive every stranded record through the SAME bridge code path.
 *
 * Idempotent by construction: candidates are selected by the absence of their
 * bridged row, and both bridges carry their own once-only guards
 * (ExpenseBridgeService keys on source_module+source_id; the shift listener
 * skips any asab_shifts row already past active|late).
 */
class BridgeBackfillCommand extends Command
{
    protected $signature = 'asab:bridge-backfill
        {--dry-run : List the stranded records and why, without bridging}';

    protected $description = 'Re-bridge mobile expenses and closed shifts that never reached the dashboard';

    public function handle(ExpenseBridgeService $expenses, BridgeLegacyCashierShift $shifts): int
    {
        $dry = (bool) $this->option('dry-run');

        $this->backfillExpenses($expenses, $dry);
        $this->backfillShifts($shifts, $dry);

        if ($dry) {
            $this->comment('Dry run — nothing was written. Re-run without --dry-run to bridge.');
        }

        return self::SUCCESS;
    }

    private function backfillExpenses(ExpenseBridgeService $bridge, bool $dry): void
    {
        $bridged = 0;
        $skipped = [];

        // Submitted mobile expenses with no live asab_operations mirror. Drafts
        // never bridge; a soft-deleted op means the dashboard removed it — do
        // not resurrect those, so the NOT EXISTS ignores deleted_at on purpose
        // only for live rows.
        Expense::query()
            ->where('status', '!=', 'draft')
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('asab_operations')
                    ->whereColumn('asab_operations.source_id', 'expenses.id')
                    ->where('asab_operations.source_module', ExpenseBridgeService::SOURCE)
                    ->whereNull('asab_operations.deleted_at');
            })
            ->orderBy('created_at')
            ->chunkById(200, function ($chunk) use ($bridge, $dry, &$bridged, &$skipped) {
                foreach ($chunk as $expense) {
                    $reason = $this->diagnoseExpense($expense);

                    if ($dry) {
                        $reason === null ? $bridged++ : $skipped[] = [$expense->id, $reason];

                        continue;
                    }

                    try {
                        $bridge->sync($expense) === null
                            ? $skipped[] = [$expense->id, $reason ?? 'SKIPPED']
                            : $bridged++;
                    } catch (\Throwable $e) {
                        $skipped[] = [$expense->id, 'ERROR: '.$e->getMessage()];
                    }
                }
            });

        $this->info(($dry ? '[dry] ' : '')."Expenses — bridgeable: {$bridged}, stranded: ".count($skipped).'.');
        $this->printSkips($skipped, 'expense');
    }

    private function backfillShifts(BridgeLegacyCashierShift $listener, bool $dry): void
    {
        $bridged = 0;
        $skipped = [];

        // Completed cashier shifts whose asab mirror is absent or never closed.
        CashierShift::query()
            ->where('status', ShiftStatus::COMPLETED->value)
            ->whereNotExists(function ($q) {
                $q->selectRaw('1')
                    ->from('asab_shifts')
                    ->whereColumn('asab_shifts.legacy_shift_id', 'cashier_shifts.id')
                    ->whereNotIn('asab_shifts.status', ['active', 'late']);
            })
            ->orderBy('created_at')
            ->chunkById(200, function ($chunk) use ($listener, $dry, &$bridged, &$skipped) {
                foreach ($chunk as $shift) {
                    if ($dry) {
                        $bridged++;

                        continue;
                    }

                    try {
                        $listener->handle(new ShiftEndedEvent($shift, false));
                        $bridged++;
                    } catch (\Throwable $e) {
                        $skipped[] = [$shift->id, 'ERROR: '.$e->getMessage()];
                    }
                }
            });

        $this->info(($dry ? '[dry] ' : '')."Closed shifts — re-driven through the bridge: {$bridged}, failed: ".count($skipped).'.');
        $this->printSkips($skipped, 'cashier_shift');
        if (! $dry && $bridged > 0) {
            $this->comment('Shifts whose cashier/actor is still unlinked were logged by the bridge (grep "shift-bridge: skipped").');
        }
    }

    /** Mirror of ExpenseBridgeService::sync's skip conditions, read-only. */
    private function diagnoseExpense(Expense $expense): ?string
    {
        $branchId = $expense->branchManager?->branch_id;
        if ($branchId === null) {
            return 'BRANCH_MISSING — submitter has no branch';
        }

        $branch = Branch::whereKey($branchId)
            ->first(['id', 'name', 'asab_company_id', 'asab_restaurant_id', 'asab_brand_id']);
        if ($branch === null) {
            return 'BRANCH_GONE — branch row deleted';
        }

        if ($branch->asab_company_id === null && $branch->asab_restaurant_id === null) {
            return "BRANCH_UNLINKED — link branch «{$branch->name}» ({$branch->id}) via PATCH /admin/branches/{id} restaurantId";
        }

        return null; // bridgeable (the linker can heal the rest)
    }

    /** @param array<int, array{0: string, 1: string}> $skipped */
    private function printSkips(array $skipped, string $label): void
    {
        if ($skipped === []) {
            return;
        }

        $this->table(["{$label} id", 'why'], array_slice($skipped, 0, 50));
        if (count($skipped) > 50) {
            $this->comment(count($skipped) - 50 .' more — full detail in the log (grep "bridge: skipped").');
        }
    }
}
