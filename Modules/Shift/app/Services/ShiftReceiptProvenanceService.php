<?php

namespace Modules\Shift\Services;

use App\Support\ShiftFinancialCalculator;
use Illuminate\Support\Facades\DB;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\BranchManagerCashTransfer;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Modules\Shift\Models\ShiftTransferAttempt;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

/** Historical transfer identities shared by daily evidence and settlements. */
class ShiftReceiptProvenanceService
{
    public function resolve(CashierShiftHandoverReceipt $receipt): array
    {
        $handoverReceipt = $receipt->cashier_shift_handover_id !== null;
        if ($handoverReceipt === ($receipt->branch_manager_cash_transfer_id !== null) || $receipt->confirmed_at === null) {
            $this->invalid();
        }
        if ($handoverReceipt) {
            $request = CashierShiftHandover::find($receipt->cashier_shift_handover_id);
            $source = $request ? CashierShift::withoutEagerLoads()->find($request->cashier_shift_id) : null;
            if (! $source || ! in_array($request->handover_to_type, ['cashier', 'branch_manager'], true)) {
                $this->invalid();
            }
            $branchId = $this->cashierBranch($source);
            if ($request->branch_id !== null && (string) $request->branch_id !== $branchId) {
                $this->invalid();
            }
            $senderType = 'cashier';
            $senderId = (string) $source->cashier_id;
            $recipientType = $request->handover_to_type;
            $recipientId = (string) $request->handover_to_id;
            $requestType = 'handover';
        } else {
            $request = BranchManagerCashTransfer::find($receipt->branch_manager_cash_transfer_id);
            $source = $request ? BranchManagerShift::find($request->branch_manager_shift_id) : null;
            if (! $source) {
                $this->invalid();
            }
            $branchId = (string) $source->branch_id;
            $senderType = 'branch_manager';
            $senderId = (string) $source->branch_manager_id;
            $recipientType = 'cashier';
            $recipientId = (string) $request->destination_cashier_id;
            $requestType = 'branch_manager_cash_transfer';
        }
        $companyId = DB::table('branches')->where('id', $branchId)->value('asab_company_id');
        if (! $branchId || ! $companyId || ! $senderId || ! $recipientId) {
            $this->invalid();
        }
        $sender = $senderType === 'cashier' ? Cashier::find($senderId) : BranchManager::find($senderId);
        $recipient = $recipientType === 'cashier' ? Cashier::find($recipientId) : BranchManager::find($recipientId);
        if (! $sender || ! $recipient) {
            $this->invalid();
        }
        $destination = null;
        $managerDay = $source instanceof BranchManagerShift ? $source : null;
        if ($recipientType === 'cashier') {
            $destination = CashierShift::withoutEagerLoads()->find($receipt->receiving_cashier_shift_id);
            if (! $destination || (string) $destination->cashier_id !== $recipientId
                || (string) $receipt->receiving_cashier_id !== $recipientId
                || $receipt->receiving_branch_manager_id !== null || $receipt->receiving_branch_manager_shift_id !== null
                || (! $handoverReceipt && (string) $request->destination_cashier_shift_id !== (string) $destination->id)) {
                $this->invalid();
            }
            if ($this->cashierBranch($destination) !== $branchId) {
                throw new ConflictHttpException('FOREIGN_RECIPIENT_PROVENANCE');
            }
        } else {
            $managerDay = BranchManagerShift::find($receipt->receiving_branch_manager_shift_id);
            if (! $managerDay || (string) $managerDay->branch_manager_id !== $recipientId
                || (string) $receipt->receiving_branch_manager_id !== $recipientId
                || $receipt->receiving_cashier_id !== null || $receipt->receiving_cashier_shift_id !== null) {
                $this->invalid();
            }
            if ((string) $managerDay->branch_id !== $branchId) {
                throw new ConflictHttpException('FOREIGN_RECIPIENT_PROVENANCE');
            }
        }
        $revision = DB::table('shift_report_revisions as revision')
            ->join('shift_report_aggregates as aggregate', 'aggregate.id', '=', 'revision.report_aggregate_id')
            ->where('revision.id', $receipt->report_revision_id)->first(['aggregate.source_type', 'aggregate.source_id']);
        if (! $revision || $revision->source_type !== ($handoverReceipt ? 'cashier_shift' : 'branch_manager_shift')
            || (string) $revision->source_id !== (string) $source->id) {
            $this->invalid();
        }
        if ($receipt->transfer_attempt_id !== null) {
            $attempt = ShiftTransferAttempt::find($receipt->transfer_attempt_id);
            if (! $attempt || $attempt->request_type !== ($handoverReceipt ? 'handover' : 'manager_transfer')
                || (string) $attempt->request_id !== (string) $request->id
                || $attempt->sender_type !== $senderType || (string) $attempt->sender_id !== $senderId
                || $attempt->recipient_type !== $recipientType || (string) $attempt->recipient_id !== $recipientId
                || (string) $attempt->source_branch_id !== $branchId || (string) $attempt->source_company_id !== (string) $companyId
                || (string) $attempt->report_revision_id !== (string) $receipt->report_revision_id
                || (string) $attempt->receiving_cashier_shift_id !== (string) $destination?->id
                || $attempt->presented_halalas !== ShiftFinancialCalculator::storedSarToHalalas($receipt->confirmed_amount)) {
                $this->invalid();
            }
        } elseif ($request->current_transfer_attempt_id !== null) {
            // A prospective typed request cannot masquerade as an untyped legacy receipt.
            $this->invalid();
        }
        if ($senderType === $recipientType && $senderId === $recipientId) {
            throw new ConflictHttpException('IDENTICAL_SETTLEMENT_PARTIES');
        }

        return [
            'request_type' => $requestType, 'request_id' => (string) $request->id,
            'sender_type' => $senderType, 'sender_id' => $senderId, 'sender_name' => $sender->name,
            'recipient_type' => $recipientType, 'recipient_id' => $recipientId, 'recipient_name' => $recipient->name,
            'branch_id' => $branchId, 'company_id' => (string) $companyId,
            'source_shift_id' => $source instanceof CashierShift ? (string) $source->id : null,
            'destination_shift_id' => $destination ? (string) $destination->id : null,
            'manager_workday_id' => $managerDay ? (string) $managerDay->id : null,
        ];
    }

    private function cashierBranch(CashierShift $shift): string
    {
        $branch = (string) $shift->shift()->value('branch_id');
        if (! $branch || ($shift->branch_id !== null && (string) $shift->branch_id !== $branch)) {
            $this->invalid();
        }

        return $branch;
    }

    private function invalid(): never
    {
        throw new ConflictHttpException('UNRESOLVED_RECEIPT_PROVENANCE');
    }
}
