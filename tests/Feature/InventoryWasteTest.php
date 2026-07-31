<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabNotification;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\EmployeeMovement;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\OperationSequence;
use Modules\Admin\Support\WasteEnums;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * T07 — Inventory & Waste. Focus: financial correctness (خصم هدر ledger posting
 * on waste approval, allocation validation parity with sales shortfall, the
 * daily-variance double-post fix), monthly compare + server-side anomaly, the
 * daily equation, and the confirmation/push loop.
 */
class InventoryWasteTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabBrand $brand;

    private Branch $branchA;

    private Branch $branchB;

    private AsabUser $accountant;

    private Employee $emp1;

    private Employee $emp2;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Inv Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'براند', 'sub_status' => 'active', 'status' => 'active']);
        $this->branchA = Branch::factory()->create(['name' => 'فرع أ', 'asab_brand_id' => $this->brand->id, 'asab_company_id' => $this->company->id]);
        $this->branchB = Branch::factory()->create(['name' => 'فرع ب', 'asab_brand_id' => $this->brand->id, 'asab_company_id' => $this->company->id]);

        $this->accountant = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'محاسب', 'email' => 'acc@inv.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->accountant->id, 'role_key' => 'accountant', 'scope' => 'all']);

        $this->emp1 = Employee::create(['company_id' => $this->company->id, 'branch_id' => $this->branchA->id, 'emp_number' => '1001', 'name' => 'محمد', 'role' => 'كاشير', 'status' => 'active']);
        $this->emp2 = Employee::create(['company_id' => $this->company->id, 'branch_id' => $this->branchA->id, 'emp_number' => '1002', 'name' => 'خالد', 'role' => 'مشرف', 'status' => 'active']);
    }

    private function acc()
    {
        return $this->actingAs($this->accountant, 'sanctum');
    }

    private function wasteOp(array $products, array $attrs = []): Operation
    {
        return Operation::create(array_merge([
            'public_id' => OperationSequence::next('WD'),
            'company_id' => $this->company->id, 'branch_id' => $this->branchA->id,
            'module_key' => 'waste', 'amount' => array_sum(array_column($products, 'value')),
            'match' => 'exact', 'origin' => 'mobile', 'status' => Operation::STATUS_PENDING,
            'operation_date' => now(), 'payload' => ['products' => $products],
        ], $attrs));
    }

    private function inventoryOp(array $items, array $attrs = []): Operation
    {
        return Operation::create(array_merge([
            'public_id' => OperationSequence::next('INV'),
            'company_id' => $this->company->id, 'branch_id' => $this->branchA->id,
            'module_key' => 'inventory', 'amount' => 0, 'match' => 'exact', 'origin' => 'mobile',
            'status' => Operation::STATUS_PENDING, 'operation_date' => now(), 'payload' => ['items' => $items],
        ], $attrs));
    }

    // ── Waste: خصم هدر ledger posting (T07.7) ────────────────────────────────

    public function test_approving_waste_charges_employee_products_to_the_ledger(): void
    {
        $op = $this->wasteOp([
            ['name' => 'دجاج', 'classification' => 'هدر', 'responsibility' => 'موظف', 'value' => 30000,
                'empAllocs' => [['employeeId' => $this->emp1->id, 'amountHalalas' => 20000], ['employeeId' => $this->emp2->id, 'amountHalalas' => 10000]]],
            ['name' => 'خبز', 'classification' => 'تالف', 'responsibility' => 'مطعم', 'value' => 5000],
        ]);

        $this->acc()->postJson("/api/v1/company/me/waste/{$op->id}/approve")
            ->assertOk()->assertJsonPath('status', 'approved');

        $movements = EmployeeMovement::where('category', WasteEnums::CATEGORY)->get();
        $this->assertCount(2, $movements);                       // only the موظف product
        $this->assertSame(30000, (int) $movements->sum('amount'));
        foreach ($movements as $m) {
            $this->assertSame('debit', $m->movement_type);
            $this->assertSame($op->id, $m->ref_operation_id);
        }
    }

    public function test_reapproving_waste_does_not_double_charge(): void
    {
        $op = $this->wasteOp([
            ['name' => 'دجاج', 'responsibility' => 'موظف', 'value' => 30000,
                'empAllocs' => [['employeeId' => $this->emp1->id, 'amountHalalas' => 30000]]],
        ]);

        $this->acc()->postJson("/api/v1/company/me/waste/{$op->id}/approve")->assertOk();
        // Second approve — op is no longer pending.
        $this->acc()->postJson("/api/v1/company/me/waste/{$op->id}/approve")->assertStatus(409);

        $this->assertSame(1, EmployeeMovement::where('category', WasteEnums::CATEGORY)->count());
    }

    public function test_rejecting_waste_posts_no_charges(): void
    {
        $op = $this->wasteOp([
            ['name' => 'دجاج', 'responsibility' => 'موظف', 'value' => 30000,
                'empAllocs' => [['employeeId' => $this->emp1->id, 'amountHalalas' => 30000]]],
        ]);

        $this->acc()->postJson("/api/v1/company/me/waste/{$op->id}/reject", ['reason' => 'incomplete_data'])
            ->assertOk()->assertJsonPath('status', 'rejected');

        $this->assertSame(0, EmployeeMovement::count());
    }

    // ── Waste allocation validation (T07.6) ──────────────────────────────────

    public function test_allocation_must_sum_to_the_product_value(): void
    {
        $op = $this->wasteOp([['name' => 'دجاج', 'responsibility' => 'موظف', 'value' => 30000]]);

        $this->acc()->putJson("/api/v1/company/me/waste/{$op->id}/products/0/allocations", [
            'empAllocs' => [['employeeId' => $this->emp1->id, 'amountHalalas' => 20000]],
        ])->assertStatus(422)->assertJsonPath('error.messageAr', 'يجب أن يساوي مجموع التخصيصات قيمة الفارق');
    }

    public function test_allocation_rejects_unknown_employee(): void
    {
        $op = $this->wasteOp([['name' => 'دجاج', 'responsibility' => 'موظف', 'value' => 30000]]);

        $this->acc()->putJson("/api/v1/company/me/waste/{$op->id}/products/0/allocations", [
            'empAllocs' => [['employeeId' => 'ghost', 'amountHalalas' => 30000]],
        ])->assertStatus(422);
    }

    public function test_valid_allocation_is_stored_normalised(): void
    {
        $op = $this->wasteOp([['name' => 'دجاج', 'responsibility' => 'موظف', 'value' => 30000]]);

        $this->acc()->putJson("/api/v1/company/me/waste/{$op->id}/products/0/allocations", [
            'empAllocs' => [['empNumber' => '1001', 'amountHalalas' => 30000]],
        ])->assertOk();

        $stored = $op->fresh()->payload['products'][0]['empAllocs'];
        $this->assertSame($this->emp1->id, $stored[0]['employeeId']);
        $this->assertSame(30000, $stored[0]['amountHalalas']);
    }

    // ── Waste classify + list (T07.8) ────────────────────────────────────────

    public function test_classify_persists_and_validates_enums(): void
    {
        $op = $this->wasteOp([['name' => 'دجاج', 'value' => 30000]]);

        $this->acc()->patchJson("/api/v1/company/me/waste/{$op->id}/products/0", ['classification' => 'تالف', 'responsibility' => 'مطعم'])
            ->assertOk();
        $this->acc()->patchJson("/api/v1/company/me/waste/{$op->id}/products/0", ['classification' => 'مجهول'])
            ->assertStatus(422);
        $this->acc()->patchJson("/api/v1/company/me/waste/{$op->id}/products/9", ['classification' => 'هدر'])
            ->assertStatus(404);
    }

    public function test_waste_list_carries_enriched_rows_and_kpis(): void
    {
        $this->wasteOp([
            ['name' => 'دجاج', 'responsibility' => 'موظف', 'value' => 30000,
                'empAllocs' => [['employeeId' => $this->emp1->id, 'amountHalalas' => 30000]]],
        ]);

        $body = $this->acc()->getJson('/api/v1/company/me/waste')->assertOk()->json();
        $row = $body['data'][0];

        $this->assertSame('براند', $row['brandName']);
        $this->assertSame(1, $row['productsCount']);
        $this->assertSame(30000, $row['employeeChargedHalalas']);
        $this->assertArrayHasKey('date', $row);
        $this->assertSame(1, $body['meta']['summary']['pendingReview']);
        $this->assertArrayHasKey('chargedToEmployeesHalalas', $body['meta']['summary']);
    }

    public function test_waste_list_search_matches_public_id(): void
    {
        $op = $this->wasteOp([['name' => 'دجاج', 'value' => 30000]]);

        $ids = array_column($this->acc()->getJson('/api/v1/company/me/waste?search='.$op->public_id)->assertOk()->json('data'), 'publicId');
        $this->assertContains($op->public_id, $ids);
    }

    // ── Inventory monthly compare + anomaly (T07.2/7.3) ──────────────────────

    public function test_monthly_compare_computes_change_and_flags_anomaly_server_side(): void
    {
        // Previous month: 100; current month: 40 → −60% swing (anomaly), even
        // though the payload never sets isAnomaly.
        $this->inventoryOp([['itemId' => 'sku-1', 'name' => 'أرز', 'actualQty' => 100]], ['operation_date' => now()->subMonthNoOverflow()]);
        $this->inventoryOp([['itemId' => 'sku-1', 'name' => 'أرز', 'actualQty' => 40]]);

        $branch = collect($this->acc()->getJson('/api/v1/company/me/inventory/branches?type=monthly')->assertOk()->json('branches'))
            ->firstWhere('branchId', $this->branchA->id);
        $item = $branch['items'][0];

        $this->assertSame(100.0, (float) $item['prevQty']);
        $this->assertSame(40.0, (float) $item['currQty']);
        $this->assertSame(-60.0, (float) $item['changePct']);
        $this->assertTrue($item['isAnomaly']);
        $this->assertSame('red', $item['chip']);
    }

    public function test_kpi_block_counts_todays_waste(): void
    {
        $this->inventoryOp([['itemId' => 'sku-1', 'actualQty' => 10]]);
        $this->wasteOp([['name' => 'دجاج', 'value' => 12000]]);

        $summary = $this->acc()->getJson('/api/v1/company/me/inventory/branches')->assertOk()->json('summary');
        $this->assertSame(12000, $summary['totalWasteTodayHalalas']);
        $this->assertArrayHasKey('anomalyAlerts', $summary);
    }

    // ── Daily equation (T07.4) ───────────────────────────────────────────────

    public function test_daily_reconciliation_computes_the_equation_and_stock_status(): void
    {
        // فتح 100 + مشتريات 50 − استهلاك 30 − هدر 5 ± تحويلات 0 = إغلاق متوقّع 115.
        // Counted 110 → mismatch. minLevel 120 → «حرج» (below half? no: 110 < 120 → منخفض).
        $this->inventoryOp([[
            'itemId' => 'sku-1', 'name' => 'أرز', 'unit' => 'كجم',
            'opening' => 100, 'received' => 50, 'consumed' => 30, 'waste' => 5, 'transfers' => 0,
            'actualQty' => 110, 'minLevel' => 120, 'unitPriceHalalas' => 1000,
        ]]);

        $item = $this->acc()->getJson("/api/v1/accountant/inventory/branches/{$this->branchA->id}/daily-reconciliation?date=".now()->toDateString())
            ->assertOk()->json('items.0');

        $this->assertSame(115.0, (float) $item['expectedClosing']);
        $this->assertSame(110.0, (float) $item['actualClosing']);
        $this->assertFalse($item['equationMatch']);
        $this->assertSame('low', $item['stockStatus']['key']);
    }

    // ── Daily variance allocation double-post fix (T07.5) ─────────────────────

    public function test_reallocating_daily_variance_does_not_double_charge(): void
    {
        $this->inventoryOp([['itemId' => 'sku-1', 'name' => 'أرز', 'expectedQty' => 10, 'actualQty' => 8, 'unitPriceHalalas' => 1000]]);
        $date = now()->toDateString();
        $body = ['date' => $date, 'items' => [['itemId' => 'sku-1', 'allocations' => [['employeeId' => $this->emp1->id, 'qty' => 2]]]]];

        $this->acc()->postJson("/api/v1/accountant/inventory/branches/{$this->branchA->id}/daily-variance-allocation", $body)->assertOk();
        $this->acc()->postJson("/api/v1/accountant/inventory/branches/{$this->branchA->id}/daily-variance-allocation", $body)->assertOk();

        // Two identical saves → exactly one debit, not two.
        $this->assertSame(1, EmployeeMovement::where('category', 'inventory_variance')->count());
    }

    public function test_daily_variance_allocation_without_op_is_404(): void
    {
        $this->acc()->postJson("/api/v1/accountant/inventory/branches/{$this->branchA->id}/daily-variance-allocation", [
            'date' => now()->addDay()->toDateString(),
            'items' => [['itemId' => 'sku-1', 'allocations' => [['employeeId' => $this->emp1->id, 'qty' => 1]]]],
        ])->assertStatus(404);
    }

    // ── Confirmation loop (T07.9) + push (T07.1) ─────────────────────────────

    public function test_mark_confirmed_shows_in_the_index(): void
    {
        $this->inventoryOp([['itemId' => 'sku-1', 'actualQty' => 10]]);

        $this->acc()->postJson("/api/v1/company/me/inventory/branches/{$this->branchA->id}/mark-confirmed", ['confirmed' => true])->assertOk();

        $branch = collect($this->acc()->getJson('/api/v1/company/me/inventory/branches')->json('branches'))
            ->firstWhere('branchId', $this->branchA->id);
        $this->assertTrue($branch['branchConfirmed']);
    }

    public function test_mark_confirmed_without_op_is_404(): void
    {
        $this->acc()->postJson("/api/v1/company/me/inventory/branches/{$this->branchB->id}/mark-confirmed")->assertStatus(404);
    }

    public function test_saving_a_daily_list_notifies_the_branch_manager(): void
    {
        $brm = AsabUser::create(['company_id' => $this->company->id, 'name' => 'مدير فرع', 'email' => 'brm@inv.test', 'password' => 'secret-password', 'status' => 'active']);
        AsabUserRole::create(['user_id' => $brm->id, 'role_key' => 'branch', 'scope' => 'branch', 'branch_ids' => [$this->branchA->id]]);

        $this->acc()->putJson("/api/v1/company/me/branches/{$this->branchA->id}/inventory-list", ['items' => ['cat-1', 'cat-2']])
            ->assertOk()->assertJsonPath('savedCount', 2);

        $this->assertSame(1, AsabNotification::where('user_id', $brm->id)->where('type', 'inventory.daily_list_updated')->count());
    }

    /**
     * A repeated id in one payload used to hit the (branch_id,
     * catalog_item_id) unique index and 500 the whole save.
     */
    public function test_saving_a_daily_list_dedupes_repeated_ids(): void
    {
        $this->acc()->putJson("/api/v1/company/me/branches/{$this->branchA->id}/inventory-list", ['items' => ['cat-1', 'cat-1', 'cat-2']])
            ->assertOk()->assertJsonPath('savedCount', 2);

        $this->assertSame(
            ['cat-1', 'cat-2'],
            \Modules\Admin\Models\BranchInventoryList::where('branch_id', $this->branchA->id)
                ->orderBy('catalog_item_id')->pluck('catalog_item_id')->all(),
        );
    }

    /** Bulk catalog create against an unlinked branch must 422, not poison rows. */
    public function test_store_catalog_on_an_unlinked_branch_is_422_branch_unlinked(): void
    {
        $unlinked = Branch::factory()->create([
            'asab_company_id' => $this->company->id, 'asab_brand_id' => null, 'asab_restaurant_id' => null,
        ]);

        $res = $this->acc()->putJson('/api/v1/company/me/inventory/catalog', [
            'branchId' => $unlinked->id,
            'items' => [['name' => 'دجاج مجمد', 'category' => 'لحوم', 'unit' => 'كجم']],
        ])->assertStatus(422);

        $this->assertSame('BRANCH_UNLINKED', $res->json('error.code'));
        $this->assertNotNull($res->json('error.messageAr'));
        $this->assertSame(0, \Modules\Admin\Models\InventoryCatalogItem::count());
    }

    // ── Catalog search (T07.10) ──────────────────────────────────────────────

    public function test_catalog_search_filters_by_name(): void
    {
        \Modules\Admin\Models\InventoryCatalogItem::create(['brand_id' => $this->brand->id, 'name' => 'دجاج مجمد', 'category' => 'لحوم', 'unit' => 'كجم', 'status' => 'active', 'type' => 'sales_item']);
        \Modules\Admin\Models\InventoryCatalogItem::create(['brand_id' => $this->brand->id, 'name' => 'أرز بسمتي', 'category' => 'حبوب', 'unit' => 'كجم', 'status' => 'active', 'type' => 'sales_item']);

        $items = $this->acc()->getJson('/api/v1/company/me/inventory/items?search=دجاج')->assertOk()->json('items');
        $this->assertCount(1, $items);
        $this->assertSame('دجاج مجمد', $items[0]['name']);
    }

    // ── Exports (T07.11) ─────────────────────────────────────────────────────

    public function test_accountant_surface_exports_stream(): void
    {
        $this->wasteOp([['name' => 'دجاج', 'value' => 30000]]);
        $this->inventoryOp([['itemId' => 'sku-1', 'actualQty' => 10]]);

        $this->acc()->get('/api/v1/accountant/waste/export?format=csv')->assertOk();
        $this->acc()->get('/api/v1/accountant/inventory/export?format=csv')->assertOk();
    }

    // ── Zero-trust ───────────────────────────────────────────────────────────

    public function test_a_branch_role_cannot_reach_the_waste_or_inventory_surfaces(): void
    {
        $branchUser = AsabUser::create(['company_id' => $this->company->id, 'name' => 'م', 'email' => 'b@inv.test', 'password' => 'secret-password', 'status' => 'active']);
        AsabUserRole::create(['user_id' => $branchUser->id, 'role_key' => 'branch', 'scope' => 'all']);
        $op = $this->wasteOp([['name' => 'دجاج', 'value' => 30000]]);

        $as = $this->actingAs($branchUser, 'sanctum');
        $as->getJson('/api/v1/accountant/waste')->assertStatus(403);
        $as->postJson("/api/v1/accountant/waste/{$op->id}/approve")->assertStatus(403);
        $as->getJson('/api/v1/accountant/inventory')->assertStatus(403);
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson('/api/v1/accountant/waste')->assertStatus(401);
    }
}
