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
use Modules\Purchase\Models\PurchaseOrderGroup;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Supplier\Models\Supplier;
use Modules\Supplier\Models\SupplierProduct;
use Tests\TestCase;

/**
 * Consolidation of approved mobile purchase orders: supplier/city grouping,
 * capacity checks against supplier-declared stock, send + tracking.
 */
class ProcurementConsolidationTest extends TestCase
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
        $this->company = AsabCompany::create(['name' => 'Consolidation Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->manager = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'مدير المشتريات',
            'email' => 'procurement@consolidation.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->manager->id, 'role_key' => 'procurement', 'scope' => 'all']);
        $this->branch = Branch::factory()->create(['asab_company_id' => $this->company->id, 'city' => 'الرياض']);
        $this->supplier = Supplier::create([
            'name' => 'شركة الدواجن الوطنية',
            'email' => 'supplier@consolidation.test',
            'password' => bcrypt('password'),
            'phone' => '0553421100',
            'is_active' => true,
            'status' => 'online',
        ]);
        $this->item = Item::create(['name' => 'صدر دجاج', 'code' => 'CHB-1', 'unit' => 'كجم', 'is_active' => true]);
    }

    private function asManager()
    {
        return $this->actingAs($this->manager, 'sanctum');
    }

    private function confirmedOrder(float $qty, ?Branch $branch = null, ?Supplier $supplier = null): PurchaseOrder
    {
        $order = PurchaseOrder::factory()->create([
            'order_number' => 'PO-'.strtoupper(Str::random(8)),
            'order_type' => OrderType::DIRECT_SUPPLIER,
            'status' => OrderStatus::CONFIRMED,
            'branch_id' => ($branch ?? $this->branch)->id,
            'supplier_id' => ($supplier ?? $this->supplier)->id,
            'total_amount' => $qty * 45,
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
            'unit_price' => 45,
        ]);

        return $order->fresh(['items']);
    }

    public function test_grouped_preview_aggregates_quantities_and_flags_exceeded_capacity(): void
    {
        SupplierProduct::create([
            'supplier_id' => $this->supplier->id,
            'item_id' => $this->item->id,
            'name' => $this->item->name,
            'unit_price' => 45,
            'stock_quantity' => 300,
        ]);
        $this->confirmedOrder(200);
        $this->confirmedOrder(120);
        // Pending order must NOT count.
        PurchaseOrder::factory()->create([
            'order_type' => OrderType::DIRECT_SUPPLIER,
            'status' => OrderStatus::PENDING,
            'branch_id' => $this->branch->id,
            'supplier_id' => $this->supplier->id,
        ]);

        $res = $this->asManager()->getJson('/api/v1/procurement/purchase-orders/grouped');

        $res->assertOk();
        $supplierRow = collect($res->json('suppliers'))->firstWhere('supplierId', $this->supplier->id);
        $this->assertSame(2, $supplierRow['ordersCount']);
        $itemRow = collect($supplierRow['items'])->firstWhere('itemId', $this->item->id);
        $this->assertEquals(320, $itemRow['totalQuantity']);
        $this->assertEquals(300, $itemRow['capacity']);
        $this->assertSame(107, $itemRow['capacityPct']);
        $this->assertTrue($itemRow['exceeded']);
        $this->assertEquals(20, $itemRow['excessQuantity']);
        $this->assertTrue($supplierRow['capacityExceeded']);
    }

    public function test_grouped_preview_by_city_and_excludes_foreign_company(): void
    {
        $this->confirmedOrder(50);
        $jeddah = Branch::factory()->create(['asab_company_id' => $this->company->id, 'city' => 'جدة']);
        $this->confirmedOrder(30, $jeddah);

        $otherCompany = AsabCompany::create(['name' => 'Other', 'plan' => 'Basic', 'status' => 'active']);
        $foreignBranch = Branch::factory()->create(['asab_company_id' => $otherCompany->id, 'city' => 'الدمام']);
        $this->confirmedOrder(99, $foreignBranch);

        $res = $this->asManager()->getJson('/api/v1/procurement/purchase-orders/grouped?by=city');

        $res->assertOk();
        $cities = collect($res->json('cities'))->pluck('city');
        $this->assertTrue($cities->contains('الرياض'));
        $this->assertTrue($cities->contains('جدة'));
        $this->assertFalse($cities->contains('الدمام'));
    }

    public function test_send_creates_group_removes_from_preview_and_appears_in_sent(): void
    {
        $a = $this->confirmedOrder(200);
        $b = $this->confirmedOrder(120);

        $send = $this->asManager()->postJson('/api/v1/procurement/purchase-orders/grouped/send', [
            'supplierId' => $this->supplier->id,
        ]);

        $send->assertCreated()->assertJsonPath('ordersCount', 2);
        $groupId = $send->json('groupId');
        $this->assertSame($groupId, $a->fresh()->group_id);
        $this->assertSame($groupId, $b->fresh()->group_id);

        // Gone from the live preview.
        $preview = $this->asManager()->getJson('/api/v1/procurement/purchase-orders/grouped');
        $this->assertNull(collect($preview->json('suppliers'))->firstWhere('supplierId', $this->supplier->id));

        // Present in "sent" with a derived tracking status.
        $b->fresh()->transitionTo(OrderStatus::PREPARING);
        $sent = $this->asManager()->getJson('/api/v1/procurement/purchase-orders/sent');
        $row = collect($sent->json('data'))->firstWhere('groupId', $groupId);
        $this->assertSame('preparing', $row['status']);
        $this->assertSame(2, $row['ordersCount']);

        // Details endpoint aggregates the group's items.
        $details = $this->asManager()->getJson('/api/v1/procurement/purchase-orders/groups/'.$groupId);
        $details->assertOk();
        $this->assertEquals(320, collect($details->json('items'))->firstWhere('itemId', $this->item->id)['totalQuantity']);
    }

    public function test_send_returns_a_ready_whatsapp_order_link(): void
    {
        $this->confirmedOrder(200);
        $this->confirmedOrder(120);

        $send = $this->asManager()->postJson('/api/v1/procurement/purchase-orders/grouped/send', [
            'supplierId' => $this->supplier->id,
            'expectedDeliveryDate' => '2026-07-30',
        ]);

        $send->assertCreated()
            ->assertJsonPath('whatsapp.channel', 'whatsapp')
            ->assertJsonPath('whatsapp.deliverable', true);

        $wa = $send->json('whatsapp');
        // Supplier phone 0553421100 → Saudi E.164.
        $this->assertStringContainsString('wa.me/966553421100', $wa['url']);
        // FR-PUR-1 — the order number shown to the supplier is the batch number.
        $this->assertSame($send->json('groupNumber'), $wa['reference']);
        $this->assertStringContainsString($send->json('groupNumber'), $wa['message']);
        // The aggregated item line (320 kg of chicken) rides in the message + ETA.
        $this->assertStringContainsString('صدر دجاج', $wa['message']);
        $this->assertStringContainsString('320', $wa['message']);
        $this->assertStringContainsString('2026-07-30', $wa['message']);
    }

    public function test_send_to_a_supplier_without_a_phone_yields_a_non_deliverable_dispatch(): void
    {
        $phoneless = Supplier::create([
            'name' => 'مورد بدون هاتف',
            'email' => 'nophone@consolidation.test',
            'password' => bcrypt('password'),
            'phone' => null,
            'is_active' => true,
            'status' => 'online',
        ]);
        $this->confirmedOrder(50, null, $phoneless);

        $send = $this->asManager()->postJson('/api/v1/procurement/purchase-orders/grouped/send', [
            'supplierId' => $phoneless->id,
        ]);

        $send->assertCreated()
            ->assertJsonPath('whatsapp.deliverable', false)
            ->assertJsonPath('whatsapp.url', null);
    }

    public function test_send_refuses_orders_outside_tenant_scope(): void
    {
        $otherCompany = AsabCompany::create(['name' => 'Other', 'plan' => 'Basic', 'status' => 'active']);
        $foreignBranch = Branch::factory()->create(['asab_company_id' => $otherCompany->id]);
        $foreign = $this->confirmedOrder(10, $foreignBranch);

        $res = $this->asManager()->postJson('/api/v1/procurement/purchase-orders/grouped/send', [
            'supplierId' => $this->supplier->id,
            'orderIds' => [$foreign->id],
        ]);

        $res->assertStatus(409);
        $this->assertNull($foreign->fresh()->group_id);
        $this->assertSame(0, PurchaseOrderGroup::count());
    }
}
