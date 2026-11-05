<?php

namespace Tests\Feature\Harness;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Modules\Admin\Database\Seeders\AsabRolePermissionSeeder;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\Testing\StagingHarnessProvisioner;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use RuntimeException;
use Tests\Support\Harness\AssabHarnessFixtureBuilder;
use Tests\TestCase;

final class TrialProfileProvisionerTest extends TestCase
{
    use RefreshDatabase;

    private array $trialProfile;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
        (new AsabRolePermissionSeeder)->run();

        $profilePath = base_path('Modules/Admin/resources/fixtures/trial-dar-alhail.json');
        $this->trialProfile = json_decode(file_get_contents($profilePath), true, 512, JSON_THROW_ON_ERROR);
    }

    public function test_profile_provisioner_creates_five_accounts_arabic_names_and_saudi_phones(): void
    {
        $provisioner = app(StagingHarnessProvisioner::class);
        $password = 'SecretTrial123!';
        $manifest = $provisioner->provision('dar-alhail-trial', $password, $this->trialProfile);

        // Accounts count: exactly 4 dashboard accounts + 1 cashier
        $this->assertCount(4, $manifest['accounts']);
        $this->assertSame(4, AsabUser::where('company_id', $manifest['companyId'])->count());
        $this->assertSame(4, CompanyUser::withoutGlobalScopes()->where('company_id', $manifest['companyId'])->count());

        // Verify Company Admin
        $companyAdmin = AsabUser::where('email', 'faisal.alshehri@daralhail.test')->firstOrFail();
        $this->assertSame('فيصل الشهري', $companyAdmin->name);
        $this->assertTrue(Hash::check($password, $companyAdmin->password));

        // Verify Branch Manager
        $branchManagerUser = AsabUser::where('email', 'majed.alqahtani@daralhail.test')->firstOrFail();
        $this->assertSame('ماجد القحطاني', $branchManagerUser->name);
        $this->assertSame('+966500000101', $branchManagerUser->phone);
        $this->assertTrue(Hash::check($password, $branchManagerUser->password));

        $branchManager = BranchManager::where('email', 'majed.alqahtani@daralhail.test')->firstOrFail();
        $this->assertSame('ماجد القحطاني', $branchManager->name);
        $this->assertSame('+966500000101', $branchManager->phone);
        $this->assertTrue(Hash::check($password, $branchManager->password));

        // Verify Cashier
        $cashier = Cashier::where('email', 'yousef.aldosari@daralhail.test')->firstOrFail();
        $this->assertSame('يوسف الدوسري', $cashier->name);
        $this->assertSame('+966500000102', $cashier->phone);
        $this->assertSame('active', $cashier->status);
        $this->assertTrue(Hash::check($password, $cashier->password));

        // Verify Cashier Mirrored to Employee roster
        $mirroredEmployee = Employee::withoutGlobalScopes()
            ->where('legacy_cashier_id', $cashier->id)
            ->first();
        $this->assertNotNull($mirroredEmployee);
        $this->assertSame('يوسف الدوسري', $mirroredEmployee->name);
        $this->assertSame('+966500000102', $mirroredEmployee->phone);

        // Verify Accountant
        $accountant = AsabUser::where('email', 'noura.alzahrani@daralhail.test')->firstOrFail();
        $this->assertSame('نورة الزهراني', $accountant->name);
        $this->assertTrue(Hash::check($password, $accountant->password));

        // Verify Head Accountant
        $headAccountant = AsabUser::where('email', 'khalid.alghamdi@daralhail.test')->firstOrFail();
        $this->assertSame('خالد الغامدي', $headAccountant->name);
        $this->assertTrue(Hash::check($password, $headAccountant->password));

        // Verify Shift Template
        $shift = Shift::whereKey($manifest['shiftTemplateId'])->firstOrFail();
        $this->assertSame('الوردية الرئيسية', $shift->name);
        $this->assertSame('08:00:00', $shift->start_time->format('H:i:s'));
        $this->assertSame('23:59:00', $shift->end_time->format('H:i:s'));

        // Verify Seeded Unstarted Shift for Cashier
        $unstarted = CashierShift::where('cashier_id', $cashier->id)->firstOrFail();
        $this->assertSame('not_started', $unstarted->status->value ?? (string) $unstarted->status);
        $this->assertSame('2026-11-06', $unstarted->shift_date->format('Y-m-d'));
        $this->assertSame('0.00', (string) $unstarted->opening_balance);
        $this->assertSame('0.00', (string) $unstarted->total_sales);
    }

    public function test_profile_provisioner_is_idempotent(): void
    {
        $provisioner = app(StagingHarnessProvisioner::class);
        $password = 'SecretTrial123!';

        $first = $provisioner->provision('dar-alhail-trial', $password, $this->trialProfile);
        $countsAfterFirst = $this->fixtureCounts();

        $second = $provisioner->provision('dar-alhail-trial', $password, $this->trialProfile);
        $countsAfterSecond = $this->fixtureCounts();

        $this->assertSame($countsAfterFirst, $countsAfterSecond);
        $this->assertSame($first['definitionSha256'], $second['definitionSha256']);
        $this->assertSame($first['companyId'], $second['companyId']);
    }

    public function test_collision_guard_refuses_foreign_email_and_phone(): void
    {
        // Foreign user with same email
        AsabUser::create([
            'name' => 'Foreign User',
            'email' => 'faisal.alshehri@daralhail.test',
            'password' => 'ForeignPass123!',
            'status' => 'active',
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('non-fixture user');

        app(StagingHarnessProvisioner::class)->provision('dar-alhail-trial', 'SecretTrial123!', $this->trialProfile);
    }

    public function test_collision_guard_refuses_foreign_phone(): void
    {
        $branch = Branch::factory()->create();
        BranchManager::create([
            'name' => 'Foreign Manager',
            'email' => 'foreign.manager@example.com',
            'phone' => '+966500000101',
            'branch_id' => $branch->id,
            'password' => 'ForeignPass123!',
            'status' => 'active',
            'is_active' => true,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('phone');

        app(StagingHarnessProvisioner::class)->provision('dar-alhail-trial', 'SecretTrial123!', $this->trialProfile);
    }

    public function test_profile_refuses_if_company_exists_with_different_name(): void
    {
        $provisioner = app(StagingHarnessProvisioner::class);
        $password = 'SecretTrial123!';
        $first = $provisioner->provision('dar-alhail-trial', $password, $this->trialProfile);

        // Tamper company name
        AsabCompany::whereKey($first['companyId'])->update(['name' => 'Tampered Company Name']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('name differs from profile');

        $provisioner->provision('dar-alhail-trial', $password, $this->trialProfile);
    }

    public function test_no_profile_run_preserves_byte_identical_harness_behavior(): void
    {
        $provisioner = app(StagingHarnessProvisioner::class);
        $plan = $provisioner->plan('assab-harness-v1');

        $this->assertCount(6, $plan['accounts']);
        $this->assertArrayHasKey('multi_role_finance', $plan['accounts']);
        $this->assertArrayHasKey('restricted', $plan['accounts']);
        $this->assertSame('Cashier', $plan['cashier']['label']);
        $this->assertSame('assab-harness-v1.cashier@fixture.invalid', $plan['cashier']['email']);
        $this->assertArrayNotHasKey('profile', $plan);

        // Byte identical hash check with standard baseline
        $expectedHash = hash('sha256', json_encode([
            'schemaVersion' => 1,
            'namespace' => 'assab-harness-v1',
            'marker' => 'assab-harness-v1@fixture.invalid',
            'companyId' => $plan['companyId'],
            'brandId' => $plan['brandId'],
            'restaurantId' => $plan['restaurantId'],
            'branchId' => $plan['branchId'],
            'branchManagerId' => $plan['branchManagerId'],
            'shiftTemplateId' => $plan['shiftTemplateId'],
            'accounts' => $plan['accounts'],
            'cashier' => $plan['cashier'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));

        $this->assertSame($expectedHash, $plan['definitionSha256']);
    }

    public function test_manifest_and_plan_contain_no_plaintext_credentials(): void
    {
        $provisioner = app(StagingHarnessProvisioner::class);
        $password = 'SuperSecretString123!';
        $manifest = $provisioner->provision('dar-alhail-trial', $password, $this->trialProfile);

        $json = json_encode($manifest, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString($password, $json);
    }

    public function test_tenant_isolation_between_harness_and_trial(): void
    {
        $provisioner = app(StagingHarnessProvisioner::class);

        // 1. Provision standard harness
        $harnessBuilder = new AssabHarnessFixtureBuilder;
        $harness = $harnessBuilder->build('assab-harness-ci');

        // 2. Provision trial tenant
        $trialManifest = $provisioner->provision('dar-alhail-trial', 'SecretTrial123!', $this->trialProfile);

        // 3. Create operation for harness
        $harnessBranch = Branch::where('asab_company_id', $harness->manifest['companyId'])->firstOrFail();
        $harnessOp = Operation::create([
            'public_id' => 'OP-HARNESS-001',
            'company_id' => $harness->manifest['companyId'],
            'branch_id' => $harnessBranch->id,
            'module_key' => 'shifts',
            'amount' => 100,
            'match' => 'exact',
            'status' => Operation::STATUS_PENDING,
            'operation_date' => now(),
            'submitted_at' => now(),
            'payload' => ['identifier' => 'HARNESS_EXCLUSIVE_PAYLOAD'],
        ]);

        // 4. Create operation for trial
        $trialBranch = Branch::where('asab_company_id', $trialManifest['companyId'])->firstOrFail();
        $trialOp = Operation::create([
            'public_id' => 'OP-TRIAL-002',
            'company_id' => $trialManifest['companyId'],
            'branch_id' => $trialBranch->id,
            'module_key' => 'shifts',
            'amount' => 200,
            'match' => 'exact',
            'status' => Operation::STATUS_PENDING,
            'operation_date' => now(),
            'submitted_at' => now(),
            'payload' => ['identifier' => 'TRIAL_EXCLUSIVE_PAYLOAD'],
        ]);

        // 5. Query as Trial Accountant
        $trialAccountant = AsabUser::where('email', 'noura.alzahrani@daralhail.test')->firstOrFail();
        $trialResponse = $this->actingAs($trialAccountant, 'sanctum')->getJson('/api/v1/operations');
        $trialResponse->assertStatus(200);
        $trialContent = $trialResponse->getContent();
        $this->assertStringContainsString('OP-TRIAL-002', $trialContent);
        $this->assertStringNotContainsString('OP-HARNESS-001', $trialContent);

        // 6. Query as Harness Accountant
        $harnessAccountant = $harness->user('accountant');
        $harnessResponse = $this->actingAs($harnessAccountant, 'sanctum')->getJson('/api/v1/operations');
        $harnessResponse->assertStatus(200);
        $harnessContent = $harnessResponse->getContent();
        $this->assertStringContainsString('OP-HARNESS-001', $harnessContent);
        $this->assertStringNotContainsString('OP-TRIAL-002', $harnessContent);
    }

    /** @return array<string, int> */
    private function fixtureCounts(): array
    {
        return collect([
            'asab_companies', 'asab_brands', 'asab_restaurants', 'branches',
            'asab_users', 'asab_user_roles', 'asab_company_users',
            'branch_managers', 'cashiers', 'shifts', 'asab_identity_map', 'asab_employees',
            'cashier_shifts',
        ])->mapWithKeys(fn (string $table): array => [$table => DB::table($table)->count()])->all();
    }
}
