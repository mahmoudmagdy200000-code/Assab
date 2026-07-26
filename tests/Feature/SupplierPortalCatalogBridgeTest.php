<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\SupplierItem;
use Modules\Branch\Models\Branch;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\Item as PurchaseItem;
use Modules\Purchase\Models\SupplierItem as MobileSupplierItem;
use Modules\Supplier\Models\Supplier as LegacySupplier;
use Tests\TestCase;

/**
 * «المورد ضايف أصنافه من الداش بورد، وفلتر المورد في التطبيق بيرجع فاضي» —
 * reported 2026-07-26.
 *
 * The portal wrote `asab_supplier_items` and stopped there: it never stamped the
 * company/supplier the mobile projection needs, and never called the bridge the
 * procurement surface has always called. So the item existed on the dashboard
 * only — the app's item list reads `branch_item`, and its per-supplier list
 * reads `supplier_items`.
 */
class SupplierPortalCatalogBridgeTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabUser $supplierUser;

    private AsabSupplier $supplier;

    private Branch $branch;

    private LegacySupplier $legacy;

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

        $this->company = AsabCompany::create(['name' => 'Bridge Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->supplierUser = $this->supplierUser('portal@sup.test', $this->company->id);
        $this->legacy = LegacySupplier::create([
            'name' => 'شركة الدواجن الوطنية',
            'email' => 'legacy@sup.test',
            'phone' => '0551110000',
            'password' => bcrypt('secret-password'),
            'is_active' => true,
            'is_first_login' => false,
        ]);
        // legacy_supplier_id is guarded (the bridge writes it with forceFill).
        $this->supplier = AsabSupplier::create([
            'company_id' => $this->company->id,
            'name' => 'شركة الدواجن الوطنية',
            'user_id' => $this->supplierUser->id,
            'status' => 'active',
        ]);
        $this->supplier->forceFill(['legacy_supplier_id' => $this->legacy->id])->save();
        $this->branch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'name' => 'فرع الملز',
        ]);
    }

    private function supplierUser(string $email, ?string $companyId): AsabUser
    {
        $user = AsabUser::create([
            'company_id' => $companyId,
            'name' => 'مورد',
            'email' => $email,
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $user->id, 'role_key' => 'supplier', 'scope' => 'all']);

        return $user;
    }

    private function addItem(?AsabUser $as = null, array $body = [])
    {
        return $this->actingAs($as ?? $this->supplierUser, 'sanctum')
            ->postJson('/api/v1/asab/supplier/items', array_merge([
                'name' => 'دجاج',
                'code' => 'RM-9',
                'unit' => 'KG',
                'priceHalalas' => 200000,
                'minQty' => 200,
                'maxQty' => 1000,
                'leadTimeDays' => 1,
                'available' => true,
            ], $body));
    }

    // ---- the reported case ----

    public function test_an_item_added_in_the_portal_reaches_both_mobile_lists(): void
    {
        $this->addItem()
            ->assertStatus(201)
            ->assertJsonPath('publishedToApp', true)
            ->assertJsonPath('warnings', []);

        $item = SupplierItem::firstOrFail();
        // The columns the mobile projection needs, which the portal never set.
        $this->assertSame($this->company->id, $item->company_id);
        $this->assertSame($this->supplier->id, $item->supplier_id);

        // A: «All Items» reads branch_item of the user's branch.
        $mobile = PurchaseItem::where('name', 'دجاج')->firstOrFail();
        $this->assertSame($mobile->id, $item->purchase_item_id);
        $this->assertDatabaseHas('branch_item', ['branch_id' => $this->branch->id, 'item_id' => $mobile->id]);

        // B: «Filter by supplier» reads supplier_items, priced and available.
        $priced = MobileSupplierItem::where('supplier_id', $this->legacy->id)
            ->where('item_id', $mobile->id)->firstOrFail();
        $this->assertTrue((bool) $priced->is_available);
        $this->assertSame('2000.00', (string) $priced->unit_price); // 200000 halalas
    }

    public function test_editing_an_item_publishes_one_created_before_the_bridge(): void
    {
        // A pre-fix row: no company, no supplier, never bridged.
        $legacyRow = SupplierItem::create([
            'supplier_user_id' => $this->supplierUser->id,
            'name' => 'أرز', 'price' => 100000, 'available' => true, 'status' => 'active',
        ]);

        $this->actingAs($this->supplierUser, 'sanctum')
            ->patchJson("/api/v1/asab/supplier/items/{$legacyRow->id}", ['unit' => 'KG'])
            ->assertStatus(200)
            ->assertJsonPath('publishedToApp', true);

        $legacyRow->refresh();
        $this->assertSame($this->company->id, $legacyRow->company_id);
        $this->assertSame($this->supplier->id, $legacyRow->supplier_id);
        $mobile = PurchaseItem::where('name', 'أرز')->firstOrFail();
        $this->assertDatabaseHas('branch_item', ['branch_id' => $this->branch->id, 'item_id' => $mobile->id]);
    }

    public function test_toggling_an_item_off_hides_it_from_the_app(): void
    {
        $this->addItem()->assertStatus(201);
        $item = SupplierItem::firstOrFail();

        $this->actingAs($this->supplierUser, 'sanctum')
            ->postJson("/api/v1/asab/supplier/items/{$item->id}/toggle-active")
            ->assertStatus(200)
            ->assertJsonPath('available', false);

        $mobile = PurchaseItem::withTrashed()->where('name', 'دجاج')->firstOrFail();
        $this->assertFalse((bool) $mobile->is_active);
        $this->assertFalse((bool) MobileSupplierItem::where('item_id', $mobile->id)->firstOrFail()->is_available);
    }

    public function test_deleting_an_item_removes_it_from_the_branch_lists(): void
    {
        $this->addItem()->assertStatus(201);
        $item = SupplierItem::firstOrFail();
        $mobileId = $item->purchase_item_id;

        $this->actingAs($this->supplierUser, 'sanctum')
            ->deleteJson("/api/v1/asab/supplier/items/{$item->id}")
            ->assertStatus(204);

        $this->assertSoftDeleted('items', ['id' => $mobileId]);
        $this->assertSame(0, BranchItem::where('item_id', $mobileId)->count());
        $this->assertFalse((bool) MobileSupplierItem::where('item_id', $mobileId)->firstOrFail()->is_available);
    }

    // ---- what cannot be published, said out loud ----

    public function test_a_platform_supplier_is_told_no_branch_list_was_touched(): void
    {
        $platformUser = $this->supplierUser('platform@sup.test', null);
        AsabSupplier::create([
            'company_id' => null,
            'name' => 'مورد منصة',
            'user_id' => $platformUser->id,
            'status' => 'active',
        ])->forceFill(['legacy_supplier_id' => $this->legacy->id])->save();

        $this->addItem($platformUser, ['name' => 'زيت', 'code' => 'RM-OIL'])
            ->assertStatus(201)
            ->assertJsonPath('publishedToApp', false)
            ->assertJsonPath('warnings.0.code', 'NO_BRANCH_AVAILABILITY');

        // …and crucially it did NOT fan out over every company-less branch:
        // Eloquent turns where(col, null) into whereNull(col).
        $unlinked = Branch::factory()->create(['asab_company_id' => null]);
        $mobile = PurchaseItem::where('name', 'زيت')->firstOrFail();
        $this->assertSame(0, BranchItem::where('item_id', $mobile->id)->count());
        $this->assertSame(0, BranchItem::where('branch_id', $unlinked->id)->count());
    }

    public function test_a_login_with_no_supplier_record_is_refused_with_the_reason(): void
    {
        $orphan = $this->supplierUser('orphan@sup.test', $this->company->id);

        $this->addItem($orphan)
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'SUPPLIER_RECORD_MISSING');

        $this->assertSame(0, SupplierItem::count());
    }

    public function test_a_login_owning_several_records_must_name_the_one(): void
    {
        $second = AsabSupplier::create([
            'company_id' => $this->company->id,
            'name' => 'سجل ثانٍ',
            'user_id' => $this->supplierUser->id,
            'status' => 'active',
        ]);

        $this->addItem()
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'SUPPLIER_RECORD_AMBIGUOUS');

        $this->addItem(null, ['supplierId' => $second->id])
            ->assertStatus(201);

        $this->assertSame($second->id, SupplierItem::firstOrFail()->supplier_id);
    }
}
