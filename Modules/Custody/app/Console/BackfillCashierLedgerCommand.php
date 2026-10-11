<?php

namespace Modules\Custody\Console;

use Illuminate\Console\Command;

/**
 * Retained as a safe failure for older operational instructions. Approval is
 * not receipt evidence; S1-08 receipt, custody, ledger, state, and audit writes
 * now share one transaction owned by ShiftTransferReceiptService. Rebuilding
 * ledger rows from an approved handover could fabricate receipt evidence or
 * duplicate a committed close effect.
 */
class BackfillCashierLedgerCommand extends Command
{
    protected $signature = 'custody:backfill-cashier-ledger';

    protected $description = 'Disabled: legacy handover approval is not confirmed receipt evidence';

    public function handle(): int
    {
        $this->error('No entries were written. Handover approval is not proof of recipient confirmation or liability approval. Use the authoritative S1-08 confirmation path; historical gaps require a separately reviewed repair.');

        return self::FAILURE;
    }
}
