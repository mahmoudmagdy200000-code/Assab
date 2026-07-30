<?php

namespace Modules\Custody\Console;

use Illuminate\Console\Command;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Models\CashierShiftHandover;

/**
 * Approval is branch-wide, so a handover addressed to manager A but approved by
 * manager B put the ledger cash-IN on A while B physically holds the money.
 * Rows written before receivingBranchManagerId() existed carry that wrong
 * attribution — this repoints them to the approving manager.
 */
class RepointHandoverLedgerCommand extends Command
{
    protected $signature = 'custody:repoint-handover-ledger
        {--dry-run : List the rows that would be repointed without writing}';

    protected $description = 'Repoint personal-ledger handover entries to the branch manager who actually approved/received the cash';

    public function handle(): int
    {
        $dry = (bool) $this->option('dry-run');
        $repointed = 0;

        PersonalLedgerTransaction::query()
            ->whereNotNull('related_handover_id')
            ->whereIn('transaction_type', ['Total Sales', 'Variance from Cashier'])
            ->orderBy('created_at')
            ->chunkById(200, function ($rows) use ($dry, &$repointed) {
                $handovers = CashierShiftHandover::whereIn('id', $rows->pluck('related_handover_id'))
                    ->get()
                    ->keyBy('id');

                foreach ($rows as $row) {
                    $handover = $handovers->get($row->related_handover_id);
                    $correctId = $handover?->receivingBranchManagerId();

                    if (! $correctId || $correctId === $row->branch_manager_id) {
                        continue;
                    }

                    $this->line(sprintf(
                        '%s %s %s: %s -> %s (%.2f)',
                        $dry ? '[dry]' : '[fix]',
                        $row->transaction_type,
                        $row->id,
                        $row->branch_manager_id,
                        $correctId,
                        (float) $row->amount,
                    ));

                    if (! $dry) {
                        $row->update(['branch_manager_id' => $correctId]);
                    }

                    $repointed++;
                }
            });

        $this->info(($dry ? 'Would repoint ' : 'Repointed ').$repointed.' ledger row(s).');

        return self::SUCCESS;
    }
}
