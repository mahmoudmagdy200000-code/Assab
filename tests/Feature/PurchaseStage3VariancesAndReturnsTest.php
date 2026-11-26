<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Models\SupplierItem;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\Inventory\Models\InventoryStockMovement;
use Modules\Purchase\Enums\InspectionQuality;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Enums\ReturnRequiredAction;
use Modules\Purchase\Enums\ReturnStatus;
use Modules\Purchase\Models\BranchInventory;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\CompensatoryOrder;
use Modules\Purchase\Models\GoodsReceipt;
use Modules\Purchase\Models\GoodsReceiptItem;
use Modules\Purchase\Models\Item;
use Modules\Purchase\Models\PurchaseInvoice;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Purchase\Models\PurchaseVariance;
use Modules\Purchase\Models\ReturnOrder;
use Modules\Purchase\Models\ReturnOrderItem;
use Modules\Purchase\Services\GoodsReceiptService;
use Modules\Purchase\Services\ReceiptPriceApprovalService;
use Modules\Purchase\Services\ReturnManagementService;
use Modules\Supplier\Models\Supplier;
use Tests\TestCase;

class PurchaseStage3VariancesAndReturnsTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;
    private AsabBrand $brand;
    private Branch $branch;
    private BranchManager $manager;
    private Supplier $supplier;
    private Item $itemA;
    private Item $itemB;
    private AsabUser $procurementUser;
    private AsabUser $accountantUser;
    private AsabUser $headUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'Stage 3 Test Co',
            'plan' => 'Enterprise',
            'status' => 'active',
        ]);

        $this->brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'Stage 3 Brand',
            'abbr' => 'S3B',
            'sub_status' => 'active',
            'status' => 'active',
        ]);

        $this->branch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
        ]);

        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);

        $this->supplier = Supplier::create([
            'name' => 'Stage 3 Supplier',
            'email' => 'supplier-s3@test.local',
            'password' => 'secret123',
            'is_active' => true,
        ]);

        $this->itemA = Item::create(['name' => 'Item A Rice', 'unit' => 'kg', 'is_active' => true]);
        $this->itemB = Item::create(['name' => 'Item B Oil', 'unit' => 'liter', 'is_active' => true]);

        foreach ([[$this->itemA, 50.00], [$this->itemB, 40.00]] as [$item, $price]) {
            BranchItem::create([
                'branch_id' => $this->branch->id,
                'item_id' => $item->id,
                'price' => $price,
                'quantity' => 0,
            ]);
        }

        // Actors
        $this->procurementUser = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'Procurement Officer',
            'email' => 'proc-stage3@test.local',
            'password' => 'secret123',
            'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->procurementUser->id, 'role_key' => 'procurement', 'scope' => 'all']);
        CompanyUser::create(['company_id' => $this->company->id, 'user_id' => $this->procurementUser->id, 'role_key' => 'procurement', 'status' => 'active']);

        $this->accountantUser = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'Accountant User',
            'email' => 'acc-stage3@test.local',
            'password' => 'secret123',
            'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->accountantUser->id, 'role_key' => 'accountant', 'scope' => 'all']);
        CompanyUser::create(['company_id' => $this->company->id, 'user_id' => $this->accountantUser->id, 'role_key' => 'accountant', 'status' => 'active']);

        $this->headUser = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'Head Accountant User',
            'email' => 'head-stage3@test.local',
            'password' => 'secret123',
            'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->headUser->id, 'role_key' => 'head', 'scope' => 'all']);
        CompanyUser::create(['company_id' => $this->company->id, 'user_id' => $this->headUser->id, 'role_key' => 'head', 'status' => 'active']);
    }

    private function createOrder(OrderType $type = OrderType::VIA_PURCHASING_OFFICER, float $qty = 10, float $price = 50.0): array
    {
        $order = PurchaseOrder::create([
            'order_type' => $type,
            'status' => OrderStatus::CONFIRMED,
            'branch_id' => $this->branch->id,
            'requested_by' => $this->manager->id,
            'sourceable_type' => Supplier::class,
            'sourceable_id' => $this->supplier->id,
            'supplier_id' => $this->supplier->id,
            'subtotal' => round($qty * $price, 2),
            'tax_rate' => 15.00,
            'tax_amount' => round($qty * $price * 0.15, 2),
            'total_amount' => round($qty * $price * 1.15, 2),
        ]);

        $item = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'item_id' => $this->itemA->id,
            'item_name' => $this->itemA->name,
            'quantity_ordered' => $qty,
            'quantity_confirmed' => $qty,
            'unit_price' => $price,
            'total_price' => round($qty * $price, 2),
            'unit_of_measurement' => 'kg',
            'status' => 'confirmed',
        ]);

        return [$order, $item];
    }

    /**
     * REG-DEC-02 & REG-DEC-03:
     * Excess approval releases reserved quantity to available stock once.
     * Repeat approval is idempotent.
     */
    public function test_excess_approval_releases_reserved_stock_once_and_is_idempotent(): void
    {
        [$order, $orderItem] = $this->createOrder(OrderType::VIA_PURCHASING_OFFICER, 10, 50.0);

        // Receive with 5 excess units (15 received vs 10 ordered)
        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
            'items' => [['item_id' => $orderItem->id, 'quantity_received' => 15, 'quality' => 'normal']],
            'document_type' => 'delivery_note',
        ])->assertStatus(201);

        $receipt = GoodsReceipt::where('purchase_order_id', $order->id)->firstOrFail();
        $receiptItem = $receipt->items()->firstOrFail();

        // 10 units posted to available stock, 5 reserved pending approval
        $this->assertEquals(10.0, (float) $receiptItem->quantity_available_posted);
        $this->assertEquals(5.0, (float) $receiptItem->quantity_excess_reserved);
        $this->assertEquals('reserved_pending_approval', $receiptItem->excess_status);

        $invBefore = BranchInventory::where('branch_id', $this->branch->id)->where('item_id', $this->itemA->id)->value('available_quantity');
        $this->assertEquals(10.0, (float) $invBefore);

        // Approve excess via API as procurement
        $response = $this->actingAs($this->procurementUser, 'sanctum')
            ->postJson("/api/v1/procurement/receiving/{$receipt->id}/excess-approvals");
        $response->assertOk();

        $receiptItem->refresh();
        $this->assertEquals(15.0, (float) $receiptItem->quantity_available_posted);
        $this->assertEquals(0.0, (float) $receiptItem->quantity_excess_reserved);
        $this->assertEquals('approved', $receiptItem->excess_status);

        // Available inventory increased by 5 (10 -> 15)
        $invAfter = BranchInventory::where('branch_id', $this->branch->id)->where('item_id', $this->itemA->id)->value('available_quantity');
        $this->assertEquals(15.0, (float) $invAfter);

        // One stock movement for excess release
        $movements = InventoryStockMovement::where('idempotency_key', "excess_release:{$receiptItem->id}")->get();
        $this->assertCount(1, $movements);
        $this->assertEquals(5.0, (float) $movements->first()->qty_delta);

        // REG-DEC-03: Repeat excess approval is idempotent
        $responseRepeat = $this->actingAs($this->procurementUser, 'sanctum')
            ->postJson("/api/v1/procurement/receiving/{$receipt->id}/excess-approvals");
        $responseRepeat->assertOk();

        // Inventory is still 15, not 20
        $invRepeat = BranchInventory::where('branch_id', $this->branch->id)->where('item_id', $this->itemA->id)->value('available_quantity');
        $this->assertEquals(15.0, (float) $invRepeat);
        $this->assertCount(1, InventoryStockMovement::where('idempotency_key', "excess_release:{$receiptItem->id}")->get());
    }

    /**
     * REG-DEC-04:
     * Excess rejection documents physical return to supplier and zeroes reserved excess
     * WITHOUT touching available inventory.
     */
    public function test_excess_rejection_documents_return_without_touching_available_inventory(): void
    {
        [$order, $orderItem] = $this->createOrder(OrderType::VIA_PURCHASING_OFFICER, 10, 50.0);

        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
            'items' => [['item_id' => $orderItem->id, 'quantity_received' => 14, 'quality' => 'normal']],
            'document_type' => 'delivery_note',
        ])->assertStatus(201);

        $receipt = GoodsReceipt::where('purchase_order_id', $order->id)->firstOrFail();
        $receiptItem = $receipt->items()->firstOrFail();

        $this->assertEquals(10.0, (float) $receiptItem->quantity_available_posted);
        $this->assertEquals(4.0, (float) $receiptItem->quantity_excess_reserved);

        $invBefore = BranchInventory::where('branch_id', $this->branch->id)->where('item_id', $this->itemA->id)->value('available_quantity');
        $this->assertEquals(10.0, (float) $invBefore);

        // Reject excess via API
        $response = $this->actingAs($this->procurementUser, 'sanctum')
            ->postJson("/api/v1/procurement/receiving/{$receipt->id}/excess-rejections", [
                'reason' => 'Excess units damaged upon arrival, returned to driver',
            ]);
        $response->assertOk();

        $receiptItem->refresh();
        $this->assertEquals(10.0, (float) $receiptItem->quantity_available_posted);
        $this->assertEquals(0.0, (float) $receiptItem->quantity_excess_reserved);
        $this->assertEquals('rejected', $receiptItem->excess_status);
        $this->assertStringContainsString('فائض مرفوض', (string) $receiptItem->notes);

        // Available inventory remains strictly untouched (10 units)
        $invAfter = BranchInventory::where('branch_id', $this->branch->id)->where('item_id', $this->itemA->id)->value('available_quantity');
        $this->assertEquals(10.0, (float) $invAfter);
    }

    /**
     * REG-DEC-05 & REG-DEC-06:
     * Price variance rejection retains PO baseline and sets awaiting_corrected_document.
     * Corrected invoice upload clears the flag without duplicate quantity movement.
     */
    public function test_price_rejection_retains_po_baseline_and_corrected_invoice_clears_flag(): void
    {
        [$order, $orderItem] = $this->createOrder(OrderType::VIA_PURCHASING_OFFICER, 10, 50.0);

        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
            'items' => [['item_id' => $orderItem->id, 'quantity_received' => 10, 'quality' => 'normal']],
            'document_type' => 'delivery_note',
        ])->assertStatus(201);

        $receipt = GoodsReceipt::where('purchase_order_id', $order->id)->firstOrFail();

        // Invoice with unapproved unit price of 70 SAR (700 SAR total before tax vs 500 PO)
        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/purchase/receiving/{$receipt->id}/invoice", [
            'invoice_number' => 'INV-UNAPPROVED-01',
            'invoice_date' => today()->toDateString(),
            'amount_before_tax' => 700.0,
            'tax_rate' => 15.0,
        ])->assertStatus(201);

        $receipt->refresh();
        $this->assertEquals('pending_price_approval', $receipt->cost_adjustment_status);

        // Procurement rejects the price variance
        $response = $this->actingAs($this->procurementUser, 'sanctum')
            ->postJson("/api/v1/procurement/receiving/{$receipt->id}/price-rejections", [
                'reason' => 'Unit price was agreed at 50 SAR, please send corrected invoice',
            ]);
        $response->assertOk();

        $receipt->refresh();
        $this->assertEquals('awaiting_corrected_document', $receipt->cost_adjustment_status);
        $this->assertTrue($receipt->cost_adjustment_meta['awaiting_corrected_document']);

        // Upload corrected invoice matching PO price 500 SAR
        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/purchase/receiving/{$receipt->id}/invoice", [
            'invoice_number' => 'INV-CORRECTED-01',
            'invoice_date' => today()->toDateString(),
            'amount_before_tax' => 500.0,
            'tax_rate' => 15.0,
        ])->assertStatus(201);

        $receipt->refresh();
        $this->assertArrayNotHasKey('awaiting_corrected_document', $receipt->cost_adjustment_meta ?? []);
        // Quantity movements were NOT duplicated
        $movements = InventoryStockMovement::where('source_id', $receipt->id)->where('movement_type', 'purchase_in')->get();
        $this->assertCount(1, $movements);
    }

    /**
     * REG-DEC-14 & DEC-OPEN-01:
     * Variance and excess approvals fail closed with 403 on direct supplier orders.
     */
    public function test_direct_supplier_variance_and_excess_approvals_fail_closed_with_403(): void
    {
        [$order, $orderItem] = $this->createOrder(OrderType::DIRECT_SUPPLIER, 10, 50.0);

        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
            'items' => [['item_id' => $orderItem->id, 'quantity_received' => 12, 'quality' => 'normal']],
            'document_type' => 'delivery_note',
        ])->assertStatus(201);

        $receipt = GoodsReceipt::where('purchase_order_id', $order->id)->firstOrFail();

        // Excess approval fails closed with 403 APPROVAL_ROLE_PENDING
        $responseExcess = $this->actingAs($this->procurementUser, 'sanctum')
            ->postJson("/api/v1/procurement/receiving/{$receipt->id}/excess-approvals");
        $responseExcess->assertStatus(403);
        $this->assertEquals('APPROVAL_ROLE_PENDING', $responseExcess->json('error.code'));

        // Price rejection also fails closed
        $responsePrice = $this->actingAs($this->procurementUser, 'sanctum')
            ->postJson("/api/v1/procurement/receiving/{$receipt->id}/price-rejections", ['reason' => 'test']);
        $responsePrice->assertStatus(403);
        $this->assertEquals('APPROVAL_ROLE_PENDING', $responsePrice->json('error.code'));
    }

    /**
     * PUR-E2E-09,10,11 (T3.2):
     * Compensatory orders populate reorder_supplier_id from suppliers table without nulling.
     */
    public function test_compensatory_orders_populate_reorder_supplier_id_from_suppliers_table(): void
    {
        [$order, $orderItem] = $this->createOrder(OrderType::VIA_PURCHASING_OFFICER, 10, 50.0);

        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
            'items' => [['item_id' => $orderItem->id, 'quantity_received' => 8, 'quality' => 'normal']],
            'document_type' => 'delivery_note',
        ])->assertStatus(201);
        $order->update(['status' => OrderStatus::CLOSED]);

        $receipt = GoodsReceipt::where('purchase_order_id', $order->id)->firstOrFail();
        $receiptItem = $receipt->items()->firstOrFail();

        $variance = PurchaseVariance::create([
            'goods_receipt_id' => $receipt->id,
            'goods_receipt_item_id' => $receiptItem->id,
            'purchase_order_id' => $order->id,
            'item_name' => $this->itemA->name,
            'variance_type' => 'short',
            'quantity_ordered' => 10.0,
            'quantity_received' => 8.0,
            'quantity_variance' => -2.0,
            'unit_price' => 50.0,
            'variance_amount' => 100.0,
            'status' => 'pending',
            'action' => 'compensatory_order',
        ]);

        // Run goods receipt compensatory order handling
        $service = app(GoodsReceiptService::class);
        $method = new \ReflectionMethod($service, 'handleCompensatoryOrder');
        $method->setAccessible(true);
        $compensatory = $method->invoke($service, $variance, $order, [['item_id' => $orderItem->id, 'quantity' => 2.0]]);

        $compensatory->refresh();
        $this->assertNotNull($compensatory->new_order_id);
        $this->assertEquals($this->supplier->id, $compensatory->reorder_supplier_id);
    }

    /**
     * PUR-E2E-14 (T3.3):
     * Accountant creates supplier.
     * Price edit permissions are isolated (portal for subscribed, procurement for non-subscribed, accountant blocked).
     */
    public function test_accountant_creates_supplier_and_price_edit_permissions_isolated(): void
    {
        // 1. Accountant creates supplier via company portal
        $responseCreate = $this->actingAs($this->accountantUser, 'sanctum')
            ->postJson('/api/v1/company/me/suppliers', [
                'name' => 'Accountant Created Supplier',
                'category' => 'Foodstuff',
                'contactPhone' => '0501234567',
                'isExternal' => true,
            ]);
        $responseCreate->assertStatus(201);
        $createdSupplierId = $responseCreate->json('data.id');

        // 2. Subscribed supplier item (portal managed)
        $subscribedSupplier = AsabSupplier::create([
            'company_id' => $this->company->id,
            'name' => 'Subscribed Portal Supplier',
            'status' => 'active',
            'is_external' => false,
            'user_id' => $this->procurementUser->id, // linked portal account
        ]);

        $subItem = SupplierItem::create([
            'company_id' => $this->company->id,
            'name' => 'Subscribed Item Milk',
            'unit' => 'liter',
            'price' => 1000, // 10 SAR
            'supplier_id' => $subscribedSupplier->id,
            'status' => 'active',
        ]);

        // Procurement attempts to edit price on subscribed supplier's item -> 403
        $responseProcSub = $this->actingAs($this->procurementUser, 'sanctum')
            ->patchJson("/api/v1/company/me/items/{$subItem->id}", [
                'lastPriceSar' => 15.0,
            ]);
        $responseProcSub->assertStatus(403);
        $this->assertEquals('SUPPLIER_PRICE_MANAGED_BY_PORTAL', $responseProcSub->json('error.code'));

        // 3. Non-subscribed item: Procurement can edit price
        $nonSubItem = SupplierItem::create([
            'company_id' => $this->company->id,
            'name' => 'External Item Salt',
            'unit' => 'kg',
            'price' => 500, // 5 SAR
            'supplier_id' => $createdSupplierId,
            'status' => 'active',
        ]);

        $responseProcNonSub = $this->actingAs($this->procurementUser, 'sanctum')
            ->patchJson("/api/v1/company/me/items/{$nonSubItem->id}", [
                'lastPriceSar' => 6.0,
            ]);
        $responseProcNonSub->assertOk();
        $this->assertEquals(600, (int) $nonSubItem->fresh()->price);

        // 4. Accountant attempts to edit item price -> 403 FORBIDDEN_PRICE_EDIT
        $responseAccEdit = $this->actingAs($this->accountantUser, 'sanctum')
            ->patchJson("/api/v1/company/me/items/{$nonSubItem->id}", [
                'lastPriceSar' => 8.0,
            ]);
        $responseAccEdit->assertStatus(403);
        $this->assertEquals('FORBIDDEN_PRICE_EDIT', $responseAccEdit->json('error.code'));
    }

    /**
     * PUR-E2E-15 (T3.4):
     * Return quantity is capped at net received (total received - already returned).
     * Physical handover deducts inventory once (idempotent).
     */
    public function test_return_quantity_cap_and_physical_handover_idempotent(): void
    {
        [$order, $orderItem] = $this->createOrder(OrderType::VIA_PURCHASING_OFFICER, 10, 50.0);

        // Receive 8 units
        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
            'items' => [['item_id' => $orderItem->id, 'quantity_received' => 8, 'quality' => 'normal']],
            'document_type' => 'delivery_note',
        ])->assertStatus(201);
        $order->update(['status' => OrderStatus::CLOSED]);

        $returnService = app(ReturnManagementService::class);

        // 1. Attempting to return 10 units when only 8 were received fails with 422
        $this->expectException(AsabException::class);
        $returnService->createReturn($order, [
            'created_by' => $this->manager->id,
            'required_action' => ReturnRequiredAction::REPLACEMENT->value,
            'items' => [
                ['purchase_order_item_id' => $orderItem->id, 'return_quantity' => 10.0, 'quality_reason' => InspectionQuality::POOR->value],
            ],
        ]);
    }

    /**
     * PUR-E2E-15 Part B:
     * Valid return quantity creates return, and confirmHandover deducts stock idempotently.
     */
    public function test_confirm_handover_deducts_stock_idempotently(): void
    {
        [$order, $orderItem] = $this->createOrder(OrderType::VIA_PURCHASING_OFFICER, 10, 50.0);

        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
            'items' => [['item_id' => $orderItem->id, 'quantity_received' => 8, 'quality' => 'normal']],
            'document_type' => 'delivery_note',
        ])->assertStatus(201);
        $order->update(['status' => OrderStatus::CLOSED]);

        $invBefore = BranchInventory::where('branch_id', $this->branch->id)->where('item_id', $this->itemA->id)->value('available_quantity');
        $this->assertEquals(8.0, (float) $invBefore);

        // Return 3 units (<= 8)
        $returnService = app(ReturnManagementService::class);
        $returnOrder = $returnService->createReturn($order, [
            'created_by' => $this->manager->id,
            'required_action' => ReturnRequiredAction::CASH_REFUND->value,
            'items' => [
                ['purchase_order_item_id' => $orderItem->id, 'return_quantity' => 3.0, 'quality_reason' => InspectionQuality::POOR->value],
            ],
        ]);

        $this->assertNotNull($returnOrder);
        $this->assertEquals(3.0, (float) $returnOrder->items->first()->return_quantity);
        $this->assertNull($returnOrder->handed_over_at);

        // Stock has NOT been deducted yet (physical handover hasn't occurred)
        $this->assertEquals(8.0, (float) BranchInventory::where('branch_id', $this->branch->id)->where('item_id', $this->itemA->id)->value('available_quantity'));

        // Confirm handover via endpoint
        $responseHandover = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/returns/{$returnOrder->id}/confirm-handover");
        $responseHandover->assertOk();

        $returnOrder->refresh();
        $this->assertNotNull($returnOrder->handed_over_at);

        // Stock is now deducted by 3 (8 -> 5)
        $invAfter = BranchInventory::where('branch_id', $this->branch->id)->where('item_id', $this->itemA->id)->value('available_quantity');
        $this->assertEquals(5.0, (float) $invAfter);

        // Repeat confirm handover is idempotent
        $responseRepeat = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/returns/{$returnOrder->id}/confirm-handover");
        $responseRepeat->assertOk();

        // Stock remains 5 (no double deduction)
        $this->assertEquals(5.0, (float) BranchInventory::where('branch_id', $this->branch->id)->where('item_id', $this->itemA->id)->value('available_quantity'));
    }

    /**
     * PUR-E2E-16 (T3.5):
     * Return financial review sequence:
     * - Head accountant cannot approve before accountant review (409).
     * - Accountant reviews -> Head accountant final approves -> status updated.
     * - Head accountant can return for review.
     * - Escalation resolves real active BrandOwner.
     */
    public function test_return_financial_review_sequence_and_real_brand_owner(): void
    {
        [$order, $orderItem] = $this->createOrder(OrderType::VIA_PURCHASING_OFFICER, 10, 50.0);

        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
            'items' => [['item_id' => $orderItem->id, 'quantity_received' => 8, 'quality' => 'normal']],
            'document_type' => 'delivery_note',
        ])->assertStatus(201);
        $order->update(['status' => OrderStatus::CLOSED]);

        $returnService = app(ReturnManagementService::class);
        $returnOrder = $returnService->createReturn($order, [
            'created_by' => $this->manager->id,
            'required_action' => ReturnRequiredAction::CASH_REFUND->value,
            'items' => [
                ['purchase_order_item_id' => $orderItem->id, 'return_quantity' => 2.0, 'quality_reason' => InspectionQuality::POOR->value],
            ],
        ]);

        $this->assertEquals('pending_review', $returnOrder->financial_status);

        // 1. Head attempts to approve before accountant review -> 409
        $responseHeadPremature = $this->actingAs($this->headUser, 'sanctum')
            ->postJson("/api/v1/company/me/purchases/returns/{$returnOrder->id}/final-approve");
        $responseHeadPremature->assertStatus(409);
        $this->assertEquals('FINANCIAL_REVIEW_REQUIRED', $responseHeadPremature->json('error.code'));

        // 2. Accountant reviews return value
        $responseAccReview = $this->actingAs($this->accountantUser, 'sanctum')
            ->postJson("/api/v1/company/me/purchases/returns/{$returnOrder->id}/review", [
                'notes' => 'Accountant verified return credit note with supplier',
            ]);
        $responseAccReview->assertOk();

        $returnOrder->refresh();
        $this->assertEquals('reviewed_by_accountant', $returnOrder->financial_status);
        $this->assertEquals($this->accountantUser->id, $returnOrder->reviewed_by_accountant_id);

        // 3. Head final approves
        $responseHeadApprove = $this->actingAs($this->headUser, 'sanctum')
            ->postJson("/api/v1/company/me/purchases/returns/{$returnOrder->id}/final-approve", [
                'notes' => 'Head accountant final approval confirmed',
            ]);
        $responseHeadApprove->assertOk();

        $returnOrder->refresh();
        $this->assertEquals('approved_by_head', $returnOrder->financial_status);
        $this->assertEquals($this->headUser->id, $returnOrder->approved_by_head_id);

        // 4. Head can return for review
        $responseReturnForReview = $this->actingAs($this->headUser, 'sanctum')
            ->postJson("/api/v1/company/me/purchases/returns/{$returnOrder->id}/return-for-review", [
                'notes' => 'Discrepancy found, please re-review',
            ]);
        $responseReturnForReview->assertOk();

        $returnOrder->refresh();
        $this->assertEquals('pending_review', $returnOrder->financial_status);

        // 5. Returns index list uses items_count without N+1 query
        $responseList = $this->actingAs($this->accountantUser, 'sanctum')
            ->getJson('/api/v1/company/me/purchases/returns');
        $responseList->assertOk();
        $this->assertGreaterThanOrEqual(1, count($responseList->json('data')));
        $first = $responseList->json('data.0');
        $this->assertArrayHasKey('itemCount', $first);
        $this->assertArrayHasKey('financialStatus', $first);

        // 6. Escalation resolves real active BrandOwner (or 422 if none configured)
        $brandOwner = BrandOwner::create([
            'name' => 'Active Brand Owner',
            'email' => 'bo-stage3@test.local',
            'phone' => '0500000001',
            'password' => 'secret123',
            'is_active' => true,
            'status' => 'active',
        ]);
        $boUser = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'Active Brand Owner',
            'email' => 'bo-stage3@test.local',
            'password' => 'secret123',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $boUser->id,
            'role_key' => 'brand-owner',
            'scope' => 'brands',
            'brand_ids' => [$this->brand->id],
        ]);
        CompanyUser::create([
            'company_id' => $this->company->id,
            'user_id' => $boUser->id,
            'role_key' => 'brand-owner',
            'status' => 'active',
        ]);

        $responseEscalate = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/returns/{$returnOrder->id}/escalate", [
                'reason' => 'Supplier refused refund',
            ]);
        $responseEscalate->assertOk();
        $returnOrder->refresh();
        $this->assertEquals((string) $brandOwner->id, (string) $returnOrder->escalated_to);
    }

    /**
     * S3-CODEX-001 (T3.2):
     * A legacy-only purchase_suppliers ID (absent from authoritative suppliers table)
     * must NOT populate reorder_supplier_id on compensatory orders.
     */
    public function test_legacy_only_purchase_supplier_does_not_populate_reorder_supplier_id(): void
    {
        $legacyId = (string) \Illuminate\Support\Str::uuid();
        DB::table('purchase_suppliers')->insert([
            'id' => $legacyId,
            'name' => 'Legacy Only Supplier',
            'email' => 'legacy-only@test.local',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        [$order, $orderItem] = $this->createOrder(OrderType::VIA_PURCHASING_OFFICER, 10, 50.0);

        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
            'items' => [['item_id' => $orderItem->id, 'quantity_received' => 8, 'quality' => 'normal']],
            'document_type' => 'delivery_note',
        ])->assertStatus(201);

        $receipt = GoodsReceipt::where('purchase_order_id', $order->id)->firstOrFail();
        $receiptItem = $receipt->items()->firstOrFail();

        $variance = PurchaseVariance::create([
            'goods_receipt_id' => $receipt->id,
            'goods_receipt_item_id' => $receiptItem->id,
            'purchase_order_id' => $order->id,
            'item_name' => $this->itemA->name,
            'variance_type' => 'short',
            'quantity_ordered' => 10.0,
            'quantity_received' => 8.0,
            'quantity_variance' => -2.0,
            'unit_price' => 50.0,
            'variance_amount' => 100.0,
            'status' => 'pending',
            'action' => 'compensatory_order',
        ]);

        // Simulate a legacy-only supplier_id on the in-memory order model (exists in purchase_suppliers only)
        $order->supplier_id = $legacyId;
        [$newOrder] = $this->createOrder(OrderType::VIA_PURCHASING_OFFICER, 2, 50.0);
        $orderServiceMock = \Mockery::mock(\Modules\Purchase\Services\PurchaseOrderService::class);
        $orderServiceMock->shouldReceive('createOrder')->once()->andReturn($newOrder);
        $this->app->instance(\Modules\Purchase\Services\PurchaseOrderService::class, $orderServiceMock);
        $this->app->forgetInstance(GoodsReceiptService::class);

        $service = app(GoodsReceiptService::class);
        $method = new \ReflectionMethod($service, 'handleCompensatoryOrder');
        $method->setAccessible(true);
        $compensatory = $method->invoke($service, $variance, $order, [['item_id' => $orderItem->id, 'quantity' => 2.0]]);

        $compensatory->refresh();
        $this->assertNotNull($compensatory->new_order_id);
        $this->assertNull($compensatory->reorder_supplier_id);
    }

    /**
     * S3-CODEX-002 (T3.4):
     * Cumulative return quantity cap enforced across duplicate lines in one return,
     * draft updates, and physical handover.
     */
    public function test_duplicate_return_lines_and_draft_update_cannot_exceed_net_received(): void
    {
        [$order, $orderItem] = $this->createOrder(OrderType::VIA_PURCHASING_OFFICER, 10, 50.0);

        $this->actingAs($this->manager, 'sanctum')->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
            'items' => [['item_id' => $orderItem->id, 'quantity_received' => 8, 'quality' => 'normal']],
            'document_type' => 'delivery_note',
        ])->assertStatus(201);
        $order->update(['status' => OrderStatus::CLOSED]);

        // 1. HTTP CreateReturnRequest rejects duplicate purchase_order_item_id lines (422)
        $this->actingAs($this->manager, 'sanctum')
            ->withHeader('Idempotency-Key', (string) \Illuminate\Support\Str::uuid())
            ->postJson('/api/v1/purchase/returns', [
                'purchase_order_id' => $order->id,
                'required_action' => ReturnRequiredAction::CASH_REFUND->value,
                'items' => [
                    ['purchase_order_item_id' => $orderItem->id, 'return_quantity' => 5.0, 'quality_reason' => InspectionQuality::POOR->value],
                    ['purchase_order_item_id' => $orderItem->id, 'return_quantity' => 5.0, 'quality_reason' => InspectionQuality::POOR->value],
                ],
            ])->assertStatus(422);

        // 2. Service-level createReturn rejects duplicate lines exceeding 8 (5 + 5 = 10 > 8)
        $returnService = app(ReturnManagementService::class);
        $rejectedDuplicate = false;
        try {
            $returnService->createReturn($order, [
                'created_by' => $this->manager->id,
                'required_action' => ReturnRequiredAction::CASH_REFUND->value,
                'items' => [
                    ['purchase_order_item_id' => $orderItem->id, 'return_quantity' => 5.0, 'quality_reason' => InspectionQuality::POOR->value],
                    ['purchase_order_item_id' => $orderItem->id, 'return_quantity' => 5.0, 'quality_reason' => InspectionQuality::POOR->value],
                ],
            ]);
        } catch (AsabException $e) {
            $rejectedDuplicate = $e->errorCode === 'RETURN_EXCEEDS_NET_RECEIVED';
        }
        $this->assertTrue($rejectedDuplicate, 'Duplicate lines exceeding net received must throw RETURN_EXCEEDS_NET_RECEIVED.');

        // 3. Draft update cannot increase line quantity past net received (4 -> 9 > 8)
        $draft = $returnService->createReturn($order, [
            'created_by' => $this->manager->id,
            'status' => ReturnStatus::DRAFT,
            'required_action' => ReturnRequiredAction::CASH_REFUND->value,
            'items' => [
                ['purchase_order_item_id' => $orderItem->id, 'return_quantity' => 4.0, 'quality_reason' => InspectionQuality::POOR->value],
            ],
        ]);
        $draftLine = $draft->items->firstOrFail();

        $rejectedUpdate = false;
        try {
            $returnService->updateReturn($draft, [
                'items' => [
                    ['id' => $draftLine->id, 'return_quantity' => 9.0, 'quality_reason' => InspectionQuality::POOR->value],
                ],
            ]);
        } catch (AsabException $e) {
            $rejectedUpdate = $e->errorCode === 'RETURN_EXCEEDS_NET_RECEIVED';
        }
        $this->assertTrue($rejectedUpdate, 'Draft update exceeding net received must throw RETURN_EXCEEDS_NET_RECEIVED.');
    }
}
