<?php

namespace Modules\Purchase\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Http\Middleware\EnsureAsabRole;
use Modules\Admin\Http\Middleware\ResolveTenant;
use Modules\Admin\Models\ApprovalStep;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\TenantBranchResolver;
use Modules\Admin\Support\TenantContext;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Models\GoodsReceipt;
use Modules\Purchase\Models\PurchaseInvoice;

/** Receipt/invoice price decisions only. Excess release and financial approval remain separate. */
class ReceiptPriceApprovalService
{
    public function review(string $receiptId, AsabUser $actor): array
    {
        return DB::transaction(function () use ($receiptId, $actor) {
            $receipt = GoodsReceipt::whereKey($receiptId)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $receipt);
            $this->assertSupported($receipt);
            $invoice = PurchaseInvoice::where('goods_receipt_id', $receiptId)->firstOrFail();
            return $this->reviewData($receipt, $invoice);
        });
    }

    public function approve(string $receiptId, AsabUser $actor, array $data): Operation
    {
        return DB::transaction(function () use ($receiptId, $actor, $data) {
            $receipt = GoodsReceipt::whereKey($receiptId)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $receipt);
            $this->assertSupported($receipt);
            $invoice = PurchaseInvoice::where('goods_receipt_id', $receiptId)->lockForUpdate()->firstOrFail();
            $review = $this->reviewData($receipt, $invoice);
            if (! hash_equals($review['context_hash'], $data['context_hash'])) {
                throw new AsabException('STALE_PRICE_REVIEW', 'Invoice or receipt changed; review again.', status: 409);
            }
            $ids = $data['receipt_item_ids'];
            sort($ids);
            $owned = array_column($review['lines'], 'receipt_item_id');
            if (count($ids) !== count(array_unique($ids)) || array_diff($ids, $owned) !== []) {
                throw new AsabException('INVALID_PRICE_LINES', 'Every approved line must belong to this receipt.', status: 422);
            }
            $payload = ['context_hash' => $review['context_hash'], 'receipt_item_ids' => $ids, 'reason' => $data['reason'],
                'receipt_hash' => $this->receiptHash($receipt),
                'approved_targets' => array_column(array_filter($review['lines'], fn ($line) => in_array($line['receipt_item_id'], $ids, true)), 'invoice_available_halalas', 'receipt_item_id')];
            $existing = $this->currentOperation($receipt);
            // Intrinsic replay suppression also covers callers without an HTTP idempotency key.
            if ($existing && $this->valid($existing, $receipt, $invoice) && $existing->payload === $payload) {
                return $existing;
            }
            if ($existing && ! $existing->trashed() && $existing->status === Operation::STATUS_APPROVED) {
                $existing->update(['status' => Operation::STATUS_REJECTED, 'reject_reason' => 'superseded']);
                $this->audit($existing, $actor, 'supersede', 'superseded by a new price decision');
            }
            $operation = Operation::create([
                'public_id' => 'PP-'.strtoupper(bin2hex(random_bytes(6))),
                'company_id' => $receipt->purchaseOrder->branch->asab_company_id,
                'branch_id' => $receipt->purchaseOrder->branch_id,
                'module_key' => 'purchases', 'source_module' => 'purchase_price_decision',
                'source_id' => $receipt->id, 'payload' => $payload,
                'amount' => (int) round((float) $invoice->amount_before_tax * 100),
                'status' => Operation::STATUS_APPROVED,
                'approved_by_id' => $actor->id, 'approved_at' => now(), 'operation_date' => today(),
            ]);
            $step = $this->audit($operation, $actor, 'approve', $data['reason']);
            $step->update(['meta' => ['provenance_version' => 1, 'seal' => $this->seal($operation->fresh(), $step)]]);
            $meta = $receipt->cost_adjustment_meta ?? [];
            $meta['price_approval_id'] = $operation->id;
            $receipt->update(['cost_adjustment_meta' => $meta]);
            app(GoodsReceiptService::class)->reconcileApprovedInvoice($receipt);
            return $operation->fresh();
        });
    }

    public function revoke(string $receiptId, string $decisionId, AsabUser $actor, string $reason): Operation
    {
        return DB::transaction(function () use ($receiptId, $decisionId, $actor, $reason) {
            $receipt = GoodsReceipt::whereKey($receiptId)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $receipt);
            $this->assertSupported($receipt);
            $operation = Operation::withoutGlobalScopes()->whereKey($decisionId)
                ->where('source_module', 'purchase_price_decision')->where('source_id', $receiptId)
                ->where('company_id', $receipt->purchaseOrder->branch->asab_company_id)
                ->where('branch_id', $receipt->purchaseOrder->branch_id)->whereNull('deleted_at')
                ->lockForUpdate()->firstOrFail();
            if ($operation->status !== Operation::STATUS_REJECTED) {
                $operation->update(['status' => Operation::STATUS_REJECTED, 'reject_reason' => $reason,
                    'rejected_by_id' => $actor->id, 'rejected_at' => now()]);
                $this->audit($operation, $actor, 'revoke', $reason);
                app(GoodsReceiptService::class)->reconcileApprovedInvoice($receipt);
            }
            return $operation->fresh();
        });
    }

    /**
     * T3.1 / REG-DEC-05 / REG-DEC-06: Reject price variance, retain approved PO baseline,
     * and flag awaiting_corrected_document.
     */
    public function reject(string $receiptId, AsabUser $actor, string $reason): GoodsReceipt
    {
        return DB::transaction(function () use ($receiptId, $actor, $reason) {
            $receipt = GoodsReceipt::whereKey($receiptId)->lockForUpdate()->firstOrFail();
            $this->authorize($actor, $receipt);
            $this->assertSupported($receipt);

            $existing = $this->currentOperation($receipt);
            if ($existing && ! $existing->trashed() && $existing->status === Operation::STATUS_APPROVED) {
                $existing->update([
                    'status' => Operation::STATUS_REJECTED,
                    'reject_reason' => $reason,
                    'rejected_by_id' => $actor->id,
                    'rejected_at' => now(),
                ]);
                $this->audit($existing, $actor, 'supersede', $reason);
            }

            $meta = $receipt->cost_adjustment_meta ?? [];
            $meta['awaiting_corrected_document'] = true;
            $meta['price_rejection_reason'] = $reason;
            $meta['rejected_by_id'] = $actor->id;
            $meta['rejected_at'] = now()->toISOString();
            $receipt->update([
                'cost_adjustment_status' => 'awaiting_corrected_document',
                'cost_adjustment_meta' => $meta,
            ]);

            app(GoodsReceiptService::class)->reconcileApprovedInvoice($receipt);

            return $receipt->fresh();
        });
    }

    /** IDs authorized for this exact immutable invoice/receipt revision; no fallback to older decisions. */
    public function authorizedLines(GoodsReceipt $receipt, PurchaseInvoice $invoice): array
    {
        if ($receipt->purchaseOrder?->order_type !== OrderType::VIA_PURCHASING_OFFICER) {
            return [];
        }
        $operation = $this->currentOperation($receipt);
        return $operation && $this->valid($operation, $receipt, $invoice)
            ? $operation->payload['receipt_item_ids'] : [];
    }

    /** A changed invoice cannot erase a previously posted authorized value. It remains the
     * approved baseline, without authorizing the new delta. Revocation/deletion/auth loss denies this too. */
    public function previousApprovedTargets(GoodsReceipt $receipt, PurchaseInvoice $invoice): array
    {
        if ($receipt->purchaseOrder?->order_type !== OrderType::VIA_PURCHASING_OFFICER) {
            return [];
        }
        $op = $this->currentOperation($receipt);
        return $op && $this->valid($op, $receipt, $invoice, false) ? ($op->payload['approved_targets'] ?? []) : [];
    }
    private function currentOperation(GoodsReceipt $receipt): ?Operation
    {
        $id = ($receipt->cost_adjustment_meta ?? [])['price_approval_id'] ?? null;
        return $id ? Operation::withoutGlobalScopes()->whereKey($id)->first() : null;
    }

    private function valid(Operation $op, GoodsReceipt $receipt, PurchaseInvoice $invoice, bool $requireCurrent = true): bool
    {
        $receipt->loadMissing('purchaseOrder.branch');
        if ($op->trashed() || $op->status !== Operation::STATUS_APPROVED || ! $op->approved_at
            || $op->rejected_at !== null || $op->reject_reason !== null
            || $op->module_key !== 'purchases' || $op->source_module !== 'purchase_price_decision'
            || (string) $op->source_id !== (string) $receipt->id
            || (string) $op->branch_id !== (string) $receipt->purchaseOrder->branch_id
            || (string) $op->company_id !== (string) $receipt->purchaseOrder->branch->asab_company_id
            || ($op->payload['receipt_hash'] ?? null) !== $this->receiptHash($receipt)
            || ($requireCurrent && ($op->payload['context_hash'] ?? null) !== $this->contextHash($receipt, $invoice))
            || ! is_array($op->payload['receipt_item_ids'] ?? null)) {
            return false;
        }
        $step = ApprovalStep::where('operation_id', $op->id)->where('action', 'approve')->first();
        if (! $step || ! $step->occurred_at || $step->stage_id !== 'approved'
            || (string) $step->actor_user_id !== (string) $op->approved_by_id
            || ! is_string($step->meta['seal'] ?? null)
            || ! hash_equals($this->seal($op, $step), $step->meta['seal'])) {
            return false;
        }
        $actor = AsabUser::find($op->approved_by_id);
        if (! $actor) {
            return false;
        }
        try {
            $this->authorize($actor, $receipt);
        } catch (AsabException $e) {
            return false;
        }
        return true;
    }

    private function seal(Operation $op, ApprovalStep $step): string
    {
        $key = config('app.key');
        if (! is_string($key) || $key === '') {
            throw new AsabException('PRICE_APPROVAL_KEY_UNAVAILABLE', 'Server approval key unavailable.', status: 503);
        }
        // Bind the audit event, actor, tenant, receipt, values and exact revision. Seeded columns are insufficient.
        return hash_hmac('sha256', json_encode(['receipt-price-v1', $op->id, $op->company_id, $op->branch_id,
            $op->source_id, $op->approved_by_id, $op->approved_at->toISOString(), $op->amount,
            $op->payload, $step->id, $step->actor_user_id, $step->occurred_at->toISOString()], JSON_THROW_ON_ERROR), $key);
    }

    public function contextHash(GoodsReceipt $receipt, PurchaseInvoice $invoice): string
    {
        $receipt->loadMissing(['items', 'purchaseOrder.branch']);
        return hash('sha256', json_encode([
            $receipt->id, $receipt->purchaseOrder->branch->asab_company_id,
            $receipt->purchaseOrder->branch_id, $receipt->purchaseOrder->order_type->value,
            ($receipt->cost_adjustment_meta ?? [])['invoice_price_revision'] ?? 0,
            $this->invoiceFacts($invoice), $this->receiptHash($receipt),
        ], JSON_THROW_ON_ERROR));
    }

    private function receiptHash(GoodsReceipt $receipt): string
    {
        $lines = $receipt->items->sortBy('id')->map(fn ($line) => [
            (string) $line->id, (string) $line->item_id, (string) $line->quantity_received,
            (string) $line->quantity_available_posted, (string) $line->quantity_excess_reserved,
            (string) $line->unit_price, $line->excess_status,
        ])->values()->all();
        return hash('sha256', json_encode($lines, JSON_THROW_ON_ERROR));
    }

    public function invoiceFacts(PurchaseInvoice $invoice): array
    {
        return [$invoice->id, $invoice->invoice_number, $invoice->invoice_date?->toDateString(),
            (string) $invoice->amount_before_tax, (string) $invoice->tax_rate,
            (string) $invoice->deduction_amount, $invoice->deduction_details];
    }

    private function reviewData(GoodsReceipt $receipt, PurchaseInvoice $invoice): array
    {
        $receipt->loadMissing('items');
        $items = $receipt->items->filter(fn ($line) => (float) $line->quantity_received > 0)->sortBy('id')->values();
        $weights = $items->map(fn ($line) => max(0.01, (float) $line->quantity_received * max(0.01, (float) $line->unit_price)));
        $total = (int) round((float) $invoice->amount_before_tax * 100);
        $allocated = 0;
        $lines = [];
        foreach ($items as $index => $line) {
            $share = $index === $items->count() - 1 ? $total - $allocated
                : (int) round($total * $weights[$index] / max(0.01, $weights->sum()));
            $allocated += $share;
            $available = max(0, (float) ($line->quantity_available_posted ?? $line->quantity_received));
            $reserved = max(0, (float) $line->quantity_excess_reserved);
            $availableShare = $available <= 0 ? 0 : ($reserved > 0
                ? (int) round($share * $available / max(0.001, $available + $reserved)) : $share);
            $lines[] = ['receipt_item_id' => $line->id, 'item_id' => $line->item_id,
                'quantity_received' => $line->quantity_received, 'available_qty' => $available,
                'reserved_qty' => $reserved, 'po_unit_price' => $line->unit_price,
                'invoice_line_halalas' => $share, 'invoice_available_halalas' => $availableShare,
                'invoice_reserved_halalas' => $share - $availableShare];
        }
        return ['receipt_id' => $receipt->id, 'invoice_id' => $invoice->id,
            'amount_before_tax' => $invoice->amount_before_tax,
            'context_hash' => $this->contextHash($receipt, $invoice), 'lines' => $lines];
    }
    private function assertSupported(GoodsReceipt $receipt): void
    {
        $receipt->loadMissing('purchaseOrder.branch');
        if ($receipt->purchaseOrder?->order_type !== OrderType::VIA_PURCHASING_OFFICER) {
            throw new AsabException('APPROVAL_ROLE_PENDING', 'DEC-OPEN-01: price approval unavailable for this order type.', status: 403);
        }
    }

    private function audit(Operation $operation, AsabUser $actor, string $action, string $reason): ApprovalStep
    {
        return ApprovalStep::create(['operation_id' => $operation->id,
            'stage_id' => $action === 'approve' ? 'approved' : 'rejected', 'action' => $action,
            'actor_user_id' => $actor->id, 'actor_label' => $actor->name, 'note' => $reason, 'occurred_at' => now()]);
    }

    /** Reuse canonical middleware and hierarchical resolver for both issuing and consuming an approval. */
    public function authorize(AsabUser $actor, GoodsReceipt $receipt): void
    {
        $receipt->loadMissing('purchaseOrder.branch');
        $saved = app(TenantContext::class);
        $ctx = new TenantContext;
        app()->instance(TenantContext::class, $ctx);
        try {
            $request = Request::create('/api/v1/procurement/receipt-price-approval');
            $request->setUserResolver(fn () => $actor);
            $response = app(ResolveTenant::class)->handle($request, fn ($r) =>
                app(EnsureAsabRole::class)->handle($r, fn () => response()->json([]), 'procurement'));
            $company = (string) $receipt->purchaseOrder?->branch?->asab_company_id;
            if ($response->getStatusCode() !== 200 || $ctx->roleKey !== 'procurement' || $company === '') {
                throw new AsabException('PRICE_APPROVAL_FORBIDDEN', 'Active procurement authorization required.', status: 403);
            }
            if ($ctx->isPlatform && $ctx->scope !== 'all') {
                // Restricted platform grants still resolve against the target tenant's hierarchy.
                $ctx->isPlatform = false;
                $ctx->companyId = $company;
                $ctx->companyIds = [$company];
            }
            if (! $ctx->isPlatform && ! in_array($company, $ctx->companyIds(), true)) {
                throw new AsabException('PRICE_APPROVAL_FORBIDDEN', 'Company outside procurement scope.', status: 403);
            }
            $branches = app(TenantBranchResolver::class)->legacyBranchIds($ctx);
            if ($branches !== null && ! in_array((string) $receipt->purchaseOrder->branch_id, $branches, true)) {
                throw new AsabException('PRICE_APPROVAL_FORBIDDEN', 'Branch outside procurement scope.', status: 403);
            }
        } finally {
            app()->instance(TenantContext::class, $saved);
        }
    }
}
