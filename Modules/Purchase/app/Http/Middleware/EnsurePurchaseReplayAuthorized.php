<?php

namespace Modules\Purchase\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Models\GoodsReceipt;
use Modules\Purchase\Models\GoodsReceiptItem;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseVariance;
use Modules\Purchase\Models\ReturnOrder;
use Modules\Purchase\Models\SavedPriceComparison;
use Modules\Purchase\Services\PurchaseAuthorizationService;
use Modules\RecurringOrder\Models\RecurringOrder;
use Symfony\Component\HttpFoundation\Response;

/**
 * Fail-closed current-state resource, nested-resource, and source authorization for
 * mutating Purchase, RecurringOrder, and BrandOwner-return routes.
 *
 * Runs AFTER `purchase.actor:*` and BEFORE `asab.idempotency` so a stored replay
 * snapshot is never returned when authorization cannot be positively established:
 * - Unmapped protected handlers fail closed (403).
 * - Non-DELETE resource routes require an active (non-trashed) resource and current ownership.
 * - Explicit DELETE routes only accept an already soft-deleted resource for an authorized
 *   idempotency replay when the actor still holds current ownership of that resource.
 * - Hard-deleted or non-existent required resources fail closed (403).
 */
class EnsurePurchaseReplayAuthorized
{
    public function __construct(private readonly PurchaseAuthorizationService $auth) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->method(), ['GET', 'HEAD', 'OPTIONS'], true)) {
            return $next($request);
        }

        $user = $request->user();
        $rawName = (string) ($request->route()?->getName() ?? '');
        $name = preg_replace('/^(apilocale\.|api\.)+/', '', $rawName) ?? $rawName;

        if (! $this->authorizeHandler($request, $user, $name) || ! $this->authorizeSources($request, $user, $name)) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized access to this purchase resource.',
            ], 403);
        }

        return $next($request);
    }

    private function authorizeHandler(Request $request, mixed $user, string $name): bool
    {
        $route = $request->route();
        $p = fn (string $key): ?string => ($v = $route?->parameter($key)) !== null ? (string) $v : null;
        $isDelete = strtoupper($request->method()) === 'DELETE';

        // 1. BrandOwner escalation decisions (both /api and /api/v1 prefixes normalize here).
        if (in_array($name, [
            'brand-owner.purchase.returns.approve-escalation',
            'brand-owner.purchase.returns.reject-escalation',
        ], true)) {
            $return = $this->findActive(ReturnOrder::class, $p('returnId'));

            return $return !== null && $this->auth->canDecideEscalation($user, $return);
        }

        // 2. Explicit creation / stateless routes with no route resource ID.
        if (in_array($name, [
            'purchase.orders.store',
            'purchase.orders.compare-prices',
            'purchase.orders.compare-prices.save',
            'purchase.receiving.without-order',
        ], true)) {
            return $this->auth->resolveActingBranchIds($user, false) !== [];
        }

        if ($name === 'recurring-orders.store') {
            return $this->auth->ensureActiveBranchManager($user) !== null;
        }

        if (in_array($name, ['purchase.returns.store', 'purchase.returns.save-draft'], true)) {
            if ($this->auth->resolveActingBranchIds($user, false) === []) {
                return false;
            }
            $poId = $request->input('purchase_order_id');
            if ($poId === null || $poId === '') {
                // Allow empty/invalid payloads to reach 422 validation unless a stored replay key exists.
                return ! $this->hasStoredReplay($request, $user);
            }
            $po = $this->findActive(PurchaseOrder::class, (string) $poId);

            return $po !== null && $this->auth->canAccessPurchaseOrder($user, $po, false);
        }

        // 3. Explicit DELETE routes (explicit contract for active or replayed soft-deleted rows).
        if ($isDelete) {
            return $this->authorizeDelete($request, $user, $name, $p('id'));
        }

        // 4. RecurringOrder mutations.
        if (in_array($name, [
            'recurring-orders.update',
            'recurring-orders.pause',
            'recurring-orders.resume',
        ], true)) {
            $order = $this->findActive(RecurringOrder::class, $p('id'));
            if ($order === null) {
                return false;
            }
            $branchIds = $this->auth->resolveActingBranchIds($user, false);

            return $branchIds !== [] && in_array((string) $order->branch_id, $branchIds, true);
        }

        // 5. Return mutations (BranchManager / branch actor).
        if (in_array($name, [
            'purchase.returns.update',
            'purchase.returns.submit',
            'purchase.returns.accept-rejection',
            'purchase.returns.escalate',
        ], true)) {
            $return = $this->findActive(ReturnOrder::class, $p('id'));

            return $return !== null && $this->auth->canMutateReturnOrder($user, $return);
        }

        // 6. Goods Receiving routes.
        if (in_array($name, [
            'purchase.receiving.start',
            'purchase.receiving.receive-internal-transfer',
            'purchase.receiving.close-transfer-variance',
        ], true)) {
            $order = $this->findActive(PurchaseOrder::class, $p('orderId'));
            if ($order === null) {
                return false;
            }

            if (in_array($name, ['purchase.receiving.receive-internal-transfer', 'purchase.receiving.close-transfer-variance'], true)) {
                return $this->auth->canReceiveInternalTransfer($user, $order, true);
            }

            if (! $this->auth->canReceivePurchaseOrder($user, $order, true)) {
                return false;
            }

            return $this->validateOrderItemIds($order, (array) $request->input('items', []), 'item_id');
        }

        if (in_array($name, [
            'purchase.receiving.variance-action',
            'purchase.receiving.accept-rejection',
            'purchase.receiving.escalate-rejection',
        ], true)) {
            $variance = $this->findActive(PurchaseVariance::class, $p('varianceId'));

            return $variance !== null && $this->auth->canAccessVariance($user, $variance);
        }

        if ($name === 'purchase.receiving.supplier-response') {
            $variance = $this->findActive(PurchaseVariance::class, $p('varianceId'));

            return $variance !== null && $this->auth->canRespondToVariance($user, $variance);
        }

        if (in_array($name, [
            'purchase.receiving.delivery-details',
            'purchase.receiving.add-unlisted',
            'purchase.receiving.document-type',
            'purchase.receiving.invoice',
            'purchase.receiving.delivery-note',
            'purchase.receiving.receipt-without-document',
            'purchase.receiving.complete',
            'purchase.receiving.save-draft',
        ], true)) {
            $receipt = $this->findActive(GoodsReceipt::class, $p('id'));

            return $receipt !== null && $this->auth->canAccessGoodsReceipt($user, $receipt, true);
        }

        if ($name === 'purchase.receiving.inspect-item') {
            $receipt = $this->findActive(GoodsReceipt::class, $p('id'));
            if ($receipt === null || ! $this->auth->canAccessGoodsReceipt($user, $receipt, true)) {
                return false;
            }

            return GoodsReceiptItem::query()
                ->where('goods_receipt_id', $receipt->id)
                ->where('id', (string) $p('itemId'))
                ->exists();
        }

        // 7. Purchase Orders & Pending Orders.
        if (in_array($name, [
            'purchase.orders.update-items',
            'purchase.orders.submit',
            'purchase.pending.approve',
            'purchase.pending.partial-approve',
            'purchase.pending.reject',
            'purchase.pending.cancel',
            'purchase.pending.approve-transfer',
            'purchase.pending.approve-modifications',
            'purchase.pending.reject-modifications',
            'purchase.pending.approve-order-delay',
            'purchase.pending.reject-order-delay',
            'purchase.pending.direct-supplier.approve-delay',
            'purchase.pending.direct-supplier.reject-delay',
            'purchase.pending.via-purchasing-officer.approve-delay',
            'purchase.pending.via-purchasing-officer.reject-delay',
        ], true)) {
            $order = $this->findActive(PurchaseOrder::class, $p('id'));
            if ($order === null) {
                return false;
            }

            if (in_array($name, ['purchase.pending.approve', 'purchase.pending.partial-approve', 'purchase.pending.reject', 'purchase.pending.approve-transfer'], true)
                && $order->order_type === OrderType::INTERNAL_TRANSFER) {
                return $this->auth->canDecideInternalTransfer($user, $order);
            }

            if (! $this->auth->canAccessPurchaseOrder($user, $order, false)) {
                return false;
            }

            if ($name === 'purchase.orders.update-items') {
                return $this->validateOrderItemIds($order, (array) $request->input('items', []), 'id');
            }

            return true;
        }

        if (in_array($name, [
            'purchase.pending.approve-item',
            'purchase.pending.reject-item',
            'purchase.pending.cancel-item',
            'purchase.pending.approve-modification',
            'purchase.pending.reject-modification',
        ], true)) {
            $order = $this->findActive(PurchaseOrder::class, $p('id'));
            if ($order === null || ! $this->auth->canAccessPurchaseOrder($user, $order, false)) {
                return false;
            }
            $itemParam = (string) $p('itemId');

            return $itemParam !== '' && $order->items()
                ->where(fn ($q) => $q->where('id', $itemParam)->orWhere('item_id', $itemParam))
                ->exists();
        }

        // Fail closed for any unmapped protected handler.
        return false;
    }

    private function authorizeDelete(Request $request, mixed $user, string $name, ?string $id): bool
    {
        if ($id === null || $id === '') {
            return false;
        }

        $modelClass = match ($name) {
            'purchase.orders.delete-draft' => PurchaseOrder::class,
            'purchase.orders.saved-comparisons.destroy' => SavedPriceComparison::class,
            'purchase.receiving.delete-draft' => GoodsReceipt::class,
            'purchase.returns.delete-draft' => ReturnOrder::class,
            'recurring-orders.destroy' => RecurringOrder::class,
            default => null,
        };

        if ($modelClass === null) {
            return false;
        }

        $model = $this->findActive($modelClass, $id);
        if ($model === null) {
            // Only permit soft-deleted rows during an existing idempotency replay while ownership still holds.
            if (! $this->hasStoredReplay($request, $user)) {
                return false;
            }
            $model = $modelClass::onlyTrashed()->find($id);
            if ($model === null) {
                return false;
            }
        }

        return match ($name) {
            'purchase.orders.delete-draft' => $this->auth->canAccessPurchaseOrder($user, $model, false),
            'purchase.receiving.delete-draft' => $this->auth->canAccessGoodsReceipt($user, $model, true),
            'purchase.returns.delete-draft' => $this->auth->canMutateReturnOrder($user, $model),
            'purchase.orders.saved-comparisons.destroy',
            'recurring-orders.destroy' => in_array((string) $model->branch_id, $this->auth->resolveActingBranchIds($user, false), true),
            default => false,
        };
    }

    private function findActive(string $class, ?string $id): mixed
    {
        if ($id === null || $id === '') {
            return null;
        }

        return $class::query()->find($id);
    }

    private function hasStoredReplay(Request $request, mixed $user): bool
    {
        $key = $request->header('Idempotency-Key')
            ?? $request->input('idempotencyKey')
            ?? $request->input('idempotency_key');
        if (! is_string($key) || trim($key) === '' || ! $user || ! method_exists($user, 'getAuthIdentifier')) {
            return false;
        }

        $keyHash = hash('sha256', $key);
        $actorType = get_class($user);
        $actorId = (string) $user->getAuthIdentifier();

        return DB::table('asab_command_idempotency_owners')
            ->where('key_hash', $keyHash)
            ->where('actor_type', $actorType)
            ->where('actor_id', $actorId)
            ->exists();
    }

    private function validateOrderItemIds(PurchaseOrder $order, array $rows, string $field): bool
    {
        $ids = [];
        foreach ($rows as $row) {
            if (is_array($row) && ! empty($row[$field])) {
                $ids[] = (string) $row[$field];
            }
        }
        if ($ids === []) {
            return true;
        }

        $owned = $order->items()->pluck('id')->map(fn ($id) => (string) $id)->all();

        return array_diff(array_unique($ids), $owned) === [];
    }

    /**
     * Source references in the body (peer branch, supplier, officer) must be in the actor's scope.
     */
    private function authorizeSources(Request $request, mixed $user, string $name): bool
    {
        if ($name === 'purchase.orders.store') {
            foreach ((array) $request->input('branches', []) as $row) {
                if (! empty($row['branch_id']) && ! $this->auth->canAccessPeerBranch($user, (string) $row['branch_id'])) {
                    return false;
                }
            }
            foreach ((array) $request->input('direct_supplier', []) as $row) {
                if (! empty($row['supplier_id']) && ! $this->auth->canAccessSupplier($user, (string) $row['supplier_id'])) {
                    return false;
                }
            }
            $branchId = $this->auth->resolvePrimaryBranchId($user, false);
            foreach ((array) $request->input('purchase_officer', []) as $row) {
                if (! empty($row['purchasing_officer_id'])
                    && (! $branchId || ! $this->auth->canUsePurchasingOfficer($branchId, (string) $row['purchasing_officer_id']))) {
                    return false;
                }
            }
        }

        if ($name === 'purchase.receiving.without-order' && $request->filled('supplier_id')) {
            return $this->auth->canAccessVisibleSupplier($user, (string) $request->input('supplier_id'));
        }

        if ($name === 'purchase.receiving.start') {
            foreach ((array) $request->input('unlisted_items', []) as $row) {
                if (! empty($row['supplier_id']) && ! $this->auth->canAccessVisibleSupplier($user, (string) $row['supplier_id'])) {
                    return false;
                }
            }
        }

        if ($name === 'recurring-orders.store' || $name === 'recurring-orders.update') {
            $supplierId = $request->input('direct_supplier.supplier_id');
            if (! empty($supplierId) && ! $this->auth->canAccessSupplier($user, (string) $supplierId)) {
                return false;
            }
            $officerId = $request->input('purchase_officer.purchasing_officer_id');
            $branchId = $this->auth->resolvePrimaryBranchId($user, false);
            if (! empty($officerId) && (! $branchId || ! $this->auth->canUsePurchasingOfficer($branchId, (string) $officerId))) {
                return false;
            }
        }

        return true;
    }
}
