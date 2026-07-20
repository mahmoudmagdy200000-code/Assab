<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Employee;
use Modules\Admin\Models\Operation;
use Modules\Supplier\Models\Supplier as LegacySupplier;
use Tests\TestCase;

/**
 * Suppliers and procurement managers contract with ASAB, not with one company
 * (client, 2026-07-20): they serve every company and so carry NO company_id.
 *
 * The dangerous half of that change is the tenant scope. It used to apply NO
 * where-clause when the context had no company, so the only thing stopping a
 * companyless user from reading every tenant was ResolveTenant's 403 — which
 * this feature has to relax. These tests pin both halves: the platform roles get
 * through and see across companies, and everything else still fails closed.
 */
class PlatformSupplierProcurementTest extends TestCase
{
    use RefreshDatabase;

    private AsabUser $admin;

    private AsabCompany $alpha;

    private AsabCompany $beta;

    protected function setUp(): void
    {
        parent::setUp();

        $this->alpha = AsabCompany::create(['name' => 'Alpha', 'plan' => 'Basic', 'status' => 'active']);
        $this->beta = AsabCompany::create(['name' => 'Beta', 'plan' => 'Basic', 'status' => 'active']);

        $this->admin = AsabUser::create([
            'name' => 'Platform Admin', 'email' => 'admin@asab.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->admin->id, 'role_key' => 'admin', 'scope' => 'all']);
    }

    private function user(string $email, string $role, ?string $companyId): AsabUser
    {
        $user = AsabUser::create([
            'company_id' => $companyId, 'name' => $email,
            'email' => $email, 'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $user->id, 'role_key' => $role, 'scope' => 'all']);

        return $user->fresh(['roleAssignments']);
    }

    private function purchaseOrder(AsabCompany $company, ?string $supplierId = null): Operation
    {
        return Operation::create([
            'public_id' => 'OP-'.substr(md5($company->id.$supplierId.microtime()), 0, 8),
            'company_id' => $company->id,
            'module_key' => 'purchases',
            'status' => Operation::STATUS_PENDING,
            'amount' => 1000,
            'operation_date' => now(),
            'payload' => $supplierId ? ['supplierId' => $supplierId] : [],
        ]);
    }

    // ---- the tenant scope: the part that must not leak ----

    public function test_a_companyless_non_platform_user_reads_nothing_instead_of_everything(): void
    {
        $this->purchaseOrder($this->alpha);
        $this->purchaseOrder($this->beta);

        // An accountant with no company is a misconfiguration, not a platform
        // account. The global scope must fail closed for it — before this change
        // it applied no filter at all and returned both companies' rows.
        $orphan = $this->user('orphan@asab.test', 'accountant', null);

        $this->actingAs($orphan, 'sanctum');
        app(\Modules\Admin\Support\TenantContext::class)->resolved = true;
        app(\Modules\Admin\Support\TenantContext::class)->companyId = null;

        $this->assertSame(0, Operation::count(), 'a companyless non-platform context must see no rows');
    }

    public function test_a_companyless_accountant_is_still_refused_by_the_middleware(): void
    {
        $orphan = $this->user('orphan2@asab.test', 'accountant', null);

        $this->actingAs($orphan, 'sanctum')
            ->getJson('/api/v1/operations')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'WRONG_TENANT');
    }

    public function test_a_platform_procurement_user_is_admitted(): void
    {
        $procurement = $this->user('proc@asab.test', 'procurement', null);

        $res = $this->actingAs($procurement, 'sanctum')->getJson('/api/v1/procurement/overview');

        $this->assertNotSame(403, $res->status(), 'a platform procurement account must not be treated as tenant-less');
    }

    public function test_a_platform_account_is_refused_on_the_company_surface(): void
    {
        $procurement = $this->user('proc2@asab.test', 'procurement', null);

        // /company/me answers "my company" and reads $user->company_id directly.
        $this->actingAs($procurement, 'sanctum')
            ->getJson('/api/v1/company/me/procurement/overview')
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'WRONG_TENANT');
    }

    public function test_a_platform_account_still_cannot_read_models_it_did_not_opt_into(): void
    {
        $branch = \Modules\Branch\Models\Branch::factory()->create(['asab_company_id' => $this->alpha->id]);
        Employee::create([
            'company_id' => $this->alpha->id, 'branch_id' => $branch->id, 'emp_number' => 'EMP-0001',
            'name' => 'موظف', 'role' => 'طباخ', 'status' => 'active',
        ]);

        $ctx = app(\Modules\Admin\Support\TenantContext::class);
        $ctx->resolved = true;
        $ctx->companyId = null;
        $ctx->isPlatform = true;
        $ctx->roleKey = 'procurement';

        // Operation and AsabSupplier opt in; Employee deliberately does not.
        $this->assertSame(0, Employee::count(), 'platform visibility must be opt-in per model');
    }

    // ---- platform suppliers ----

    public function test_creating_a_supplier_user_without_a_supplier_id_mints_a_platform_supplier(): void
    {
        Notification::fake();

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/users', [
            'name' => 'مورد المنصة',
            'email' => 'vendor@platform.test',
            'role' => 'supplier',
        ])->assertCreated();

        $supplier = AsabSupplier::withoutGlobalScope('tenant')
            ->where('contact_email', 'vendor@platform.test')->firstOrFail();

        $this->assertNull($supplier->company_id, 'a platform supplier belongs to no company');

        $user = AsabUser::where('email', 'vendor@platform.test')->firstOrFail();
        $this->assertSame($user->id, $supplier->user_id, 'the portal resolves orders through user_id');

        // ...and the mobile side exists, so the emailed password opens both.
        $this->assertNotNull($supplier->legacy_supplier_id);
        $this->assertNotNull(LegacySupplier::find($supplier->legacy_supplier_id));
    }

    public function test_every_company_can_see_a_platform_supplier(): void
    {
        Notification::fake();

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/users', [
            'name' => 'مورد مشترك',
            'email' => 'shared@platform.test',
            'role' => 'supplier',
        ])->assertCreated();

        // A supplier only Alpha added stays private to Alpha.
        AsabSupplier::create([
            'company_id' => $this->alpha->id, 'name' => 'مورد ألفا',
            'contact_email' => 'alpha-only@vendor.test', 'status' => 'active',
        ]);

        $ctx = app(\Modules\Admin\Support\TenantContext::class);
        $ctx->resolved = true;
        $ctx->isAdmin = false;
        $ctx->companyId = $this->beta->id;

        $names = AsabSupplier::pluck('name')->all();

        $this->assertContains('مورد مشترك', $names, 'a platform supplier must be orderable by every company');
        $this->assertNotContains('مورد ألفا', $names, "another company's private supplier must stay hidden");
    }

    public function test_a_company_login_must_still_name_its_supplier(): void
    {
        Notification::fake();

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/users', [
            'name' => 'مورد شركة',
            'email' => 'company-vendor@alpha.test',
            'role' => 'supplier',
            'companyId' => $this->alpha->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SUPPLIER_ID_REQUIRED');
    }

    public function test_a_company_login_cannot_claim_a_platform_supplier(): void
    {
        Notification::fake();

        $platform = AsabSupplier::create([
            'name' => 'مورد المنصة', 'contact_email' => 'claim@platform.test', 'status' => 'active',
        ]);
        $platform->forceFill(['company_id' => null])->save();

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/users', [
            'name' => 'محاولة استيلاء',
            'email' => 'claim@platform.test',
            'role' => 'supplier',
            'companyId' => $this->alpha->id,
            'supplierId' => $platform->id,
        ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SUPPLIER_NOT_IN_COMPANY');
    }

    public function test_a_platform_supplier_sees_its_orders_from_every_company(): void
    {
        Notification::fake();

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/users', [
            'name' => 'مورد عابر', 'email' => 'cross@platform.test', 'role' => 'supplier',
        ])->assertCreated();

        $supplier = AsabSupplier::withoutGlobalScope('tenant')
            ->where('contact_email', 'cross@platform.test')->firstOrFail();

        $mine = [
            $this->purchaseOrder($this->alpha, $supplier->id)->id,
            $this->purchaseOrder($this->beta, $supplier->id)->id,
        ];
        $notMine = $this->purchaseOrder($this->alpha, 'some-other-supplier')->id;

        $ctx = app(\Modules\Admin\Support\TenantContext::class);
        $ctx->resolved = true;
        $ctx->isAdmin = false;
        $ctx->companyId = null;
        $ctx->isPlatform = true;
        $ctx->roleKey = 'supplier';

        $visible = Operation::where('module_key', 'purchases')
            ->whereIn('payload->supplierId', [$supplier->id])
            ->pluck('id')->all();

        sort($mine);
        $sorted = $visible;
        sort($sorted);

        $this->assertSame($mine, $sorted, 'orders from BOTH companies must reach the supplier');
        $this->assertNotContains($notMine, $visible, "another supplier's order must never appear");
    }
}
