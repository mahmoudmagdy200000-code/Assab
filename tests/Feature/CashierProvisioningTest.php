<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Employee;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Cashier\Notifications\CashierActivationNotification;
use Tests\TestCase;

/**
 * WS2 cashier login bridge: dashboard "add employee" with a cashier role also
 * provisions a legacy cashiers row (mobile-app login) with emailed one-time
 * credentials, with tenant isolation on duplicate emails.
 */
class CashierProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabUser $manager;

    private Branch $branch;

    private BranchManager $legacyManager;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->company = AsabCompany::create(['name' => 'Bridge Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['asab_company_id' => $this->company->id]);
        $this->legacyManager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
        $this->manager = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'مدير الفرع',
            'email' => 'branch-manager@asab.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $this->manager->id,
            'role_key' => 'branch',
            'scope' => 'branch',
            'branch_ids' => [$this->branch->id],
        ]);
    }

    private function asManager()
    {
        return $this->actingAs($this->manager, 'sanctum');
    }

    /** A second tenant with its own branch, manager and an existing cashier. */
    private function foreignCashier(string $email): Cashier
    {
        $otherCompany = AsabCompany::create(['name' => 'Other Co', 'plan' => 'Basic', 'status' => 'active']);
        $otherBranch = Branch::factory()->create(['asab_company_id' => $otherCompany->id]);
        $otherManager = BranchManager::factory()->create(['branch_id' => $otherBranch->id]);

        return Cashier::create([
            'name' => 'Foreign Cashier',
            'email' => $email,
            'password' => 'foreign-password',
            'branch_id' => $otherBranch->id,
            'status' => 'active',
            'created_by' => $otherManager->id,
        ]);
    }

    // ---- Company-dashboard endpoint (§5.4) ----

    public function test_cashier_role_employee_provisions_mobile_cashier_with_hashed_password_and_notification(): void
    {
        $res = $this->asManager()->postJson('/api/v1/company/me/branch/employees', [
            'name' => 'سارة الكاشير',
            'role' => 'Cashier',
            'salaryHalalas' => 450000,
            'email' => 'sara.cashier@asab.test',
            'phone' => '+966500000001',
        ]);

        $res->assertCreated()
            ->assertJsonPath('cashier.provisioned', true)
            ->assertJsonPath('cashier.emailSent', true);

        $cashier = Cashier::where('email', 'sara.cashier@asab.test')->first();
        $this->assertNotNull($cashier);
        $this->assertSame($this->branch->id, $cashier->branch_id);
        $this->assertSame('pending', $cashier->status);
        $this->assertSame($this->legacyManager->id, $cashier->created_by);
        $this->assertTrue(Hash::isHashed($cashier->getAuthPassword()));

        $this->assertSame($cashier->id, Employee::first()->legacy_cashier_id);
        Notification::assertSentTo($cashier, CashierActivationNotification::class);
    }

    public function test_arabic_cashier_role_is_recognized_on_branch_endpoint(): void
    {
        $res = $this->asManager()->postJson('/api/v1/branch/employees', [
            'empNumber' => 'EMP-0007',
            'name' => 'أحمد',
            'role' => 'كاشير',
            'monthlySalary' => 400000,
            'email' => 'ahmed.cashier@asab.test',
        ]);

        $res->assertCreated()->assertJsonPath('cashier.provisioned', true);

        $cashier = Cashier::where('email', 'ahmed.cashier@asab.test')->first();
        $this->assertNotNull($cashier);
        $this->assertSame($this->branch->id, $cashier->branch_id);
        $this->assertSame($cashier->id, Employee::first()->legacy_cashier_id);
        Notification::assertSentTo($cashier, CashierActivationNotification::class);
    }

    public function test_non_cashier_role_stays_registry_only(): void
    {
        $res = $this->asManager()->postJson('/api/v1/company/me/branch/employees', [
            'name' => 'طباخ',
            'role' => 'Chef',
            'salaryHalalas' => 500000,
            'email' => 'chef@asab.test',
        ]);

        $res->assertCreated()->assertJsonMissingPath('cashier');
        $this->assertSame(1, Employee::count());
        $this->assertSame(0, Cashier::count());
        Notification::assertNothingSent();
    }

    public function test_duplicate_email_in_another_company_422s_and_rolls_back_the_employee(): void
    {
        $this->foreignCashier('taken@asab.test');

        $res = $this->asManager()->postJson('/api/v1/company/me/branch/employees', [
            'name' => 'منتحل',
            'role' => 'cashier',
            'salaryHalalas' => 300000,
            'email' => 'taken@asab.test',
        ]);

        $res->assertStatus(422)->assertJsonPath('error.code', 'EMAIL_CONFLICT');
        $this->assertSame(0, Employee::withoutGlobalScopes()->count());
        $this->assertSame(1, Cashier::count());
    }

    public function test_same_company_duplicate_email_links_existing_cashier_instead_of_duplicating(): void
    {
        $existing = Cashier::create([
            'name' => 'Existing Cashier',
            'email' => 'existing@asab.test',
            'password' => 'existing-password',
            'branch_id' => $this->branch->id,
            'status' => 'active',
            'created_by' => $this->legacyManager->id,
        ]);

        $res = $this->asManager()->postJson('/api/v1/company/me/branch/employees', [
            'name' => 'Existing Cashier',
            'role' => 'cashier',
            'salaryHalalas' => 350000,
            'email' => 'existing@asab.test',
        ]);

        $res->assertCreated()
            ->assertJsonPath('cashier.provisioned', true)
            ->assertJsonPath('cashier.reason', 'LINKED_EXISTING')
            ->assertJsonPath('cashier.cashierId', $existing->id);

        $this->assertSame(1, Cashier::where('email', 'existing@asab.test')->count());
        $this->assertSame($existing->id, Employee::first()->legacy_cashier_id);
        // Linking must not reset the live account's credentials or re-email them.
        $this->assertSame('active', $existing->fresh()->status);
        Notification::assertNothingSent();
    }

    public function test_missing_email_still_creates_registry_row_and_reports_skip(): void
    {
        $res = $this->asManager()->postJson('/api/v1/company/me/branch/employees', [
            'name' => 'كاشير بلا بريد',
            'role' => 'cashier',
            'salaryHalalas' => 300000,
        ]);

        $res->assertCreated()
            ->assertJsonPath('cashier.provisioned', false)
            ->assertJsonPath('cashier.reason', 'EMAIL_REQUIRED');

        $emp = Employee::first();
        $this->assertNotNull($emp);
        $this->assertNull($emp->legacy_cashier_id);
        $this->assertSame(0, Cashier::count());
        Notification::assertNothingSent();
    }
}
