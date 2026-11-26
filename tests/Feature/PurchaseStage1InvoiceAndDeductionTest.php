<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Inventory\Models\InventoryStockMovement;
use Modules\Purchase\Enums\DocumentType;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Enums\VarianceAction;
use Modules\Purchase\Models\BranchInventory;
use Modules\Purchase\Models\GoodsReceipt;
use Modules\Purchase\Models\Item;
use Modules\Purchase\Models\PurchaseInvoice;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Purchase\Models\PurchaseVariance;
use Modules\Purchase\Services\VarianceService;
use Modules\Supplier\Models\Supplier;
use Tests\TestCase;

class PurchaseStage1InvoiceAndDeductionTest extends TestCase
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
            'name' => 'Stage 1 Inv Co',
            'plan' => 'Professional',
            'status' => 'active',
        ]);
        $brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'Brand Inv',
            'abbr' => 'IN',
            'sub_status' => 'active',
            'status' => 'active',
        ]);
        $this->branch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $brand->id,
        ]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
        $this->supplier = Supplier::create([
            'name' => 'Stage 1 Inv Supplier',
            'email' => 'supplier-inv@test.local',
            'password' => 'secret123',
            'is_active' => true,
        ]);
        $this->item = Item::create([
            'name' => 'Olive Oil',
            'unit' => 'liter',
            'is_active' => true,
        ]);
    }

    private function createConfirmedOrder(float $qty = 10.0, float $unitPrice = 30.0): array
    {
        $order = PurchaseOrder::create([
            'order_type' => OrderType::DIRECT_SUPPLIER,
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
            'unit_of_measurement' => 'liter',
            'status' => 'confirmed',
        ]);

        return [$order, $orderItem];
    }

    public function test_t1_4_start_receiving_creates_invoice_before_deduct_from_invoice_variance(): void
    {
        [$order, $orderItem] = $this->createConfirmedOrder(10, 30);

        $res = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
                'items' => [[
                    'item_id' => $orderItem->id,
                    'quantity_received' => 8,
                    'quality' => 'normal',
                ]],
                'document_type' => 'invoice',
                'invoice_data' => [
                    'invoice_number' => 'INV-DEDUCT-001',
                    'invoice_date' => today()->toDateString(),
                    'amount_before_tax' => 300.00,
                    'tax_rate' => 15,
                ],
                'variance' => [
                    'action' => 'deduct_from_invoice',
                    'note' => '2 liters short',
                    'deduct_data' => [
                        'amount' => 46.00,
                        'reason' => 'short_quantity',
                        'notes' => 'VAT-inclusive deduction sample',
                    ],
                ],
            ]);

        $res->assertStatus(201);

        $receipt = GoodsReceipt::where('purchase_order_id', $order->id)->firstOrFail();
        $invoice = PurchaseInvoice::where('goods_receipt_id', $receipt->id)->first();
        $this->assertNotNull($invoice);
        $this->assertEquals(345.00, (float) $invoice->total_amount);
        $this->assertEquals(46.00, (float) $invoice->deduction_amount);
        $this->assertEquals(299.00, (float) $invoice->final_amount);

        $variance = PurchaseVariance::where('purchase_order_id', $order->id)->firstOrFail();
        $this->assertSame(VarianceAction::DEDUCT_FROM_INVOICE, $variance->action);
        $this->assertSame($receipt->id, $variance->goods_receipt_id);
        $this->assertEquals(46.00, (float) $variance->amount_to_deduct);
        $this->assertStringContainsString($variance->id, (string) json_encode($invoice->deduction_details));
    }

    public function test_t1_4_later_invoice_upload_upgrades_delivery_note_and_updates_same_receipt_without_duplicate_stock(): void
    {
        Storage::fake('public');
        [$order, $orderItem] = $this->createConfirmedOrder(10, 30);

        $resStart = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
                'items' => [[
                    'item_id' => $orderItem->id,
                    'quantity_received' => 10,
                    'quality' => 'normal',
                ]],
                'document_type' => 'delivery_note',
            ]);

        $resStart->assertStatus(201);

        $receipt = GoodsReceipt::where('purchase_order_id', $order->id)->firstOrFail();
        $this->assertSame(DocumentType::DELIVERY_NOTE, $receipt->document_type);
        $this->assertEquals(10.0, (float) BranchInventory::where('branch_id', $this->branch->id)->where('item_id', $this->item->id)->value('available_quantity'));

        $movementsBefore = InventoryStockMovement::where('branch_id', $this->branch->id)
            ->where('item_id', $this->item->id)
            ->count();

        // PUR-E2E-07/08: Attach supplier tax invoice later on the SAME receipt
        $resInv1 = $this->actingAs($this->manager, 'sanctum')
            ->post("/api/v1/purchase/receiving/{$receipt->id}/invoice", [
                'invoice_number' => 'INV-LATE-001',
                'invoice_date' => today()->toDateString(),
                'amount_before_tax' => 300.00,
                'tax_rate' => 15,
                'file' => UploadedFile::fake()->create('supplier-invoice.pdf', 120, 'application/pdf'),
            ], ['Accept' => 'application/json']);

        $resInv1->assertStatus(201);

        $receipt->refresh();
        $this->assertSame(DocumentType::INVOICE, $receipt->document_type);
        $this->assertSame(1, GoodsReceipt::where('purchase_order_id', $order->id)->count());
        $this->assertSame(1, PurchaseInvoice::where('goods_receipt_id', $receipt->id)->count());

        $inv = PurchaseInvoice::where('goods_receipt_id', $receipt->id)->firstOrFail();
        $this->assertSame('INV-LATE-001', $inv->invoice_number);
        $this->assertEquals(300.00, (float) $inv->amount_before_tax);
        $this->assertEquals(45.00, (float) $inv->tax_amount);
        $this->assertEquals(345.00, (float) $inv->total_amount);
        $this->assertNotEmpty($inv->file_path);

        // REG-DEC-06: Replace/correct supplier invoice on the same receipt cleanly
        $resInv2 = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/{$receipt->id}/invoice", [
                'invoice_number' => 'INV-LATE-001',
                'invoice_date' => today()->toDateString(),
                'amount_before_tax' => 280.00,
                'tax_rate' => 15,
            ]);

        $this->assertTrue(in_array($resInv2->getStatusCode(), [200, 201], true));
        $this->assertSame(1, PurchaseInvoice::where('goods_receipt_id', $receipt->id)->count());
        $inv->refresh();
        $this->assertEquals(280.00, (float) $inv->amount_before_tax);
        $this->assertEquals(42.00, (float) $inv->tax_amount);
        $this->assertEquals(322.00, (float) $inv->total_amount);

        // Zero duplicate purchase_in movements or quantity inflation (value-only cost adjustment allowed)
        $this->assertSame(
            $movementsBefore,
            InventoryStockMovement::where('branch_id', $this->branch->id)
                ->where('item_id', $this->item->id)
                ->where('movement_type', 'purchase_in')
                ->count()
        );
        $this->assertEquals(
            10.0,
            (float) InventoryStockMovement::where('branch_id', $this->branch->id)
                ->where('item_id', $this->item->id)
                ->sum('qty_delta')
        );
        $this->assertEquals(10.0, (float) BranchInventory::where('branch_id', $this->branch->id)->where('item_id', $this->item->id)->value('available_quantity'));
    }

    public function test_t1_4_deduction_accumulates_and_rejects_non_positive_or_over_total(): void
    {
        [$order, $orderItem] = $this->createConfirmedOrder(10, 30);

        $resStart = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", [
                'items' => [[
                    'item_id' => $orderItem->id,
                    'quantity_received' => 10,
                    'quality' => 'normal',
                ]],
                'document_type' => 'invoice',
                'invoice_data' => [
                    'invoice_number' => 'INV-ACCUM-001',
                    'invoice_date' => today()->toDateString(),
                    'amount_before_tax' => 100.00,
                    'tax_rate' => 15,
                ],
            ]);
        $resStart->assertStatus(201);

        $receipt = GoodsReceipt::where('purchase_order_id', $order->id)->firstOrFail();
        $invoice = PurchaseInvoice::where('goods_receipt_id', $receipt->id)->firstOrFail();

        $invoice->applyDeduction(15.00, 'short_quantity', ['notes' => 'First deduction']);
        $invoice->applyDeduction(25.00, 'damaged_quality', ['notes' => 'Second deduction']);
        $invoice->refresh();

        $this->assertEquals(40.00, (float) $invoice->deduction_amount);
        $this->assertEquals(75.00, (float) $invoice->final_amount);
        $this->assertCount(2, $invoice->deduction_details);

        // Reject <= 0
        $variance = PurchaseVariance::create([
            'purchase_order_id' => $order->id,
            'goods_receipt_id' => $receipt->id,
            'goods_receipt_item_id' => $receipt->items()->firstOrFail()->id,
            'item_id' => $this->item->id,
            'item_name' => $this->item->name,
            'variance_type' => 'short',
            'quantity_ordered' => 10,
            'quantity_received' => 9,
            'quantity_variance' => -1,
            'unit_price' => 30,
            'variance_amount' => 30,
            'status' => 'pending',
        ]);

        $varianceService = app(VarianceService::class);
        $threwNonPositive = false;
        try {
            $varianceService->deductFromInvoice($variance, 0.0, 'short_quantity');
        } catch (\InvalidArgumentException) {
            $threwNonPositive = true;
        }
        $this->assertTrue($threwNonPositive, 'Zero or negative deduction must throw InvalidArgumentException.');

        // Reject over-total deduction (remaining = 75, requesting 80)
        $threwOverTotal = false;
        try {
            $varianceService->deductFromInvoice($variance, 80.00, 'short_quantity');
        } catch (\InvalidArgumentException) {
            $threwOverTotal = true;
        }
        $this->assertTrue($threwOverTotal, 'Over-total deduction must throw InvalidArgumentException.');

        $invoice->refresh();
        $this->assertEquals(40.00, (float) $invoice->deduction_amount);
        $this->assertEquals(75.00, (float) $invoice->final_amount);
    }
}
