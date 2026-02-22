<?php

namespace Modules\Custody\Http\Controllers;

use App\Http\Controllers\BaseController;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Custody\Services\CustodyTransactionService;
use Modules\Custody\Services\PersonalLedgerService;
use Modules\Custody\Services\CustodyBalanceService;
use Modules\Custody\Models\PersonalLedgerTransaction;

class CustodyHandoverController extends BaseController
{
    public function __construct(
        private CustodyTransactionService $transactionService,
        private PersonalLedgerService $ledgerService,
        private CustodyBalanceService $balanceService
    ) {}

    /**
     * Handover to Branch/Owner Manager or Transfer to Custody
     * POST /api/custody/handover
     */
    public function handover(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'recipientId' => 'required|string',
            'recipientType' => 'required|in:Branch Manager,Brand Owner,Custody',
            'handoverAmount' => 'required|numeric|min:1',
            'additionalNotes' => 'nullable|string|max:500',
            'handoverMethod' => 'required_if:recipientType,Branch Manager|required_if:recipientType,Brand Owner|in:Cash Handover,Bank Transfer',
            'handoverDate' => 'required_if:recipientType,Branch Manager|required_if:recipientType,Brand Owner|date|after_or_equal:today',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        try {
            $branchManager = auth()->user();
            $personalBalance = $this->ledgerService->getPersonalBalanceOnly($branchManager->id);

            // Validate balance
            if ($request->input('handoverAmount') > $personalBalance) {
                return $this->errorResponse('Insufficient balance', 400, [
                    'code' => 'INSUFFICIENT_BALANCE',
                    'required' => $request->input('handoverAmount'),
                    'available' => $personalBalance,
                ]);
            }

            return DB::transaction(function () use ($request, $branchManager, $personalBalance) {
                $amount = $request->input('handoverAmount');
                $recipientType = $request->input('recipientType');

                if ($recipientType === 'Custody') {
                    // Transfer to Branch Custody Balance
                    $this->transactionService->createCashTransferTransaction([
                        'branch_manager_id' => $branchManager->id,
                        'branch_id' => $branchManager->branch_id,
                        'amount' => $amount,
                    ]);

                    // Create personal ledger transaction (cash out)
                    PersonalLedgerTransaction::create([
                        'branch_manager_id' => $branchManager->id,
                        'transaction_type' => 'Transfer to Custody',
                        'amount' => $amount,
                        'is_cash_in' => false,
                        'transaction_date' => now(),
                    ]);
                } else {
                    // Handover to Branch Manager or Brand Owner
                    $this->transactionService->createHandoverTransaction([
                        'branch_manager_id' => $branchManager->id,
                        'branch_id' => $branchManager->branch_id,
                        'amount' => $amount,
                        'recipient_type' => $recipientType,
                        'recipient_id' => $request->input('recipientId'),
                        'handover_method' => $request->input('handoverMethod'),
                        'handover_date' => $request->input('handoverDate'),
                        'additional_notes' => $request->input('additionalNotes'),
                    ]);

                    // Create personal ledger transaction
                    PersonalLedgerTransaction::create([
                        'branch_manager_id' => $branchManager->id,
                        'transaction_type' => 'Handover to Brand Owner',
                        'amount' => $amount,
                        'is_cash_in' => false,
                        'brand_owner_name' => $this->getRecipientName($request->input('recipientId'), $recipientType),
                        'transaction_date' => now(),
                    ]);
                }

                $newBalance = $personalBalance - $amount;

                return $this->successResponse([
                    'handoverId' => uniqid('hand_'),
                    'newBalance' => round($newBalance, 2),
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
     * Returns: Custody option + Branch Managers (same branch, excluding current user) + Brand Owners (if any).
     */
    public function getRecipients(): JsonResponse
    {
        try {
            $user = auth()->user();
            $branchId = $user->branch_id ?? null;

            $recipients = [];

            // 1. Custody option (transfer to branch custody balance)
            $recipients[] = [
                'id' => 'custody',
                'type' => 'Custody',
                'name' => 'Transfer to Custody',
            ];

            // 2. Other Branch Managers in the same branch (active, exclude current user)
            if ($branchId) {
                $branchManagers = BranchManager::query()
                    ->byBranch($branchId)
                    ->active()
                    ->where('id', '!=', $user->id)
                    ->orderBy('name')
                    ->get(['id', 'name', 'email']);

                foreach ($branchManagers as $bm) {
                    $recipients[] = [
                        'id' => $bm->id,
                        'type' => 'Branch Manager',
                        'name' => $bm->name,
                        'email' => $bm->email,
                    ];
                }
            }

            // 3. Brand Owners (no BrandOwner model in codebase yet; extend when available)
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
        if ($recipientType === 'Branch Manager') {
            $bm = BranchManager::query()->find($recipientId);

            return $bm?->name;
        }

        if ($recipientType === 'Brand Owner') {
            // Extend when BrandOwner model exists
            return null;
        }

        return null;
    }
}
