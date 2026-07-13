<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\SupplierItem;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * ASAB supplier portal (/api/v1/asab/supplier/*, T13) with the feature flag ON.
 * The flag is read when the module routes register (RouteServiceProvider boot),
 * so it must be set BEFORE the app bootstraps — hence the createApplication
 * override rather than a config() call in setUp.
 *
 * Run with: ./vendor/bin/pest tests/Feature/AsabSupplierPortalTest.php -d memory_limit=1024M
 */
class AsabSupplierPortalTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabUser $supplierA;

    private AsabUser $supplierB;

    private AsabSupplier $supA;

    private AsabSupplier $supB;

    private Branch $branch;

    /** Force the portal flag ON before the framework loads config/routes. */
    public function createApplication()
    {
        putenv('FEATURE_ASAB_SUPPLIER_PORTAL=true');
        $_ENV['FEATURE_ASAB_SUPPLIER_PORTAL'] = 'true';
        $_SERVER['FEATURE_ASAB_SUPPLIER_PORTAL'] = 'true';

        return parent::createApplication();
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Sup Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->supplierA = $this->makeSupplierUser('a@sup.test');
        $this->supplierB = $this->makeSupplierUser('b@sup.test');
        // Both suppliers live in the SAME company — proves per-supplier isolation, not just per-tenant.
        $this->supA = AsabSupplier::create(['company_id' => $this->company->id, 'name' => 'مورد أ', 'user_id' => $this->supplierA->id, 'status' => 'active']);
        $this->supB = AsabSupplier::create(['company_id' => $this->company->id, 'name' => 'مورد ب', 'user_id' => $this->supplierB->id, 'status' => 'active']);
        $this->branch = Branch::factory()->create(['asab_company_id' => $this->company->id, 'name' => 'فرع الملز']);
    }

    private function makeSupplierUser(string $email, ?string $companyId = null): AsabUser
    {
        $user = AsabUser::create([
            'company_id' => $companyId ?? $this->company->id,
            'name' => 'مورد',
            'email' => $email,
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $user->id, 'role_key' => 'supplier', 'scope' => 'all']);

        return $user;
    }

    private function as(?AsabUser $user = null)
    {
        return $this->actingAs($user ?? $this->supplierA, 'sanctum');
    }

    /** Create a purchases Operation owned (by default) by supplier A. */
    private function op(array $payload = [], array $attrs = []): Operation
    {
        return Operation::create(array_merge([
            'public_id' => 'PUR-'.Str::upper(Str::random(6)),
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'module_key' => 'purchases',
            'payload' => array_merge(['supplierId' => $this->supA->id], $payload),
            'amount' => 1000,
            'match' => 'exact',
            'origin' => 'procurement',
            'status' => 'pending',
            'operation_date' => now(),
        ], $attrs));
    }

    private function item(array $attrs = []): SupplierItem
    {
        return SupplierItem::create(array_merge([
            'supplier_user_id' => $this->supplierA->id,
            'name' => 'صنف',
            'price' => 500,
            'available' => true,
            'status' => 'active',
        ], $attrs));
    }

    private static function raw(string $id): Operation
    {
        return Operation::withoutGlobalScopes()->findOrFail($id);
    }

    // ---- Overview KPIs (T13.2) ----

    public function test_overview_kpis_use_accepted_synonyms_and_exclude_non_sales(): void
    {
        $this->op([], ['status' => 'pending', 'amount' => 1000]);
        $this->op([], ['status' => 'accepted', 'amount' => 2000]);
        // Accepted then delivered this month — must still count as accepted (fulfilled set).
        $this->op([], ['status' => 'delivered', 'amount' => 3000]);
        $this->op([], ['status' => 'rejected', 'amount' => 5000]);
        $this->item(['status' => 'active']);
        $this->item(['status' => 'inactive', 'available' => false]);

        $res = $this->as()->getJson('/api/v1/asab/supplier/overview');
        $res->assertOk()
            ->assertJsonPath('kpis.newOrders', 1)
            // The old code filtered status='approved' (never written) → always 0. Proves the fix,
            // and that a same-month accept→deliver order still counts (accepted + delivered).
            ->assertJsonPath('kpis.acceptedThisMonth', 2)
            ->assertJsonPath('kpis.totalSalesThisMonth', 5000) // accepted + delivered; rejected/pending excluded
            ->assertJsonPath('kpis.activeItems', 1)
            ->assertJsonPath('kpis.totalItems', 2);
        $this->assertArrayHasKey('totalSalesTrendPct', $res->json('kpis'));
    }

    // ---- Orders list + separate lists (T13.1 / T13.3) ----

    public function test_orders_list_is_paginated_and_enriched(): void
    {
        $branchOrder = $this->op(['kind' => 'branch_request', 'item' => 'طماطم', 'qty' => 5, 'unit' => 'كجم'], ['status' => 'pending']);
        $procOrder = $this->op(['items' => [['itemId' => 'x', 'qty' => 2]]], ['status' => 'accepted']);

        $res = $this->as()->getJson('/api/v1/asab/supplier/orders');
        $res->assertOk()->assertJsonStructure(['data', 'meta' => ['page', 'pageSize', 'total', 'totalPages']]);

        $rows = collect($res->json('data'));
        $branchRow = $rows->firstWhere('id', $branchOrder->id);
        $procRow = $rows->firstWhere('id', $procOrder->id);

        $this->assertSame('فرع الملز', $branchRow['from']);          // branch-origin → branch name
        $this->assertSame('مدير المشتريات', $procRow['from']);       // procurement-origin
        $this->assertStringContainsString('طماطم', $branchRow['itemsText']);
        $this->assertNotNull($branchRow['orderDate']);
        $this->assertSame('pending', $branchRow['statusKey']);
        $this->assertSame('في انتظار الرد', $branchRow['statusLabel']);
        $this->assertSame('accepted', $procRow['statusKey']);
        $this->assertSame('مقبول', $procRow['statusLabel']);
    }

    public function test_status_filter_folds_synonyms_into_disjoint_lists(): void
    {
        $accepted = $this->op([], ['status' => 'accepted']);
        $approvedSynonym = $this->op([], ['status' => 'approved']); // procurement-side synonym of accepted
        $rejected = $this->op([], ['status' => 'rejected']);

        $acceptedIds = collect($this->as()->getJson('/api/v1/asab/supplier/orders?status=accepted')->json('data'))->pluck('id');
        $rejectedIds = collect($this->as()->getJson('/api/v1/asab/supplier/orders?status=rejected')->json('data'))->pluck('id');

        $this->assertTrue($acceptedIds->contains($accepted->id));
        $this->assertTrue($acceptedIds->contains($approvedSynonym->id), 'approved must fold into the accepted list');
        $this->assertFalse($acceptedIds->contains($rejected->id));
        $this->assertSame([$rejected->id], $rejectedIds->all());
        $this->assertTrue($acceptedIds->intersect($rejectedIds)->isEmpty(), 'lists must be disjoint');
    }

    // ---- accept / reject / mark-delivered + state guards (T13.4) ----

    public function test_accept_pending_order_persists_supplier_response(): void
    {
        $op = $this->op([], ['status' => 'pending']);

        $this->as()->postJson('/api/v1/asab/supplier/orders/'.$op->id.'/accept', ['deliveryDate' => '2026-08-01', 'note' => 'ok'])
            ->assertOk()->assertJsonPath('statusKey', 'accepted')->assertJsonPath('statusLabel', 'مقبول')
            ->assertJsonPath('deliveryDate', '2026-08-01'); // response-facing supplierResponse.deliveryDate resolution

        $fresh = self::raw($op->id);
        $this->assertSame('accepted', $fresh->status);
        $this->assertTrue($fresh->payload['supplierResponse']['accepted']);
        $this->assertSame('2026-08-01', $fresh->payload['supplierResponse']['deliveryDate']);
    }

    public function test_accept_on_delivered_order_is_409(): void
    {
        $op = $this->op([], ['status' => 'delivered']);

        $this->as()->postJson('/api/v1/asab/supplier/orders/'.$op->id.'/accept')
            ->assertStatus(409)->assertJsonPath('error.code', 'ORDER_NOT_PENDING');
    }

    public function test_reject_requires_reason_and_records_it(): void
    {
        $op = $this->op([], ['status' => 'pending']);

        $this->as()->postJson('/api/v1/asab/supplier/orders/'.$op->id.'/reject')->assertStatus(422);

        $this->as()->postJson('/api/v1/asab/supplier/orders/'.$op->id.'/reject', ['reason' => 'سعر مرتفع'])
            ->assertOk()->assertJsonPath('statusKey', 'rejected');

        $fresh = self::raw($op->id);
        $this->assertSame('rejected', $fresh->status);
        $this->assertSame('سعر مرتفع', $fresh->reject_reason);
    }

    public function test_reject_on_non_pending_order_is_409(): void
    {
        $op = $this->op([], ['status' => 'accepted']);

        $this->as()->postJson('/api/v1/asab/supplier/orders/'.$op->id.'/reject', ['reason' => 'x'])
            ->assertStatus(409)->assertJsonPath('error.code', 'ORDER_NOT_PENDING');
        $this->assertSame('accepted', self::raw($op->id)->status); // guard left the order untouched
    }

    public function test_mark_delivered_only_from_accepted(): void
    {
        $pending = $this->op([], ['status' => 'pending']);
        $this->as()->postJson('/api/v1/asab/supplier/orders/'.$pending->id.'/mark-delivered')
            ->assertStatus(409)->assertJsonPath('error.code', 'ORDER_NOT_ACCEPTED');

        $accepted = $this->op([], ['status' => 'accepted']);
        $this->as()->postJson('/api/v1/asab/supplier/orders/'.$accepted->id.'/mark-delivered')
            ->assertOk()->assertJsonPath('statusKey', 'delivered');
        $this->assertSame('delivered', self::raw($accepted->id)->status);
    }

    // ---- Items CRUD (T13.5) ----

    public function test_store_item_requires_a_price(): void
    {
        $this->as()->postJson('/api/v1/asab/supplier/items', ['name' => 'بندورة'])->assertStatus(422);

        $this->as()->postJson('/api/v1/asab/supplier/items', ['name' => 'بندورة', 'priceHalalas' => 750, 'code' => 'T-1'])
            ->assertCreated()->assertJsonPath('priceHalalas', 750)->assertJsonPath('code', 'T-1');
    }

    public function test_update_item_allows_code_and_availability_toggle(): void
    {
        $item = $this->item(['code' => 'OLD', 'status' => 'active', 'available' => true]);

        $this->as()->patchJson('/api/v1/asab/supplier/items/'.$item->id, ['code' => 'X-9', 'available' => false])
            ->assertOk()->assertJsonPath('code', 'X-9')->assertJsonPath('status', 'inactive')->assertJsonPath('available', false);
    }

    public function test_toggle_and_delete_item(): void
    {
        $item = $this->item(['status' => 'active', 'available' => true]);

        $this->as()->postJson('/api/v1/asab/supplier/items/'.$item->id.'/toggle-active')
            ->assertOk()->assertJsonPath('status', 'inactive')->assertJsonPath('available', false);

        $this->as()->deleteJson('/api/v1/asab/supplier/items/'.$item->id)->assertNoContent();
        $this->assertSoftDeleted('asab_supplier_items', ['id' => $item->id]);
    }

    public function test_exports_return_files(): void
    {
        $this->op([], ['status' => 'accepted']);
        $this->item();

        $this->as()->get('/api/v1/asab/supplier/orders/export?status=accepted&format=csv')->assertOk();
        $this->as()->get('/api/v1/asab/supplier/orders/export?status=accepted&format=xlsx')->assertOk();
        $this->as()->get('/api/v1/asab/supplier/items/export?format=csv')->assertOk();
    }

    // ---- Reports (T13.6) — no permanently-empty placeholder keys ----

    public function test_reports_omit_deferred_placeholder_keys(): void
    {
        $this->op([], ['status' => 'delivered', 'amount' => 3000]);
        $this->op([], ['status' => 'rejected', 'amount' => 9000]);

        $res = $this->as()->getJson('/api/v1/asab/supplier/reports');
        $res->assertOk()
            ->assertJsonPath('totalRevenue', 3000) // delivered counts; rejected excluded
            ->assertJsonPath('orderCount', 1);
        $body = $res->json();
        $this->assertArrayNotHasKey('topItems', $body);
        $this->assertArrayNotHasKey('topBranches', $body);
        $this->assertArrayNotHasKey('monthly', $body);
    }

    // ---- Zero-trust: per-supplier ownership isolation ----

    public function test_supplier_b_cannot_touch_or_see_supplier_a_orders(): void
    {
        $op = $this->op([], ['status' => 'pending']);

        // B acts on A's order → scoped-out → 404 (not 403 leak).
        $this->as($this->supplierB)->postJson('/api/v1/asab/supplier/orders/'.$op->id.'/accept')->assertStatus(404);
        $this->as($this->supplierB)->postJson('/api/v1/asab/supplier/orders/'.$op->id.'/reject', ['reason' => 'x'])->assertStatus(404);
        $this->as($this->supplierB)->postJson('/api/v1/asab/supplier/orders/'.$op->id.'/mark-delivered')->assertStatus(404);

        // B's list must not contain A's order.
        $ids = collect($this->as($this->supplierB)->getJson('/api/v1/asab/supplier/orders')->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($op->id));
    }

    public function test_orders_without_owned_supplier_id_never_leak(): void
    {
        $this->op(['supplierId' => null], ['status' => 'pending']);       // no supplier
        $this->op(['supplierId' => $this->supB->id], ['status' => 'pending']); // other supplier

        $ids = collect($this->as()->getJson('/api/v1/asab/supplier/orders')->json('data'))->pluck('id');
        $this->assertCount(0, $ids);
    }

    public function test_supplier_a_cannot_see_cross_tenant_op_even_with_colliding_supplier_id(): void
    {
        $otherCompany = AsabCompany::create(['name' => 'Other Co', 'plan' => 'Professional', 'status' => 'active']);
        // Company B op that references supplier A's id (a cross-tenant collision attempt).
        $leak = Operation::create([
            'public_id' => 'PUR-LEAK', 'company_id' => $otherCompany->id, 'branch_id' => $this->branch->id,
            'module_key' => 'purchases', 'payload' => ['supplierId' => $this->supA->id], 'amount' => 1000,
            'status' => 'pending', 'operation_date' => now(),
        ]);

        $ids = collect($this->as()->getJson('/api/v1/asab/supplier/orders')->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($leak->id), 'tenant scope must exclude the other company op');
    }

    // ---- Role denial ----

    public function test_non_supplier_roles_are_denied(): void
    {
        $accountant = AsabUser::create(['company_id' => $this->company->id, 'name' => 'محاسب', 'email' => 'acc@sup.test', 'password' => 'secret-password', 'status' => 'active']);
        AsabUserRole::create(['user_id' => $accountant->id, 'role_key' => 'accountant', 'scope' => 'all']);

        $this->as($accountant)->getJson('/api/v1/asab/supplier/overview')->assertStatus(403);
        $this->as($accountant)->getJson('/api/v1/asab/supplier/items')->assertStatus(403);
    }

    public function test_unauthenticated_is_401(): void
    {
        $this->getJson('/api/v1/asab/supplier/overview')->assertStatus(401);
    }
}
