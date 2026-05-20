<?php

namespace Modules\Custody\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\Custody\Events\HandoverApproved;
use Modules\Custody\Services\PersonalLedgerService;

class CreatePersonalLedgerTransactionFromHandover
{
    public function __construct(
        private PersonalLedgerService $ledgerService
    ) {}

    /**
     * Handle the event.
     */
    public function handle(HandoverApproved $event): void
    {
        try {
            $handover = $event->handover;

            // Only create transaction if handover is to a branch manager
            if ($handover->handover_to_type !== 'branch_manager') {
                return;
            }

            // Check if transaction already exists
            $existingTransaction = \Modules\Custody\Models\PersonalLedgerTransaction::where('related_handover_id', $handover->id)
                ->where('transaction_type', 'Total Sales')
                ->first();

            if ($existingTransaction) {
                Log::info('Personal ledger transaction already exists for handover', [
                    'handover_id' => $handover->id,
                    'transaction_id' => $existingTransaction->id,
                ]);

                return;
            }

            // Create personal ledger transaction
            $this->ledgerService->createTransactionFromHandover($handover);

            Log::info('Personal ledger transaction created from approved handover', [
                'handover_id' => $handover->id,
                'branch_manager_id' => $handover->handover_to_id,
                'amount' => $handover->handover_amount,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to create personal ledger transaction from handover', [
                'handover_id' => $event->handover->id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
        }
    }
}
