<?php

namespace Modules\Custody\Http\Controllers;

use App\Http\Controllers\BaseController;
use App\Support\ShiftFinancialCalculator;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BrandOwner\Models\CashSalesTransferRequest;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Exceptions\SalesCashTransferRefusedException;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Custody\Models\CustodyHandoverRequest;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Custody\Services\CashierCustodyService;
use Modules\Custody\Services\CustodyBalanceService;
use Modules\Custody\Services\CustodyTransactionService;
use Modules\Custody\Services\PersonalLedgerService;
use Modules\Custody\Services\SalesCashTransferService;

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
        if (auth()->user() instanceof Cashier) {
            return $this->errorResponse(
                'Legacy custody handover is retired for cashiers; use the shift handover flow.',
                409,
                ['code' => 'LEGACY_CUSTODY_HANDOVER_RETIRED']
            );
        }

        $validator = Validator::make($request->all(), [
            'recipientId' => 'required|string',
            'recipientType' => 'required|in:Cashier,Branch Manager,Brand Owner,Custody',
            'handoverAmount' => 'required|numeric|min:1',
            'additionalNotes' => 'nullable|string|max:500',
            'handoverMethod' => 'required_if:recipientType,Branch Manager|required_if:recipientType,Brand Owner|in:Cash Handover,Bank Transfer,cash_handover,bank_transfer',
            'handoverDate' => 'required_if:recipientType,Branch Manager|required_if:recipientType,Brand Owner|date|after_or_equal:today',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $handoverMethod = $this->normalizeHandoverMethod($request->input('handoverMethod'));

        try {
            $user = auth()->user();
            $isCashier = $user instanceof Cashier;

            // ── Cashier path ──────────────────────────────────────────────────
            if ($isCashier) {
                return DB::transaction(function () use ($request, $user) {
                    CashierCustodyTransaction::where('cashier_id', $user->id)->lockForUpdate()->get(['id']);
                    $personalBalance = $this->cashierCustodyService->getPersonalBalanceOnly($user->id);
                    $amount = (float) $request->input('handoverAmount');
                    if ($amount > $personalBalance) {
                        return $this->errorResponse(
                            sprintf('Insufficient balance. Available: %s SAR, requested: %s SAR.',
                                number_format($personalBalance, 2),
                                number_format($amount, 2)
                            ),
                            400,
                            [
                                'code' => 'INSUFFICIENT_BALANCE',
                                'required' => $amount,
                                'available' => $personalBalance,
                            ]
                        );
                    }

                    $recipientType = $request->input('recipientType');
                    $recipientId = $request->input('recipientId');
                    $recipientName = $this->getRecipientName($recipientId, $recipientType);

                    // Cashier-to-cashier: create pending request; ledger entries when recipient accepts/rejects
                    if ($recipientType === 'Cashier') {
                        $handoverRequest = CustodyHandoverRequest::create([
                            'from_cashier_id' => $user->id,
                            'to_cashier_id' => $recipientId,
                            'amount' => $amount,
                            'additional_notes' => $request->input('additionalNotes'),
                            'status' => 'pending',
                        ]);

                        return $this->successResponse([
                            'handoverRequestId' => $handoverRequest->id,
                            'message' => 'Handover request sent. It will be deducted from your balance when the recipient accepts.',
                            'newBalance' => round($personalBalance, 2),
                        ], 'Handover request submitted successfully');
                    }

                    // Branch Manager: immediate ledger (no request flow)
                    $this->cashierCustodyService->recordManualHandoverSent($user->id, $amount, $recipientName);
                    PersonalLedgerTransaction::create([
                        'branch_manager_id' => $recipientId,
                        'transaction_type' => 'Total Sales',
                        'amount' => $amount,
                        'is_cash_in' => true,
                        'cashier_name' => $user->name,
                        'transaction_date' => now(),
                    ]);

                    return $this->successResponse([
                        'handoverId' => uniqid('hand_'),
                        'newBalance' => round($personalBalance - $amount, 2),
                    ], 'Handover submitted successfully');
                });
            }

            // ── Branch Manager path ──────────────────────────────
            $branchManager = $user;

            return DB::transaction(function () use ($request, $branchManager, $handoverMethod) {
                // Lock BM ledger rows + recompute balance inside the transaction to
                // avoid races (two parallel handovers reading the same stale balance).
                PersonalLedgerTransaction::where('branch_manager_id', $branchManager->id)
                    ->lockForUpdate()->get(['id']);
                $personalBalance = $this->ledgerService->getPersonalBalanceOnly($branchManager->id);

                $amount = (float) $request->input('handoverAmount');
                if ($amount > $personalBalance) {
                    return $this->errorResponse(
                        sprintf('Insufficient balance. Available: %s SAR, requested: %s SAR.',
                            number_format($personalBalance, 2),
                            number_format($amount, 2)
                        ),
                        400,
                        [
                            'code' => 'INSUFFICIENT_BALANCE',
                            'required' => $amount,
                            'available' => $personalBalance,
                        ]
                    );
                }

                $recipientType = $request->input('recipientType');

                if ($recipientType === 'Custody') {
                    $this->transactionService->createCashTransferTransaction([
                        'branch_manager_id' => $branchManager->id,
                        'branch_id' => $branchManager->branch_id,
                        'amount' => $amount,
                    ]);

                    PersonalLedgerTransaction::create([
                        'branch_manager_id' => $branchManager->id,
                        'transaction_type' => 'Transfer to Custody',
                        'amount' => $amount,
                        'is_cash_in' => false,
                        'transaction_date' => now(),
                    ]);
                } else {
                    $this->transactionService->createHandoverTransaction([
                        'branch_manager_id' => $branchManager->id,
                        'branch_id' => $branchManager->branch_id,
                        'amount' => $amount,
                        'recipient_type' => $recipientType,
                        'recipient_id' => $request->input('recipientId'),
                        'handover_method' => $handoverMethod,
                        'handover_date' => $request->input('handoverDate'),
                        'additional_notes' => $request->input('additionalNotes'),
                    ]);

                    if ($recipientType === 'Brand Owner') {
                        CashSalesTransferRequest::create([
                            'sender_id' => $branchManager->id,
                            'sender_type' => 'branch_manager',
                            'branch_id' => $branchManager->branch_id,
                            'brand_owner_id' => $request->input('recipientId'),
                            'handover_amount' => $amount,
                            'handover_method' => $handoverMethod,
                            'handover_date' => $request->input('handoverDate'),
                            'additional_notes' => $request->input('additionalNotes'),
                            'status' => 'pending',
                        ]);
                    }

                    PersonalLedgerTransaction::create([
                        'branch_manager_id' => $branchManager->id,
                        'transaction_type' => 'Handover to Brand Owner',
                        'amount' => $amount,
                        'is_cash_in' => false,
                        'brand_owner_name' => $this->getRecipientName($request->input('recipientId'), $recipientType),
                        'transaction_date' => now(),
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
     *
     * BRC v3.4 F-04 (FUND-01/02): only cash whose source day is fully approved (accountant, then head of
     * accounts) and which the manager really holds (executed movements once, open reservations once, uncollected
     * shortages never) can be moved. Optional `Idempotency-Key` header (route middleware): a retry replays the
     * first answer and posts nothing twice. Money is SAR with at most two decimals; a finer amount is a 422.
     */
    public function transfer(Request $request): JsonResponse
    {
        if (! auth()->user() instanceof BranchManager) {
            return $this->errorResponse('Only branch managers can transfer to custody', 403);
        }

        $validator = Validator::make($request->all(), [
            'transferAmount' => 'required_without:isFullTransfer|numeric|min:1',
            'isFullTransfer' => 'sometimes|boolean',
        ]);

        if ($validator->fails()) {
            return $this->validationErrorResponse($validator->errors());
        }

        $amountHalalas = null; // null = everything the rules release
        if (! $request->boolean('isFullTransfer', false)) {
            try {
                $amountHalalas = ShiftFinancialCalculator::sarToHalalas($request->input('transferAmount'));
            } catch (\InvalidArgumentException) {
                // Sub-halala input is refused, never rounded (the rounding rule is not approved — AC-46).
                return $this->validationErrorResponse(['transferAmount' => ['The transfer amount may have at most two decimal places.']]);
            }
        }

        try {
            $branchManager = auth()->user();
            $result = app(SalesCashTransferService::class)->transfer($branchManager, $amountHalalas);

            $newPersonalBalance = $result['personalBalanceSar'];
            $currentCustodyBalance = $this->balanceService->getCustodyBalance($branchManager->id);

            return $this->successResponse([
                'transferId' => uniqid('trans_'),
                'newPersonalBalance' => round($newPersonalBalance, 2),
                // The custody row is already posted: this is the balance AFTER the transfer.
                'newCustodyBalance' => round($currentCustodyBalance, 2),
            ], 'Transfer completed successfully');
        } catch (SalesCashTransferRefusedException $e) {
            return $this->errorResponse($e->getMessage(), 400, [
                'code' => 'INVALID_AMOUNT',
                'reason' => $e->reason,
                'maxAmount' => round($e->transferableHalalas / 100, 2),
                'availableAmount' => round($e->availableHalalas / 100, 2),
                'unapprovedAmount' => round($e->unapprovedHalalas / 100, 2),
            ]);
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * List custody handover requests (cashier-to-cashier).
     * GET /api/custody/handover-requests
     * Query: ?status=pending|accepted|rejected (default: pending for recipient)
     * Cashier: as recipient (to_cashier_id = me) or as sender when status filter applied.
     * Branch Manager: all requests in their branch.
     */
    public function indexHandoverRequests(Request $request): JsonResponse
    {
        try {
            $user = auth()->user();
            if (! $user instanceof Cashier && ! $user instanceof BranchManager) {
                return $this->errorResponse('Only cashiers and branch managers can view custody handover requests', 403);
            }

            $status = $request->query('status', 'pending');
            $query = CustodyHandoverRequest::with(['fromCashier:id,name,email,branch_id', 'toCashier:id,name,email,branch_id']);

            if ($user instanceof Cashier) {
                if ($status === 'pending') {
                    $query->where('to_cashier_id', $user->id);
                } else {
                    $query->where(function ($q) use ($user) {
                        $q->where('to_cashier_id', $user->id)->orWhere('from_cashier_id', $user->id);
                    });
                }
            } else {
                // Branch Manager: all requests in their branch
                $query->whereHas('fromCashier', fn ($q) => $q->where('branch_id', $user->branch_id));
            }

            $query->where('status', $status);
            $items = $query->orderByDesc('created_at')->paginate((int) $request->input('per_page', 15));

            $data = collect($items->items())->map(function (CustodyHandoverRequest $req) use ($user) {
                $isIncoming = $user instanceof Cashier && (string) $req->to_cashier_id === (string) $user->id;

                return [
                    'id' => $req->id,
                    'amount' => (float) $req->amount,
                    'status' => $req->status,
                    'notes' => $req->additional_notes,
                    'created_at' => $req->created_at->toIso8601String(),
                    'responded_at' => $req->responded_at?->toIso8601String(),
                    'from' => $req->fromCashier ? ['id' => $req->from_cashier_id, 'name' => $req->fromCashier->name] : null,
                    'to' => $req->toCashier ? ['id' => $req->to_cashier_id, 'name' => $req->toCashier->name] : null,
                    'isIncoming' => $isIncoming,
                ];
            });

            return $this->successResponse([
                'data' => $data,
                'meta' => [
                    'current_page' => $items->currentPage(),
                    'last_page' => $items->lastPage(),
                    'per_page' => $items->perPage(),
                    'total' => $items->total(),
                ],
            ], 'Custody handover requests retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Accept a custody handover request (recipient only). Records Cash OUT for sender, Cash IN for recipient.
     * POST /api/custody/handover-requests/{id}/accept
     */
    public function acceptHandoverRequest(string $id): JsonResponse
    {
        try {
            $user = auth()->user();
            if (! $user instanceof Cashier) {
                return $this->errorResponse('Only cashiers can accept custody handover requests', 403);
            }

            return $this->errorResponse(
                'Legacy custody handover is retired for cashiers; use the shift handover flow.',
                409,
                ['code' => 'LEGACY_CUSTODY_HANDOVER_RETIRED']
            );

            $req = CustodyHandoverRequest::with(['fromCashier', 'toCashier'])->findOrFail($id);
            if ($req->to_cashier_id !== $user->id) {
                return $this->errorResponse('You can only accept handover requests sent to you', 403);
            }
            if (! $req->isPending()) {
                return $this->errorResponse('This request has already been responded to', 400);
            }

            DB::transaction(function () use ($req) {
                $req->update(['status' => 'accepted', 'responded_at' => now()]);
                $this->cashierCustodyService->recordManualHandoverSent(
                    $req->from_cashier_id,
                    (float) $req->amount,
                    $req->toCashier?->name
                );
                $this->cashierCustodyService->recordManualHandoverReceived(
                    $req->to_cashier_id,
                    (float) $req->amount,
                    $req->fromCashier?->name
                );
            });

            return $this->successResponse([
                'handoverRequestId' => $req->id,
                'status' => 'accepted',
            ], 'Handover request accepted. Amount deducted from sender and added to your balance.');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Reject a custody handover request (recipient only). No ledger entries.
     * POST /api/custody/handover-requests/{id}/reject
     */
    public function rejectHandoverRequest(string $id): JsonResponse
    {
        try {
            $user = auth()->user();
            if (! $user instanceof Cashier) {
                return $this->errorResponse('Only cashiers can reject custody handover requests', 403);
            }

            $req = CustodyHandoverRequest::findOrFail($id);
            if ($req->to_cashier_id !== $user->id) {
                return $this->errorResponse('You can only reject handover requests sent to you', 403);
            }
            if (! $req->isPending()) {
                return $this->errorResponse('This request has already been responded to', 400);
            }

            $req->update(['status' => 'rejected', 'responded_at' => now()]);

            return $this->successResponse([
                'handoverRequestId' => $req->id,
                'status' => 'rejected',
            ], 'Handover request rejected.');
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

            // Brand Owner: branch managers only (per brand-owner contract)
            if ($user instanceof \Modules\BrandOwner\Models\BrandOwner) {
                $branchManagers = BranchManager::query()
                    ->active()
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

                return $this->successResponse([
                    'recipients' => $recipients,
                ], 'Recipients retrieved successfully');
            }

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
                        'id' => $c->id,
                        'type' => 'Cashier',
                        'name' => $c->name,
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
                        'id' => $bm->id,
                        'type' => 'Branch Manager',
                        'name' => $bm->name,
                        'email' => $bm->email,
                    ];
                }
            }

            // Branch Manager: Brand Owners FIRST, then other Branch Managers in same branch
            if ($user instanceof BranchManager) {
                foreach ($this->getBrandOwnerRecipients() as $bo) {
                    $recipients[] = $bo;
                }

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
            }

            return $this->successResponse([
                'recipients' => $recipients,
            ], 'Recipients retrieved successfully');
        } catch (\Exception $e) {
            return $this->errorResponse($e->getMessage(), 500);
        }
    }

    /**
     * Brand owner recipients (active brand owners).
     */
    protected function getBrandOwnerRecipients(): array
    {
        return \Modules\BrandOwner\Models\BrandOwner::query()
            ->where('is_active', true)
            ->where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'email'])
            ->map(fn ($bo) => [
                'id' => $bo->id,
                'type' => 'Brand Owner',
                'name' => $bo->name,
                'email' => $bo->email,
            ])
            ->all();
    }

    private function normalizeHandoverMethod(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        return match (strtolower($value)) {
            'cash_handover', 'cash handover' => 'Cash Handover',
            'bank_transfer', 'bank transfer' => 'Bank Transfer',
            default => $value,
        };
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
