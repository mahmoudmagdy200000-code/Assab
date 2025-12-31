<?php

namespace Modules\Purchase\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Supplier\Models\Supplier;

class PurchaseOrderFactory extends Factory
{
    protected $model = PurchaseOrder::class;

    public function definition(): array
    {
        // Automatically create dependencies if they don't exist
        $branch = Branch::first() ?? Branch::factory()->create();
        $manager = BranchManager::first() ?? BranchManager::factory()->create(['branch_id' => $branch->id]);
        
        // For sourceable, we'll default to a supplier if available, otherwise use branch manager
        $supplier = Supplier::first();
        $sourceableType = $supplier ? Supplier::class : BranchManager::class;
        $sourceableId = $supplier ? $supplier->id : $manager->id;

        return [
            'order_number' => 'PO-' . strtoupper(uniqid()),
            'order_type' => OrderType::DIRECT_SUPPLIER,
            'status' => OrderStatus::DRAFT,
            'branch_id' => $branch->id,
            'requested_by' => $manager->id,
            'sourceable_type' => $sourceableType,
            'sourceable_id' => $sourceableId,
            'from_branch_id' => null,
            'to_branch_id' => null,
            'supplier_id' => $supplier?->id,
            'quality_level' => null,
            'processing_time' => null,
            'priority' => 'normal',
            'preferred_delivery_date' => null,
            'latest_delivery_date' => null,
            'expected_delivery_at' => null,
            'actual_delivery_at' => null,
            'notification_channels' => null,
            'subtotal' => 0,
            'tax_amount' => 0,
            'tax_rate' => 15.00,
            'total_amount' => 0,
            'discount_amount' => 0,
            'total_items' => 0,
            'received_items' => 0,
            'message' => null,
            'special_instructions' => null,
            'rejection_reason' => null,
            'cancellation_reason' => null,
            'delay_reason' => null,
            'transport_method' => null,
            'estimated_transport_hours' => null,
            'driver_name' => null,
            'driver_contact' => null,
            'vehicle_number' => null,
            'temperature' => null,
            'cooling_status' => null,
            'ready_time' => null,
            'parent_order_id' => null,
            'submitted_at' => null,
            'confirmed_at' => null,
            'preparation_started_at' => null,
            'dispatched_at' => null,
            'received_at' => null,
            'closed_at' => null,
            'canceled_at' => null,
            'rejected_at' => null,
        ];
    }

    /**
     * Indicate that the order is for internal transfer
     */
    public function internalTransfer(): static
    {
        $fromBranch = Branch::factory()->create();
        $toBranch = Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $toBranch->id]);

        return $this->state(fn(array $attributes) => [
            'order_type' => OrderType::INTERNAL_TRANSFER,
            'from_branch_id' => $fromBranch->id,
            'to_branch_id' => $toBranch->id,
            'branch_id' => $toBranch->id,
            'requested_by' => $manager->id,
            'sourceable_type' => Branch::class,
            'sourceable_id' => $fromBranch->id,
        ]);
    }

    /**
     * Indicate that the order is pending
     */
    public function pending(): static
    {
        return $this->state(fn(array $attributes) => [
            'status' => OrderStatus::PENDING,
        ]);
    }

    /**
     * Indicate that the order is confirmed
     */
    public function confirmed(): static
    {
        return $this->state(fn(array $attributes) => [
            'status' => OrderStatus::CONFIRMED,
            'confirmed_at' => now(),
        ]);
    }
}

