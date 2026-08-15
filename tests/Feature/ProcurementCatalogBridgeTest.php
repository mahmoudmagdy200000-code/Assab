<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\SupplierItem as AsabSupplierItem;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\Item as PurchaseItem;
use Modules\Purchase\Models\SupplierItem as MobileSupplierItem;
use Modules\Supplier\Models\Supplier as LegacySupplier;
use Tests\TestCase;

/**
 * Catalog write-through bridge (WS5): items/suppliers the purchasing manager
 * creates on the dashboard must materialize in the mobile Purchase world
 * (items / branch_item / supplier_items / suppliers) so the app's order flows
 * can see them — with the upload bridge's create-only collision semantics.
 */
class ProcurementCatalogBridgeTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabUser $manager;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = AsabCompany::create(['name' => 'Bridge Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->manager = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'مدير المشتريات',
            'email' => 'procurement@bridge.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->manager->id, 'role_key' => 'procurement', 'scope' => 'all']);
        $this->branch = Branch::factory()->create(['asab_company_id' => $this->company->id]);
    }

    private function as()
    {
        return $this->actingAs($this->manager, 'sanctum');
    }

    /** @return array{asabId: string, legacyId: string} */
    private function createSupplier(array $overrides = []): array
    {
        $res = $this->as()->postJson('/api/v1/company/me/procurement/suppliers', array_merge([
            'name' => 'شركة الدواجن الوطنية',
            'category' => 'لحوم ودواجن',
            'contactPhone' => '0553421100',
            'contactEmail' => 'supplier@bridge.test',
        ], $overrides));
        $res->assertCreated();

        $asab = AsabSupplier::withoutGlobalScope('tenant')->findOrFail($res->json('id'));

        return ['asabId' => $asab->id, 'legacyId' => (string) $asab->legacy_supplier_id];
    }

    // ---- Items ----

    public function test_store_item_creates_mobile_item_and_seeds_branch_rows(): void
    {
        $res = $this->as()->postJson('/api/v1/company/me/procurement/items', [
            'name' => 'دجاج طازج', 'unit' => 'كجم', 'category' => 'لحوم ودواجن',
            'lastPriceHalalas' => 2400, 'code' => 'CHK-01',
        ]);
        $res->assertCreated();

        $asabItem = AsabSupplierItem::findOrFail($res->json('id'));
        $this->assertNotNull($asabItem->purchase_item_id, 'asab item must remember its mobile row');

        $mobile = PurchaseItem::findOrFail($asabItem->purchase_item_id);
        $this->assertSame('دجاج طازج', $mobile->name);
        $this->assertSame('CHK-01', $mobile->code);
        $this->assertSame('كجم', $mobile->unit);
        $this->assertTrue((bool) $mobile->is_active);

        // getPurchasingOfficerItems paginates branch_item of the branch — a seeded row makes it surface there.
        $branchItem = BranchItem::where('branch_id', $this->branch->id)->where('item_id', $mobile->id)->first();
        $this->assertNotNull($branchItem, 'item must be seeded into the tenant branches');
        $this->assertEquals(24.00, (float) $branchItem->price);
    }

    public function test_store_item_with_linked_supplier_creates_priced_mobile_row(): void
    {
        $supplier = $this->createSupplier();

        $res = $this->as()->postJson('/api/v1/company/me/procurement/items', [
            'name' => 'أرز بسمتي', 'unit' => 'كيس', 'lastPriceHalalas' => 8500,
            'supplierId' => $supplier['asabId'], 'code' => 'RCE-01',
        ]);
        $res->assertCreated();

        $asabItem = AsabSupplierItem::findOrFail($res->json('id'));
        $priced = MobileSupplierItem::where('supplier_id', $supplier['legacyId'])
            ->where('item_id', $asabItem->purchase_item_id)->first();

        $this->assertNotNull($priced, 'supplier_items row must exist for the linked supplier');
        $this->assertEquals(85.00, (float) $priced->unit_price);
        $this->assertTrue((bool) $priced->is_available);
    }

    public function test_update_item_syncs_name_and_price_to_mobile_rows(): void
    {
        $supplier = $this->createSupplier();
        $created = $this->as()->postJson('/api/v1/company/me/procurement/items', [
            'name' => 'زيت زيتون', 'unit' => 'لتر', 'lastPriceHalalas' => 4000,
            'supplierId' => $supplier['asabId'], 'code' => 'OIL-01',
        ]);
        $created->assertCreated();
        $asabItem = AsabSupplierItem::findOrFail($created->json('id'));

        $this->as()->patchJson('/api/v1/company/me/procurement/items/'.$asabItem->id, [
            'name' => 'زيت زيتون بكر', 'lastPriceHalalas' => 5500,
        ])->assertOk();

        $this->assertSame('زيت زيتون بكر', PurchaseItem::findOrFail($asabItem->purchase_item_id)->name);
        $priced = MobileSupplierItem::where('supplier_id', $supplier['legacyId'])
            ->where('item_id', $asabItem->purchase_item_id)->firstOrFail();
        $this->assertEquals(55.00, (float) $priced->unit_price);
    }

    public function test_destroy_item_deactivates_mobile_rows_without_deleting(): void
    {
        $supplier = $this->createSupplier();
        $created = $this->as()->postJson('/api/v1/company/me/procurement/items', [
            'name' => 'سكر أبيض', 'unit' => 'كيس', 'lastPriceHalalas' => 1200,
            'supplierId' => $supplier['asabId'], 'code' => 'SGR-01',
        ]);
        $created->assertCreated();
        $asabItem = AsabSupplierItem::findOrFail($created->json('id'));

        $this->as()->deleteJson('/api/v1/company/me/procurement/items/'.$asabItem->id)->assertNoContent();

        $mobile = PurchaseItem::withTrashed()->findOrFail($asabItem->purchase_item_id);
        $this->assertTrue($mobile->trashed(), 'mobile item must be soft-deleted, never hard-deleted');
        $this->assertFalse((bool) $mobile->is_active);

        $priced = MobileSupplierItem::where('supplier_id', $supplier['legacyId'])
            ->where('item_id', $asabItem->purchase_item_id)->first();
        $this->assertNotNull($priced, 'priced row must survive (other flows may reference it)');
        $this->assertFalse((bool) $priced->is_available);

        // The bridge's own zero-quantity seeds must go, or the joined
        // purchasing-officer list keeps showing a null-named ghost row.
        $this->assertSame(
            0,
            BranchItem::where('branch_id', $this->branch->id)->where('item_id', $asabItem->purchase_item_id)->count(),
            'zero-quantity branch_item seeds must be removed on destroy'
        );
    }

    public function test_destroy_item_keeps_branch_rows_holding_real_stock(): void
    {
        $created = $this->as()->postJson('/api/v1/company/me/procurement/items', [
            'name' => 'ملح خشن', 'unit' => 'كيس', 'lastPriceHalalas' => 300, 'code' => 'SLT-01',
        ]);
        $created->assertCreated();
        $asabItem = AsabSupplierItem::findOrFail($created->json('id'));

        BranchItem::where('branch_id', $this->branch->id)
            ->where('item_id', $asabItem->purchase_item_id)
            ->update(['quantity' => 7]);

        $this->as()->deleteJson('/api/v1/company/me/procurement/items/'.$asabItem->id)->assertNoContent();

        $this->assertNotNull(
            BranchItem::where('branch_id', $this->branch->id)->where('item_id', $asabItem->purchase_item_id)->first(),
            'branch rows with real stock must never be deleted by the bridge'
        );
    }

    public function test_destroyed_item_disappears_from_mobile_purchasing_officer_list(): void
    {
        $created = $this->as()->postJson('/api/v1/company/me/procurement/items', [
            'name' => 'طحين فاخر', 'unit' => 'كيس', 'lastPriceHalalas' => 1800, 'code' => 'FLR-01',
        ]);
        $created->assertCreated();
        $asabItem = AsabSupplierItem::findOrFail($created->json('id'));

        $mobileManager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);

        $before = $this->actingAs($mobileManager, 'sanctum')
            ->getJson('/api/v1/purchase/orders/purchasing-officer-items');
        $before->assertOk();
        $this->assertStringContainsString('FLR-01', $before->getContent(), 'sanity: item surfaces in the app list first');

        $this->as()->deleteJson('/api/v1/company/me/procurement/items/'.$asabItem->id)->assertNoContent();

        $after = $this->actingAs($mobileManager, 'sanctum')
            ->getJson('/api/v1/purchase/orders/purchasing-officer-items');
        $after->assertOk();
        $this->assertStringNotContainsString($asabItem->purchase_item_id, $after->getContent(), 'destroyed item must not linger as a ghost row');
        $this->assertStringNotContainsString('FLR-01', $after->getContent());
    }

    /**
     * `items.code` is UNIQUE, so a supplier item whose code already exists can
     * never mint its own row. Skipping it left the supplier with NO priced row
     * — a full dashboard catalog above an empty «choose supplier» list in the
     * app (2026-08-15). The shared row is linked and left exactly as it was.
     */
    public function test_live_code_collision_links_without_clobbering(): void
    {
        $supplier = $this->createSupplier();
        $other = PurchaseItem::create(['name' => 'صنف علامة أخرى', 'code' => 'SHARED-01', 'unit' => 'kg', 'is_active' => true]);

        $res = $this->as()->postJson('/api/v1/company/me/procurement/items', [
            'name' => 'صنفنا نحن', 'unit' => 'كجم', 'lastPriceHalalas' => 900, 'code' => 'SHARED-01',
            'supplierId' => $supplier['asabId'],
        ]);
        $res->assertCreated();

        $asabItem = AsabSupplierItem::findOrFail($res->json('id'));
        $this->assertSame($other->id, $asabItem->purchase_item_id, 'the shared row must be linked, not skipped');
        $this->assertFalse((bool) $asabItem->owns_purchase_item, 'a shared row is referenced, never owned');

        $this->assertSame('صنف علامة أخرى', $other->fresh()->name, 'other brand row must be untouched');
        $this->assertSame('kg', $other->fresh()->unit);
        $this->assertSame(1, PurchaseItem::withTrashed()->where('code', 'SHARED-01')->count());

        $priced = MobileSupplierItem::where('supplier_id', $supplier['legacyId'])
            ->where('item_id', $other->id)->first();
        $this->assertNotNull($priced, 'the supplier must still get its priced row for the shared item');
        $this->assertEquals(9.00, (float) $priced->unit_price);
    }

    /** Destroying a shared item drops OUR price row and leaves the item alive. */
    public function test_destroying_a_shared_item_never_deletes_the_other_catalogs_row(): void
    {
        $supplier = $this->createSupplier();
        $other = PurchaseItem::create(['name' => 'أرز الآخرين', 'code' => 'SHARED-02', 'unit' => 'kg', 'is_active' => true]);

        $created = $this->as()->postJson('/api/v1/company/me/procurement/items', [
            'name' => 'أرزنا', 'unit' => 'كجم', 'lastPriceHalalas' => 1000, 'code' => 'SHARED-02',
            'supplierId' => $supplier['asabId'],
        ]);
        $created->assertCreated();
        $asabItem = AsabSupplierItem::findOrFail($created->json('id'));

        $this->as()->deleteJson('/api/v1/company/me/procurement/items/'.$asabItem->id)->assertNoContent();

        $shared = PurchaseItem::withTrashed()->findOrFail($other->id);
        $this->assertFalse($shared->trashed(), 'a shared row must survive our delete');
        $this->assertTrue((bool) $shared->is_active);

        $priced = MobileSupplierItem::where('supplier_id', $supplier['legacyId'])
            ->where('item_id', $other->id)->firstOrFail();
        $this->assertFalse((bool) $priced->is_available, 'our price row must drop out of the picker');
    }

    public function test_soft_deleted_foreign_row_is_not_resurrected_or_renamed(): void
    {
        $other = PurchaseItem::create(['name' => 'سكر خام', 'code' => 'SUG-01', 'unit' => 'kg', 'is_active' => true]);
        $other->delete(); // another brand deleted its item

        $res = $this->as()->postJson('/api/v1/company/me/procurement/items', [
            'name' => 'Fine Sugar', 'unit' => 'كجم', 'lastPriceHalalas' => 1500, 'code' => 'SUG-01',
        ]);
        $res->assertCreated();

        $asabItem = AsabSupplierItem::findOrFail($res->json('id'));
        $this->assertNull($asabItem->purchase_item_id, 'trashed foreign match must not be claimed');

        $foreign = PurchaseItem::withTrashed()->findOrFail($other->id);
        $this->assertTrue($foreign->trashed(), 'foreign row must stay deleted');
        $this->assertSame('سكر خام', $foreign->name, 'foreign row must not be renamed');
    }

    public function test_recreating_own_destroyed_item_restores_and_relinks_its_mobile_row(): void
    {
        $first = $this->as()->postJson('/api/v1/company/me/procurement/items', [
            'name' => 'قهوة مطحونة', 'unit' => 'كجم', 'lastPriceHalalas' => 6000, 'code' => 'COF-01',
        ]);
        $first->assertCreated();
        $firstAsab = AsabSupplierItem::findOrFail($first->json('id'));
        $mobileId = $firstAsab->purchase_item_id;

        $this->as()->deleteJson('/api/v1/company/me/procurement/items/'.$firstAsab->id)->assertNoContent();

        $second = $this->as()->postJson('/api/v1/company/me/procurement/items', [
            'name' => 'قهوة مطحونة فاخرة', 'unit' => 'كجم', 'lastPriceHalalas' => 6500, 'code' => 'COF-01',
        ]);
        $second->assertCreated();

        $secondAsab = AsabSupplierItem::findOrFail($second->json('id'));
        $this->assertSame($mobileId, $secondAsab->purchase_item_id, 'own trashed row must be reused, not duplicated');

        $mobile = PurchaseItem::findOrFail($mobileId); // findOrFail = restored
        $this->assertSame('قهوة مطحونة فاخرة', $mobile->name);
        $this->assertTrue((bool) $mobile->is_active);
        $this->assertNotNull(
            BranchItem::where('branch_id', $this->branch->id)->where('item_id', $mobileId)->first(),
            'branch seeds must be recreated on restore'
        );
    }

    // ---- Suppliers ----

    public function test_store_supplier_provisions_legacy_login_capable_supplier(): void
    {
        $supplier = $this->createSupplier(['contactEmail' => 'chicken@bridge.test', 'contactPhone' => '0551112233']);

        $this->assertNotSame('', $supplier['legacyId']);
        $legacy = LegacySupplier::findOrFail($supplier['legacyId']);
        $this->assertSame('شركة الدواجن الوطنية', $legacy->name);
        $this->assertSame('chicken@bridge.test', $legacy->email);
        $this->assertSame('0551112233', $legacy->phone);
        $this->assertTrue((bool) $legacy->is_active);
        // Password provisioned + hashed (portal hidden per client meeting; nothing emailed).
        $this->assertNotEmpty($legacy->password);
        $this->assertTrue(strlen($legacy->password) > 20);
    }

    public function test_toggle_supplier_syncs_legacy_active_state(): void
    {
        $supplier = $this->createSupplier();

        $this->as()->postJson('/api/v1/company/me/procurement/suppliers/'.$supplier['asabId'].'/toggle-active')->assertOk();
        $this->assertFalse((bool) LegacySupplier::findOrFail($supplier['legacyId'])->is_active);

        $this->as()->postJson('/api/v1/company/me/procurement/suppliers/'.$supplier['asabId'].'/toggle-active')->assertOk();
        $this->assertTrue((bool) LegacySupplier::findOrFail($supplier['legacyId'])->is_active);
    }

    public function test_update_supplier_syncs_name_and_phone_to_legacy_row(): void
    {
        $supplier = $this->createSupplier();

        $this->as()->patchJson('/api/v1/company/me/procurement/suppliers/'.$supplier['asabId'], [
            'name' => 'شركة الدواجن الذهبية', 'contactPhone' => '0559998877',
        ])->assertOk();

        $legacy = LegacySupplier::findOrFail($supplier['legacyId']);
        $this->assertSame('شركة الدواجن الذهبية', $legacy->name);
        $this->assertSame('0559998877', $legacy->phone);
    }

    public function test_supplier_email_owned_by_another_company_is_rejected(): void
    {
        $otherCompany = AsabCompany::create(['name' => 'Other Co', 'plan' => 'Basic', 'status' => 'active']);
        $legacy = LegacySupplier::create([
            'name' => 'مورد شركة أخرى', 'email' => 'taken@bridge.test', 'password' => 'irrelevant-password', 'is_active' => true,
        ]);
        AsabSupplier::create(['company_id' => $otherCompany->id, 'name' => 'مورد شركة أخرى', 'status' => 'active'])
            ->forceFill(['legacy_supplier_id' => $legacy->id])->save();

        $res = $this->as()->postJson('/api/v1/company/me/procurement/suppliers', [
            'name' => 'محاولة استيلاء', 'contactEmail' => 'taken@bridge.test',
        ]);

        $res->assertStatus(422);
        // Transaction rolled back: no dashboard supplier row leaked for our company.
        $this->assertNull(
            AsabSupplier::withoutGlobalScope('tenant')
                ->where('company_id', $this->company->id)->where('name', 'محاولة استيلاء')->first()
        );
    }

    public function test_supplier_email_matching_unclaimed_legacy_supplier_links_it(): void
    {
        $legacy = LegacySupplier::create([
            'name' => 'مورد قديم', 'email' => 'legacy@bridge.test', 'password' => 'irrelevant-password', 'is_active' => true,
        ]);

        $supplier = $this->createSupplier(['contactEmail' => 'legacy@bridge.test', 'contactPhone' => null]);

        $this->assertSame($legacy->id, $supplier['legacyId']);
        $this->assertSame(1, LegacySupplier::withTrashed()->where('email', 'legacy@bridge.test')->count());
    }

    // ---- Meeting 2026-07-30: supplier link must never drop silently ----

    public function test_priceless_item_still_links_to_its_supplier(): void
    {
        $supplier = $this->createSupplier();

        $res = $this->as()->postJson('/api/v1/company/me/procurement/items', [
            'name' => 'بيتزا', 'unit' => 'كجم', 'lastPriceHalalas' => 0,
            'supplierId' => $supplier['asabId'], 'code' => 'PZA-01',
        ]);
        $res->assertCreated();

        $asabItem = AsabSupplierItem::findOrFail($res->json('id'));
        $priced = MobileSupplierItem::where('supplier_id', $supplier['legacyId'])
            ->where('item_id', $asabItem->purchase_item_id)->first();

        $this->assertNotNull($priced, 'a min/avg-only (price 0) item must STILL belong to its supplier');
        $this->assertTrue((bool) $priced->is_available);
    }

    public function test_platform_supplier_item_links_despite_null_company(): void
    {
        $legacy = LegacySupplier::create([
            'name' => 'مورد عصب', 'email' => 'asab-supplier@bridge.test',
            'password' => 'irrelevant-password', 'is_active' => true,
        ]);
        $platform = AsabSupplier::withoutGlobalScope('tenant')->create([
            'company_id' => null, 'name' => 'مورد عصب', 'status' => 'active',
        ]);
        $platform->forceFill(['legacy_supplier_id' => $legacy->id])->save();

        $item = AsabSupplierItem::withoutGlobalScopes()->create([
            'company_id' => $this->company->id,
            'name' => 'بيتزا', 'unit' => 'كجم', 'price' => 5000,
            'supplier_id' => $platform->id, 'status' => 'active',
        ]);

        app(\Modules\Admin\Services\ProcurementCatalogBridgeService::class)->syncItem($item->fresh());

        $mobileItemId = $item->fresh()->purchase_item_id;
        $this->assertNotNull($mobileItemId);
        $this->assertNotNull(
            MobileSupplierItem::where('supplier_id', $legacy->id)->where('item_id', $mobileItemId)->first(),
            'a PLATFORM supplier (company_id NULL) must still get its supplier_items link'
        );
    }
}
