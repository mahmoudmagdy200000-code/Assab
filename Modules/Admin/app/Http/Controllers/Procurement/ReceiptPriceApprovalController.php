<?php

namespace Modules\Admin\Http\Controllers\Procurement;

use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Purchase\Services\GoodsReceiptService;
use Modules\Purchase\Services\ReceiptPriceApprovalService;

class ReceiptPriceApprovalController extends AsabController
{
    public function review(Request $request, string $receipt, ReceiptPriceApprovalService $service)
    {
        return $this->run(fn () => $this->ok($service->review($receipt, $request->user())));
    }

    public function approve(Request $request, string $receipt, ReceiptPriceApprovalService $service)
    {
        return $this->run(function () use ($request, $receipt, $service) {
            $data = $request->validate(['context_hash' => 'required|string|size:64',
                'receipt_item_ids' => 'required|array|min:1', 'receipt_item_ids.*' => 'required|string|distinct',
                'reason' => 'required|string|max:1000']);
            return $this->ok($service->approve($receipt, $request->user(), $data));
        });
    }

    public function revoke(Request $request, string $receipt, string $decision, ReceiptPriceApprovalService $service)
    {
        return $this->run(function () use ($request, $receipt, $decision, $service) {
            $data = $request->validate(['reason' => 'required|string|max:1000']);
            return $this->ok($service->revoke($receipt, $decision, $request->user(), $data['reason']));
        });
    }

    public function reject(Request $request, string $receipt, ReceiptPriceApprovalService $service)
    {
        return $this->run(function () use ($request, $receipt, $service) {
            $data = $request->validate(['reason' => 'required|string|max:1000']);
            return $this->ok($service->reject($receipt, $request->user(), $data['reason']));
        });
    }

    public function approveExcess(Request $request, string $receipt, GoodsReceiptService $service)
    {
        return $this->run(function () use ($request, $receipt, $service) {
            $data = $request->validate([
                'receipt_item_ids' => 'sometimes|array',
                'receipt_item_ids.*' => 'required|string|distinct',
            ]);
            return $this->ok($service->approveExcess($receipt, $request->user(), $data['receipt_item_ids'] ?? null));
        });
    }

    public function rejectExcess(Request $request, string $receipt, GoodsReceiptService $service)
    {
        return $this->run(function () use ($request, $receipt, $service) {
            $data = $request->validate([
                'reason' => 'required|string|max:1000',
                'receipt_item_ids' => 'sometimes|array',
                'receipt_item_ids.*' => 'required|string|distinct',
            ]);
            return $this->ok($service->rejectExcess($receipt, $request->user(), $data['reason'], $data['receipt_item_ids'] ?? null));
        });
    }
}
