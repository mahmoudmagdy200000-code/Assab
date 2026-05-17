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

    private function buildApprovalBlock(CashSalesTransferRequest $request): array
    {
        $approvedByName = null;
        if ($request->status === 'approved' && $request->approved_by) {
            $approvedByName = BrandOwner::find($request->approved_by)?->name;
        }
        if ($request->status === 'rejected' && $request->rejected_by) {
            $approvedByName = BrandOwner::find($request->rejected_by)?->name;
        }

        $timestamp = match ($request->status) {
            'approved' => $request->approved_at?->toIso8601String(),
            'rejected' => $request->rejected_at?->toIso8601String(),
            default    => null,
        };

        return [
            'status'         => $request->status,
            'approvedBy'     => $approvedByName,
            'approvedAt'     => $timestamp,
            'rejectedReason' => $request->rejection_reason,
        ];
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
