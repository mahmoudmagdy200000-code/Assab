<?php

namespace Modules\Supplier\Services;

use Illuminate\Pagination\LengthAwarePaginator;
use Modules\Supplier\Models\Supplier;
use Modules\Supplier\Models\SupplierMessage;
use Modules\Supplier\Models\SupplierNotification;

class CommunicationService
{
    /**
     * Send message to branch
     */
    public function sendMessage(Supplier $supplier, array $data): SupplierMessage
    {
        return SupplierMessage::create([
            'supplier_id' => $supplier->id,
            'branch_id' => $data['branch_id'],
            'order_id' => $data['order_id'] ?? null,
            'message' => $data['message'],
            'sender_type' => 'supplier',
            'attachments' => $data['attachments'] ?? null,
        ]);
    }

    /**
     * Get messages
     */
    public function getMessages(Supplier $supplier, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = SupplierMessage::where('supplier_id', $supplier->id)
            ->orderBy('created_at', 'desc');

        if (!empty($filters['branch_id'])) {
            $query->where('branch_id', $filters['branch_id']);
        }

        if (!empty($filters['order_id'])) {
            $query->where('order_id', $filters['order_id']);
        }

        if (isset($filters['is_read'])) {
            $query->where('is_read', $filters['is_read']);
        }

        return $query->paginate($perPage);
    }

    /**
     * Mark messages as read
     */
    public function markAsRead(Supplier $supplier, ?string $messageId = null): void
    {
        $query = SupplierMessage::where('supplier_id', $supplier->id)
            ->where('is_read', false);

        if ($messageId) {
            $query->where('id', $messageId);
        }

        $query->update([
            'is_read' => true,
            'read_at' => now(),
        ]);
    }

    /**
     * Get notifications
     */
    public function getNotifications(Supplier $supplier, array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = SupplierNotification::where('supplier_id', $supplier->id)
            ->orderBy('created_at', 'desc');

        if (!empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (isset($filters['is_read'])) {
            $query->where('is_read', $filters['is_read']);
        }

        return $query->paginate($perPage);
    }

    /**
     * Mark notifications as read
     */
    public function markNotificationAsRead(Supplier $supplier, ?string $notificationId = null): void
    {
        $query = SupplierNotification::where('supplier_id', $supplier->id)
            ->where('is_read', false);

        if ($notificationId) {
            $query->where('id', $notificationId);
        }

        $query->update([
            'is_read' => true,
            'read_at' => now(),
        ]);
    }
}

