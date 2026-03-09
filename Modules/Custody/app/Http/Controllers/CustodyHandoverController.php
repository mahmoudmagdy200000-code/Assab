<?php

namespace Modules\Custody\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Services\CashierCustodyService;
use Modules\Custody\Services\CustodyTransactionService;
use Modules\Custody\Services\PersonalLedgerService;
use Modules\Custody\Services\CustodyBalanceService;
use Modules\Custody\Models\PersonalLedgerTransaction;

class CustodyHandoverController extends BaseController
{
    public function __construct(
        private CustodyTransactionService $transactionService,
        private PersonalLedgerService $ledgerService,
        private CustodyBalanceService $balanceService,
        private CashierCustodyService $cashierCustodyService
    ) {}

    /**
     * Handover to Branch/Owner Manager or Transfer to Custody
     * POST /api/custody/handover
     */
    public function handover(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'recipientId'   => 'required|string',
            'recipientType' => 'required|in:Cashier,Branch Manager,Brand Owner,Custody',
            'handoverAmount' => 'required|numeric|min:1',
            'additionalNotes' => 'nullable|string|max:500',
            'handoverMethod' => 'required_if:recipientType,Branch Manager|required_if:recipientType,Brand Owner|in:Cash Handover,Bank Transfer',
            'handoverDate' => 'required_if:recipientType,Branch Manager|required_if:recipientType,Brand Owner|date|after_or_equal:today',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        try {
            $user    = auth()->user();
            $isCashier = $user instanceof Cashier;

            // ── Cashier path ──────────────────────────────────────────────────
            if ($isCashier) {
                $personalBalance = $this->cashierCustodyService->getPersonalBalanceOnly($user->id);

                if ($request->input('handoverAmount') > $personalBalance) {
                    return $this->errorResponse('Insufficient balance', 400, [
                        'code'      => 'INSUFFICIENT_BALANCE',
                        'required'  => $request->input('handoverAmount'),
                        'available' => $personalBalance,
                    ]);
                }

                return DB::transaction(function () use ($request, $user, $personalBalance) {
                    $amount        = (float) $request->input('handoverAmount');
                    $recipientType = $request->input('recipientType');
                    $recipientId   = $request->input('recipientId');
                    $recipientName = $this->getRecipientName($recipientId, $recipientType);

                    // Record Cash-OUT for the sending cashier
                    $this->cashierCustodyService->recordManualHandoverSent($user->id, $amount, $recipientName);

                    // Cashier-to-cashier: record Cash-IN for the receiving cashier
                    if ($recipientType === 'Cashier') {
                        $this->cashierCustodyService->recordManualHandoverReceived(
                            $recipientId,
                            $amount,
                            $user->name
                        );
                    }

                    // If handing over to a branch manager, credit their personal ledger
                    if ($recipientType === 'Branch Manager') {
                        PersonalLedgerTransaction::create([
                            'branch_manager_id' => $recipientId,
                            'transaction_type'  => 'Total Sales',
                            'amount'            => $amount,
                            'is_cash_in'        => true,
                            'cashier_name'      => $user->name,
                            'transaction_date'  => now(),
                        ]);
                    }

                    return $this->successResponse([
                        'handoverId' => uniqid('hand_'),
                        'newBalance' => round($personalBalance - $amount, 2),
                    ], 'Handover request submitted successfully');
                });
            }

            // ── Branch Manager path (unchanged) ──────────────────────────────
            $branchManager   = $user;
            $personalBalance = $this->ledgerService->getPersonalBalanceOnly($branchManager->id);

            if ($request->input('handoverAmount') > $personalBalance) {
                return $this->errorResponse('Insufficient balance', 400, [
                    'code'      => 'INSUFFICIENT_BALANCE',
                    'required'  => $request->input('handoverAmount'),
                    'available' => $personalBalance,
                ]);
            }

            return DB::transaction(function () use ($request, $branchManager, $personalBalance) {
                $amount        = $request->input('handoverAmount');
                $recipientType = $request->input('recipientType');

                if ($recipientType === 'Custody') {
                    $this->transactionService->createCashTransferTransaction([
                        'branch_manager_id' => $branchManager->id,
                        'branch_id'         => $branchManager->branch_id,
                        'amount'            => $amount,
                    ]);

                    PersonalLedgerTransaction::create([
                        'branch_manager_id' => $branchManager->id,
                        'transaction_type'  => 'Transfer to Custody',
                        'amount'            => $amount,
                        'is_cash_in'        => false,
                        'transaction_date'  => now(),
                    ]);
                } else {
                    $this->transactionService->createHandoverTransaction([
                        'branch_manager_id' => $branchManager->id,
                        'branch_id'         => $branchManager->branch_id,
                        'amount'            => $amount,
                        'recipient_type'    => $recipientType,
                        'recipient_id'      => $request->input('recipientId'),
                        'handover_method'   => $request->input('handoverMethod'),
                        'handover_date'     => $request->input('handoverDate'),
                        'additional_notes'  => $request->input('additionalNotes'),
                    ]);

                    PersonalLedgerTransaction::create([
                        'branch_manager_id' => $branchManager->id,
                        'transaction_type'  => 'Handover to Brand Owner',
                        'amount'            => $amount,
                        'is_cash_in'        => false,
                        'brand_owner_name'  => $this->getRecipientName($request->input('recipientId'), $recipientType),
                        'transaction_date'  => now(),
                    ]);
                }

                return $this->successResponse([
                    'handoverId' => uniqid('hand_'),
                    'newBalance' => round($personalBalance - $amount, 2),
                ], 'Handover request submitted successfully');
            });
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Transfer to Custody
     * POST /api/custody/transfer
     */
    public function transfer(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'transferAmount' => 'required_without:isFullTransfer|numeric|min:1',
            'isFullTransfer' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        try {
            $branchManager = auth()->user();
            $personalBalance = $this->ledgerService->getPersonalBalanceOnly($branchManager->id);

            $transferAmount = $request->input('isFullTransfer', false)
                ? $personalBalance
                : $request->input('transferAmount');

            // Validate balance
            if ($transferAmount > $personalBalance) {
                return $this->errorResponse('Transfer amount cannot exceed personal custody balance', 400, [
                    'code' => 'INVALID_AMOUNT',
                    'maxAmount' => $personalBalance,
                ]);
            }

            return DB::transaction(function () use ($branchManager, $transferAmount, $personalBalance) {
                // Create custody transaction (cash in)
                $this->transactionService->createCashTransferTransaction([
                    'branch_manager_id' => $branchManager->id,
                    'branch_id' => $branchManager->branch_id,
                    'amount' => $transferAmount,
                ]);

                // Create personal ledger transaction (cash out)
                PersonalLedgerTransaction::create([
                    'branch_manager_id' => $branchManager->id,
                    'transaction_type' => 'Transfer to Custody',
                    'amount' => $transferAmount,
                    'is_cash_in' => false,
                    'transaction_date' => now(),
                ]);

                $newPersonalBalance = $personalBalance - $transferAmount;
                $currentCustodyBalance = $this->balanceService->getCustodyBalance($branchManager->id);
                $newCustodyBalance = $currentCustodyBalance + $transferAmount;

                return $this->successResponse([
                    'transferId' => uniqid('trans_'),
                    'newPersonalBalance' => round($newPersonalBalance, 2),
                    'newCustodyBalance' => round($newCustodyBalance, 2),
                ], 'Transfer completed successfully');
            });
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Get list of available recipients for handover.
     * GET /api/custody/recipients
     * Returns: Cashiers (when user is cashier) + Custody + Branch Managers (same branch) + Brand Owners (if any).
     */
    public function getRecipients(): JsonResponse
    {
        try {
            $user = auth()->user();
            $branchId = $user->branch_id ?? null;

            $recipients = [];

            // When user is Cashier: other cashiers + branch managers (same branch)
            if ($user instanceof Cashier && $branchId) {
                $cashiers = Cashier::query()
                    ->where('branch_id', $branchId)
                    ->where('id', '!=', $user->id)
                    ->whereNull('deleted_at')
                    ->orderBy('name')
                    ->get(['id', 'name', 'email']);

                foreach ($cashiers as $c) {
                    $recipients[] = [
                        'id'    => $c->id,
                        'type'  => 'Cashier',
                        'name'  => $c->name,
                        'email' => $c->email,
                    ];
                }

                $branchManagersForCashier = BranchManager::query()
                    ->byBranch($branchId)
                    ->active()
                    ->orderBy('name')
                    ->get(['id', 'name', 'email']);

                foreach ($branchManagersForCashier as $bm) {
                    $recipients[] = [
                        'id'    => $bm->id,
                        'type'  => 'Branch Manager',
                        'name'  => $bm->name,
                        'email' => $bm->email,
                    ];
                }
            }

            // Custody option (transfer to branch custody balance) — for Branch Managers only
            if ($user instanceof BranchManager) {
                $recipients[] = [
                    'id'   => 'custody',
                    'type' => 'Custody',
                    'name' => 'Transfer to Custody',
                ];
            }

            // Other Branch Managers in the same branch (active, exclude current user)
            if ($branchId && $user instanceof BranchManager) {
                $branchManagers = BranchManager::query()
                    ->byBranch($branchId)
                    ->active()
                    ->where('id', '!=', $user->id)
                    ->orderBy('name')
                    ->get(['id', 'name', 'email']);

                foreach ($branchManagers as $bm) {
                    $recipients[] = [
                        'id'    => $bm->id,
                        'type'  => 'Branch Manager',
                        'name'  => $bm->name,
                        'email' => $bm->email,
                    ];
                }
            }

            // Brand Owners (extend when BrandOwner model exists)
            $brandOwners = $this->getBrandOwnerRecipients();
            foreach ($brandOwners as $bo) {
                $recipients[] = $bo;
            }

            return $this->successResponse([
                'recipients' => $recipients,
            ], 'Recipients retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Brand owner recipients (override or extend when BrandOwner model exists).
     */
    protected function getBrandOwnerRecipients(): array
    {
        return [];
    }

    /**
     * Get recipient name for ledger display (Branch Manager or Brand Owner).
     */
    private function getRecipientName(string $recipientId, string $recipientType): ?string
    {
        if ($recipientType === 'Cashier') {
            $c = Cashier::query()->find($recipientId);

            return $c?->name;
        }

        if ($recipientType === 'Branch Manager') {
            $bm = BranchManager::query()->find($recipientId);

            return $bm?->name;
        }

        if ($recipientType === 'Brand Owner') {
            return null;
        }

        return null;
    }
}
