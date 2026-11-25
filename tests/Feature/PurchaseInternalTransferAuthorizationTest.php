<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Inventory\Models\InternalTransferVariance;
use Modules\Inventory\Models\InventoryStockMovement;
use Modules\Purchase\Enums\OrderItemStatus;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Models\BranchInventory;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\Item;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Purchase\Services\PurchaseOrderService;
use Tests\TestCase;

/**
 * Stage 0 regression suite (T0.3 — PUR-F07 / INV-03):
 * - Only sending branch (`from_branch_id`) may approve/partial-approve/reject Internal Transfers
 * - Requesting branch (`branch_id` / `to_branch_id`) gets 403
 * - Decision + status update + dispatch stock run inside one atomic DB::transaction + lockForUpdate
 * - Only receiving branch may receive or close transfer variance
 */
class PurchaseInternalTransferAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Branch $requestingBranch;

    private Branch $sendingBranch;

    private Branch $thirdBranch;

    private BranchManager $requestingBm;

    private BranchManager $sendingBm;

    private BranchManager $thirdBm;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $company = AsabCompany::create(['name' => 'Transfer Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create([
            'company_id' => $company->id, 'name' => 'Transfer Brand', 'sub_status' => 'active', 'status' => 'active',
        ]);

        $this->requestingBranch = Branch::factory()->create([
            'name' => 'Requesting Branch', 'asab_company_id' => $company->id, 'asab_brand_id' => $brand->id,
        ]);
        $this->sendingBranch = Branch::factory()->create([
            'name' => 'Sending Branch', 'asab_company_id' => $company->id, 'asab_brand_id' => $brand->id,
        ]);
        $this->thirdBranch = Branch::factory()->create([
            'name' => 'Third Branch', 'asab_company_id' => $company->id, 'asab_brand_id' => $brand->id,
        ]);

        $this->requestingBm = BranchManager::factory()->create(['branch_id' => $this->requestingBranch->id, 'is_active' => true, 'status' => 'active']);
        $this->sendingBm = BranchManager::factory()->create(['branch_id' => $this->sendingBranch->id, 'is_active' => true, 'status' => 'active']);
        $this->thirdBm = BranchManager::factory()->create(['branch_id' => $this->thirdBranch->id, 'is_active' => true, 'status' => 'active']);

        $this->item = Item::create(['name' => 'Cheese', 'unit' => 'kg', 'is_active' => true]);

        BranchItem::create([
            'branch_id' => $this->sendingBranch->id,
            'item_id' => $this->item->id,
            'price' => 40,
            'quantity' => 50,
        ]);
        BranchInventory::create([
            'branch_id' => $this->sendingBranch->id,
            'item_id' => $this->item->id,
            'available_quantity' => 50,
            'minimum_stock' => 5,
        ]);
    }

    private function createInternalTransfer(): array
    {
        $order = PurchaseOrder::factory()->create([
            'order_number' => 'TR-'.strtoupper(Str::random(8)),
            'order_type' => OrderType::INTERNAL_TRANSFER,
            'status' => OrderStatus::PENDING,
            'branch_id' => $this->requestingBranch->id,
            'to_branch_id' => $this->requestingBranch->id,
            'from_branch_id' => $this->sendingBranch->id,
            'requested_by' => $this->requestingBm->id,
        ]);

        $orderItem = PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $order->id,
            'item_id' => $this->item->id,
            'item_name' => $this->item->name,
            'status' => OrderItemStatus::PENDING,
            'quantity_ordered' => 10,
            'unit_price' => 40,
            'total_price' => 400,
        ]);

        return [$order, $orderItem];
    }

    public function test_requesting_branch_and_third_branch_cannot_approve_or_reject_internal_transfer(): void
    {
        [$order, $orderItem] = $this->createInternalTransfer();

        foreach ([$this->requestingBm, $this->thirdBm] as $unauthorizedBm) {
            $this->actingAs($unauthorizedBm, 'sanctum')
                ->postJson("/api/v1/purchase/pending/{$order->id}/approve")
                ->assertStatus(403);

            $this->actingAs($unauthorizedBm, 'sanctum')
                ->postJson("/api/v1/purchase/pending/{$order->id}/partial-approve", [
                    'items' => [['item_id' => $orderItem->id, 'quantity' => 5]],
                ])
                ->assertStatus(403);

            $this->actingAs($unauthorizedBm, 'sanctum')
                ->postJson("/api/v1/purchase/pending/{$order->id}/reject", ['reason' => 'unauthorized'])
                ->assertStatus(403);

            $this->actingAs($unauthorizedBm, 'sanctum')
                ->postJson("/api/v1/purchase/pending/{$order->id}/approve-transfer", [
                    'action' => 'approve_all',
                    'ready_time' => '1_hour',
                ])
                ->assertStatus(403);
        }

        $this->assertSame(OrderStatus::PENDING, $order->fresh()->status);
        $this->assertSame(0, InventoryStockMovement::count());
        $this->assertEquals(50.0, (float) BranchInventory::where('branch_id', $this->sendingBranch->id)->value('available_quantity'));
    }

    public function test_sending_branch_approves_transfer_atomically_and_dispatches_stock_once(): void
    {
        [$order] = $this->createInternalTransfer();

        $this->actingAs($this->sendingBm, 'sanctum')
            ->postJson("/api/v1/purchase/pending/{$order->id}/approve-transfer", [
                'action' => 'approve_all',
                'ready_time' => '1_hour',
            ])
            ->assertOk();

        $freshOrder = $order->fresh();
        $this->assertSame(OrderStatus::FULLY_APPROVED, $freshOrder->status);
        $this->assertSame(1, InventoryStockMovement::where('source_id', $order->id)->where('movement_type', 'transfer_out')->count());
        $this->assertSame(1, InternalTransferVariance::where('purchase_order_id', $order->id)->count());
        $this->assertEquals(40.0, (float) BranchInventory::where('branch_id', $this->sendingBranch->id)->value('available_quantity'));

        // Sending branch cannot receive its own outgoing transfer (only receiving branch may)
        $this->actingAs($this->sendingBm, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/receive-internal-transfer", ['items' => []])
            ->assertStatus(403);
    }

    public function test_transfer_approval_rolls_back_status_when_stock_dispatch_fails(): void
    {
        [$order, $orderItem] = $this->createInternalTransfer();

        InternalTransferVariance::creating(function (): bool {
            throw new \RuntimeException('Simulated stock dispatch failure');
        });

        try {
            app(PurchaseOrderService::class)->approveInternalTransfer($order, null, '1_hour', (string) $this->sendingBm->id);
            $this->fail('Expected RuntimeException was not thrown');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated stock dispatch failure', $e->getMessage());
        } finally {
            InternalTransferVariance::flushEventListeners();
        }

        $this->assertSame(OrderStatus::PENDING, $order->fresh()->status);
        $this->assertSame(OrderItemStatus::PENDING, $orderItem->fresh()->status);
        $this->assertSame(0, InventoryStockMovement::count());
        $this->assertSame(0, InternalTransferVariance::count());
    }
}
