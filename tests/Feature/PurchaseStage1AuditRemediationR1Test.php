<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Admin\Models\ApprovalStep;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\InventoryReconciliationService;
use Modules\Admin\Services\MonthlyWeightedCostService;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Inventory\Models\InventoryMonthlyCost;
use Modules\Inventory\Models\InventoryStockMovement;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Models\BranchInventory;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\GoodsReceipt;
use Modules\Purchase\Models\Item;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Purchase\Models\PurchaseVariance;
use Modules\Supplier\Models\Supplier;
use Tests\TestCase;

class PurchaseStage1AuditRemediationR1Test extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branch;

    private BranchManager $manager;

    private AsabUser $accountant;

    private Supplier $supplier;

    private Item $itemA;

    private Item $itemB;

    private Item $itemUnlisted;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create([
            'name' => 'Stage 1 R1 Co',
            'plan' => 'Professional',
            'status' => 'active',
        ]);
        $brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'Brand R1',
            'abbr' => 'R1',
            'sub_status' => 'active',
            'status' => 'active',
        ]);
        $this->branch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $brand->id,
        ]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
        $this->accountant = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'R1 Accountant',
            'email' => 'r1-acc@test.local',
            'password' => 'secret123',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $this->accountant->id,
            'role_key' => 'accountant',
            'scope' => 'all',
        ]);
        CompanyUser::create([
            'user_id' => $this->accountant->id,
            'company_id' => $this->company->id,
            'role_key' => 'accountant',
            'status' => 'active',
        ]);
        $this->supplier = Supplier::create([
            'name' => 'R1 Supplier',
            'email' => 'supplier-r1@test.local',
            'password' => 'secret123',
            'is_active' => true,
        ]);
        $this->itemA = Item::create(['name' => 'Flour A', 'unit' => 'kg', 'is_active' => true]);
        $this->itemB = Item::create(['name' => 'Sugar B', 'unit' => 'kg', 'is_active' => true]);
        $this->itemUnlisted = Item::create(['name' => 'Gift Mint', 'unit' => 'kg', 'is_active' => true]);
        foreach ([
            [$this->itemA, 30.00],
            [$this->itemB, 20.00],
            [$this->itemUnlisted, 10.00],
        ] as [$item, $price]) {
            BranchItem::create([
                'branch_id' => $this->branch->id,
                'item_id' => $item->id,
                'price' => $price,
                'quantity' => 0,
            ]);
        }
    }

    private function createOrderWithItems(array $lines, OrderType $orderType = OrderType::DIRECT_SUPPLIER): array
    {
        $subtotal = 0.0;
        foreach ($lines as $line) {
            $subtotal += $line['qty'] * $line['price'];
        }
        $order = PurchaseOrder::create([
            'order_type' => $orderType,
            'status' => OrderStatus::CONFIRMED,
            'branch_id' => $this->branch->id,
            'requested_by' => $this->manager->id,
            'sourceable_type' => Supplier::class,
            'sourceable_id' => $this->supplier->id,
            'supplier_id' => $this->supplier->id,
            'subtotal' => round($subtotal, 2),
            'tax_rate' => 15.00,
            'tax_amount' => round($subtotal * 0.15, 2),
            'total_amount' => round($subtotal * 1.15, 2),
        ]);

        $orderItems = [];
        foreach ($lines as $line) {
            $item = $line['item'];
            $orderItems[] = PurchaseOrderItem::create([
                'purchase_order_id' => $order->id,
                'item_id' => $item->id,
                'item_name' => $item->name,
                'quantity_ordered' => $line['qty'],
                'quantity_confirmed' => $line['qty'],
                'unit_price' => $line['price'],
                'total_price' => round($line['qty'] * $line['price'], 2),
                'unit_of_measurement' => 'kg',
                'status' => 'confirmed',
            ]);
        }

        return [$order, $orderItems];
    }

    public function test_aud_s1_002_multi_line_deduction_applies_request_total_once_and_respects_cap(): void
    {
        [$order, [$item1, $item2]] = $this->createOrderWithItems([
            ['item' => $this->itemA, 'qty' => 10, 'price' => 30],
            ['item' => $this->itemB, 'qty' => 10, 'price' => 20],
        ]);

        $res = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
                'items' => [
                    ['item_id' => $item1->id, 'quantity_received' => 8, 'quality' => 'normal'],
                    ['item_id' => $item2->id, 'quantity_received' => 9, 'quality' => 'poor'],
                ],
                'document_type' => 'invoice',
                'invoice_data' => [
                    'invoice_number' => 'INV-MULTI-002',
                    'invoice_date' => today()->toDateString(),
                    'amount_before_tax' => 500.00,
                    'tax_rate' => 15,
                ],
                'variance' => [
                    'action' => 'deduct_from_invoice',
                    'note' => 'Receipt-level aggregate deduction',
                    'deduct_data' => [
                        'amount' => 46.00,
                        'reason' => 'short_quantity',
                    ],
                ],
            ]);

        $res->assertStatus(201);
        $receipt = GoodsReceipt::where('purchase_order_id', $order->id)->firstOrFail();
        $invoice = $receipt->fresh('invoice')->invoice;
        $this->assertEquals(575.00, (float) $invoice->total_amount);
        $this->assertEquals(46.00, (float) $invoice->deduction_amount);
        $this->assertEquals(529.00, (float) $invoice->final_amount);

        $variances = PurchaseVariance::where('goods_receipt_id', $receipt->id)->get();
        $this->assertCount(2, $variances);
        $this->assertEquals(
            46.00,
            round((float) $variances->sum('amount_to_deduct'), 2),
            'Per-line allocated deductions must sum strictly to requested aggregate deduction.'
        );

        // Exceeding invoice cap on multi-line request must roll back completely
        [$orderCap, [$c1, $c2]] = $this->createOrderWithItems([
            ['item' => $this->itemA, 'qty' => 10, 'price' => 10],
            ['item' => $this->itemB, 'qty' => 10, 'price' => 10],
        ]);
        $resCap = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$orderCap->id}/start", [
                'items' => [
                    ['item_id' => $c1->id, 'quantity_received' => 9, 'quality' => 'normal'],
                    ['item_id' => $c2->id, 'quantity_received' => 9, 'quality' => 'normal'],
                ],
                'document_type' => 'invoice',
                'invoice_data' => [
                    'invoice_number' => 'INV-CAP-FAIL',
                    'invoice_date' => today()->toDateString(),
                    'amount_before_tax' => 100.00,
                    'tax_rate' => 0,
                ],
                'variance' => [
                    'action' => 'deduct_from_invoice',
                    'deduct_data' => [
                        'amount' => 150.00,
                        'reason' => 'short_quantity',
                    ],
                ],
            ]);
        $resCap->assertStatus(422);
        $this->assertSame(0, GoodsReceipt::where('purchase_order_id', $orderCap->id)->count());
    }

    public function test_aud_s1_003_rejects_contradictory_vat_and_totals_on_start_and_later_invoice(): void
    {
        [$order, [$item1]] = $this->createOrderWithItems([
            ['item' => $this->itemA, 'qty' => 10, 'price' => 30],
        ]);

        // Contradictory tax_amount=0, total_amount=1 at 15% on startReceiving
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
                'items' => [['item_id' => $item1->id, 'quantity_received' => 10, 'quality' => 'normal']],
                'document_type' => 'invoice',
                'invoice_data' => [
                    'invoice_number' => 'INV-BAD-VAT-1',
                    'invoice_date' => today()->toDateString(),
                    'amount_before_tax' => 300.00,
                    'tax_rate' => 15,
                    'tax_amount' => 0,
                    'total_amount' => 1,
                ],
            ])
            ->assertStatus(422);

        $this->assertSame(0, GoodsReceipt::where('purchase_order_id', $order->id)->count());

        // Contradictory tax_rate vs vat_rate alias
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
                'items' => [['item_id' => $item1->id, 'quantity_received' => 10, 'quality' => 'normal']],
                'document_type' => 'invoice',
                'invoice_data' => [
                    'invoice_number' => 'INV-BAD-VAT-2',
                    'invoice_date' => today()->toDateString(),
                    'amount_before_tax' => 300.00,
                    'tax_rate' => 15,
                    'vat_rate' => 0,
                ],
            ])
            ->assertStatus(422);

        // Start cleanly with delivery_note, then verify later-invoice also rejects override & derives 15% accurately
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
                'items' => [['item_id' => $item1->id, 'quantity_received' => 10, 'quality' => 'normal']],
                'document_type' => 'delivery_note',
            ])
            ->assertStatus(201);

        $receipt = GoodsReceipt::where('purchase_order_id', $order->id)->firstOrFail();

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/{$receipt->id}/invoice", [
                'invoice_number' => 'INV-LATE-BAD',
                'invoice_date' => today()->toDateString(),
                'amount_before_tax' => 300.00,
                'tax_rate' => 15,
                'tax_amount' => 0,
                'total_amount' => 300.00,
            ])
            ->assertStatus(422);

        // Boundary 123.45 at 15% -> tax 18.52, total 141.97
        $resOk = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/{$receipt->id}/invoice", [
                'invoice_number' => 'INV-BOUNDARY',
                'invoice_date' => today()->toDateString(),
                'amount_before_tax' => 123.45,
                'tax_rate' => 15,
            ]);
        $this->assertTrue(in_array($resOk->getStatusCode(), [200, 201], true));
        $inv = $receipt->fresh('invoice')->invoice;
        $this->assertEquals(18.52, (float) $inv->tax_amount);
        $this->assertEquals(141.97, (float) $inv->total_amount);
    }

    private function approveReceiptPriceThroughApi(GoodsReceipt $receipt, AsabUser $actor): Operation
    {
        CompanyUser::firstOrCreate(['company_id' => $this->company->id, 'user_id' => $actor->id],
            ['role_key' => 'procurement', 'status' => 'active']);
        $review = $this->actingAs($actor, 'sanctum')->getJson("/api/v1/procurement/receiving/{$receipt->id}/price-review")
            ->assertOk()->json();
        $response = $this->postJson("/api/v1/procurement/receiving/{$receipt->id}/price-approvals", [
            'context_hash' => $review['context_hash'], 'receipt_item_ids' => array_column($review['lines'], 'receipt_item_id'),
            'reason' => 'Reviewed supplier invoice price revision',
        ])->assertOk();
        return Operation::withoutGlobalScopes()->findOrFail($response->json('id'));
    }

    // Untrusted fixture for negative authorization probes only; never used as positive production evidence.
    private function authorizeReceiptLinePrice(
        GoodsReceipt $receipt,
        string $receiptItemId,
        float $approvedUnitPrice,
        AsabUser $actor
    ): Operation {
        $op = Operation::create([
            'public_id' => 'PUR-AUTH-'.strtoupper(substr(uniqid(), -6)),
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'module_key' => 'purchases',
            'source_module' => 'purchase_price_decision',
            'source_id' => $receipt->id,
            'amount' => (int) round($approvedUnitPrice * 100),
            'status' => Operation::STATUS_APPROVED,
            'approved_by_id' => $actor->id,
            'approved_at' => now(),
            'operation_date' => today(),
            'payload' => [
                'approved_lines' => [
                    $receiptItemId => ['approved_unit_price' => $approvedUnitPrice],
                ],
            ],
        ]);

        ApprovalStep::create([
            'operation_id' => $op->id,
            'stage_id' => 'approved',
            'action' => 'approve',
            'actor_user_id' => $actor->id,
            'actor_label' => 'Procurement',
            'occurred_at' => now(),
        ]);

        return $op;
    }

    public function test_aud_s1_004_cost_revisions_a_b_a_b_and_replay_preserve_exact_valuation_and_qty(): void
    {
        $procUser = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'Procurement S1-004',
            'email' => 'proc-s1004@test.local',
            'password' => 'secret123',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $procUser->id,
            'role_key' => 'procurement',
            'scope' => 'all',
        ]);

        [$order, [$item1]] = $this->createOrderWithItems([
            ['item' => $this->itemA, 'qty' => 10, 'price' => 30],
        ], OrderType::VIA_PURCHASING_OFFICER);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
                'items' => [['item_id' => $item1->id, 'quantity_received' => 10, 'quality' => 'normal']],
                'document_type' => 'delivery_note',
            ])
            ->assertStatus(201);

        $receipt = GoodsReceipt::where('purchase_order_id', $order->id)->firstOrFail();
        $receiptItem = $receipt->items()->firstOrFail();

        // Revision cycle: 300 -> 350 -> 300 -> 350 -> repeated 350 -> 275 (each authorized through the production API)
        foreach ([350.00, 300.00, 350.00, 350.00, 275.00] as $amount) {
            $this->authorizeReceiptLinePrice($receipt, $receiptItem->id, round($amount / 10, 2), $procUser);
            $res = $this->actingAs($this->manager, 'sanctum')
                ->postJson("/api/v1/purchase/receiving/{$receipt->id}/invoice", [
                    'invoice_number' => 'INV-CYCLE',
                    'invoice_date' => today()->toDateString(),
                    'amount_before_tax' => $amount,
                    'tax_rate' => 15,
                ]);
            $this->assertTrue(in_array($res->getStatusCode(), [200, 201], true));
            $this->approveReceiptPriceThroughApi($receipt, $procUser);
        }

        $totalValue = (int) InventoryStockMovement::where('source_type', 'goods_receipt')
            ->where('source_id', $receipt->id)
            ->sum('total_cost_halalas');
        $totalQty = (float) InventoryStockMovement::where('source_type', 'goods_receipt')
            ->where('source_id', $receipt->id)
            ->sum('qty_delta');

        $this->assertSame(27500, $totalValue);
        $this->assertEquals(10.0, $totalQty);

        // Only 5 movements (initial + 4 real changes; repeated 350 skipped cleanly)
        $this->assertSame(5, InventoryStockMovement::where('source_type', 'goods_receipt')->where('source_id', $receipt->id)->count());

        $monthly = InventoryMonthlyCost::where('branch_id', $this->branch->id)->where('item_id', $this->itemA->id)->firstOrFail();
        $this->assertSame(27500, (int) $monthly->purchases_value_halalas);
        $this->assertSame(2750, (int) $monthly->unit_cost_halalas);
        $this->assertEquals(10.0, (float) $monthly->purchases_qty);
    }

    public function test_aud_s1_005_mixed_receipt_and_unlisted_excess_do_not_inflate_approved_stock_cost(): void
    {
        [$order, [$item1, $item2]] = $this->createOrderWithItems([
            ['item' => $this->itemA, 'qty' => 10, 'price' => 30], // 300
            ['item' => $this->itemB, 'qty' => 10, 'price' => 20], // 200
        ]);

        // Receive ItemA exact (10), ItemB over-delivered (13 vs 10 = 3 excess @ 20), plus Unlisted gift (5 @ 10 = 50)
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
                'items' => [
                    ['item_id' => $item1->id, 'quantity_received' => 10, 'quality' => 'normal'],
                    ['item_id' => $item2->id, 'quantity_received' => 13, 'quality' => 'normal'],
                ],
                'unlisted_items' => [
                    [
                        'item_id' => $this->itemUnlisted->id,
                        'item_name' => $this->itemUnlisted->name,
                        'unit' => 'kg',
                        'quantity' => 5,
                        'quality' => 'normal',
                        'price_per_unit' => 10,
                    ],
                ],
                'document_type' => 'delivery_note',
            ])
            ->assertStatus(201);

        $receipt = GoodsReceipt::where('purchase_order_id', $order->id)->firstOrFail();

        // Invoice pre-VAT = 300 + (13*20=260) + (5*10=50) = 610.00
        $resInv = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/{$receipt->id}/invoice", [
                'invoice_number' => 'INV-MIXED-610',
                'invoice_date' => today()->toDateString(),
                'amount_before_tax' => 610.00,
                'tax_rate' => 15,
            ]);
        $this->assertTrue(in_array($resInv->getStatusCode(), [200, 201], true));

        $costA = InventoryMonthlyCost::where('branch_id', $this->branch->id)->where('item_id', $this->itemA->id)->firstOrFail();
        $costB = InventoryMonthlyCost::where('branch_id', $this->branch->id)->where('item_id', $this->itemB->id)->firstOrFail();
        $costUnlisted = InventoryMonthlyCost::where('branch_id', $this->branch->id)->where('item_id', $this->itemUnlisted->id)->first();

        $this->assertEquals(10.0, (float) $costA->purchases_qty);
        $this->assertSame(30000, (int) $costA->purchases_value_halalas);
        $this->assertSame(3000, (int) $costA->unit_cost_halalas);

        $this->assertEquals(10.0, (float) $costB->purchases_qty);
        $this->assertSame(20000, (int) $costB->purchases_value_halalas);
        $this->assertSame(2000, (int) $costB->unit_cost_halalas);

        $this->assertNull($costUnlisted, 'Unlisted reserved items must not enter monthly weighted costs.');

        $receipt->refresh();
        $this->assertSame('pending_excess_approval', $receipt->cost_adjustment_status);
        $this->assertSame(11000, (int) $receipt->pending_cost_delta_halalas); // 6000 (ItemB 3@20) + 5000 (Unlisted 5@10)
    }

    public function test_aud_s1_006_approved_monthly_cost_is_immutable_across_reapproval_variants(): void
    {
        $ym = now()->format('Y-m');
        $service = app(MonthlyWeightedCostService::class);
        $created = $service->recalculate(
            $this->company->id,
            $this->branch->id,
            $this->itemA->id,
            $ym,
            10,
            30000,
            5,
            15000,
            0,
            0,
            InventoryMonthlyCost::STATUS_APPROVED,
            $this->accountant->id
        );
        $revBefore = (int) $created->revision;

        // Same-value reapproval returns untouched row without revision bump
        $same = $service->recalculate(
            $this->company->id,
            $this->branch->id,
            $this->itemA->id,
            $ym,
            10,
            30000,
            5,
            15000,
            0,
            0,
            InventoryMonthlyCost::STATUS_APPROVED,
            $this->accountant->id
        );
        $this->assertSame($revBefore, (int) $same->revision);
        $this->assertSame(15000, (int) $same->purchases_value_halalas);

        // Changed-value reapproval MUST fail for ALL callers including approvedById
        $threw = false;
        try {
            $service->recalculate(
                $this->company->id,
                $this->branch->id,
                $this->itemA->id,
                $ym,
                10,
                30000,
                15,
                99000,
                0,
                0,
                InventoryMonthlyCost::STATUS_APPROVED,
                $this->accountant->id
            );
        } catch (\DomainException) {
            $threw = true;
        }
        $this->assertTrue($threw);
        $created->refresh();
        $this->assertSame($revBefore, (int) $created->revision);
        $this->assertSame(15000, (int) $created->purchases_value_halalas);
        $this->assertEquals(5.0, (float) $created->purchases_qty);
    }

    public function test_aud_s1_007_stocktake_and_correction_preserve_reserved_excess_without_double_subtraction(): void
    {
        [$order, [$item1]] = $this->createOrderWithItems([
            ['item' => $this->itemA, 'qty' => 10, 'price' => 30],
        ]);

        // Receive 13 against 10 approved (10 available, 3 reserved) + unlisted gift 5 (0 available, 5 reserved)
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
                'items' => [['item_id' => $item1->id, 'quantity_received' => 13, 'quality' => 'normal']],
                'unlisted_items' => [[
                    'item_id' => $this->itemUnlisted->id,
                    'item_name' => $this->itemUnlisted->name,
                    'unit' => 'kg',
                    'quantity' => 5,
                    'quality' => 'normal',
                ]],
                'document_type' => 'delivery_note',
            ])
            ->assertStatus(201);

        $countedAt = now()->subMinutes(5);
        $op = Operation::create([
            'public_id' => 'INV-R1-007',
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'module_key' => 'inventory',
            'amount' => 0,
            'status' => Operation::STATUS_APPROVED,
            'operation_date' => today(),
            'payload' => [
                'countedAt' => $countedAt->toIso8601String(),
                'items' => [
                    ['itemId' => $this->itemA->id, 'actualQty' => 13, 'unitPriceHalalas' => 3000],
                    ['itemId' => $this->itemUnlisted->id, 'actualQty' => 5, 'unitPriceHalalas' => 1000],
                ],
                'revision' => 1,
            ],
        ]);

        /** @var InventoryReconciliationService $recon */
        $recon = app(InventoryReconciliationService::class);
        $recon->postOnFinalApproval($op, $this->accountant);

        $this->assertEquals(10.0, (float) BranchInventory::where('branch_id', $this->branch->id)->where('item_id', $this->itemA->id)->value('available_quantity'));
        $this->assertNull(BranchInventory::where('branch_id', $this->branch->id)->where('item_id', $this->itemUnlisted->id)->first());

        // Accountant correction 13 -> 12 must yield available 9 (12 - 3 reserved, no double subtraction!)
        $op->update(['status' => Operation::STATUS_FINAL]);
        $recon->correctApprovedInventory($this->company->id, $op->fresh(), [
            ['itemId' => $this->itemA->id, 'actualQty' => 12, 'reason' => 'Physical shortage 1kg in approved stock'],
        ], $this->accountant);

        $this->assertEquals(9.0, (float) BranchInventory::where('branch_id', $this->branch->id)->where('item_id', $this->itemA->id)->value('available_quantity'));
    }

    public function test_aud_s1_005_unchanged_and_changed_unapproved_prices_on_excess_receipts_remain_isolated(): void
    {
        // 1. Single-line excess receipt: 10 @ 30 ordered, 13 received (10 available, 3 reserved)
        [$order, [$item1]] = $this->createOrderWithItems([
            ['item' => $this->itemA, 'qty' => 10, 'price' => 30],
        ]);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
                'items' => [['item_id' => $item1->id, 'quantity_received' => 13, 'quality' => 'normal']],
                'document_type' => 'delivery_note',
            ])
            ->assertStatus(201);

        $receipt = GoodsReceipt::where('purchase_order_id', $order->id)->firstOrFail();

        // 1a. Unchanged PO price (13 @ 30 = 390): available stays 30000, reserved 9000, no pending price delta
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/{$receipt->id}/invoice", [
                'invoice_number' => 'INV-EXCESS-390',
                'invoice_date' => today()->toDateString(),
                'amount_before_tax' => 390.00,
                'tax_rate' => 15,
            ])
            ->assertStatus(201);

        $costA = InventoryMonthlyCost::where('branch_id', $this->branch->id)->where('item_id', $this->itemA->id)->firstOrFail();
        $this->assertSame(30000, (int) $costA->purchases_value_halalas);
        $this->assertSame(3000, (int) $costA->unit_cost_halalas);
        $receipt->refresh();
        $this->assertSame('pending_excess_approval', $receipt->cost_adjustment_status);
        $this->assertSame(9000, (int) $receipt->pending_cost_delta_halalas);

        // 1b. Unapproved price increase (13 @ 35 = 455): available stays 30000, tracks 10500 reserved + 5000 pending price delta
        $resInc = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/{$receipt->id}/invoice", [
                'invoice_number' => 'INV-EXCESS-455',
                'invoice_date' => today()->toDateString(),
                'amount_before_tax' => 455.00,
                'tax_rate' => 15,
            ]);
        $this->assertTrue(in_array($resInc->getStatusCode(), [200, 201], true));

        $costA->refresh();
        $this->assertSame(30000, (int) $costA->purchases_value_halalas);
        $this->assertSame(3000, (int) $costA->unit_cost_halalas);
        $receipt->refresh();
        $this->assertSame('pending_excess_and_price_approval', $receipt->cost_adjustment_status);
        $this->assertSame(15500, (int) $receipt->pending_cost_delta_halalas);
        $this->assertSame(10500, (int) ($receipt->cost_adjustment_meta['reserved_excess_items'][0]['reserved_value_halalas'] ?? 0));
        $this->assertSame(5000, (int) ($receipt->cost_adjustment_meta['pending_price_deltas'][0]['pending_price_delta_halalas'] ?? 0));

        // 1c. Unapproved price decrease (13 @ 25 = 325) + replay: available STILL stays 30000, tracks 7500 reserved + (-5000) price delta
        foreach ([325.00, 325.00] as $amount) {
            $resDec = $this->actingAs($this->manager, 'sanctum')
                ->postJson("/api/v1/purchase/receiving/{$receipt->id}/invoice", [
                    'invoice_number' => 'INV-EXCESS-325',
                    'invoice_date' => today()->toDateString(),
                    'amount_before_tax' => $amount,
                    'tax_rate' => 15,
                ]);
            $this->assertTrue(in_array($resDec->getStatusCode(), [200, 201], true));
        }

        $costA->refresh();
        $this->assertSame(30000, (int) $costA->purchases_value_halalas);
        $this->assertSame(3000, (int) $costA->unit_cost_halalas);
        $receipt->refresh();
        $this->assertSame('pending_excess_and_price_approval', $receipt->cost_adjustment_status);
        $this->assertSame(2500, (int) $receipt->pending_cost_delta_halalas);
        $this->assertSame(7500, (int) ($receipt->cost_adjustment_meta['reserved_excess_items'][0]['reserved_value_halalas'] ?? 0));
        $this->assertSame(-5000, (int) ($receipt->cost_adjustment_meta['pending_price_deltas'][0]['pending_price_delta_halalas'] ?? 0));

        // 1d. Corrected invoice returning to approved PO unit price (390) clears pending price deltas cleanly
        $resCorr = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/{$receipt->id}/invoice", [
                'invoice_number' => 'INV-EXCESS-390-CORR',
                'invoice_date' => today()->toDateString(),
                'amount_before_tax' => 390.00,
                'tax_rate' => 15,
            ]);
        $this->assertTrue(in_array($resCorr->getStatusCode(), [200, 201], true));

        $receipt->refresh();
        $this->assertSame('pending_excess_approval', $receipt->cost_adjustment_status);
        $this->assertSame(9000, (int) $receipt->pending_cost_delta_halalas);
        $this->assertArrayNotHasKey('pending_price_deltas', $receipt->cost_adjustment_meta ?? []);
        $this->assertSame(0, InventoryStockMovement::where('source_type', 'goods_receipt')->where('source_id', $receipt->id)->where('movement_type', 'purchase_cost_adjustment')->count());

        // 2. Mixed + unlisted excess with higher total (10A@30 + 13/10B@20 + 5@10 unlisted; invoice 715 instead of 610)
        [$orderMixed, [$mItem1, $mItem2]] = $this->createOrderWithItems([
            ['item' => $this->itemA, 'qty' => 10, 'price' => 30],
            ['item' => $this->itemB, 'qty' => 10, 'price' => 20],
        ]);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$orderMixed->id}/start", [
                'items' => [
                    ['item_id' => $mItem1->id, 'quantity_received' => 10, 'quality' => 'normal'],
                    ['item_id' => $mItem2->id, 'quantity_received' => 13, 'quality' => 'normal'],
                ],
                'unlisted_items' => [[
                    'item_id' => $this->itemUnlisted->id,
                    'item_name' => $this->itemUnlisted->name,
                    'unit' => 'kg',
                    'quantity' => 5,
                    'quality' => 'normal',
                    'price_per_unit' => 10,
                ]],
                'document_type' => 'delivery_note',
            ])
            ->assertStatus(201);

        $mixedReceipt = GoodsReceipt::where('purchase_order_id', $orderMixed->id)->firstOrFail();
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/{$mixedReceipt->id}/invoice", [
                'invoice_number' => 'INV-MIXED-715',
                'invoice_date' => today()->toDateString(),
                'amount_before_tax' => 715.00,
                'tax_rate' => 15,
            ])
            ->assertStatus(201);

        $costA->refresh();
        $costB = InventoryMonthlyCost::where('branch_id', $this->branch->id)->where('item_id', $this->itemB->id)->firstOrFail();
        $this->assertSame(60000, (int) $costA->purchases_value_halalas, 'Line A must stay capped at +30000 approved baseline.');
        $this->assertSame(20000, (int) $costB->purchases_value_halalas, 'Line B must stay capped at +20000 approved baseline.');
        $this->assertNull(InventoryMonthlyCost::where('branch_id', $this->branch->id)->where('item_id', $this->itemUnlisted->id)->first());
        $mixedReceipt->refresh();
        $this->assertSame('pending_excess_and_price_approval', $mixedReceipt->cost_adjustment_status);
        $this->assertNotEmpty($mixedReceipt->cost_adjustment_meta['pending_price_deltas'] ?? []);
    }

    public function test_aud_s1_005_approved_vs_unresolved_price_decision_and_dec_open_01_fail_closed(): void
    {
        $procUser = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'Procurement Decider',
            'email' => 'proc-r2@test.local',
            'password' => 'secret123',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $procUser->id,
            'role_key' => 'procurement',
            'scope' => 'all',
        ]);

        // A. DIRECT_SUPPLIER remains fail-closed (DEC-OPEN-01): neither bogus metadata nor Operation bypasses
        [$directOrder, [$dItem]] = $this->createOrderWithItems([
            ['item' => $this->itemA, 'qty' => 10, 'price' => 30],
        ]);
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$directOrder->id}/start", [
                'items' => [['item_id' => $dItem->id, 'quantity_received' => 13, 'quality' => 'normal']],
                'document_type' => 'delivery_note',
            ])
            ->assertStatus(201);
        $directReceipt = GoodsReceipt::where('purchase_order_id', $directOrder->id)->firstOrFail();
        $directReceiptItem = $directReceipt->items()->firstOrFail();
        $directReceipt->update([
            'cost_adjustment_meta' => [
                'approved_price_decisions' => [
                    $directReceiptItem->id => [
                        'status' => 'approved',
                        'approved_unit_price' => 35.00,
                        'approved_by' => '00000000-0000-4000-8000-000000000000',
                        'approved_at' => 'not-a-date',
                        'dec_open_01_authorized' => true,
                    ],
                ],
            ],
        ]);
        $this->authorizeReceiptLinePrice($directReceipt, $directReceiptItem->id, 35.00, $procUser);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/{$directReceipt->id}/invoice", [
                'invoice_number' => 'INV-DIRECT-OPEN01',
                'invoice_date' => today()->toDateString(),
                'amount_before_tax' => 455.00,
                'tax_rate' => 15,
            ])
            ->assertStatus(201);

        $costDirect = InventoryMonthlyCost::where('branch_id', $this->branch->id)->where('item_id', $this->itemA->id)->firstOrFail();
        $this->assertSame(30000, (int) $costDirect->purchases_value_halalas, 'DEC-OPEN-01 direct supplier must remain fail-closed.');

        // B. Non-excess (10/10) and shortage (8/10) without approval hold unapproved price increases and decreases pending
        [$exactOrder, [$eItem]] = $this->createOrderWithItems([
            ['item' => $this->itemB, 'qty' => 10, 'price' => 20],
        ]);
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$exactOrder->id}/start", [
                'items' => [['item_id' => $eItem->id, 'quantity_received' => 10, 'quality' => 'normal']],
                'document_type' => 'delivery_note',
            ])
            ->assertStatus(201);
        $exactReceipt = GoodsReceipt::where('purchase_order_id', $exactOrder->id)->firstOrFail();

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/{$exactReceipt->id}/invoice", [
                'invoice_number' => 'INV-NOEXCESS-INC',
                'invoice_date' => today()->toDateString(),
                'amount_before_tax' => 250.00,
                'tax_rate' => 15,
            ])
            ->assertStatus(201);
        $costB = InventoryMonthlyCost::where('branch_id', $this->branch->id)->where('item_id', $this->itemB->id)->firstOrFail();
        $this->assertSame(20000, (int) $costB->purchases_value_halalas);
        $this->assertSame('pending_price_approval', $exactReceipt->fresh()->cost_adjustment_status);
        $this->assertSame(5000, (int) $exactReceipt->fresh()->pending_cost_delta_halalas);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/{$exactReceipt->id}/invoice", [
                'invoice_number' => 'INV-NOEXCESS-DEC',
                'invoice_date' => today()->toDateString(),
                'amount_before_tax' => 160.00,
                'tax_rate' => 15,
            ]);
        $costB->refresh();
        $this->assertSame(20000, (int) $costB->purchases_value_halalas);
        $this->assertSame('pending_price_approval', $exactReceipt->fresh()->cost_adjustment_status);
        $this->assertSame(-4000, (int) $exactReceipt->fresh()->pending_cost_delta_halalas);

        // C. VIA_PURCHASING_OFFICER: Operation without ApprovalStep or role is rejected; genuine provenance approves
        $poOrder = PurchaseOrder::create([
            'order_type' => OrderType::VIA_PURCHASING_OFFICER,
            'status' => OrderStatus::CONFIRMED,
            'branch_id' => $this->branch->id,
            'requested_by' => $this->manager->id,
            'sourceable_type' => Supplier::class,
            'sourceable_id' => $this->supplier->id,
            'supplier_id' => $this->supplier->id,
            'subtotal' => 200.00,
            'tax_rate' => 15.00,
            'tax_amount' => 30.00,
            'total_amount' => 230.00,
        ]);
        $poLine = PurchaseOrderItem::create([
            'purchase_order_id' => $poOrder->id,
            'item_id' => $this->itemB->id,
            'item_name' => $this->itemB->name,
            'quantity_ordered' => 10,
            'quantity_confirmed' => 10,
            'unit_price' => 20,
            'total_price' => 200.00,
            'unit_of_measurement' => 'kg',
            'status' => 'confirmed',
        ]);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$poOrder->id}/start", [
                'items' => [['item_id' => $poLine->id, 'quantity_received' => 13, 'quality' => 'normal']],
                'document_type' => 'delivery_note',
            ])
            ->assertStatus(201);

        $poReceipt = GoodsReceipt::where('purchase_order_id', $poOrder->id)->firstOrFail();
        $poReceiptItem = $poReceipt->items()->firstOrFail();

        // Operation lacking ApprovalStep / non-procurement actor must NOT authorize price update
        Operation::create([
            'public_id' => 'PUR-UNVERIFIED-1',
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'module_key' => 'purchases',
            'source_module' => 'purchase_price_decision',
            'source_id' => $poReceipt->id,
            'amount' => 2500,
            'status' => Operation::STATUS_APPROVED,
            'approved_by_id' => $this->accountant->id,
            'approved_at' => now(),
            'operation_date' => today(),
            'payload' => ['approved_lines' => [$poReceiptItem->id => ['approved_unit_price' => 25.00]]],
        ]);

        // Unresolved decision: 13 @ 25 = 325 keeps available at +20000 (total 40000)
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/{$poReceipt->id}/invoice", [
                'invoice_number' => 'INV-PO-PRICE',
                'invoice_date' => today()->toDateString(),
                'amount_before_tax' => 325.00,
                'tax_rate' => 15,
            ])
            ->assertStatus(201);

        $costB->refresh();
        $this->assertSame(40000, (int) $costB->purchases_value_halalas);

        // Record authoritative procurement Operation + ApprovalStep (25.00/unit) and re-reconcile
        $this->approveReceiptPriceThroughApi($poReceipt, $procUser);

        $resPoApproved = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/{$poReceipt->id}/invoice", [
                'invoice_number' => 'INV-PO-PRICE',
                'invoice_date' => today()->toDateString(),
                'amount_before_tax' => 325.00,
                'tax_rate' => 15,
            ]);
        $this->assertTrue(in_array($resPoApproved->getStatusCode(), [200, 201], true));

        $costB->refresh();
        $this->assertSame(45000, (int) $costB->purchases_value_halalas);
        $poReceipt->refresh();
        $this->assertSame('pending_excess_approval', $poReceipt->cost_adjustment_status);
        $this->assertSame(7500, (int) $poReceipt->pending_cost_delta_halalas);
        $this->assertArrayNotHasKey('pending_price_deltas', $poReceipt->cost_adjustment_meta ?? []);
    }

    public function test_aud_s1_008_exact_widget_generated_multipart_passes_real_laravel_contract(): void
    {
        Storage::fake('public');
        $snapshotPath = base_path('../ASSAB_PURCHASE_DELIVERY/audit-stage-1-remediation-r2/widget-multipart-contracts.json');
        $this->assertFileExists($snapshotPath, 'Run Flutter widget HTTP test first to generate widget-multipart-contracts.json.');
        $snapshots = json_decode((string) file_get_contents($snapshotPath), true, 512, JSON_THROW_ON_ERROR);

        $expectations = [
            'qty_short_15' => [
                'lines' => [
                    ['widget_item_id' => '11111111-1111-4111-8111-111111111111', 'item' => $this->itemA, 'qty' => 10, 'price' => 20],
                ],
                'deduction' => 46.00,
                'reason' => 'short_quantity',
                'tax_rate' => 15.0,
            ],
            'qty_short_0' => [
                'lines' => [
                    ['widget_item_id' => '11111111-1111-4111-8111-111111111112', 'item' => $this->itemA, 'qty' => 10, 'price' => 20],
                    ['widget_item_id' => '11111111-1111-4111-8111-111111111113', 'item' => $this->itemB, 'qty' => 5, 'price' => 20],
                ],
                'deduction' => 40.00,
                'reason' => 'short_quantity',
                'tax_rate' => 0.0,
            ],
            'quality_only_15' => [
                'lines' => [
                    ['widget_item_id' => '22222222-2222-4222-8222-222222222221', 'item' => $this->itemA, 'qty' => 5, 'price' => 20],
                ],
                'deduction' => 23.00,
                'reason' => 'damaged_quality',
                'tax_rate' => 15.0,
            ],
            'quality_only_0' => [
                'lines' => [
                    ['widget_item_id' => '22222222-2222-4222-8222-222222222222', 'item' => $this->itemA, 'qty' => 5, 'price' => 20],
                ],
                'deduction' => 20.00,
                'reason' => 'damaged_quality',
                'tax_rate' => 0.0,
            ],
        ];

        foreach ($expectations as $scenarioKey => $cfg) {
            $this->assertArrayHasKey($scenarioKey, $snapshots, "Missing widget contract '{$scenarioKey}'.");
            $snapshot = $snapshots[$scenarioKey];

            [$order, $orderItems] = $this->createOrderWithItems($cfg['lines']);
            $idMap = [];
            foreach ($cfg['lines'] as $idx => $lineCfg) {
                $idMap[$lineCfg['widget_item_id']] = $orderItems[$idx]->id;
            }

            // Reconstruct exact nested multipart array from widget-emitted FormData fields + distinct PNG attachments
            $queryPairs = [];
            foreach ($snapshot['fields'] as $entry) {
                $val = strtr((string) $entry['value'], $idMap);
                $queryPairs[] = rawurlencode((string) $entry['key']).'='.rawurlencode($val);
            }
            $payload = [];
            parse_str(implode('&', $queryPairs), $payload);

            // Ensure quality-only widget output never includes fabricated variance.items
            if (str_starts_with($scenarioKey, 'quality_only_')) {
                $this->assertArrayNotHasKey('items', $payload['variance'] ?? []);
            }

            $fileQueryPairs = [];
            $attachedFiles = [];
            foreach ($snapshot['files'] as $fEntry) {
                $field = (string) $fEntry['field'];
                $filename = pathinfo((string) $fEntry['filename'], PATHINFO_FILENAME).'.png';
                $attachedFiles[$field] = UploadedFile::fake()->image($filename);
                $fileQueryPairs[] = rawurlencode($field).'='.'__FILE__'.count($attachedFiles);
            }
            $fileTree = [];
            parse_str(implode('&', $fileQueryPairs), $fileTree);
            $i = 0;
            foreach ($attachedFiles as $field => $uploadedFile) {
                $i++;
                if ($field === 'invoice_data[photo]') {
                    $payload['invoice_data']['photo'] = $uploadedFile;
                } elseif ($field === 'variance[photo]') {
                    $payload['variance']['photo'] = $uploadedFile;
                }
            }
            $this->assertArrayHasKey('photo', $payload['invoice_data']);
            $this->assertArrayHasKey('photo', $payload['variance']);

            $res = $this->actingAs($this->manager, 'sanctum')
                ->post("/api/v1/purchase/receiving/orders/{$order->id}/start", $payload, [
                    'Accept' => 'application/json',
                    'Content-Type' => 'multipart/form-data',
                ]);

            $res->assertStatus(201, "Scenario {$scenarioKey} failed: ".$res->getContent());
            $receipt = GoodsReceipt::where('purchase_order_id', $order->id)->with(['invoice', 'variances'])->firstOrFail();
            $this->assertEquals($cfg['deduction'], (float) $receipt->invoice->deduction_amount, $scenarioKey);
            $this->assertSame($cfg['reason'], (string) $receipt->invoice->deduction_reason, $scenarioKey);
            $this->assertEquals($cfg['tax_rate'], (float) $receipt->invoice->tax_rate, $scenarioKey);
            $this->assertNotEmpty($receipt->invoice->file_path, $scenarioKey);
            $this->assertNotEmpty($receipt->variances->first()?->photo_evidence, $scenarioKey);
        }

        // Negative control: compensatory_order still rejects 0 quantity
        [$negOrder, [$negLine]] = $this->createOrderWithItems([
            ['item' => $this->itemA, 'qty' => 5, 'price' => 20],
        ]);
        $this->actingAs($this->manager, 'sanctum')
            ->post('/api/v1/purchase/receiving/orders/'.$negOrder->id.'/start', [
                'items' => [['item_id' => $negLine->id, 'quantity_received' => '3', 'quality' => 'normal']],
                'document_type' => 'delivery_note',
                'variance' => [
                    'action' => 'compensatory_order',
                    'items' => [['item_id' => $negLine->id, 'quantity' => '0']],
                ],
            ], ['Accept' => 'application/json', 'Content-Type' => 'multipart/form-data'])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['variance.items.0.quantity']);
    }
}
