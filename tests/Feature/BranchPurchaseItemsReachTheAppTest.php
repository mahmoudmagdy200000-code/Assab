<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\InventoryCatalogItem;
use Modules\Admin\Services\RawMaterialBranchSyncService;
use Modules\Branch\Models\Branch;
use Modules\Inventory\Models\DailyInventorySchedule;
use Modules\Inventory\Models\DailyInventoryScheduleItem;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\Item as PurchaseItem;
use Tests\TestCase;

/**
 * Meeting 2026-08-05, branch manager of «الريان»:
 *  1. the accountant's daily-list selection did not show on Daily Quick
 *     Inventory («0 products»);
 *  2. purchase items were missing from Waste & Damage;
 *  3. the New Purchase Order picker showed 15 items instead of 30;
 *  4. every item rendered «(Kg)» whatever unit was uploaded.
 */
class BranchPurchaseItemsReachTheAppTest extends TestCase
{
    use RefreshDatabase;

    private AsabBrand $brand;

    private AsabCompany $company;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'جورمية', 'plan' => 'Professional', 'status' => 'active']);
        $this->brand = AsabBrand::create([
            'company_id' => $this->company->id, 'name' => 'جورمية كافيه', 'abbr' => 'GC',
            'sub_status' => 'active', 'status' => 'active',
        ]);
        $this->branch = Branch::factory()->create([
            'name' => 'الريان 1', 'asab_company_id' => $this->company->id, 'asab_brand_id' => $this->brand->id,
        ]);
    }

    private function rawMaterial(string $name, string $unit, string $code, string $category = 'مواد خام'): InventoryCatalogItem
    {
        return InventoryCatalogItem::create([
            'brand_id' => $this->brand->id, 'type' => InventoryCatalogItem::TYPE_RAW_MATERIAL,
            'name' => $name, 'code' => $code, 'category' => $category, 'unit' => $unit,
            'unit_price' => 1500, 'status' => 'active',
        ]);
    }

    private function accountant(): AsabUser
    {
        $user = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'محاسب', 'email' => 'acc@branchitems.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $user->id, 'role_key' => 'accountant', 'scope' => 'brand',
            'brand_ids' => [$this->brand->id], 'module_keys' => ['inventory'],
        ]);

        return $user;
    }

    /**
     * (1) The manager's screen reads the schedule through an `active()` scope.
     * A deactivated row swallowed the accountant's selection silently.
     */
    public function test_a_deactivated_schedule_is_reactivated_by_a_daily_list_save(): void
    {
        $stale = DailyInventorySchedule::create([
            'branch_id' => $this->branch->id, 'start_date' => now()->subMonth()->toDateString(),
            'start_time' => '20:00', 'is_active' => false,
        ]);
        $ids = [$this->rawMaterial('صدور دجاج', 'كجم', 'RM-002')->id];

        $this->actingAs($this->accountant(), 'sanctum')
            ->putJson("/api/v1/accountant/inventory/branches/{$this->branch->id}/daily-list", ['items' => $ids])
            ->assertSuccessful()
            ->assertJsonPath('appListCount', 1);

        $this->assertTrue($stale->fresh()->is_active, 'the app hides an inactive schedule — the save must revive it');
        $this->assertSame(1, DailyInventoryScheduleItem::where('daily_inventory_schedule_id', $stale->id)->count());
    }

    /** (4) The uploaded unit survives the bridge — «كجم» must not become «kg». */
    public function test_the_uploaded_unit_is_carried_to_the_mobile_item(): void
    {
        $litre = $this->rawMaterial('زيت قلي نباتي', 'لتر', 'RM-017');

        $this->actingAs($this->accountant(), 'sanctum')
            ->putJson("/api/v1/accountant/inventory/branches/{$this->branch->id}/daily-list", ['items' => [$litre->id]])
            ->assertSuccessful();

        $this->assertSame('لتر', PurchaseItem::firstWhere('code', 'RM-017')?->unit);
    }

    /** …and a legacy row that predates the unit column gets its blank filled. */
    public function test_a_blank_unit_on_an_existing_mobile_item_is_filled_from_the_catalog(): void
    {
        $legacy = PurchaseItem::create(['name' => 'بصل أبيض', 'code' => 'RM-010', 'unit' => null, 'is_active' => true]);
        $this->rawMaterial('بصل أبيض', 'كجم', 'RM-010');

        app(RawMaterialBranchSyncService::class)->syncBrand($this->brand);

        $this->assertSame('كجم', $legacy->fresh()->unit);
    }

    /** …but a populated unit belonging to another brand's row is never rewritten. */
    public function test_a_populated_unit_is_not_overwritten(): void
    {
        $legacy = PurchaseItem::create(['name' => 'سكر', 'code' => 'RM-030', 'unit' => 'جوال', 'is_active' => true]);
        $this->rawMaterial('سكر', 'كجم', 'RM-030');

        app(RawMaterialBranchSyncService::class)->syncBrand($this->brand);

        $this->assertSame('جوال', $legacy->fresh()->unit);
    }

    /**
     * (3) A branch created AFTER the brand catalog was uploaded starts empty:
     * the upload only seeds the branches that existed at the time.
     */
    public function test_a_branch_added_after_the_upload_is_seeded_with_the_brands_materials(): void
    {
        foreach (range(1, 3) as $i) {
            $this->rawMaterial("مادة {$i}", 'كجم', "RM-10{$i}");
        }

        $late = Branch::factory()->create([
            'name' => 'الريان 2', 'asab_company_id' => $this->company->id, 'asab_brand_id' => $this->brand->id,
        ]);
        $this->assertSame(0, BranchItem::where('branch_id', $late->id)->count());

        app(RawMaterialBranchSyncService::class)->syncBranch($late);

        $this->assertSame(3, BranchItem::where('branch_id', $late->id)->count());
    }

    /** Branches linked through a restaurant count as the brand's too. */
    public function test_restaurant_linked_branches_are_seeded(): void
    {
        $restaurant = AsabRestaurant::withoutGlobalScopes()->create([
            'company_id' => $this->company->id, 'brand_id' => $this->brand->id, 'name' => 'مطعم التحلية',
        ]);
        $viaRestaurant = Branch::factory()->create([
            'asab_company_id' => $this->company->id, 'asab_brand_id' => null,
            'asab_restaurant_id' => $restaurant->id,
        ]);
        $this->rawMaterial('جبنة سويسرية شرائح', 'كجم', 'RM-005');

        app(RawMaterialBranchSyncService::class)->syncBrand($this->brand);

        $this->assertSame(1, BranchItem::where('branch_id', $viaRestaurant->id)->count());
    }

    /**
     * (3) 30 assigned materials must arrive as 30, not as the first page of 15
     * — the picker inherited the ORDER-LIST page size.
     */
    public function test_the_app_picker_returns_every_assigned_item_not_a_page_of_fifteen(): void
    {
        foreach (range(1, 30) as $i) {
            $this->rawMaterial('مادة '.$i, 'كجم', sprintf('RM-%03d', $i));
        }
        app(RawMaterialBranchSyncService::class)->syncBrand($this->brand);

        $manager = \Modules\BranchManagers\Models\BranchManager::factory()->create(['branch_id' => $this->branch->id]);

        $res = $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/purchase/orders/purchasing-officer-items')
            ->assertOk();

        $this->assertCount(30, $res->json('data'));
    }

    /** …and the uploaded unit is what the picker shows. */
    public function test_the_app_picker_shows_the_uploaded_unit(): void
    {
        $this->rawMaterial('زيت قلي نباتي', 'لتر', 'RM-017');
        app(RawMaterialBranchSyncService::class)->syncBrand($this->brand);

        $manager = \Modules\BranchManagers\Models\BranchManager::factory()->create(['branch_id' => $this->branch->id]);

        $row = collect($this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/purchase/orders/purchasing-officer-items')
            ->assertOk()
            ->json('data'))
            ->firstWhere('item_code', 'RM-017');

        $this->assertSame('لتر', $row['item_unit']);
    }

    /**
     * A save that never reaches the app must say so — the endpoint used to
     * report success while the branch stayed empty.
     */
    public function test_the_save_reports_whether_it_reached_the_app(): void
    {
        $ids = [$this->rawMaterial('صوص حار', 'كجم', 'RM-016')->id];

        $this->actingAs($this->accountant(), 'sanctum')
            ->putJson("/api/v1/accountant/inventory/branches/{$this->branch->id}/daily-list", ['items' => $ids])
            ->assertSuccessful()
            ->assertJsonPath('appListCount', 1)
            ->assertJsonPath('appListError', null);
    }

    /** The ops doctor prints the chain instead of crashing on real data. */
    public function test_the_inventory_doctor_explains_the_branch(): void
    {
        $ids = [$this->rawMaterial('زبدة', 'كجم', 'RM-021')->id];
        $this->actingAs($this->accountant(), 'sanctum')
            ->putJson("/api/v1/accountant/inventory/branches/{$this->branch->id}/daily-list", ['items' => $ids])
            ->assertSuccessful();

        $this->artisan('asab:inventory-doctor', ['--branch' => $this->branch->id])
            ->assertExitCode(0);

        $this->artisan('asab:inventory-doctor', ['--brand' => 'جورمية كافيه'])
            ->assertExitCode(0);

        // An unknown target is a clear failure, not a silent success.
        $this->artisan('asab:inventory-doctor', ['--branch' => 'no-such-branch'])
            ->assertExitCode(1);
    }

    /**
     * The decisive lookup when the branch data is healthy but the phone shows
     * nothing: a duplicate manager login pointing at another branch.
     */
    public function test_the_doctor_follows_a_login_phone_to_the_branch_it_opens(): void
    {
        $other = Branch::factory()->create(['name' => 'التعاون 1', 'asab_company_id' => $this->company->id]);

        // `branch_managers.phone` is unique, so a duplicate person carries a
        // second phone — the name is what gives the twin away.
        \Modules\BranchManagers\Models\BranchManager::factory()->create([
            'name' => 'زكريا صبري', 'phone' => '0558544750', 'branch_id' => $this->branch->id,
        ]);
        \Modules\BranchManagers\Models\BranchManager::factory()->create([
            'name' => 'زكريا صبري 2', 'phone' => '0558544751', 'branch_id' => $other->id,
        ]);

        // The second login opens a DIFFERENT branch than the one the accountant
        // configured — which is what an empty screen on a healthy branch means.
        $this->artisan('asab:inventory-doctor', ['--phone' => '0558544751'])
            ->expectsOutputToContain('التعاون 1')
            ->assertExitCode(0);

        $this->artisan('asab:inventory-doctor', ['--phone' => '0500000000'])
            ->assertExitCode(1);
    }

    /** Re-running the sweep never duplicates a pivot row. */
    public function test_seeding_is_idempotent(): void
    {
        $this->rawMaterial('صوص باربكيو', 'كجم', 'RM-015');
        $sync = app(RawMaterialBranchSyncService::class);

        $sync->syncBrand($this->brand);
        $sync->syncBrand($this->brand);

        $this->assertSame(1, BranchItem::where('branch_id', $this->branch->id)->count());
        $this->assertSame(1, PurchaseItem::where('code', 'RM-015')->count());
    }
}
