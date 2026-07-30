<?php

namespace Modules\Custody\Console;

use Illuminate\Console\Command;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Custody\Services\CashierCustodyService;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\ShiftVarianceDetail;

/**
 * The cashier_custody_transactions enum silently rejected 'Total Sales' and
 * 'Variance' rows on MySQL, and self-variance details never reached 'approved',
 * so approved handovers left the cashier custody ledger incomplete. Replays
 * approved handovers through the (idempotent) custody writers to rebuild the
 * missing entries. Run AFTER `php artisan migrate` (needs the relaxed columns).
 */
class BackfillCashierLedgerCommand extends Command
{
    protected $signature = 'custody:backfill-cashier-ledger
        {--days=30 : How far back to replay approved handovers}
        {--dry-run : List what would be written without writing}';

    protected $description = 'Rebuild missing cashier custody entries (Total Sales / Handover Sent / Variance) for approved handovers';

    public function handle(CashierCustodyService $custody): int
    {
        $dry = (bool) $this->option('dry-run');
        $since = now()->subDays(max((int) $this->option('days'), 1));
        $written = 0;

        CashierShiftHandover::where('status', 'approved')
            ->whereDate('handover_date', '>=', $since)
            ->with(['cashierShift.cashier', 'cashierShift.varianceDetails'])
            ->orderBy('handover_date')
            ->chunkById(100, function ($handovers) use ($custody, $dry, &$written) {
                foreach ($handovers as $handover) {
                    $shift = $handover->cashierShift;
                    if (! $shift || ! $shift->cashier) {
                        continue;
                    }

                    $missing = $this->missingEntries($handover, $shift);
                    $pendingSelf = $shift->varianceDetails
                        ->where('responsible_cashier_id', $shift->cashier_id)
                        ->where('responsibility_status', 'pending');

                    if ($missing === [] && $pendingSelf->isEmpty()) {
                        continue;
                    }

                    $this->line(sprintf(
                        '%s shift %s (%s): %s%s',
                        $dry ? '[dry]' : '[fix]',
                        $shift->id,
                        $shift->cashier->name,
                        implode(', ', $missing) ?: '-',
                        $pendingSelf->isNotEmpty() ? ' + approve self-variance' : '',
                    ));
                    $written++;

                    if ($dry) {
                        continue;
                    }

                    // Same rule as HandoverService::approveHandover — the
                    // manager's (already given) approval covers the self claim.
                    if ($pendingSelf->isNotEmpty()) {
                        ShiftVarianceDetail::whereIn('id', $pendingSelf->pluck('id'))
                            ->update([
                                'responsibility_status' => 'approved',
                                'reviewed_by_id' => $handover->approved_by_id,
                                'reviewed_by_type' => $handover->approved_by_type,
                                'reviewed_at' => $handover->approved_at ?? now(),
                            ]);
                    }

                    // All three writers are idempotent (existing-row checks /
                    // delete-and-recreate), so replaying is safe.
                    $custody->recordCashCollected($handover, $shift->cashier);
                    $custody->recordHandoverSent($handover, $shift->cashier);
                    event(new \Modules\Shift\Events\VarianceRecorded(
                        $shift->fresh(['varianceDetails', 'handover'])
                    ));
                }
            });

        $this->info(($dry ? 'Would rebuild ' : 'Rebuilt ')."{$written} shift(s).");

        return self::SUCCESS;
    }

    /** @return string[] entry types absent from the cashier's ledger for this shift */
    private function missingEntries(CashierShiftHandover $handover, $shift): array
    {
        $existing = CashierCustodyTransaction::where('related_shift_id', $shift->id)
            ->where('cashier_id', $shift->cashier_id)
            ->pluck('transaction_type')
            ->all();

        $missing = array_values(array_diff(['Total Sales', 'Handover Sent'], $existing));

        $hasApprovableVariance = $shift->varianceDetails
            ->whereNotNull('responsible_cashier_id')
            ->whereIn('responsibility_status', ['approved', 'pending'])
            ->isNotEmpty();
        if ($hasApprovableVariance && ! in_array('Variance', $existing, true)) {
            $missing[] = 'Variance';
        }

        return $missing;
    }
}
