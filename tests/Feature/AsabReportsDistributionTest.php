<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Admin\Jobs\SendOwnerReportJob;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\ReportDefinition;
use Modules\Admin\Models\ReportDistribution;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * T15 reports & distribution — the tasks shipped in this pass (T15.1, T15.5–T15.8,
 * T15.18). The ERP-upload P&L parse (T15.2) + manager table (T15.4) are deferred
 * pending the external ERP Excel schema, so they are not covered here.
 *
 * Run with: ./vendor/bin/pest tests/Feature/AsabReportsDistributionTest.php -d memory_limit=1024M
 */
class AsabReportsDistributionTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabBrand $brand;

    private AsabRestaurant $restA;

    private AsabRestaurant $restB;

    private Branch $branchA;

    private Branch $branchB;

    private AsabUser $admin;

    private AsabUser $accountant;

    private AsabUser $head;

    private AsabUser $branchUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Reports Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'علامة', 'owner' => 'المالك', 'owner_email' => 'owner@rep.test', 'status' => 'active']);
        $this->restA = AsabRestaurant::create(['company_id' => $this->company->id, 'brand_id' => $this->brand->id, 'name' => 'مطعم أ', 'status' => 'active']);
        $this->restB = AsabRestaurant::create(['company_id' => $this->company->id, 'brand_id' => $this->brand->id, 'name' => 'مطعم ب', 'status' => 'active']);
        $this->branchA = Branch::factory()->create(['asab_company_id' => $this->company->id, 'asab_brand_id' => $this->brand->id, 'asab_restaurant_id' => $this->restA->id, 'name' => 'فرع أ']);
        $this->branchB = Branch::factory()->create(['asab_company_id' => $this->company->id, 'asab_brand_id' => $this->brand->id, 'asab_restaurant_id' => $this->restB->id, 'name' => 'فرع ب']);

        $this->admin = $this->user('admin@rep.test', 'admin');
        $this->accountant = $this->user('acc@rep.test', 'accountant');
        $this->head = $this->user('head@rep.test', 'head');
        $this->branchUser = $this->user('branch@rep.test', 'branch');
    }

    private function user(string $email, string $role, ?string $companyId = null): AsabUser
    {
        $u = AsabUser::create(['company_id' => $companyId ?? $this->company->id, 'name' => $role, 'email' => $email, 'password' => 'secret-password', 'status' => 'active']);
        AsabUserRole::create(['user_id' => $u->id, 'role_key' => $role, 'scope' => 'all']);

        return $u;
    }

    private function op(string $module, int $amount, ?string $branchId = null, array $payload = [], array $attrs = []): Operation
    {
        return Operation::create(array_merge([
            'public_id' => strtoupper($module).'-'.Str::upper(Str::random(6)),
            'company_id' => $this->company->id,
            'branch_id' => $branchId ?? $this->branchA->id,
            'module_key' => $module,
            'payload' => $payload,
            'amount' => $amount,
            'match' => 'exact',
            'origin' => 'mobile',
            'status' => 'final-approved',
            'operation_date' => now(),
        ], $attrs));
    }

    // ---- T15.7 de-stub typed reports ----

    public function test_typed_reports_return_real_data_not_stubs(): void
    {
        $supplier = AsabSupplier::create(['company_id' => $this->company->id, 'name' => 'مورد', 'status' => 'active']);
        $this->op('purchases', 3000, null, ['supplierId' => $supplier->id]);
        $this->op('sales', 800, null, ['items' => [['name' => 'برجر', 'qty' => 2, 'revenue' => 500]]]);
        $this->op('sales', 10000);
        $this->op('expenses', 4000);

        $sup = $this->actingAs($this->accountant, 'sanctum')->postJson('/api/v1/reports/supplier-performance', []);
        $sup->assertOk();
        $this->assertNotEmpty($sup->json('data.suppliers'));
        $this->assertSame(3000, $sup->json('data.suppliers.0.totalHalalas'));
        $this->assertSame('مورد', $sup->json('data.suppliers.0.supplierName'));

        $menu = $this->actingAs($this->accountant, 'sanctum')->postJson('/api/v1/reports/menu-engineering', []);
        $menu->assertOk();
        $this->assertNotEmpty($menu->json('data.items'));
        $this->assertSame('برجر', $menu->json('data.items.0.name'));

        $be = $this->actingAs($this->accountant, 'sanctum')->postJson('/api/v1/reports/breakeven', []);
        $be->assertOk();
        $this->assertArrayHasKey('breakevenRevenue', $be->json('data'));
        $this->assertArrayHasKey('contributionMarginPct', $be->json('data'));
    }

    public function test_payroll_honors_branch_filter(): void
    {
        Employee::create(['company_id' => $this->company->id, 'branch_id' => $this->branchA->id, 'emp_number' => 'E-A1', 'name' => 'أ1', 'role' => 'cashier', 'monthly_salary' => 1000, 'status' => 'active', 'hire_date' => '2020-01-01']);
        Employee::create(['company_id' => $this->company->id, 'branch_id' => $this->branchA->id, 'emp_number' => 'E-A2', 'name' => 'أ2', 'role' => 'cashier', 'monthly_salary' => 1000, 'status' => 'active', 'hire_date' => '2020-01-01']);
        Employee::create(['company_id' => $this->company->id, 'branch_id' => $this->branchB->id, 'emp_number' => 'E-B1', 'name' => 'ب1', 'role' => 'cashier', 'monthly_salary' => 5000, 'status' => 'active', 'hire_date' => '2020-01-01']);

        $res = $this->actingAs($this->accountant, 'sanctum')->postJson('/api/v1/reports/payroll', ['branchIds' => [$this->branchA->id]]);
        $res->assertOk()->assertJsonPath('data.totalPayroll', 2000)->assertJsonPath('data.headcount', 2);
    }

    public function test_typed_reports_are_role_gated(): void
    {
        $this->actingAs($this->branchUser, 'sanctum')->postJson('/api/v1/reports/profit-loss', [])->assertStatus(403);
        $this->actingAs($this->branchUser, 'sanctum')->postJson('/api/v1/reports/supplier-performance', [])->assertStatus(403);
    }

    // ---- T15.6 generate scope + download ----

    public function test_generate_honors_restaurant_scope(): void
    {
        $this->op('sales', 1000, $this->branchA->id);
        $this->op('sales', 5000, $this->branchB->id);

        $res = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/reports/generate', [
            'reportKey' => 'pl',
            'restaurantIds' => [$this->restA->id],
        ]);
        $res->assertOk()->assertJsonPath('data.income', 1000); // only restaurant A's branch
    }

    public function test_generate_pdf_streams_binary(): void
    {
        $this->op('sales', 1000, $this->branchA->id);

        $res = $this->actingAs($this->admin, 'sanctum')->post('/api/v1/admin/reports/generate', [
            'reportKey' => 'pl',
            'format' => 'pdf',
        ]);
        $res->assertOk();
        $this->assertStringContainsString('application/pdf', $res->headers->get('content-type'));
    }

    // ---- T15.8 builder saved list + run ----

    public function test_builder_save_list_run_roundtrip(): void
    {
        $this->op('sales', 1000);

        $definition = ['dimensions' => ['moduleKey'], 'metrics' => ['totalAmount']];
        $saved = $this->actingAs($this->accountant, 'sanctum')->postJson('/api/v1/reports/builder/save', [
            'name' => 'مبيعاتي', 'definition' => $definition,
        ]);
        $saved->assertCreated();
        $id = $saved->json('id');

        $list = $this->actingAs($this->accountant, 'sanctum')->getJson('/api/v1/reports/builder/saved');
        $list->assertOk();
        $this->assertTrue(collect($list->json('data'))->pluck('id')->contains($id));

        $run = $this->actingAs($this->accountant, 'sanctum')->postJson('/api/v1/reports/builder/'.$id.'/run');
        $run->assertOk()->assertJsonStructure(['rows', 'totals', 'rowCount']);
    }

    public function test_builder_unknown_dimension_is_422(): void
    {
        $this->actingAs($this->accountant, 'sanctum')->postJson('/api/v1/reports/builder/preview', [
            'dimensions' => ['bogusField'],
        ])->assertStatus(422);
    }

    public function test_builder_run_foreign_tenant_is_404(): void
    {
        $otherCompany = AsabCompany::create(['name' => 'Other', 'plan' => 'Professional', 'status' => 'active']);
        $otherAcc = $this->user('acc@other.test', 'accountant', $otherCompany->id);
        $foreignDef = ReportDefinition::create([
            'company_id' => $otherCompany->id, 'created_by_id' => $otherAcc->id,
            'name' => 'أجنبي', 'definition' => ['metrics' => ['count']],
        ]);

        $this->actingAs($this->accountant, 'sanctum')->postJson('/api/v1/reports/builder/'.$foreignDef->id.'/run')
            ->assertStatus(404);
    }

    public function test_builder_preview_is_tenant_isolated(): void
    {
        $this->op('sales', 1000);
        $otherCompany = AsabCompany::create(['name' => 'Leak Co', 'plan' => 'Professional', 'status' => 'active']);
        Operation::create([
            'public_id' => 'SALES-LEAK', 'company_id' => $otherCompany->id, 'branch_id' => $this->branchB->id,
            'module_key' => 'sales', 'payload' => [], 'amount' => 9999, 'status' => 'final-approved', 'operation_date' => now(),
        ]);

        $res = $this->actingAs($this->accountant, 'sanctum')->postJson('/api/v1/reports/builder/preview', [
            'metrics' => ['totalAmount'],
        ]);
        $res->assertOk();
        $this->assertSame(1000, $res->json('totals.totalAmount')); // excludes the other company's 9999
    }

    // ---- T15.1 upload verification ----

    public function test_upload_verifies_valid_sheet_and_fails_empty(): void
    {
        Storage::fake('public');

        $ok = $this->actingAs($this->admin, 'sanctum')->post('/api/v1/admin/reports/pl/upload', [
            'file' => UploadedFile::fake()->createWithContent('pl.csv', "label,value\nالإيرادات,1000\nالمصروفات,400\n"),
        ]);
        $ok->assertOk()->assertJsonPath('status', 'verified');
        $this->assertSame(2, $ok->json('rowCount'));

        $bad = $this->actingAs($this->admin, 'sanctum')->post('/api/v1/admin/reports/pl/upload', [
            'file' => UploadedFile::fake()->createWithContent('empty.csv', "onlyheader\n"),
        ]);
        $bad->assertOk()->assertJsonPath('status', 'failed');
        $this->assertNotEmpty($bad->json('errors'));

        $wrong = $this->actingAs($this->admin, 'sanctum')->post('/api/v1/admin/reports/pl/upload', [
            'file' => UploadedFile::fake()->create('bad.txt', 4),
        ]);
        $wrong->assertStatus(422);
    }

    // ---- T15.5 mark distribution viewed ----

    public function test_mark_distribution_viewed(): void
    {
        $dist = ReportDistribution::create([
            'company_id' => $this->company->id, 'report_key' => 'pl', 'restaurant_id' => $this->restA->id,
            'channels' => ['inApp'], 'period_from' => '2026-07-01', 'period_to' => '2026-07-31',
            'sent' => true, 'sent_at' => now(),
        ]);

        $this->actingAs($this->accountant, 'sanctum')->postJson('/api/v1/company/me/reports/distributions/'.$dist->id.'/viewed')
            ->assertOk()->assertJsonPath('viewed', true);

        $this->assertTrue((bool) $dist->fresh()->viewed);
        $this->assertNotNull($dist->fresh()->viewed_at);
    }

    public function test_mark_distribution_viewed_foreign_tenant_is_404(): void
    {
        $otherCompany = AsabCompany::create(['name' => 'Other', 'plan' => 'Professional', 'status' => 'active']);
        $dist = ReportDistribution::create([
            'company_id' => $otherCompany->id, 'report_key' => 'pl', 'restaurant_id' => $this->restA->id,
            'channels' => ['inApp'], 'sent' => true, 'sent_at' => now(),
        ]);

        $this->actingAs($this->accountant, 'sanctum')->postJson('/api/v1/company/me/reports/distributions/'.$dist->id.'/viewed')
            ->assertStatus(404);
    }

    // ---- T15.3 owner dispatch ----

    public function test_send_queues_owner_email_and_notifies_owner_in_app(): void
    {
        Queue::fake();
        $owner = $this->user('owneruser@rep.test', 'company-admin');
        $this->brand->update(['owner_user_id' => $owner->id, 'owner_email' => 'owner@rep.test']);
        $this->op('sales', 1000, $this->branchA->id);

        $res = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/reports/pl/send', [
            'period' => ['from' => '2026-07-01', 'to' => '2026-07-31'],
            'restaurantIds' => [$this->restA->id],
            'method' => 'both',
            'format' => 'pdf',
            'coverMessage' => 'تفضلوا التقرير الشهري',
        ]);
        $res->assertOk()->assertJsonPath('sentCount', 1);

        Queue::assertPushed(SendOwnerReportJob::class, 1); // email queued to the owner
        $this->assertDatabaseHas('asab_notifications', ['user_id' => $owner->id, 'type' => 'report.received']);
        $this->assertDatabaseHas('asab_report_distributions', [
            'report_key' => 'pl', 'restaurant_id' => $this->restA->id, 'sent' => 1, 'cover_message' => 'تفضلوا التقرير الشهري',
        ]);
    }

    public function test_send_without_restaurant_ids_targets_all_active(): void
    {
        Queue::fake();
        $res = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/reports/pl/send', [
            'period' => ['from' => '2026-07-01', 'to' => '2026-07-31'],
            'method' => 'inApp',
        ]);
        $res->assertOk()->assertJsonPath('sentCount', 2); // restA + restB, both active
    }

    public function test_send_is_idempotent_per_restaurant_period(): void
    {
        Queue::fake();
        $payload = ['period' => ['from' => '2026-07-01', 'to' => '2026-07-31'], 'restaurantIds' => [$this->restA->id], 'method' => 'inApp'];
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/reports/pl/send', $payload)->assertOk();
        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/reports/pl/send', $payload)->assertOk();

        $this->assertSame(1, ReportDistribution::where('report_key', 'pl')->where('restaurant_id', $this->restA->id)->count());
    }

    // ---- T15.18 HEAD-7 financial cards ----

    public function test_head_reports_internal_exposes_financial_cards(): void
    {
        $this->op('sales', 1000);

        $res = $this->actingAs($this->head, 'sanctum')->getJson('/api/v1/head/reports/internal');
        $res->assertOk();
        $cards = collect($res->json('meta.financialReports'));
        $this->assertTrue($cards->pluck('key')->contains('pl-by-brand'));
        $this->assertTrue($cards->pluck('key')->contains('branch-compare'));
    }
}
