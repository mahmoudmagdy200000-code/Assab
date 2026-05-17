<?php

namespace Modules\BrandOwner\Services;

use Illuminate\Support\Facades\DB;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\BrandOwner\Models\CashSalesTransferRequest;
use Modules\Cashier\Models\Cashier;

class CashSalesTransferService
{
    /**
     * Paginated list for /brand-owner/cash-sales-transfers.
     * Filters: fromDate, toDate, storeId (branch id), status.
     */
    public function listForBrandOwner(string $brandOwnerId, array $filters, int $page, int $pageSize): array
    {
        $query = CashSalesTransferRequest::query()
            ->where('brand_owner_id', $brandOwnerId);

        if (!empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (!empty($filters['storeId'])) {
            $query->where('branch_id', $filters['storeId']);
        }
        if (!empty($filters['fromDate'])) {
            $query->whereDate('handover_date', '>=', $filters['fromDate']);
        }
        if (!empty($filters['toDate'])) {
            $query->whereDate('handover_date', '<=', $filters['toDate']);
        }

        $paginator = $query->orderBy('created_at', 'desc')->paginate($pageSize, ['*'], 'page', $page);

        $data = collect($paginator->items())->map(function (CashSalesTransferRequest $t) {
            $sender = $this->resolveSender($t->sender_id, $t->sender_type);

            return [
                'id'          => $t->id,
                'submittedBy' => $sender['name'] ?? null,
                'amount'      => (float) $t->handover_amount,
                'dateTime'    => $t->created_at?->toIso8601String(),
                'status'      => $t->status,
            ];
        })->values()->all();

        return [
            'data' => $data,
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page'     => $paginator->perPage(),
                'total'        => $paginator->total(),
                'last_page'    => $paginator->lastPage(),
            ],
        ];
    }

    /**
     * Get details payload for the brand-owner cash sales transfer details screen.
     */
    public function getDetails(string $id): array
    {
        $request = CashSalesTransferRequest::with(['branch', 'brandOwner'])->findOrFail($id);

        $sender = $this->resolveSender($request->sender_id, $request->sender_type);

        return [
            'requestId' => $request->id,
            'status'    => $request->status,
            'summary'   => [
                'transferFrom'   => $sender['name'] ?? null,
                'recipient'      => $request->brandOwner?->name,
                'handOverAmount' => (float) $request->handover_amount,
                'handoverMethod' => $request->handover_method,
                'handoverDate'   => $request->handover_date?->toIso8601String(),
            ],
            'senderDetails' => [
                'name'       => $sender['name'] ?? null,
                'image'      => $sender['image'] ?? null,
                'branchName' => $request->branch?->name,
            ],
            'timelines' => [],
            'approval'  => $this->buildApprovalBlock($request),
        ];
    }

    public function approve(string $id, BrandOwner $actor): CashSalesTransferRequest
    {
        return DB::transaction(function () use ($id, $actor) {
            $request = CashSalesTransferRequest::lockForUpdate()->findOrFail($id);

            if (!$request->isPending()) {
                throw new \RuntimeException('Only pending cash sales transfer requests can be approved.');
            }

            $request->update([
                'status'      => 'approved',
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ]);

            return $request->fresh();
        });
    }

    public function reject(string $id, BrandOwner $actor, string $reason): CashSalesTransferRequest
    {
        return DB::transaction(function () use ($id, $actor, $reason) {
            $request = CashSalesTransferRequest::lockForUpdate()->findOrFail($id);

            if (!$request->isPending()) {
                throw new \RuntimeException('Only pending cash sales transfer requests can be rejected.');
            }

            $request->update([
                'status'           => 'rejected',
                'rejected_by'      => $actor->id,
                'rejected_at'      => now(),
                'rejection_reason' => $reason,
            ]);

            return $request->fresh();
        });
    }

    /**
     * Approval is an array of approvers (per BrandOwnerSalesTransferDetailsModel.approval).
     * Each entry: { id, name, role, imageUrl?, status }.
     */
    private function buildApprovalBlock(CashSalesTransferRequest $request): array
    {
        $actorId = match ($request->status) {
            'approved' => $request->approved_by,
            'rejected' => $request->rejected_by,
            default    => null,
        };

        if (!$actorId) {
            return [];
        }

        $brandOwner = BrandOwner::find($actorId);

        return [[
            'id'       => $actorId,
            'name'     => $brandOwner?->name,
            'role'     => 'brand_owner',
            'imageUrl' => $brandOwner?->image ? asset('storage/' . $brandOwner->image) : null,
            'status'   => $request->status,
        ]];
    }

    private function resolveSender(string $senderId, string $senderType): array
    {
        if ($senderType === 'cashier') {
            $c = Cashier::find($senderId);
            return [
                'name'  => $c?->name,
                'image' => $c?->image ? asset('storage/' . $c->image) : null,
            ];
        }

        $bm = BranchManager::find($senderId);
        return [
            'name'  => $bm?->name,
            'image' => $bm?->image ? asset('storage/' . $bm->image) : null,
        ];
    }
}
