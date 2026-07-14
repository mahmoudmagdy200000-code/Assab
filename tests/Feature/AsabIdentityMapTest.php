<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabIdentityMap;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Employee;
use Modules\Admin\Services\BrandOwnerProvisioningService;
use Modules\Admin\Services\IdentityMapService;
use Modules\Admin\Services\ProcurementCatalogBridgeService;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Tests\TestCase;

/**
 * Cross-world identity map (WS2): the map is dual-written by the three
 * provisioning services (cashier / supplier / brand-owner) and can be seeded
 * from existing linkage by the backfill command. branch_manager is out of v1.
 */
class AsabIdentityMapTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branch;

    private BranchManager $legacyManager;

    private AsabUser $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->company = AsabCompany::create(['name' => 'Map Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['asab_company_id' => $this->company->id]);
        $this->legacyManager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
        $this->manager = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'مدير الفرع',
            'email' => 'bm@map.test', 'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->manager->id, 'role_key' => 'branch', 'scope' => 'branch', 'branch_ids' => [$this->branch->id]]);
    }

    private function map(): IdentityMapService
    {
        return app(IdentityMapService::class);
    }

    // ── Service: link + lookups ──────────────────────────────────────────────

    public function test_link_resolves_both_directions(): void
    {
        $this->map()->link('cashier', 'asab_employee', 'emp-1', 'cashier', 'cash-1', $this->company->id, 'email', 'x@map.test');

        $this->assertSame('cash-1', $this->map()->legacyIdFor('cashier', 'emp-1'));
        $this->assertSame('emp-1', $this->map()->dashboardIdFor('cashier', 'cash-1'));
        $this->assertNull($this->map()->legacyIdFor('cashier', 'unknown'));
    }

    public function test_link_is_idempotent_on_reprovision(): void
    {
        $this->map()->link('supplier', 'asab_supplier', 'sup-1', 'supplier', 'legacy-a', $this->company->id);
        $this->map()->link('supplier', 'asab_supplier', 'sup-1', 'supplier', 'legacy-b', $this->company->id); // re-link

        $this->assertSame(1, AsabIdentityMap::where('entity_type', 'supplier')->where('dashboard_id', 'sup-1')->count());
        $this->assertSame('legacy-b', $this->map()->legacyIdFor('supplier', 'sup-1'));
    }

    // ── Dual-write from the provisioners ─────────────────────────────────────

    public function test_cashier_provision_writes_the_map(): void
    {
        $this->actingAs($this->manager, 'sanctum')->postJson('/api/v1/company/me/branch/employees', [
            'name' => 'سارة', 'role' => 'Cashier', 'salaryHalalas' => 450000, 'email' => 'sara@map.test',
        ])->assertCreated();

        $emp = Employee::withoutGlobalScopes()->first();
        $cashier = Cashier::where('email', 'sara@map.test')->first();
        $row = AsabIdentityMap::where('entity_type', 'cashier')->where('dashboard_id', $emp->id)->first();
        $this->assertNotNull($row);
        $this->assertSame($cashier->id, $row->legacy_id);
        $this->assertSame('asab_employee', $row->dashboard_type);
    }

    public function test_supplier_provision_writes_the_map(): void
    {
        $sup = AsabSupplier::create([
            'company_id' => $this->company->id, 'name' => 'مورد', 'category' => 'خضار',
            'contact_email' => 'vendor@map.test', 'contact_phone' => '+966500000010', 'status' => 'active',
        ]);

        app(ProcurementCatalogBridgeService::class)->provisionSupplier($sup);

        $legacyId = $sup->fresh()->legacy_supplier_id;
        $this->assertNotNull($legacyId);
        $row = AsabIdentityMap::where('entity_type', 'supplier')->where('dashboard_id', $sup->id)->first();
        $this->assertNotNull($row);
        $this->assertSame($legacyId, $row->legacy_id);
        $this->assertSame('vendor@map.test', $row->linked_email);
    }

    public function test_brand_owner_provision_writes_the_map(): void
    {
        $brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'علامة', 'status' => 'active']);

        $result = app(BrandOwnerProvisioningService::class)->provision($brand, 'owner@map.test', 'المالك');

        $legacyOwner = \Modules\BrandOwner\Models\BrandOwner::where('email', 'owner@map.test')->first();
        $row = AsabIdentityMap::where('entity_type', 'brand_owner')->where('dashboard_id', $result['user']->id)->first();
        $this->assertNotNull($row);
        $this->assertSame($legacyOwner->id, $row->legacy_id);
        $this->assertSame('email', $row->match_method);
    }

    // ── Backfill ─────────────────────────────────────────────────────────────

    public function test_backfill_seeds_from_existing_links_and_email_match(): void
    {
        // Cashier: employee carrying a stored legacy_cashier_id.
        $emp = Employee::create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id, 'emp_number' => '9001', 'name' => 'ك', 'role' => 'كاشير', 'status' => 'active']);
        $legacyCashierId = (string) Str::uuid();
        $emp->forceFill(['legacy_cashier_id' => $legacyCashierId])->save();

        // Supplier: asab supplier carrying a stored legacy_supplier_id.
        $sup = AsabSupplier::create(['company_id' => $this->company->id, 'name' => 'م', 'category' => 'ع', 'contact_phone' => '+966500000020', 'status' => 'active']);
        $legacySupplierId = (string) Str::uuid();
        $sup->forceFill(['legacy_supplier_id' => $legacySupplierId])->save();

        // Brand owner: matched purely by shared email.
        $owner = \Modules\BrandOwner\Models\BrandOwner::create(['name' => 'مالك', 'email' => 'shared@map.test', 'password' => 'pw', 'is_active' => true, 'is_first_login' => true, 'status' => 'active']);
        $user = AsabUser::create(['company_id' => $this->company->id, 'name' => 'مالك', 'email' => 'shared@map.test', 'password' => 'secret-password', 'status' => 'active']);

        $counts = $this->map()->backfill();

        $this->assertGreaterThanOrEqual(1, $counts['cashier']);
        $this->assertGreaterThanOrEqual(1, $counts['supplier']);
        $this->assertGreaterThanOrEqual(1, $counts['brand_owner']);

        $this->assertSame($legacyCashierId, $this->map()->legacyIdFor('cashier', $emp->id));
        $this->assertSame($legacySupplierId, $this->map()->legacyIdFor('supplier', $sup->id));
        $this->assertSame($owner->id, $this->map()->legacyIdFor('brand_owner', $user->id));
        $this->assertSame('backfill', AsabIdentityMap::where('dashboard_id', $emp->id)->value('source'));
    }
}
