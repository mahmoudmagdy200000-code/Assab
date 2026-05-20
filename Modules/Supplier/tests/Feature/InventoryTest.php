<?php

namespace Modules\Supplier\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Supplier\Models\Supplier;
use Modules\Supplier\Models\SupplierProduct;
use Tests\TestCase;

class InventoryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Test supplier can create product
     */
    public function test_supplier_can_create_product(): void
    {
        $supplier = Supplier::factory()->create();
        $token = $supplier->createToken('test-token')->plainTextToken;

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson('/api/v1/supplier/inventory/products', [
                'item_id' => \Modules\Purchase\Models\Item::factory()->create()->id,
                'name' => 'Test Product',
                'unit_price' => 100.00,
                'stock_quantity' => 50,
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'data',
            ]);
    }

    /**
     * Test supplier can update stock
     */
    public function test_supplier_can_update_stock(): void
    {
        $supplier = Supplier::factory()->create();
        $token = $supplier->createToken('test-token')->plainTextToken;

        $product = SupplierProduct::factory()->create([
            'supplier_id' => $supplier->id,
        ]);

        $response = $this->withHeader('Authorization', 'Bearer '.$token)
            ->putJson("/api/v1/supplier/inventory/stock/{$product->id}", [
                'quantity' => 100,
            ]);

        $response->assertStatus(200);
    }
}
