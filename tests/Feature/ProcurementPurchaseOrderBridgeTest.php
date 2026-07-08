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
use Modules\Purchase\Exceptions\PurchaseOrderException;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Purchase\Services\ProcurementDecisionService;
use Tests\TestCase;

/**
 * Purchasing-manager bridge: dashboard procurement decisions applied directly
 * to mobile purchase_orders (single state machine), with tenant isolation.
 */
class ProcurementPurchaseOrderBridgeTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabUser $manager;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = AsabCompany::create(['name' => 'Bridge Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->manager = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'مدير المشتريات',
            'email' => 'procurement@asab.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->manager->id, 'role_key' => 'procurement', 'scope' => 'all']);
        $this->branch = Branch::factory()->create(['asab_company_id' => $this->company->id]);
    }

    private function service(): ProcurementDecisionService
    {
        return app(ProcurementDecisionService::class);
    }

    private function order(?Branch $branch = null, OrderStatus $status = OrderStatus::PENDING, int $items = 2): PurchaseOrder
    {
        $order = PurchaseOrder::factory()->create([
            'order_number' => 'PO-'.strtoupper(Str::random(8)),
            'order_type' => OrderType::DIRECT_SUPPLIER,
            'status' => $status,
            'branch_id' => ($branch ?? $this->branch)->id,
            'submitted_at' => now(),
        ]);
        PurchaseOrderItem::factory()->count($items)->create([
            'purchase_order_id' => $order->id,
            'status' => OrderItemStatus::PENDING,
            'quantity_ordered' => 10,
        ]);

        return $order->fresh(['items']);
    }

    // ---- Service: decision state machine ----

    public function test_approve_confirms_all_lines_and_stamps_decision(): void
    {
        $order = $this->order();

        $decided = $this->service()->approve($order, $this->manager->id);

        $this->assertSame(OrderStatus::CONFIRMED, $decided->status);
        $this->assertSame($this->manager->id, $decided->decided_by_asab_user_id);
        $this->assertNotNull($decided->decided_at);
        $decided->items->each(function (PurchaseOrderItem $item) {
            $this->assertSame(OrderItemStatus::CONFIRMED, $item->status);
            $this->assertEquals(10, (float) $item->quantity_confirmed);
        });
    }

    public function test_partial_approval_confirms_and_rejects_lines(): void
    {
        $order = $this->order();
        [$keep, $drop] = $order->items->all();

        $decided = $this->service()->approvePartial($order, [
            $keep->id => 4,
            $drop->id => 0,
        ], $this->manager->id, 'سعر أعلى من المتفق عليه');

        $this->assertSame(OrderStatus::CONFIRMED, $decided->status);
        $this->assertSame(OrderItemStatus::CONFIRMED, $keep->fresh()->status);
        $this->assertEquals(4, (float) $keep->fresh()->quantity_confirmed);
        $this->assertSame(OrderItemStatus::REJECTED, $drop->fresh()->status);
        $this->assertEquals(0, (float) $drop->fresh()->quantity_confirmed);
    }

    public function test_partial_approval_with_all_zero_quantities_rejects_the_order(): void
    {
        $order = $this->order();
        $quantities = $order->items->mapWithKeys(fn ($i) => [$i->id => 0])->all();

        $decided = $this->service()->approvePartial($order, $quantities, $this->manager->id, 'لا حاجة للأصناف');

        $this->assertSame(OrderStatus::REJECTED, $decided->status);
        $this->assertNotSame(OrderStatus::CONFIRMED, $decided->status);
    }

    public function test_partial_approval_with_foreign_item_ids_is_refused(): void
    {
        $order = $this->order();
        $other = $this->order();
        $foreignId = $other->items->first()->id;

        $this->expectException(PurchaseOrderException::class);

        try {
            $this->service()->approvePartial($order, [$foreignId => 5], $this->manager->id);
        } finally {
            // Nothing on either order may have changed.
            $this->assertSame(OrderStatus::PENDING, $order->fresh()->status);
            $this->assertSame(OrderItemStatus::PENDING, $other->items->first()->fresh()->status);
        }
    }

    public function test_reject_requires_reason_and_transitions_variance_orders_too(): void
    {
        $pending = $this->order();
        $variance = $this->order(status: OrderStatus::VARIANCE);

        $decidedPending = $this->service()->reject($pending, $this->manager->id, 'السعر أعلى من المتفق عليه');
        $decidedVariance = $this->service()->reject($variance, $this->manager->id, 'فرق كميات');

        $this->assertSame(OrderStatus::REJECTED, $decidedPending->status);
        $this->assertSame('السعر أعلى من المتفق عليه', $decidedPending->rejection_reason);
        // Regression: VARIANCE→REJECTED used to be a disallowed transition that
        // half-executed (items rejected, order stuck in variance).
        $this->assertSame(OrderStatus::REJECTED, $decidedVariance->status);
        $decidedVariance->items->each(
            fn (PurchaseOrderItem $i) => $this->assertSame(OrderItemStatus::REJECTED, $i->status),
        );
    }

    public function test_already_decided_orders_cannot_be_decided_again(): void
    {
        $order = $this->order(status: OrderStatus::CONFIRMED);

        $this->expectException(PurchaseOrderException::class);
        $this->service()->approve($order, $this->manager->id);
    }

    // ---- HTTP: tenancy + endpoints ----

    private function asManager()
    {
        return $this->actingAs($this->manager, 'sanctum');
    }

    public function test_index_lists_only_orders_from_own_company_branches(): void
    {
        $mine = $this->order();

        $otherCompany = AsabCompany::create(['name' => 'Other Co', 'plan' => 'Basic', 'status' => 'active']);
        $foreignBranch = Branch::factory()->create(['asab_company_id' => $otherCompany->id]);
        $foreign = $this->order($foreignBranch);

        $res = $this->asManager()->getJson('/api/v1/procurement/purchase-orders');

        $res->assertOk();
        $ids = collect($res->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($mine->id));
        $this->assertFalse($ids->contains($foreign->id));
    }

    public function test_cannot_view_or_decide_another_companys_order(): void
    {
        $otherCompany = AsabCompany::create(['name' => 'Other Co', 'plan' => 'Basic', 'status' => 'active']);
        $foreignBranch = Branch::factory()->create(['asab_company_id' => $otherCompany->id]);
        $foreign = $this->order($foreignBranch);

        $this->asManager()->getJson('/api/v1/procurement/purchase-orders/'.$foreign->id)->assertNotFound();
        $this->asManager()
            ->postJson('/api/v1/procurement/purchase-orders/'.$foreign->id.'/reject', ['reason' => 'x'])
            ->assertNotFound();
        $this->assertSame(OrderStatus::PENDING, $foreign->fresh()->status);
    }

    public function test_approve_endpoint_confirms_and_surfaces_in_approved_by_me(): void
    {
        $order = $this->order();

        $this->asManager()
            ->postJson('/api/v1/procurement/purchase-orders/'.$order->id.'/approve')
            ->assertOk()
            ->assertJsonPath('status', OrderStatus::CONFIRMED->value);

        $list = $this->asManager()->getJson('/api/v1/procurement/purchase-orders/approved-by-me');
        $list->assertOk();
        $this->assertTrue(collect($list->json('data'))->pluck('id')->contains($order->id));
    }

    public function test_bulk_approve_confirms_own_orders_and_reports_foreign_ids_as_failed(): void
    {
        $a = $this->order();
        $b = $this->order();

        $otherCompany = AsabCompany::create(['name' => 'Other Co', 'plan' => 'Basic', 'status' => 'active']);
        $foreign = $this->order(Branch::factory()->create(['asab_company_id' => $otherCompany->id]));

        $res = $this->asManager()->postJson('/api/v1/procurement/purchase-orders/bulk-approve', [
            'orderIds' => [$a->id, $b->id, $foreign->id],
        ]);

        $res->assertOk()->assertJsonPath('count', 2);
        $this->assertSame(OrderStatus::CONFIRMED, $a->fresh()->status);
        $this->assertSame(OrderStatus::CONFIRMED, $b->fresh()->status);
        $this->assertSame(OrderStatus::PENDING, $foreign->fresh()->status);
        $this->assertSame($foreign->id, $res->json('failed.0.id'));
    }

    public function test_reject_endpoint_requires_reason(): void
    {
        $order = $this->order();

        $this->asManager()
            ->postJson('/api/v1/procurement/purchase-orders/'.$order->id.'/reject', [])
            ->assertStatus(422);
        $this->assertSame(OrderStatus::PENDING, $order->fresh()->status);
    }
}
