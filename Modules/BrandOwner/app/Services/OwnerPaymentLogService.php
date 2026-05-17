<?php

namespace Modules\BrandOwner\Services;

use Illuminate\Database\Eloquent\Builder;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\BrandOwner\Models\CashSalesTransferRequest;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CustodyRequest;

/**
 * Aggregates Owner Payment Logs across:
 *  - Owner Payment Form submissions (custody_requests where created_by_brand_owner_id is set)
 *  - Cash Sales Transfers received by the brand owner (cash_sales_transfer_requests)
 *
 * "isIncome" reflects flow into the brand owner: payment-form items are outflow (false),
 * cash-sales-transfers are inflow (true).
 */
class OwnerPaymentLogService
{
    public function listForBrandOwner(BrandOwner $brandOwner, array $filters, int $page, int $pageSize): array
    {
        $type       = $this->normalizeMethod($filters['type'] ?? null);
        $sortByDate = $filters['sortByDate'] ?? null;
        $branchId   = $filters['branchId'] ?? null;

        $payments  = $this->fetchPaymentForm($brandOwner, $type, $sortByDate, $branchId);
        $transfers = $this->fetchCashSalesTransfers($brandOwner, $type, $sortByDate, $branchId);

        $items = $payments->merge($transfers)
            ->sortByDesc('dateTimeRaw')
            ->values();

        $total = $items->count();
        $offset = ($page - 1) * $pageSize;
        $paged = $items->slice($offset, $pageSize)->values()->map(fn ($i) => $this->mapForApi($i))->all();

        $totalCashIn  = $items->where('isIncome', true)->sum('amount');
        $totalCashOut = $items->where('isIncome', false)->sum('amount');

        return [
            'summary' => [
                'totalCashIn'  => (float) $totalCashIn,
                'totalCashOut' => (float) $totalCashOut,
            ],
            'data' => $paged,
            'meta' => [
                'current_page' => $page,
                'per_page'     => $pageSize,
                'total'        => $total,
                'last_page'    => (int) ceil(max($total, 1) / max($pageSize, 1)),
            ],
        ];
    }

    public function findDetails(BrandOwner $brandOwner, string $id): ?array
    {
        $payment = CustodyRequest::with(['branchManager.branch'])
            ->where('id', $id)
            ->where('created_by_brand_owner_id', $brandOwner->id)
            ->first();
        if ($payment) {
            return $this->paymentFormDetails($payment);
        }

        $transfer = CashSalesTransferRequest::with(['branch'])
            ->where('id', $id)
            ->where('brand_owner_id', $brandOwner->id)
            ->first();
        if ($transfer) {
            return $this->cashSalesTransferDetails($transfer);
        }

        return null;
    }

    private function fetchPaymentForm(BrandOwner $brandOwner, ?string $method, ?string $sortByDate, ?string $branchId)
    {
        $query = CustodyRequest::query()
            ->with(['branchManager.branch'])
            ->where('created_by_brand_owner_id', $brandOwner->id);

        if ($method) {
            $query->where('preferred_receipt_method', $method);
        }
        if ($branchId) {
            $query->where('branch_id', $branchId);
        }
        $this->applyTimeFilter($query, $sortByDate);

        return $query->get()->map(function (CustodyRequest $r) {
            return [
                'id'           => $r->id,
                'title'        => 'Owner Payment',
                'branchName'   => $r->branchManager?->branch?->name,
                'amount'       => (float) $r->requested_amount,
                'dateTimeRaw'  => $r->created_at,
                'isIncome'     => false,
                'methodLabel'  => $this->methodLabel($r->preferred_receipt_method),
                'submittedBy'  => 'Brand Owner',
            ];
        });
    }

    private function fetchCashSalesTransfers(BrandOwner $brandOwner, ?string $method, ?string $sortByDate, ?string $branchId)
    {
        $query = CashSalesTransferRequest::query()
            ->with(['branch'])
            ->where('brand_owner_id', $brandOwner->id);

        if ($method) {
            $query->where('handover_method', $method);
        }
        if ($branchId) {
            $query->where('branch_id', $branchId);
        }
        $this->applyTimeFilter($query, $sortByDate);

        return $query->get()->map(function (CashSalesTransferRequest $t) {
            $sender = $this->resolveSenderName($t->sender_id, $t->sender_type);

            return [
                'id'           => $t->id,
                'title'        => 'Cash Sales Transfer',
                'branchName'   => $t->branch?->name,
                'amount'       => (float) $t->handover_amount,
                'dateTimeRaw'  => $t->created_at,
                'isIncome'     => true,
                'methodLabel'  => $this->methodLabel($t->handover_method),
                'submittedBy'  => $sender ?? 'Branch',
            ];
        });
    }

    private function mapForApi(array $item): array
    {
        return [
            'id'          => $item['id'],
            'title'       => $item['title'],
            'branchName'  => $item['branchName'],
            'amount'      => $item['amount'],
            'dateTime'    => $item['dateTimeRaw']?->toIso8601String(),
            'isIncome'    => $item['isIncome'],
            'methodLabel' => $item['methodLabel'],
            'submittedBy' => $item['submittedBy'],
        ];
    }

    private function paymentFormDetails(CustodyRequest $r): array
    {
        return [
            'id'          => $r->id,
            'title'       => 'Owner Payment',
            'description' => $r->purpose ?? $r->additional_notes,
            'amount'      => (float) $r->requested_amount,
            'branchName'  => $r->branchManager?->branch?->name,
            'managerName' => $r->branchManager?->name,
            'dateTime'    => $r->created_at?->toIso8601String(),
            'methodLabel' => $this->methodLabel($r->preferred_receipt_method),
            'isIncome'    => false,
        ];
    }

    private function cashSalesTransferDetails(CashSalesTransferRequest $t): array
    {
        $sender = $this->resolveSenderName($t->sender_id, $t->sender_type);

        return [
            'id'          => $t->id,
            'title'       => 'Cash Sales Transfer',
            'description' => $t->additional_notes,
            'amount'      => (float) $t->handover_amount,
            'branchName'  => $t->branch?->name,
            'managerName' => $sender,
            'dateTime'    => $t->created_at?->toIso8601String(),
            'methodLabel' => $this->methodLabel($t->handover_method),
            'isIncome'    => true,
        ];
    }

    private function applyTimeFilter(Builder $query, ?string $sortByDate): void
    {
        if (!$sortByDate) {
            $query->orderBy('created_at', 'desc');
            return;
        }

        $start = match ($sortByDate) {
            'last_24_hours' => now()->subDay(),
            'last_7_days'   => now()->subDays(7),
            'last_30_days'  => now()->subDays(30),
            'last_6_months' => now()->subMonths(6),
            default         => null,
        };
        if ($start) {
            $query->where('created_at', '>=', $start);
        }
        $query->orderBy('created_at', 'desc');
    }

    private function normalizeMethod(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        return match (strtolower($value)) {
            'cash_handover', 'cash handover' => 'Cash Handover',
            'bank_transfer', 'bank transfer' => 'Bank Transfer',
            default                          => null,
        };
    }

    private function methodLabel(?string $value): ?string
    {
        if (!$value) {
            return null;
        }

        return match ($value) {
            'Cash Handover' => 'cash_handover',
            'Bank Transfer' => 'bank_transfer',
            default         => strtolower(str_replace(' ', '_', $value)),
        };
    }

    private function resolveSenderName(?string $senderId, ?string $senderType): ?string
    {
        if (!$senderId) {
            return null;
        }

        return $senderType === 'cashier'
            ? Cashier::find($senderId)?->name
            : BranchManager::find($senderId)?->name;
    }
}
