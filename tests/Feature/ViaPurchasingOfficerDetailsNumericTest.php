<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Tests\TestCase;

/**
 * Prod 2026-08-14, «Order via Purchasing Officer» details screen crashed with
 * "type 'String' is not a subtype of type 'num' in type cast".
 *
 * `price_comparison` returned the literal string 'n/a' whenever an item had no
 * direct-supplier price to compare against (bridge-provisioned catalogs have no
 * SupplierItem rows), while the app casts those three fields `as num`.
 * They must always be numeric; `has_comparison` carries the "no data" signal.
 */
class ViaPurchasingOfficerDetailsNumericTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private BranchManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
    }

    private function order(): PurchaseOrder
    {
        return PurchaseOrder::factory()->create([
            'order_type' => OrderType::VIA_PURCHASING_OFFICER,
            'status' => OrderStatus::PENDING,
            'branch_id' => $this->branch->id,
            'requested_by' => $this->manager->id,
            'supplier_id' => null,
            'total_amount' => 300,
            'total_items' => 1,
        ]);
    }

    /** No SupplierItem, no recent direct order → nothing to compare against. */
    public function test_price_comparison_is_numeric_when_no_direct_supplier_price_exists(): void
    {
        $order = $this->order();
        PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $order->id,
            'item_id' => (string) \Illuminate\Support\Str::uuid(),
            'quantity_ordered' => 3,
            'unit_price' => 100,
            'total_price' => 300,
        ]);

        $comparison = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/purchase/history/'.$order->id)
            ->assertOk()
            ->json('data.product_details.0.price_comparison');

        $this->assertIsNumeric($comparison['direct_supplier_price_same_item']);
        $this->assertIsNumeric($comparison['VIA_PURCHASING_OFFICER_same_item']);
        $this->assertIsNumeric($comparison['saving_amount']);
        $this->assertFalse($comparison['has_comparison']);
    }

    /** An item row carrying no item_id took the early-return branch. */
    public function test_price_comparison_is_numeric_when_item_has_no_item_id(): void
    {
        $order = $this->order();
        PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $order->id,
            'item_id' => null,
            'quantity_ordered' => 3,
            'unit_price' => 100,
            'total_price' => 300,
        ]);

        $comparison = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/purchase/history/'.$order->id)
            ->assertOk()
            ->json('data.product_details.0.price_comparison');

        $this->assertSame(0.0, (float) $comparison['direct_supplier_price_same_item']);
        $this->assertSame(0.0, (float) $comparison['VIA_PURCHASING_OFFICER_same_item']);
        $this->assertSame(0.0, (float) $comparison['saving_amount']);
        $this->assertFalse($comparison['has_comparison']);
    }
}
