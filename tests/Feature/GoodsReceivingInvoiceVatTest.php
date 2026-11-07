<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Models\GoodsReceipt;
use Modules\Purchase\Models\Item;
use Modules\Purchase\Models\PurchaseInvoice;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Purchase\Services\GoodsReceiptService;
use Modules\Supplier\Models\Supplier;
use Tests\TestCase;

class GoodsReceivingInvoiceVatTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private BranchManager $manager;

    private Supplier $supplier;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = AsabCompany::create(['name' => 'Test Company', 'plan' => 'Basic', 'status' => 'active'])->id;
        $brand = AsabBrand::create([
            'company_id' => $companyId,
            'name' => 'Brand Test',
            'abbr' => 'BT',
            'sub_status' => 'active',
            'status' => 'active',
        ]);
        $this->branch = Branch::factory()->create([
            'asab_company_id' => $companyId,
            'asab_brand_id' => $brand->id,
        ]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
        $this->supplier = Supplier::create([
            'name' => 'Test Supplier',
            'email' => 'supplier@test.com',
            'password' => 'secret123',
            'is_active' => true,
        ]);
        $this->item = Item::create([
            'name' => 'Test Product',
            'unit' => 'kg',
            'is_active' => true,
        ]);
    }

    private function createOrderWithReceipt(float $amount = 100.00): array
    {
        $order = PurchaseOrder::create([
            'order_type' => OrderType::DIRECT_SUPPLIER,
            'status' => OrderStatus::CONFIRMED,
            'branch_id' => $this->branch->id,
            'requested_by' => $this->manager->id,
            'sourceable_type' => Supplier::class,
            'sourceable_id' => $this->supplier->id,
            'supplier_id' => $this->supplier->id,
            'total_amount' => $amount,
            'actual_delivery_at' => now(),
        ]);

        $orderItem = PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'item_id' => $this->item->id,
            'item_name' => $this->item->name,
            'quantity_ordered' => 10,
            'quantity_confirmed' => 10,
            'unit_price' => $amount / 10,
            'total_price' => $amount,
            'unit_of_measurement' => 'kg',
            'status' => 'confirmed',
        ]);

        $receipt = GoodsReceipt::create([
            'purchase_order_id' => $order->id,
            'branch_id' => $this->branch->id,
            'received_by' => $this->manager->id,
            'status' => 'in_progress',
            'total_items_expected' => 1,
            'expected_amount' => $amount,
            'received_amount' => $amount,
            'inspection_started_at' => now(),
        ]);

        return [$order, $receipt, $orderItem];
    }

    public function test_inspection_endpoint_returns_real_invoice_financial_values_when_invoice_exists(): void
    {
        [$order, $receipt, $orderItem] = $this->createOrderWithReceipt(100.00);

        PurchaseInvoice::create([
            'goods_receipt_id' => $receipt->id,
            'purchase_order_id' => $order->id,
            'supplier_id' => $this->supplier->id,
            'invoice_number' => 'INV-2026-001',
            'invoice_date' => today(),
            'due_date' => today()->addDays(30),
            'payment_terms' => 'Net 30 days',
            'amount_before_tax' => 100.00,
            'tax_rate' => 15.00,
            'tax_amount' => 15.00,
            'total_amount' => 115.00,
            'final_amount' => 115.00,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/purchase/receiving/orders/{$order->id}/inspection");

        $response->assertStatus(200);
        $response->assertJsonPath('data.invoice.invoice_number', 'INV-2026-001');
        $this->assertEquals(100.0, (float) $response->json('data.invoice.amount_before_tax'));
        $this->assertEquals(15.0, (float) $response->json('data.invoice.tax_rate'));
        $this->assertEquals(15.0, (float) $response->json('data.invoice.tax_amount'));
        $this->assertEquals(115.0, (float) $response->json('data.invoice.total_amount'));

        $this->assertEquals(100.0, (float) $response->json('data.financial_summary.amount_before_tax'));
        $this->assertEquals(15.0, (float) $response->json('data.financial_summary.vat_rate'));
        $this->assertEquals(15.0, (float) $response->json('data.financial_summary.vat_amount'));
        $this->assertEquals(115.0, (float) $response->json('data.financial_summary.total_amount'));
    }

    public function test_inspection_endpoint_does_not_fabricate_invoice_when_none_exists(): void
    {
        [$order, $receipt, $orderItem] = $this->createOrderWithReceipt(100.00);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/purchase/receiving/orders/{$order->id}/inspection");

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('invoice', $response->json('data'));
        $this->assertArrayNotHasKey('financial_summary', $response->json('data'));
    }

    public function test_create_invoice_supports_15_percent_vat(): void
    {
        [$order, $receipt] = $this->createOrderWithReceipt(200.00);

        $service = app(GoodsReceiptService::class);
        $invoice = $service->createInvoice($receipt, [
            'invoice_number' => 'INV-15-TEST',
            'invoice_date' => today()->toDateString(),
            'tax_rate' => 15.0,
        ]);

        $this->assertSame(200.0, (float) $invoice->amount_before_tax);
        $this->assertSame(15.0, (float) $invoice->tax_rate);
        $this->assertSame(30.0, (float) $invoice->tax_amount);
        $this->assertSame(230.0, (float) $invoice->total_amount);
    }

    public function test_create_invoice_supports_0_percent_vat(): void
    {
        [$order, $receipt] = $this->createOrderWithReceipt(200.00);

        $service = app(GoodsReceiptService::class);
        $invoice = $service->createInvoice($receipt, [
            'invoice_number' => 'INV-0-TEST',
            'invoice_date' => today()->toDateString(),
            'tax_rate' => 0.0,
        ]);

        $this->assertSame(200.0, (float) $invoice->amount_before_tax);
        $this->assertSame(0.0, (float) $invoice->tax_rate);
        $this->assertSame(0.0, (float) $invoice->tax_amount);
        $this->assertSame(200.0, (float) $invoice->total_amount);
    }

    public function test_create_invoice_rejects_missing_vat_rate_without_defaulting(): void
    {
        [$order, $receipt] = $this->createOrderWithReceipt(200.00);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invoice tax rate is required and cannot be defaulted.');

        $service = app(GoodsReceiptService::class);
        $service->createInvoice($receipt, [
            'invoice_number' => 'INV-FAIL',
            'invoice_date' => today()->toDateString(),
        ]);
    }

    public function test_start_receiving_validation_rejects_missing_or_invalid_tax_rate(): void
    {
        [$order, , $orderItem] = $this->createOrderWithReceipt(100.00);
        $order->update(['status' => OrderStatus::CONFIRMED]);

        // Missing tax_rate
        $payloadMissing = [
            'items' => [
                [
                    'item_id' => $orderItem->id,
                    'quantity_received' => 10,
                    'quality' => 'normal',
                ],
            ],
            'document_type' => 'invoice',
            'invoice_data' => [
                'invoice_number' => 'INV-NO-RATE',
                'invoice_date' => today()->toDateString(),
            ],
        ];

        $res1 = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", $payloadMissing);

        $res1->assertStatus(422);
        $res1->assertJsonValidationErrors(['invoice_data.tax_rate']);

        // Invalid tax_rate (e.g. 5%)
        $payloadInvalid = $payloadMissing;
        $payloadInvalid['invoice_data']['tax_rate'] = 5;

        $res2 = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", $payloadInvalid);

        $res2->assertStatus(422);
        $res2->assertJsonValidationErrors(['invoice_data.tax_rate']);
    }

    public function test_start_receiving_validation_accepts_0_and_15_percent_tax_rate(): void
    {
        [$order, , $orderItem] = $this->createOrderWithReceipt(100.00);
        $order->update(['status' => OrderStatus::CONFIRMED]);

        $payload0 = [
            'items' => [
                [
                    'item_id' => $orderItem->id,
                    'quantity_received' => 10,
                    'quality' => 'normal',
                ],
            ],
            'document_type' => 'invoice',
            'invoice_data' => [
                'invoice_number' => 'INV-ZERO-VAT',
                'invoice_date' => today()->toDateString(),
                'tax_rate' => 0,
            ],
        ];

        $res0 = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/purchase/receiving/orders/{$order->id}/start", $payload0);

        $res0->assertStatus(201);
        $this->assertDatabaseHas('purchase_invoices', [
            'invoice_number' => 'INV-ZERO-VAT',
            'tax_rate' => 0.0,
            'tax_amount' => 0.0,
        ]);
    }
}
