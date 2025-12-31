<?php

namespace Modules\Purchase\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Purchase\Enums\OrderItemStatus;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;

class PurchaseOrderItemFactory extends Factory
{
    protected $model = PurchaseOrderItem::class;

    public function definition(): array
    {
        // Automatically create purchase order if not provided
        $order = PurchaseOrder::first() ?? PurchaseOrder::factory()->create();
        
        $quantity = $this->faker->randomFloat(3, 1, 100);
        $unitPrice = $this->faker->randomFloat(2, 10, 1000);
        $totalPrice = ($quantity * $unitPrice);

        return [
            'purchase_order_id' => $order->id,
            'item_id' => null,
            'item_name' => $this->faker->words(3, true),
            'item_logo' => null,
            'item_sku' => $this->faker->unique()->bothify('SKU-####-???'),
            'category' => $this->faker->randomElement(['Food', 'Beverage', 'Snacks', 'Dairy']),
            'subcategory' => $this->faker->word(),
            'quantity_ordered' => $quantity,
            'quantity_confirmed' => null,
            'quantity_received' => null,
            'unit_of_measurement' => $this->faker->randomElement(['kg', 'pk', 'unit', 'box', 'liter', 'piece']),
            'unit_price' => $unitPrice,
            'total_price' => $totalPrice,
            'discount' => 0,
            'quality_ordered' => null,
            'quality_received' => null,
            'available_in_source' => null,
            'remaining_balance' => null,
            'daily_consumption' => null,
            'weekend_forecast' => null,
            'next_supply_date' => null,
            'expiry_date' => null,
            'temperature' => null,
            'cooling_status' => null,
            'inspection_photo' => null,
            'inspection_notes' => null,
            'status' => OrderItemStatus::PENDING,
            'original_quantity' => null,
            'new_quantity' => null,
            'modification_note' => null,
            'is_alternative' => false,
            'original_item_id' => null,
            'is_gift' => false,
            'gift_reason' => null,
            'approval_type' => null,
            'approval_data' => null,
        ];
    }

    /**
     * Indicate that the item is confirmed
     */
    public function confirmed(): static
    {
        return $this->state(fn(array $attributes) => [
            'status' => OrderItemStatus::CONFIRMED,
            'quantity_confirmed' => $attributes['quantity_ordered'],
        ]);
    }

    /**
     * Indicate that the item is received
     */
    public function received(): static
    {
        return $this->state(function (array $attributes) {
            $quantityReceived = $attributes['quantity_ordered'] ?? $this->faker->randomFloat(3, 1, 100);
            return [
                'status' => OrderItemStatus::RECEIVED,
                'quantity_confirmed' => $quantityReceived,
                'quantity_received' => $quantityReceived,
            ];
        });
    }
}

