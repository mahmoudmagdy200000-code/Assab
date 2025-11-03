<?php

namespace Modules\Purchase\Http\Controllers;

use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Repositories\PurchaseOrderRepository;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Purchase\Models\PurchaseTimeline;
use Illuminate\Support\Facades\DB;
use App\Models\User;

class PurchaseOrderService
{
    public function __construct(
        private PurchaseOrderRepository $purchaseOrderRepository
    ) {}

    /**
     * Get purchase history with filters
     */
    public function getPurchaseHistory(array $filters)
    {
        return $this->purchaseOrderRepository->getHistory($filters);
    }

    /**
     * Get pending orders
     */
    public function getPendingOrders(array $filters)
    {
        return $this->purchaseOrderRepository->getPending($filters);
    }

    /**
     * Create new purchase order
     */
    public function createOrder(array $data, User $user): PurchaseOrder
    {
        return DB::transaction(function () use ($data, $user) {
            // Create main order
            $orderData = [
                'branch_id' => $user->branch_id,
                'branch_manager_id' => $user->id,
                'order_type' => $data['order_type'],
                'supplier_id' => $data['supplier_id'] ?? null,
                'purchasing_officer_id' => $data['purchasing_officer_id'] ?? null,
                'transfer_from_branch_id' => $data['transfer_from_branch_id'] ?? null,
                'status' => $data['status'] ?? 'draft',
                'priority' => $data['priority'] ?? 'normal',
                'delivery_date' => $data['delivery_date'] ?? null,
                'latest_delivery_date' => $data['latest_delivery_date'] ?? null,
                'special_instructions' => $data['special_instructions'] ?? null,
                'message' => $data['message'] ?? null,
                'notification_methods' => $data['notification_methods'] ?? [],
                'requested_date' => now(),
            ];

            $order = $this->purchaseOrderRepository->create($orderData);

            // Generate order number
            $order->order_number = $order->generateOrderNumber();
            $order->save();

            // Create order items
            if (!empty($data['items'])) {
                foreach ($data['items'] as $item) {
                    $orderItem = new PurchaseOrderItem([
                        'item_id' => $item['item_id'],
                        'item_name' => $item['item_name'],
                        'quantity' => $item['quantity'],
                        'unit' => $item['unit'],
                        'quality' => $item['quality'] ?? 'standard',
                        'rate' => $item['rate'],
                        'total_price' => $item['quantity'] * $item['rate'],
                        'requested_quantity' => $item['quantity'],
                        'status' => 'pending',
                    ]);

                    $order->items()->save($orderItem);
                }
            }

            // Calculate total
            $order->total_amount = $order->items->sum('total_price');
            $order->total_items = $order->items->count();
            $order->save();

            // Create timeline entry
            $this->createTimelineEntry(
                $order,
                $user,
                'submitted',
                'pending',
                'Order submitted by branch manager'
            );

            return $order->fresh(['items', 'timeline']);
        });
    }

    /**
     * Update purchase order
     */
    public function updateOrder($orderId, array $data, User $user): PurchaseOrder
    {
        return DB::transaction(function () use ($orderId, $data, $user) {
            $order = $this->purchaseOrderRepository->findOrFail($orderId);

            // Update main order
            $order->update([
                'delivery_date' => $data['delivery_date'] ?? $order->delivery_date,
                'latest_delivery_date' => $data['latest_delivery_date'] ?? $order->latest_delivery_date,
                'special_instructions' => $data['special_instructions'] ?? $order->special_instructions,
                'message' => $data['message'] ?? $order->message,
                'priority' => $data['priority'] ?? $order->priority,
            ]);

            // Update items if provided
            if (!empty($data['items'])) {
                $order->items()->delete();

                foreach ($data['items'] as $item) {
                    $orderItem = new PurchaseOrderItem([
                        'item_id' => $item['item_id'],
                        'item_name' => $item['item_name'],
                        'quantity' => $item['quantity'],
                        'unit' => $item['unit'],
                        'quality' => $item['quality'] ?? 'standard',
                        'rate' => $item['rate'],
                        'total_price' => $item['quantity'] * $item['rate'],
                        'requested_quantity' => $item['quantity'],
                        'status' => 'pending',
                    ]);

                    $order->items()->save($orderItem);
                }

                // Recalculate total
                $order->total_amount = $order->items->sum('total_price');
                $order->total_items = $order->items->count();
                $order->save();
            }

            return $order->fresh(['items', 'timeline']);
        });
    }

    /**
     * Cancel purchase order
     */
    public function cancelOrder($orderId, ?string $reason, User $user): PurchaseOrder
    {
        return DB::transaction(function () use ($orderId, $reason, $user) {
            $order = $this->purchaseOrderRepository->findOrFail($orderId);

            if (!$order->canBeCanceled()) {
                throw new \Exception('Order cannot be canceled in its current status');
            }

            $order->update([
                'status' => 'canceled',
                'rejection_reason' => $reason,
                'canceled_at' => now(),
            ]);

            $this->createTimelineEntry(
                $order,
                $user,
                'canceled',
                'canceled',
                'Order canceled by branch manager',
                ['reason' => $reason]
            );

            return $order->fresh(['timeline']);
        });
    }

    /**
     * Approve modification
     */
    public function approveModification($orderId, $modificationId, User $user): PurchaseOrder
    {
        return DB::transaction(function () use ($orderId, $modificationId, $user) {
            $order = $this->purchaseOrderRepository->findOrFail($orderId);
            $modification = $order->modifications()->findOrFail($modificationId);

            $modification->update([
                'status' => 'approved',
                'approved_by_id' => $user->id,
                'approved_at' => now(),
            ]);

            // Apply modification to order
            if ($modification->modification_type === 'quantity_change') {
                $orderItem = $modification->orderItem;
                $orderItem->update([
                    'quantity' => $modification->new_value['quantity'],
                    'confirmed_quantity' => $modification->new_value['quantity'],
                    'total_price' => $modification->new_value['quantity'] * $orderItem->rate,
                ]);
            }

            $order->update(['status' => 'confirmed']);

            $this->createTimelineEntry(
                $order,
                $user,
                'approved',
                'confirmed',
                'Modification approved'
            );

            return $order->fresh(['modifications', 'items']);
        });
    }

    /**
     * Reject modification
     */
    public function rejectModification($orderId, $modificationId, string $reason, User $user): PurchaseOrder
    {
        return DB::transaction(function () use ($orderId, $modificationId, $reason, $user) {
            $order = $this->purchaseOrderRepository->findOrFail($orderId);
            $modification = $order->modifications()->findOrFail($modificationId);

            $modification->update([
                'status' => 'rejected',
                'rejection_reason' => $reason,
            ]);

            $order->update(['status' => 'canceled']);

            $this->createTimelineEntry(
                $order,
                $user,
                'rejected',
                'canceled',
                'Modification rejected by branch manager',
                ['reason' => $reason]
            );

            return $order->fresh(['modifications']);
        });
    }

    /**
     * Get order details
     */
    public function getOrderDetails($orderId)
    {
        return $this->purchaseOrderRepository->findWithRelations($orderId, [
            'branch',
            'branchManager',
            'supplier',
            'purchasingOfficer',
            'transferFromBranch',
            'items',
            'timeline.user',
            'modifications',
            'trackingUpdates',
        ]);
    }

    /**
     * Get order timeline
     */
    public function getOrderTimeline($orderId)
    {
        $order = $this->purchaseOrderRepository->findOrFail($orderId);
        return $order->timeline()->with('user')->orderBy('created_at', 'asc')->get();
    }

    /**
     * Change order source
     */
    public function changeOrderSource($orderId, array $data, User $user): PurchaseOrder
    {
        return DB::transaction(function () use ($orderId, $data, $user) {
            $order = $this->purchaseOrderRepository->findOrFail($orderId);

            $order->update([
                'order_type' => $data['new_order_type'],
                'supplier_id' => $data['supplier_id'] ?? null,
                'purchasing_officer_id' => $data['purchasing_officer_id'] ?? null,
                'transfer_from_branch_id' => $data['transfer_from_branch_id'] ?? null,
            ]);

            $order->order_number = $order->generateOrderNumber();
            $order->save();

            $this->createTimelineEntry(
                $order,
                $user,
                'source_changed',
                $order->status,
                'Order source changed',
                ['previous_type' => $order->getOriginal('order_type'), 'new_type' => $data['new_order_type']]
            );

            return $order->fresh();
        });
    }

    /**
     * Create timeline entry
     */
    private function createTimelineEntry(
        PurchaseOrder $order,
        User $user,
        string $action,
        string $status,
        string $description,
        array $metadata = []
    ): void {
        PurchaseTimeline::create([
            'purchase_order_id' => $order->id,
            'user_id' => $user->id,
            'action' => $action,
            'status' => $status,
            'description' => $description,
            'metadata' => $metadata,
        ]);
    }
}
