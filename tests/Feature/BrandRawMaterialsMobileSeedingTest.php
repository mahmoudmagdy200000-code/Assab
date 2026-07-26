<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Branch\Models\Branch;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\Item as PurchaseItem;
use Tests\TestCase;

/**
 * «رفعت مواد خام المشتريات ومش ظاهرة في الموبايل» — reported 2026-07-26.
 *
 * The mobile item list is `BranchItem::where('branch_id', <the user's branch>)`
 * (OrderDataService::getPurchasingOfficerItems), and the upload seeded only
 * `Branch::where('asab_brand_id', $brand->id)`. A branch whose hierarchy link
 * sits on `asab_restaurant_id` (or was never stamped) resolved to NOTHING, so
 * the whole mobile write-through was a silent no-op while the dashboard kept
 * showing «تم الرفع ✓».
 */
class BrandRawMaterialsMobileSeedingTest extends TestCase
{
    use RefreshDatabase;

    private AsabUser $admin;

    private AsabCompany $company;

    private AsabBrand $brand;

    private AsabRestaurant $restaurant;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = AsabUser::create([
            'name' => 'Platform Admin', 'email' => 'admin@seeding.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->admin->id, 'role_key' => 'admin', 'scope' => 'all']);

        $this->company = AsabCompany::create(['name' => 'Seed Co', 'plan' => 'Basic', 'status' => 'active']);
        $this->brand = AsabBrand::create([
            'company_id' => $this->company->id, 'name' => 'بازوكا', 'abbr' => 'BZ',
            'sub_status' => 'active', 'status' => 'active',
        ]);
        $this->restaurant = AsabRestaurant::create([
            'company_id' => $this->company->id, 'brand_id' => $this->brand->id,
            'name' => 'الخرج', 'status' => 'active',
        ]);
    }

    /**
     * `code` is the create-only match key for the shared `items` table, so a
     * second row needs its own code or it reuses (and keeps the name of) the
     * first item.
     */
    private function rawMaterialsCsv(string $name = 'دجاج', string $code = 'RM-1'): string
    {
        return "\xEF\xBB\xBF".'رمز المادة,اسم المادة,التصنيف,وحدة القياس,التكلفة'."\n"
            .$code.','.$name.',دواجن,KG,"20.00"'."\n";
    }

    private function upload(string $brandId, ?string $contents = null)
    {
        return $this->actingAs($this->admin, 'sanctum')->post(
            "/api/v1/admin/brands/{$brandId}/upload/raw-materials",
            ['file' => UploadedFile::fake()->createWithContent('raw.csv', $contents ?? $this->rawMaterialsCsv())],
        );
    }

    public function test_seeds_a_branch_linked_only_through_its_restaurant_and_backfills_the_brand(): void
    {
        $viaBrand = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
            'asab_restaurant_id' => $this->restaurant->id,
        ]);
        // The reported shape: the brand link was never stamped.
        $viaRestaurant = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => null,
            'asab_restaurant_id' => $this->restaurant->id,
        ]);

        $res = $this->upload($this->brand->id);

        $res->assertStatus(200)
            ->assertJsonPath('rowsImported', 1)
            ->assertJsonPath('branchesSeeded', 2)
            ->assertJsonPath('warnings', []);

        $item = PurchaseItem::where('name', 'دجاج')->firstOrFail();
        foreach ([$viaBrand, $viaRestaurant] as $branch) {
            $this->assertDatabaseHas('branch_item', ['branch_id' => $branch->id, 'item_id' => $item->id]);
        }
        // …and the missing link is repaired, so later reads are direct.
        $this->assertSame($this->brand->id, $viaRestaurant->fresh()->asab_brand_id);
    }

    public function test_never_seeds_a_branch_of_another_brand(): void
    {
        $mine = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
            'asab_restaurant_id' => $this->restaurant->id,
        ]);
        $otherBrand = AsabBrand::create([
            'company_id' => $this->company->id, 'name' => 'حية عنب', 'abbr' => 'HN',
            'sub_status' => 'active', 'status' => 'active',
        ]);
        $otherRestaurant = AsabRestaurant::create([
            'company_id' => $this->company->id, 'brand_id' => $otherBrand->id,
            'name' => 'المنز', 'status' => 'active',
        ]);
        $foreign = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => null,
            'asab_restaurant_id' => $otherRestaurant->id,
        ]);

        $this->upload($this->brand->id)->assertStatus(200)->assertJsonPath('branchesSeeded', 1);

        $item = PurchaseItem::where('name', 'دجاج')->firstOrFail();
        $this->assertDatabaseHas('branch_item', ['branch_id' => $mine->id, 'item_id' => $item->id]);
        $this->assertSame(0, BranchItem::where('branch_id', $foreign->id)->count());
        // The other brand's branch keeps its (absent) link — no cross-brand adoption.
        $this->assertNull($foreign->fresh()->asab_brand_id);
    }

    public function test_a_brand_with_no_branch_warns_instead_of_reading_as_published(): void
    {
        $res = $this->upload($this->brand->id);

        $res->assertStatus(200)
            ->assertJsonPath('rowsImported', 1)      // the catalog rows ARE saved
            ->assertJsonPath('branchesSeeded', 0)
            ->assertJsonPath('warnings.0.code', 'NO_BRANCHES_LINKED');

        $this->assertSame(0, BranchItem::count());
    }

    public function test_brand_upload_status_exposes_how_many_branches_the_catalog_reaches(): void
    {
        Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => null,
            'asab_restaurant_id' => $this->restaurant->id,
        ]);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/brands/{$this->brand->id}/upload-status")
            ->assertStatus(200)
            ->assertJsonPath('branchesLinked', 1);
    }

    /**
     * The escape hatch for a legacy branch: nothing but `store()` used to set the
     * hierarchy columns, so a branch created before the dashboard (all three
     * columns NULL) could never be attached to a brand — which made the
     * NO_BRANCHES_LINKED warning tell the admin to do something impossible.
     */
    public function test_an_unlinked_legacy_branch_can_be_attached_and_then_receives_the_catalog(): void
    {
        $legacyBranch = Branch::factory()->create([
            'asab_company_id' => null, 'asab_brand_id' => null, 'asab_restaurant_id' => null,
        ]);

        $this->upload($this->brand->id)->assertJsonPath('branchesSeeded', 0);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/branches/{$legacyBranch->id}", ['restaurantId' => $this->restaurant->id])
            ->assertStatus(200)
            ->assertJsonPath('restaurantId', $this->restaurant->id)
            ->assertJsonPath('brandId', $this->brand->id)
            ->assertJsonPath('companyId', $this->company->id);

        // Re-uploading now publishes to it (the mobile list is per branch).
        $this->upload($this->brand->id, $this->rawMaterialsCsv('لحم', 'RM-2'))
            ->assertStatus(200)
            ->assertJsonPath('branchesSeeded', 1);

        $item = PurchaseItem::where('name', 'لحم')->firstOrFail();
        $this->assertDatabaseHas('branch_item', ['branch_id' => $legacyBranch->id, 'item_id' => $item->id]);
    }

    /** Re-uploading must not duplicate the pivot rows. */
    public function test_reupload_is_idempotent_per_branch(): void
    {
        $branch = Branch::factory()->create([
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
            'asab_restaurant_id' => $this->restaurant->id,
        ]);

        $this->upload($this->brand->id)->assertStatus(200);
        $this->upload($this->brand->id)->assertStatus(200);

        $this->assertSame(1, BranchItem::where('branch_id', $branch->id)->count());
    }
}
