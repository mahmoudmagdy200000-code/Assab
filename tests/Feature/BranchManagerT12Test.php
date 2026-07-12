<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\BranchInventoryList;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\InventoryCatalogItem;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\SupplierRequest;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * T12 Branch Manager: overview (hero/KPIs/tasks/crew), hardened upload
 * (validation + attachments + channel), items enrichment + stock status,
 * items/count guards, persisted supplier requests + approval, employees list.
 */
class BranchManagerT12Test extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabBrand $brand;

    private Branch $branch;

    private AsabUser $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = AsabCompany::create(['name' => 'T12 Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'Brand T12', 'status' => 'active']);
        $this->branch = Branch::create([
            'name' => 'فرع العليا', 'location' => 'Riyadh', 'lat' => 0, 'lng' => 0,
            'phone' => '0112223344', 'address' => 'شارع العليا',
            'asab_company_id' => $this->company->id, 'asab_brand_id' => $this->brand->id,
            'asab_monthly_target' => 1_000_000,
        ]);
        $this->manager = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'مدير الفرع',
            'email' => 'branch@t12.test', 'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $this->manager->id, 'role_key' => 'branch', 'scope' => 'branch',
            'branch_ids' => [$this->branch->id],
        ]);
    }

    private function as()
    {
        return $this->actingAs($this->manager, 'sanctum');
    }

    private function salesOpToday(int $amount): Operation
    {
        return Operation::create([
            'public_id' => 'OPS-'.\Illuminate\Support\Str::upper(\Illuminate\Support\Str::random(6)),
            'company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'module_key' => 'sales',
            'payload' => [], 'amount' => $amount, 'match' => 'exact', 'origin' => 'mobile',
            'status' => Operation::STATUS_PENDING, 'operation_date' => now(),
        ]);
    }

    private function catalogItem(array $attrs = []): InventoryCatalogItem
    {
        return InventoryCatalogItem::create(array_merge([
            'brand_id' => $this->brand->id, 'type' => InventoryCatalogItem::TYPE_SALES_ITEM,
            'name' => 'برجر', 'category' => 'وجبات', 'unit' => 'حبة', 'status' => 'active', 'unit_price' => 1500,
        ], $attrs));
    }

    // ---- T12.1 / T12.2 overview ----

    public function test_overview_returns_hero_kpis_tasks_and_crew(): void
    {
        $this->salesOpToday(200_000);
        Employee::create([
            'company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'emp_number' => 'EMP-0001',
            'name' => 'أحمد', 'role' => 'Chef', 'monthly_salary' => 300000, 'status' => 'active',
        ]);

        $res = $this->as()->getJson('/api/v1/branch/overview');
        $res->assertOk();

        $res->assertJsonPath('hero.targetHalalas', 1_000_000);
        $res->assertJsonPath('hero.actualHalalas', 200_000);
        $res->assertJsonPath('hero.achievementPct', 20);
        $res->assertJsonPath('kpis.todaySales', 200_000);
        $res->assertJsonPath('kpis.netProfit', 200_000);
        $this->assertSame(1, $res->json('kpis.activeEmployees'));

        $sales = collect($res->json('tasksOfDay'))->firstWhere('id', 'upload-morning-sales');
        $this->assertSame('completed', $sales['state']);
        $this->assertSame('مكتمل', $sales['stateLabel']);
        $close = collect($res->json('tasksOfDay'))->firstWhere('id', 'close-evening-shift');
        $this->assertSame('later', $close['state']);

        $this->assertCount(1, $res->json('crew'));
        $this->assertSame('حاضر', $res->json('crew.0.attendanceLabel'));
    }

    public function test_open_shift_flips_close_task_to_pending(): void
    {
        $this->as()->postJson('/api/v1/company/me/branch/shifts/open', [])->assertCreated();

        $res = $this->as()->getJson('/api/v1/branch/overview');
        $close = collect($res->json('tasksOfDay'))->firstWhere('id', 'close-evening-shift');
        $this->assertSame('pending', $close['state']);
    }

    // ---- T12.3 upload ----

    public function test_platform_upload_validates_and_stamps_dashboard_channel(): void
    {
        $res = $this->as()->postJson('/api/v1/branch/upload/sales', [
            'totalSales' => 5000, 'shift' => 'صباحي',
        ]);
        $res->assertCreated()->assertJsonPath('channel', 'dashboard')->assertJsonPath('origin', 'mobile');
        $this->assertSame('dashboard', Operation::findOrFail($res->json('id'))->channel);
    }

    public function test_platform_upload_rejects_unknown_shift_and_report_type(): void
    {
        $this->as()->postJson('/api/v1/branch/upload/sales', ['shift' => 'ليلي'])->assertStatus(422);
        $this->as()->postJson('/api/v1/branch/upload/nope', ['totalSales' => 1])->assertStatus(400);
    }

    public function test_upload_persists_attachments_and_syncs_count(): void
    {
        Storage::fake('public');

        $res = $this->as()->post('/api/v1/branch/upload/sales', [
            'totalSales' => 5000,
            'attachments' => [UploadedFile::fake()->create('report.pdf', 120, 'application/pdf')],
        ]);

        $res->assertCreated();
        $this->assertCount(1, $res->json('attachments'));
        $this->assertSame(1, (int) Operation::findOrFail($res->json('id'))->attachment_count);
    }

    // ---- T12.4 items ----

    public function test_items_return_stock_status_three_states(): void
    {
        $critical = $this->catalogItem(['name' => 'حرج', 'code' => 'C-1', 'min_level' => 10, 'expected_qty' => 5]);
        $low = $this->catalogItem(['name' => 'منخفض', 'code' => 'L-1', 'min_level' => 10, 'expected_qty' => 12]);
        $ok = $this->catalogItem(['name' => 'كافٍ', 'code' => 'K-1', 'min_level' => 10, 'expected_qty' => 30]);
        foreach ([$critical, $low, $ok] as $i) {
            BranchInventoryList::create(['branch_id' => $this->branch->id, 'catalog_item_id' => $i->id]);
        }

        $rows = collect($this->as()->getJson('/api/v1/branch/inventory-items')->json('items'));

        $this->assertSame('critical', $rows->firstWhere('id', $critical->id)['stockStatus']);
        $this->assertSame('low', $rows->firstWhere('id', $low->id)['stockStatus']);
        $this->assertSame('ok', $rows->firstWhere('id', $ok->id)['stockStatus']);
        $this->assertSame('حرج', $rows->firstWhere('id', $critical->id)['stockStatusLabel']);
        $this->assertSame(1500, $rows->firstWhere('id', $ok->id)['priceHalalas']);
        $this->assertSame('K-1', $rows->firstWhere('id', $ok->id)['code']);
    }

    // ---- T12.5 items/count ----

    public function test_items_count_validates_list_captures_expected_and_guards_duplicate(): void
    {
        $item = $this->catalogItem(['expected_qty' => 25]);
        BranchInventoryList::create(['branch_id' => $this->branch->id, 'catalog_item_id' => $item->id]);

        // Foreign id (not on the branch list) is refused.
        $this->as()->postJson('/api/v1/company/me/branch/items/count', [
            'counts' => [['inventoryItemId' => 'not-on-list', 'actualQty' => 3]],
        ])->assertStatus(422);

        $ok = $this->as()->postJson('/api/v1/company/me/branch/items/count', [
            'counts' => [['inventoryItemId' => $item->id, 'actualQty' => 20]],
        ]);
        $ok->assertCreated();
        $op = Operation::findOrFail($ok->json('id'));
        $this->assertEquals(25, $op->payload['counts'][0]['expectedQty']);

        // A second daily count the same day is blocked.
        $this->as()->postJson('/api/v1/company/me/branch/items/count', [
            'counts' => [['inventoryItemId' => $item->id, 'actualQty' => 21]],
        ])->assertStatus(409);
    }

    // ---- T12.6 / T12.7 suppliers ----

    public function test_suppliers_include_commercial_reg_and_pending_requests(): void
    {
        AsabSupplier::create([
            'company_id' => $this->company->id, 'name' => 'مورد الخضار', 'category' => 'خضار',
            'commercial_reg' => 'CR-9988', 'status' => 'active',
        ]);

        $req = $this->as()->postJson('/api/v1/company/me/branch/suppliers/request-new', [
            'name' => 'مورد مقترح', 'category' => 'لحوم', 'reason' => 'أسعار أفضل',
        ]);
        $req->assertCreated()->assertJsonPath('status', 'pending_review');
        $this->assertDatabaseHas('asab_supplier_requests', ['name' => 'مورد مقترح', 'status' => 'pending_review']);

        $rows = collect($this->as()->getJson('/api/v1/branch/suppliers')->json('data'));
        $approved = $rows->firstWhere('name', 'مورد الخضار');
        $this->assertSame('CR-9988', $approved['commercialReg']);
        $this->assertFalse($approved['isRequest']);

        $pending = $rows->firstWhere('name', 'مورد مقترح');
        $this->assertSame('قيد المراجعة', $pending['statusLabel']);
        $this->assertTrue($pending['isRequest']);
    }

    public function test_procurement_can_approve_a_supplier_request(): void
    {
        $procurement = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'مدير المشتريات',
            'email' => 'proc@t12.test', 'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $procurement->id, 'role_key' => 'procurement', 'scope' => 'all']);

        $req = SupplierRequest::create([
            'company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'name' => 'مورد للاعتماد',
            'status' => SupplierRequest::STATUS_PENDING, 'requested_by_id' => $this->manager->id,
        ]);

        $res = $this->actingAs($procurement, 'sanctum')
            ->postJson('/api/v1/company/me/procurement/supplier-requests/'.$req->id.'/approve');
        $res->assertCreated();

        $this->assertSame(SupplierRequest::STATUS_APPROVED, $req->fresh()->status);
        $this->assertDatabaseHas('asab_suppliers', ['name' => 'مورد للاعتماد', 'company_id' => $this->company->id]);
    }

    // ---- T12.8 / T12.11 employees ----

    public function test_employees_list_is_paginated_with_fields_and_search(): void
    {
        Employee::create([
            'company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'emp_number' => 'EMP-0001',
            'name' => 'أحمد الطباخ', 'role' => 'Chef', 'monthly_salary' => 300000, 'national_id' => '1122',
            'hire_date' => '2026-01-05', 'status' => 'active',
        ]);
        Employee::create([
            'company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'emp_number' => 'EMP-0002',
            'name' => 'سارة الكاشير', 'role' => 'Cashier', 'monthly_salary' => 250000, 'status' => 'active',
        ]);

        $list = $this->as()->getJson('/api/v1/branch/employees');
        $list->assertOk()->assertJsonStructure(['data', 'meta' => ['page', 'pageSize', 'total']]);
        $ahmed = collect($list->json('data'))->firstWhere('empNumber', 'EMP-0001');
        $this->assertSame('1122', $ahmed['nationalId']);
        $this->assertSame('2026-01-05', $ahmed['hireDate']);

        $filtered = $this->as()->getJson('/api/v1/branch/employees?search=سارة');
        $this->assertCount(1, $filtered->json('data'));
    }

    public function test_next_emp_number_skips_existing_max_suffix(): void
    {
        Employee::create([
            'company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'emp_number' => 'EMP-0002',
            'name' => 'موجود', 'role' => 'Chef', 'monthly_salary' => 100000, 'status' => 'active',
        ]);

        $res = $this->as()->postJson('/api/v1/company/me/branch/employees', [
            'name' => 'جديد', 'role' => 'Chef', 'salaryHalalas' => 200000,
        ]);
        $res->assertCreated();
        $this->assertSame('EMP-0003', $res->json('empNumber'));
    }
}
