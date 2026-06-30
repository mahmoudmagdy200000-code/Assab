<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Operation;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * Head (رئيس الحسابات) backend follow-ups B-H1..B-H6 — per-op enrichment,
 * dashboard/perf aliases, ERP preflight labels, report baseline, and the
 * platform /head/reminders surface.
 */
class HeadBackendFollowupsTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabUser $head;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = AsabCompany::create(['name' => 'Head Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->head = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'رئيس الحسابات',
            'email' => 'head@asab.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->head->id, 'role_key' => 'head', 'scope' => 'all']);
    }

    private function accountant(string $name, array $branchIds = []): AsabUser
    {
        $u = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => $name,
            'email' => Str::random(8).'@asab.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $u->id, 'role_key' => 'accountant', 'scope' => 'branch', 'branch_ids' => $branchIds,
        ]);

        return $u;
    }

    private function op(array $attrs = []): Operation
    {
        return Operation::create(array_merge([
            'public_id' => strtoupper(Str::random(12)),
            'company_id' => $this->company->id,
            'module_key' => 'sales',
            'amount' => 100000,
            'match' => 'exact',
            'status' => Operation::STATUS_APPROVED,
            'operation_date' => now(),
            'reviewed_at' => now(),
            'submitted_at' => now(),
        ], $attrs));
    }

    private function asHead()
    {
        return $this->actingAs($this->head, 'sanctum');
    }

    // ---- B-H1: enriched operations ----

    public function test_bh1_pending_operations_carry_accountant_brand_branch_module(): void
    {
        $accountant = $this->accountant('محمد العامري');
        $brand = AsabBrand::create([
            'company_id' => $this->company->id, 'name' => 'براند برجر', 'sub_status' => 'active', 'status' => 'active',
        ]);
        $branch = Branch::factory()->create([
            'name' => 'فرع الرياض', 'asab_brand_id' => $brand->id, 'asab_company_id' => $this->company->id,
        ]);
        $this->op([
            'branch_id' => $branch->id, 'approved_by_id' => $accountant->id, 'module_key' => 'sales',
            'match' => 'diff', 'erp_posted' => true, 'erp_batch_id' => 'ERP-BATCH-X',
        ]);

        $res = $this->asHead()->getJson('/api/v1/head/operations/pending');

        $res->assertStatus(200)
            ->assertJsonStructure(['data' => [[
                'accountantId', 'accountantName', 'brandId', 'brandName', 'branchId', 'branchName',
                'moduleKey', 'moduleLabel', 'amount', 'amountHalalas', 'match', 'status', 'erpPosted', 'erpBatchId',
            ]], 'meta'])
            ->assertJsonPath('data.0.accountantName', 'محمد العامري')
            ->assertJsonPath('data.0.brandName', 'براند برجر')
            ->assertJsonPath('data.0.branchName', 'فرع الرياض')
            ->assertJsonPath('data.0.moduleLabel', 'المبيعات')
            ->assertJsonPath('data.0.amountHalalas', 100000)
            ->assertJsonPath('data.0.erpBatchId', 'ERP-BATCH-X');
    }

    // ---- B-H2: dashboard kpi + weekly aliases ----

    public function test_bh2_dashboard_kpi_and_weekly_aliases(): void
    {
        $this->op(['status' => Operation::STATUS_APPROVED]);
        $this->op(['status' => Operation::STATUS_FINAL, 'erp_posted' => false]);
        $this->op(['status' => Operation::STATUS_REJECTED, 'rejected_at' => now()]);

        $res = $this->asHead()->getJson('/api/v1/head/dashboard');

        $res->assertStatus(200)
            ->assertJsonStructure([
                'kpis' => ['awaiting', 'finalApproved', 'erpPosted', 'rejected', 'performanceRatePct'],
                'weeklyPerformance' => [['day', 'thisW', 'lastW']],
            ])
            ->assertJsonPath('kpis.awaiting', 1)
            ->assertJsonPath('kpis.finalApproved', 1);
    }

    // ---- B-H3: accountants performance ----

    public function test_bh3_accountants_performance_rich_aliases(): void
    {
        $branch = Branch::factory()->create(['asab_company_id' => $this->company->id]);
        $accountant = $this->accountant('سارة', [$branch->id]);
        $this->op(['approved_by_id' => $accountant->id, 'status' => Operation::STATUS_APPROVED]);

        $res = $this->asHead()->getJson('/api/v1/head/accountants/performance');

        $res->assertStatus(200)
            ->assertJsonStructure([[
                'id', 'name', 'rate', 'prevRate', 'avgTime', 'reviewed', 'approved', 'pending',
                'branches', 'level', 'levelCls', 'rating', 'recentMovements',
            ]])
            ->assertJsonPath('0.branches', 1);
    }

    // ---- B-H4: ERP preflight + batch createdAt ----

    public function test_bh4_erp_preflight_labels_and_batch_created_at(): void
    {
        $this->op(['status' => Operation::STATUS_FINAL, 'erp_posted' => false]);

        $preflight = $this->asHead()->getJson('/api/v1/head/erp/preflight');
        $preflight->assertStatus(200)
            ->assertJsonStructure(['checks' => [['ok', 'labelAr', 'labelEn', 'severity']], 'canProceed', 'warningCount']);

        $create = $this->asHead()->postJson('/api/v1/erp/batches', []);
        $create->assertStatus(201)
            ->assertJsonStructure(['id', 'batchId', 'createdAt']);

        $batches = $this->asHead()->getJson('/api/v1/head/erp/batches');
        $batches->assertStatus(200)
            ->assertJsonStructure(['data' => [['id', 'batchId', 'createdAt', 'completedAt']], 'meta']);
    }

    // ---- B-H5: reports ----

    public function test_bh5_internal_reports_have_labels_and_download(): void
    {
        $this->op(['module_key' => 'sales']);

        $res = $this->asHead()->getJson('/api/v1/head/reports/internal');
        $res->assertStatus(200)
            ->assertJsonStructure(['data' => [['moduleKey', 'labelAr', 'labelEn', 'count', 'total', 'downloadUrl']]]);
    }

    public function test_bh5_owner_report_has_baseline(): void
    {
        $res = $this->asHead()->getJson('/api/v1/head/reports/owner');

        $res->assertStatus(200)
            ->assertJsonStructure(['headline' => ['netPosition', 'currentMonthNet', 'previousMonthNet', 'netPctChange']]);
    }

    // ---- B-H6: platform head reminders ----

    public function test_bh6_platform_head_reminders_crud(): void
    {
        $list = $this->asHead()->getJson('/api/v1/head/reminders');
        $list->assertStatus(200)->assertJsonStructure(['data']);

        $create = $this->asHead()->postJson('/api/v1/head/reminders', ['title' => 'مراجعة التقارير', 'type' => 'report']);
        $create->assertStatus(201)->assertJsonPath('titleAr', 'مراجعة التقارير');
        $id = $create->json('id');

        $patch = $this->asHead()->patchJson("/api/v1/head/reminders/{$id}", ['done' => true]);
        $patch->assertStatus(200)->assertJsonPath('done', true);

        $this->asHead()->postJson('/api/v1/head/reminders/mark-all-done')->assertStatus(204);
    }
}
