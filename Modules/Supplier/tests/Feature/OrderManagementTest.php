<?php

namespace Modules\Supplier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Supplier\Models\Supplier;
use Tests\TestCase;

class OrderManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test supplier can view orders
     */
    public function test_supplier_can_view_orders(): void
    {
        $supplier = Supplier::factory()->create();
        $token = $supplier->createToken('test-token')->plainTextToken;

        PurchaseOrder::factory()->create([
            'supplier_id' => $supplier->id,
            'order_type' => 'direct_supplier',
            'status' => OrderStatus::PENDING,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/supplier/orders');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data',
                'links',
                'meta',
            ]);
    }

    /**
     * Test supplier can accept order
     */
    public function test_supplier_can_accept_order(): void
    {
        $supplier = Supplier::factory()->create();
        $token = $supplier->createToken('test-token')->plainTextToken;

        $order = PurchaseOrder::factory()->create([
            'supplier_id' => $supplier->id,
            'order_type' => 'direct_supplier',
            'status' => OrderStatus::PENDING,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->postJson("/api/v1/supplier/orders/{$order->id}/accept", [
                'expected_delivery_at' => now()->addDays(2)->toDateTimeString(),
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('purchase_orders', [
            'id' => $order->id,
            'status' => OrderStatus::CONFIRMED->value,
        ]);
    }
}

