<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Branch\Models\Branch;
use Modules\Purchase\Enums\OrderItemStatus;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Models\Item;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Supplier\Models\Supplier;
use Modules\Supplier\Models\SupplierProduct;
use Tests\TestCase;

/**
 * Item-level consolidation (PRC-2.1 core value loop): by=item grouping with a
 * suggested cheapest supplier + savings, savings snapshot at send, ETA, and the
 * sent-vs-supplier-confirmed status distinction. T11.7–T11.10.
 */
class ProcurementItemConsolidationTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabUser $manager;

    private Branch $branch;

    private Supplier $supplier;

    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = AsabCompany::create(['name' => 'ItemConso Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->manager = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'مدير المشتريات',
            'email' => 'procurement@itemconso.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->manager->id, 'role_key' => 'procurement', 'scope' => 'all']);
        $this->branch = Branch::factory()->create(['asab_company_id' => $this->company->id, 'city' => 'الرياض']);
        $this->supplier = Supplier::create([
            'name' => 'مورد الأصناف',
            'email' => 'supplier@itemconso.test',
            'password' => bcrypt('password'),
            'phone' => '0553421177',
            'is_active' => true,
            'status' => 'online',
        ]);
        $this->item = Item::create(['name' => 'صدر دجاج', 'code' => 'CHB-9', 'unit' => 'كجم', 'is_active' => true]);
    }

    private function as()
    {
        return $this->actingAs($this->manager, 'sanctum');
    }

    private function confirmedOrder(float $qty, ?Branch $branch = null, float $unitPrice = 45, ?string $nextSupply = null): PurchaseOrder
    {
        $order = PurchaseOrder::factory()->create([
            'order_number' => 'PO-'.strtoupper(Str::random(8)),
            'order_type' => OrderType::DIRECT_SUPPLIER,
            'status' => OrderStatus::CONFIRMED,
            'branch_id' => ($branch ?? $this->branch)->id,
            'supplier_id' => $this->supplier->id,
            'total_amount' => $qty * $unitPrice,
            'submitted_at' => now(),
            'confirmed_at' => now(),
        ]);
        PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $order->id,
            'item_id' => $this->item->id,
            'item_name' => $this->item->name,
            'unit_of_measurement' => 'kg',
            'status' => OrderItemStatus::CONFIRMED,
            'quantity_ordered' => $qty,
            'quantity_confirmed' => $qty,
            'unit_price' => $unitPrice,
            'next_supply_date' => $nextSupply,
        ]);

        return $order->fresh(['items']);
    }

    // ---- T11.7 by=item ----

    public function test_by_item_groups_branches_with_suggested_supplier_and_savings(): void
    {
        // Cheaper active supplier offer than the entered line price (45).
        SupplierProduct::create([
            'supplier_id' => $this->supplier->id,
            'item_id' => $this->item->id,
            'name' => $this->item->name,
            'unit_price' => 40,
            'is_available' => true,
            'stock_quantity' => 1000,
        ]);
        $jeddah = Branch::factory()->create(['asab_company_id' => $this->company->id, 'city' => 'جدة']);
        $this->confirmedOrder(200);
        $this->confirmedOrder(120, $jeddah);

        $res = $this->as()->getJson('/api/v1/procurement/purchase-orders/grouped?by=item');
        $res->assertOk();

        $card = collect($res->json('items'))->firstWhere('itemId', $this->item->id);
        $this->assertNotNull($card);
        $this->assertSame(2, $card['requestsCount']);
        $this->assertCount(2, $card['branchLines']);
        $this->assertEquals(320, $card['totalQuantity']);
        $this->assertSame($this->supplier->id, $card['suggestedSupplier']['id']);
        $this->assertEquals(40, $card['suggestedSupplier']['unitPrice']);
        // (45 - 40) * 320 = 1600
        $this->assertEquals(1600, $card['savings']);
        $this->assertNotNull($card['savingsPct']);
    }

    public function test_by_item_without_supplier_price_yields_null_savings(): void
    {
        $this->confirmedOrder(50);

        $res = $this->as()->getJson('/api/v1/procurement/purchase-orders/grouped?by=item');
        $res->assertOk();

        $card = collect($res->json('items'))->firstWhere('itemId', $this->item->id);
        $this->assertNull($card['suggestedSupplier']);
        $this->assertNull($card['savings']);
    }

    // ---- T11.8 savings snapshot + monthly KPI ----

    public function test_send_snapshots_savings_and_overview_reflects_it(): void
    {
        SupplierProduct::create([
            'supplier_id' => $this->supplier->id, 'item_id' => $this->item->id, 'name' => $this->item->name,
            'unit_price' => 40, 'is_available' => true, 'stock_quantity' => 1000,
        ]);
        $this->confirmedOrder(200);
        $this->confirmedOrder(120);

        $send = $this->as()->postJson('/api/v1/procurement/purchase-orders/grouped/send', [
            'supplierId' => $this->supplier->id,
        ]);
        $send->assertCreated();
        $this->assertEquals(1600, $send->json('savings'));

        $overview = $this->as()->getJson('/api/v1/procurement/overview');
        $overview->assertOk();
        $this->assertEquals(1600, $overview->json('monthlySavings.amount'));
        $this->assertGreaterThan(0, $overview->json('monthlySavings.pctOfPurchases'));
    }

    // ---- T11.9 ETA ----

    public function test_send_with_expected_delivery_date_carries_eta(): void
    {
        $this->confirmedOrder(50);

        $send = $this->as()->postJson('/api/v1/procurement/purchase-orders/grouped/send', [
            'supplierId' => $this->supplier->id,
            'expectedDeliveryDate' => '2026-08-01',
        ]);
        $send->assertCreated()->assertJsonPath('eta', '2026-08-01');

        $sent = $this->as()->getJson('/api/v1/procurement/purchase-orders/sent');
        $row = collect($sent->json('data'))->firstWhere('groupId', $send->json('groupId'));
        $this->assertSame('2026-08-01', $row['eta']);
    }

    public function test_eta_falls_back_to_earliest_next_supply_date(): void
    {
        $this->confirmedOrder(30, null, 45, '2026-09-10');
        $this->confirmedOrder(20, null, 45, '2026-08-15');

        $send = $this->as()->postJson('/api/v1/procurement/purchase-orders/grouped/send', [
            'supplierId' => $this->supplier->id,
        ]);
        $send->assertCreated()->assertJsonPath('eta', '2026-08-15');
    }

    // ---- T11.10 sent vs supplier-confirmed ----

    public function test_freshly_sent_group_reads_sent_then_advances_on_supplier_action(): void
    {
        $a = $this->confirmedOrder(200);
        $b = $this->confirmedOrder(120);

        $send = $this->as()->postJson('/api/v1/procurement/purchase-orders/grouped/send', [
            'supplierId' => $this->supplier->id,
        ]);
        $groupId = $send->json('groupId');

        $sent = $this->as()->getJson('/api/v1/procurement/purchase-orders/sent');
        $row = collect($sent->json('data'))->firstWhere('groupId', $groupId);
        $this->assertSame('sent', $row['status'], 'freshly-sent batch awaits the supplier — status sent, not confirmed');
        $this->assertSame('أُرسل للمورد', $row['statusLabel']);

        $b->fresh()->transitionTo(OrderStatus::PREPARING);
        $sent2 = $this->as()->getJson('/api/v1/procurement/purchase-orders/sent');
        $row2 = collect($sent2->json('data'))->firstWhere('groupId', $groupId);
        $this->assertSame('preparing', $row2['status']);
    }
}
