<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Tests\TestCase;

/**
 * GET /admin/suppliers (FE wiring 2026-07-17 §1) — the admin-side supplier read
 * that feeds the role=supplier picker in POST /admin/users.
 */
class AdminSupplierDirectoryTest extends TestCase
{
    use RefreshDatabase;

    private AsabUser $admin;

    private AsabCompany $companyA;

    private AsabCompany $companyB;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeAsabUser('admin', 'admin@sup.test');
        $this->companyA = AsabCompany::create(['name' => 'Co A', 'plan' => 'Professional', 'status' => 'active']);
        $this->companyB = AsabCompany::create(['name' => 'Co B', 'plan' => 'Professional', 'status' => 'active']);
    }

    private function makeAsabUser(string $role, string $email, ?string $companyId = null): AsabUser
    {
        $user = AsabUser::create([
            'company_id' => $companyId,
            'name' => ucfirst($role).' User',
            'email' => $email,
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $user->id, 'role_key' => $role, 'scope' => 'all']);

        return $user;
    }

    private function supplier(AsabCompany $company, array $attrs = []): AsabSupplier
    {
        return AsabSupplier::create(array_merge([
            'company_id' => $company->id,
            'name' => 'Supplier '.uniqid(),
            'contact_email' => uniqid('sup').'@vendor.test',
            'status' => 'active',
        ], $attrs));
    }

    public function test_admin_lists_suppliers_with_the_fields_the_user_picker_needs(): void
    {
        $s = $this->supplier($this->companyA, ['name' => 'Alpha Foods', 'contact_email' => 'alpha@vendor.test']);

        $res = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/suppliers');

        $res->assertStatus(200)
            ->assertJsonStructure(['data' => [['id', 'name', 'contactEmail', 'companyId', 'status', 'hasLogin']], 'meta'])
            ->assertJsonPath('data.0.id', $s->id)
            ->assertJsonPath('data.0.name', 'Alpha Foods')
            ->assertJsonPath('data.0.contactEmail', 'alpha@vendor.test')
            ->assertJsonPath('data.0.hasLogin', false);
    }

    /** The admin read is cross-company; companyId is what narrows it. */
    public function test_company_id_filter_narrows_to_one_company(): void
    {
        $a = $this->supplier($this->companyA);
        $this->supplier($this->companyB);

        $all = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/suppliers');
        $all->assertStatus(200)->assertJsonCount(2, 'data');

        $filtered = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/suppliers?companyId={$this->companyA->id}");

        $filtered->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $a->id)
            ->assertJsonPath('meta.total', 1);
    }

    public function test_search_matches_name_or_contact_email(): void
    {
        $this->supplier($this->companyA, ['name' => 'Zeta Meats', 'contact_email' => 'zeta@vendor.test']);
        $this->supplier($this->companyA, ['name' => 'Omega Dairy', 'contact_email' => 'omega@vendor.test']);

        $byName = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/suppliers?search=Zeta');
        $byName->assertStatus(200)->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Zeta Meats');

        $byEmail = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/suppliers?search=omega@vendor.test');
        $byEmail->assertStatus(200)->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Omega Dairy');
    }

    public function test_status_filter_excludes_inactive_suppliers(): void
    {
        $this->supplier($this->companyA, ['name' => 'Active Co', 'status' => 'active']);
        $this->supplier($this->companyA, ['name' => 'Dormant Co', 'status' => 'inactive']);

        $res = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/suppliers?status=active');

        $res->assertStatus(200)->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Active Co');
    }

    public function test_has_login_reports_an_already_provisioned_supplier(): void
    {
        $login = $this->makeAsabUser('supplier', 'linked@vendor.test', $this->companyA->id);
        $this->supplier($this->companyA, ['contact_email' => 'linked@vendor.test', 'user_id' => $login->id]);

        $res = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/suppliers');

        $res->assertStatus(200)->assertJsonPath('data.0.hasLogin', true);
    }

    public function test_soft_deleted_suppliers_are_excluded(): void
    {
        $s = $this->supplier($this->companyA);
        $s->delete();

        $res = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/suppliers');

        $res->assertStatus(200)->assertJsonCount(0, 'data');
    }

    public function test_page_size_is_capped(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->supplier($this->companyA);
        }

        $res = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/suppliers?pageSize=5000');

        $res->assertStatus(200)->assertJsonPath('meta.pageSize', 100);
    }

    public function test_non_admin_cannot_read_the_admin_supplier_directory(): void
    {
        $accountant = $this->makeAsabUser('accountant', 'acc@sup.test', $this->companyA->id);

        $res = $this->actingAs($accountant, 'sanctum')->getJson('/api/v1/admin/suppliers');

        $res->assertStatus(403);
    }

    public function test_guests_are_rejected(): void
    {
        $this->getJson('/api/v1/admin/suppliers')->assertStatus(401);
    }

    /**
     * The reason this endpoint exists: contactEmail is the value POST /admin/users
     * must send for role=supplier, or SupplierUserProvisioner 422s the create.
     */
    public function test_contact_email_from_the_directory_satisfies_the_supplier_login_rule(): void
    {
        $s = $this->supplier($this->companyA, ['contact_email' => 'picker@vendor.test']);

        $listed = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/suppliers?companyId={$this->companyA->id}");
        $contactEmail = $listed->json('data.0.contactEmail');

        $mismatch = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/users', [
            'name' => 'Supplier Login', 'email' => 'typo@vendor.test',
            'role' => 'supplier', 'companyId' => $this->companyA->id, 'supplierId' => $s->id,
        ]);
        $mismatch->assertStatus(422)->assertJsonPath('error.code', 'SUPPLIER_EMAIL_MISMATCH');

        $ok = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/users', [
            'name' => 'Supplier Login', 'email' => $contactEmail,
            'role' => 'supplier', 'companyId' => $this->companyA->id, 'supplierId' => $s->id,
        ]);
        $ok->assertStatus(201);
    }
}
