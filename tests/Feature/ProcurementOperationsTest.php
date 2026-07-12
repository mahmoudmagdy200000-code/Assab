<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\ApprovalStep;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Operation;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * Operations-family procurement surface (asab_operations, integer halalas):
 * dashboard-created orders, consolidate/send, partial-reject, overview KPIs.
 * T11.1–T11.6, T11.8, T11.14.
 */
class ProcurementOperationsTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabUser $manager;

    private Branch $branch;

    private AsabSupplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = AsabCompany::create(['name' => 'Ops Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->manager = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'مدير المشتريات',
            'email' => 'procurement@ops.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->manager->id, 'role_key' => 'procurement', 'scope' => 'all']);
        $this->branch = Branch::factory()->create(['asab_company_id' => $this->company->id]);
        $this->supplier = AsabSupplier::create(['company_id' => $this->company->id, 'name' => 'مورد التجربة', 'status' => 'active']);
    }

    private function as()
    {
        return $this->actingAs($this->manager, 'sanctum');
    }

    /** Create a pending purchases Operation directly (arrangement helper). */
    private function pendingOp(array $payload = [], int $amount = 1000): Operation
    {
        return Operation::create([
            'public_id' => 'PUR-'.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(6)),
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'module_key' => 'purchases',
            'payload' => $payload,
            'amount' => $amount,
            'match' => 'exact',
            'origin' => 'procurement',
            'status' => Operation::STATUS_PENDING,
            'operation_date' => now(),
        ]);
    }

    // ---- T11.14 storeOrder ----

    public function test_store_order_persists_brand_origin_and_total(): void
    {
        $res = $this->as()->postJson('/api/v1/company/me/procurement/orders', [
            'supplierId' => $this->supplier->id,
            'brandId' => 'brand-123',
            'branchId' => $this->branch->id,
            'urgency' => 'urgent',
            'items' => [
                ['itemId' => 'a', 'qty' => 3, 'unitPriceHalalas' => 200],
                ['itemId' => 'b', 'qty' => 2, 'unitPriceHalalas' => 500],
            ],
        ]);

        $res->assertCreated()->assertJsonPath('totalHalalas', 1600);

        $op = Operation::findOrFail($res->json('id'));
        $this->assertSame('procurement', $op->origin, 'origin column must be procurement');
        $this->assertSame('brand-123', $op->payload['brandId']);
        $this->assertSame('urgent', $op->payload['urgency']);
    }

    public function test_store_order_rejects_negative_price(): void
    {
        $this->as()->postJson('/api/v1/company/me/procurement/orders', [
            'supplierId' => $this->supplier->id,
            'items' => [['itemId' => 'a', 'qty' => 1, 'unitPriceHalalas' => -5]],
        ])->assertStatus(422);
    }

    // ---- T11.1 urgency ----

    public function test_orders_list_urgency_labels(): void
    {
        $this->pendingOp(['urgency' => 'urgent']);
        $this->pendingOp(['urgency' => 'normal']);

        $rows = collect($this->as()->getJson('/api/v1/procurement/orders')->json('data'));

        $this->assertTrue($rows->contains(fn ($r) => $r['urgency'] === 'urgent' && $r['urgencyLabel'] === 'عاجل'));
        $this->assertTrue($rows->contains(fn ($r) => $r['urgency'] === 'normal' && $r['urgencyLabel'] === 'عادي'));
    }

    // ---- T11.8 overview ----

    public function test_overview_has_urgency_bridge_counts_and_monthly_savings(): void
    {
        $this->pendingOp(['urgency' => 'urgent']);

        $res = $this->as()->getJson('/api/v1/procurement/overview');
        $res->assertOk();

        $res->assertJsonStructure([
            'kpis' => ['newOrders', 'consolidated', 'sentToSuppliers', 'incoming', 'readyToSend', 'sentAwaitingConfirmation'],
            'monthlySavings' => ['amount', 'pctOfPurchases', 'trendPct'],
            'newOrders',
        ]);
        $this->assertSame('عاجل', collect($res->json('newOrders'))->first()['urgencyLabel']);
    }

    // ---- T11.3 consolidate / send ----

    public function test_consolidate_rejects_bogus_id(): void
    {
        $op = $this->pendingOp();

        $res = $this->as()->postJson('/api/v1/procurement/orders/consolidate', [
            'orderIds' => [$op->id, 'bogus-id'],
            'supplierId' => $this->supplier->id,
        ]);

        $res->assertStatus(422);
        $this->assertContains('bogus-id', $res->json('error.details.missing'));
    }

    public function test_consolidate_rejects_non_pending(): void
    {
        $op = $this->pendingOp();
        $op->update(['status' => Operation::STATUS_APPROVED]);

        $this->as()->postJson('/api/v1/procurement/orders/consolidate', [
            'orderIds' => [$op->id],
            'supplierId' => $this->supplier->id,
        ])->assertStatus(422);
    }

    public function test_consolidate_and_send_stamps_batch_id(): void
    {
        $a = $this->pendingOp();
        $b = $this->pendingOp();

        $consolidate = $this->as()->postJson('/api/v1/procurement/orders/consolidate', [
            'orderIds' => [$a->id, $b->id],
            'supplierId' => $this->supplier->id,
        ]);
        $consolidate->assertCreated()->assertJsonPath('orderCount', 2);
        $groupId = $consolidate->json('consolidatedGroupId');

        $this->assertSame(Operation::STATUS_APPROVED, $a->fresh()->status);

        $send = $this->as()->postJson('/api/v1/procurement/orders/'.$groupId.'/send');
        $send->assertOk()->assertJsonPath('sent', 2);
        $this->assertStringStartsWith('PO-BATCH-', $send->json('batchId'));
        $this->assertSame(Operation::STATUS_FINAL, $a->fresh()->status);
        $this->assertSame($send->json('batchId'), $a->fresh()->payload['poBatchId']);
    }

    public function test_send_unknown_group_is_404(): void
    {
        $this->as()->postJson('/api/v1/procurement/orders/'.\Illuminate\Support\Str::uuid().'/send')
            ->assertStatus(404);
    }

    // ---- T11.5 partial-reject ----

    public function test_partial_reject_validates_subset_and_records_step(): void
    {
        $op = $this->pendingOp(['items' => [['itemId' => 'a'], ['itemId' => 'b']]]);

        $ok = $this->as()->postJson('/api/v1/procurement/orders/'.$op->id.'/partial-reject', [
            'reason' => 'سعر أعلى من المتفق عليه',
            'rejectedItemIds' => ['a'],
        ]);
        $ok->assertOk()->assertJsonPath('status', 'partial_reject')->assertJsonPath('statusLabel', 'مرفوض جزئياً');
        $this->assertSame(['a'], $op->fresh()->payload['partialReject']['rejectedItemIds']);
        $this->assertTrue(ApprovalStep::where('operation_id', $op->id)->where('stage_id', 'rejected')->exists());
    }

    public function test_partial_reject_rejects_foreign_item_id(): void
    {
        $op = $this->pendingOp(['items' => [['itemId' => 'a']]]);

        $this->as()->postJson('/api/v1/procurement/orders/'.$op->id.'/partial-reject', [
            'reason' => 'سبب',
            'rejectedItemIds' => ['not-in-order'],
        ])->assertStatus(422);
    }

    // ---- T11.4 pipeline enforcement ----

    public function test_update_order_cannot_set_final_approved(): void
    {
        $op = $this->pendingOp();

        $this->as()->patchJson('/api/v1/company/me/procurement/orders/'.$op->id, [
            'status' => 'final-approved',
        ])->assertStatus(409);
    }

    public function test_destroy_final_approved_order_is_blocked_but_pending_deletes(): void
    {
        $locked = $this->pendingOp();
        $locked->update(['status' => Operation::STATUS_FINAL]);
        $this->as()->deleteJson('/api/v1/company/me/procurement/orders/'.$locked->id)->assertStatus(409);

        $pending = $this->pendingOp();
        $this->as()->deleteJson('/api/v1/company/me/procurement/orders/'.$pending->id)->assertNoContent();
    }

    // ---- T11.6 bounded lists ----

    public function test_company_grouped_and_sent_are_bounded_with_meta(): void
    {
        $this->pendingOp(['supplierId' => $this->supplier->id]);

        $grouped = $this->as()->getJson('/api/v1/company/me/procurement/orders/grouped');
        $grouped->assertOk()->assertJsonPath('meta.limit', 100);

        $sent = $this->as()->getJson('/api/v1/company/me/procurement/orders/sent');
        $sent->assertOk()->assertJsonPath('meta.limit', 100);
    }
}
