<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\ApprovalStep;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\MonthlyWeightedCostService;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Inventory\Models\InventoryMonthlyCost;
use Modules\Inventory\Models\InventoryStockMovement;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Models\GoodsReceipt;
use Modules\Purchase\Models\Item;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Supplier\Models\Supplier;
use Tests\TestCase;

class PurchaseStage1ProvisionalCostTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branch;

    private BranchManager $manager;

    private Supplier $supplier;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'Stage 1 Cost Co',
            'plan' => 'Professional',
            'status' => 'active',
        ]);
        $brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'Brand Cost',
            'abbr' => 'CS',
            'sub_status' => 'active',
            'status' => 'active',
        ]);
        $this->branch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $brand->id,
        ]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
        $this->supplier = Supplier::create([
            'name' => 'Stage 1 Cost Supplier',
            'email' => 'supplier-cost@test.local',
            'password' => 'secret123',
            'is_active' => true,
        ]);
        $this->item = Item::create([
            'name' => 'Mozzarella',
            'unit' => 'kg',
            'is_active' => true,
        ]);
    }

    private function createConfirmedOrder(
        float $qty = 10.0,
        float $unitPrice = 30.0,
        OrderType $orderType = OrderType::DIRECT_SUPPLIER
    ): array {
        $order = PurchaseOrder::create([
            'order_type' => $orderType,
            'status' => OrderStatus::CONFIRMED,
            'branch_id' => $this->branch->id,
            'requested_by' => $this->manager->id,
            'sourceable_type' => Supplier::class,
            'sourceable_id' => $this->supplier->id,
            'supplier_id' => $this->supplier->id,
            'subtotal' => round($qty * $unitPrice, 2),
            'tax_rate' => 15.00,
            'tax_amount' => round($qty * $unitPrice * 0.15, 2),
            'total_amount' => round($qty * $unitPrice * 1.15, 2),
        ]);

        $orderItem = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'item_id' => $this->item->id,
            'item_name' => $this->item->name,
            'quantity_ordered' => $qty,
            'quantity_confirmed' => $qty,
            'unit_price' => $unitPrice,
            'total_price' => round($qty * $unitPrice, 2),
            'unit_of_measurement' => 'kg',
            'status' => 'confirmed',
        ]);

        return [$order, $orderItem];
    }

    public function test_t1_7_reg_dec_11_receiving_before_invoice_posts_provisional_weighted_cost(): void
    {
        [$order, $orderItem] = $this->createConfirmedOrder(10, 30);

        $res = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
                'items' => [[
                    'item_id' => $orderItem->id,
                    'quantity_received' => 10,
                    'quality' => 'normal',
                ]],
                'document_type' => 'delivery_note',
            ]);

        $res->assertStatus(201);

        $movement = InventoryStockMovement::where('branch_id', $this->branch->id)
            ->where('item_id', $this->item->id)
            ->firstOrFail();

        $this->assertSame('purchase_in', $movement->movement_type);
        $this->assertSame('PO_UNIT_PRICE_PROVISIONAL', $movement->cost_source);
        $this->assertEquals(10.0, (float) $movement->qty_delta);
        $this->assertSame(3000, (int) $movement->unit_cost_halalas);
        $this->assertSame(30000, (int) $movement->total_cost_halalas);

        $yearMonth = now()->format('Y-m');
        $monthly = InventoryMonthlyCost::where('company_id', $this->company->id)
            ->where('branch_id', $this->branch->id)
            ->where('item_id', $this->item->id)
            ->where('year_month', $yearMonth)
            ->firstOrFail();

        $this->assertSame(InventoryMonthlyCost::STATUS_PROVISIONAL, $monthly->status);
        $this->assertEquals(10.0, (float) $monthly->purchases_qty);
        $this->assertSame(30000, (int) $monthly->purchases_value_halalas);
        $this->assertSame(3000, (int) $monthly->unit_cost_halalas);
        $this->assertSame(1, (int) $monthly->revision);
    }

    private function authorizePriceRevision(GoodsReceipt $receipt, float $approvedUnitPrice): void
    {
        $procUser = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'Procurement Price Approver',
            'email' => 'proc-cost-'.uniqid().'@test.local',
            'password' => 'secret123',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $procUser->id,
            'role_key' => 'procurement',
            'scope' => 'all',
        ]);

        \Modules\Admin\Models\CompanyUser::create(['company_id' => $this->company->id, 'user_id' => $procUser->id,
            'role_key' => 'procurement', 'status' => 'active']);
        $review = $this->actingAs($procUser, 'sanctum')->getJson("/api/v1/procurement/receiving/{$receipt->id}/price-review")
            ->assertOk()->json();
        $this->postJson("/api/v1/procurement/receiving/{$receipt->id}/price-approvals", [
            'context_hash' => $review['context_hash'], 'receipt_item_ids' => array_column($review['lines'], 'receipt_item_id'),
            'reason' => 'Verified invoice price '.$approvedUnitPrice,
        ])->assertOk();
    }

    public function test_t1_7_reg_dec_12_later_invoice_value_adjustment_updates_cost_without_duplicating_qty(): void
    {
        [$order, $orderItem] = $this->createConfirmedOrder(10, 30, OrderType::VIA_PURCHASING_OFFICER);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
                'items' => [[
                    'item_id' => $orderItem->id,
                    'quantity_received' => 10,
                    'quality' => 'normal',
                ]],
                'document_type' => 'delivery_note',
            ])
            ->assertStatus(201);

        $receipt = GoodsReceipt::where('purchase_order_id', $order->id)->firstOrFail();


        // Later invoice has pre-VAT cost 350.00 SAR vs provisional 300.00 SAR (+5000 halalas delta)
        $resInv = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/{$receipt->id}/invoice", [
                'invoice_number' => 'INV-ADJ-350',
                'invoice_date' => today()->toDateString(),
                'amount_before_tax' => 350.00,
                'tax_rate' => 15,
            ]);

        $this->assertTrue(in_array($resInv->getStatusCode(), [200, 201], true));

        $this->authorizePriceRevision($receipt, 35.00);
        $receipt->refresh();
        $this->assertSame('adjusted', $receipt->cost_adjustment_status);
        $this->assertSame(0, (int) $receipt->pending_cost_delta_halalas);

        $movements = InventoryStockMovement::where('branch_id', $this->branch->id)
            ->where('item_id', $this->item->id)
            ->orderBy('revision')
            ->get();

        $this->assertCount(2, $movements);
        $this->assertSame('purchase_in', $movements[0]->movement_type);
        $this->assertSame('purchase_cost_adjustment', $movements[1]->movement_type);
        $this->assertSame('INVOICE_VALUE_ADJUSTMENT', $movements[1]->cost_source);
        $this->assertEquals(0.0, (float) $movements[1]->qty_delta);
        $this->assertSame(5000, (int) $movements[1]->total_cost_halalas);

        // Total quantity stays 10.0, total value is 35000 halalas
        $this->assertEquals(10.0, (float) $movements->sum('qty_delta'));
        $this->assertSame(35000, (int) $movements->sum('total_cost_halalas'));

        $monthly = InventoryMonthlyCost::where('company_id', $this->company->id)
            ->where('branch_id', $this->branch->id)
            ->where('item_id', $this->item->id)
            ->where('year_month', now()->format('Y-m'))
            ->firstOrFail();

        $this->assertSame(InventoryMonthlyCost::STATUS_PROVISIONAL, $monthly->status);
        $this->assertEquals(10.0, (float) $monthly->purchases_qty);
        $this->assertSame(35000, (int) $monthly->purchases_value_halalas);
        $this->assertSame(3500, (int) $monthly->unit_cost_halalas);
        $this->assertSame(2, (int) $monthly->revision);

        // Re-submitting identical invoice amount must not create a duplicate adjustment
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/{$receipt->id}/invoice", [
                'invoice_number' => 'INV-ADJ-350',
                'invoice_date' => today()->toDateString(),
                'amount_before_tax' => 350.00,
                'tax_rate' => 15,
            ]);

        $this->assertSame(2, InventoryStockMovement::where('branch_id', $this->branch->id)->where('item_id', $this->item->id)->count());
    }

    public function test_t1_7_reg_dec_13_closed_period_status_approved_is_immutable_and_records_pending_decision(): void
    {
        [$order, $orderItem] = $this->createConfirmedOrder(10, 30, OrderType::VIA_PURCHASING_OFFICER);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
                'items' => [[
                    'item_id' => $orderItem->id,
                    'quantity_received' => 10,
                    'quality' => 'normal',
                ]],
                'document_type' => 'delivery_note',
            ])
            ->assertStatus(201);

        $receipt = GoodsReceipt::where('purchase_order_id', $order->id)->firstOrFail();

        $yearMonth = now()->format('Y-m');
        $approver = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'Cost Approver',
            'email' => 'approver@test.local',
            'password' => 'secret123',
            'status' => 'active',
        ]);

        /** @var MonthlyWeightedCostService $costService */
        $costService = app(MonthlyWeightedCostService::class);
        $approvedRow = $costService->approve(
            (string) $this->company->id,
            (string) $this->branch->id,
            (string) $this->item->id,
            $yearMonth,
            (string) $approver->id
        );
        $this->assertSame(InventoryMonthlyCost::STATUS_APPROVED, $approvedRow->status);
        $revisionBefore = (int) $approvedRow->revision;

        // Direct recalculate() against closed period must fail closed
        $threwOnClosedRecalculate = false;
        try {
            $costService->recalculate(
                (string) $this->company->id,
                (string) $this->branch->id,
                (string) $this->item->id,
                $yearMonth,
                0.0,
                0,
                10.0,
                40000
            );
        } catch (\DomainException) {
            $threwOnClosedRecalculate = true;
        }
        $this->assertTrue($threwOnClosedRecalculate);

        // Late invoice adjustment in closed period must preserve STATUS_APPROVED & record pending_period_decision
        $resInv = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/{$receipt->id}/invoice", [
                'invoice_number' => 'INV-CLOSED-380',
                'invoice_date' => today()->toDateString(),
                'amount_before_tax' => 380.00,
                'tax_rate' => 15,
            ]);

        $this->assertTrue(in_array($resInv->getStatusCode(), [200, 201], true));

        $this->authorizePriceRevision($receipt, 38.00);
        $approvedRow->refresh();
        $this->assertSame(InventoryMonthlyCost::STATUS_APPROVED, $approvedRow->status);
        $this->assertSame(30000, (int) $approvedRow->purchases_value_halalas);
        $this->assertSame(3000, (int) $approvedRow->unit_cost_halalas);
        $this->assertSame($revisionBefore, (int) $approvedRow->revision);

        // No silent adjustment movement posted into closed month
        $this->assertSame(
            0,
            InventoryStockMovement::where('source_type', 'goods_receipt')
                ->where('source_id', $receipt->id)
                ->where('movement_type', 'purchase_cost_adjustment')
                ->count()
        );

        $receipt->refresh();
        $this->assertSame('pending_period_decision', $receipt->cost_adjustment_status);
        $this->assertSame(8000, (int) $receipt->pending_cost_delta_halalas);
    }
}
