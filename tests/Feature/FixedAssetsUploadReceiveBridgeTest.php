<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Asset;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\FixedAssets\Models\PendingReceipt;
use Tests\TestCase;

/**
 * Meeting 2026-08-03 «لا توجد بيانات الأصول الثابتة التي تم رفعها عبر الداشبورد
 * لفرع الريان 1»: the bulk importer wrote asab_assets and stopped. Only the
 * single-asset paths dispatched AssetAssignedToBranch, so the phone's «طلبات
 * الاستلام» stayed empty and the branch asset screen read 0 — for assets the
 * dashboard had already stored.
 */
class FixedAssetsUploadReceiveBridgeTest extends TestCase
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
            'name' => 'Platform Admin', 'email' => 'admin@assets-bridge.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->admin->id, 'role_key' => 'admin', 'scope' => 'all']);

        $this->company = AsabCompany::create(['name' => 'جورمية', 'plan' => 'Basic', 'status' => 'active']);
        $this->brand = AsabBrand::create([
            'company_id' => $this->company->id, 'name' => 'جورمية', 'abbr' => 'GRM',
            'sub_status' => 'active', 'status' => 'active',
        ]);
        $this->restaurant = AsabRestaurant::create([
            'company_id' => $this->company->id, 'brand_id' => $this->brand->id,
            'name' => 'الرياض', 'status' => 'active',
        ]);
    }

    /** @param  array<int, array{0:string, 1:string}>  $rows  [asset name, branch name] */
    private function assetsCsv(array $rows): string
    {
        $csv = "\xEF\xBB\xBF".'اسم الأصل,الفئة,اسم الفرع,رقم الفاتورة,التكلفة (ر.س),العمر الافتراضي (شهر),أمين العهدة,ملاحظات'."\n";
        foreach ($rows as [$name, $branchName]) {
            $csv .= $name.',معدات مطبخ,'.$branchName.',INV-1,"10,000.00",60,أحمد,'."\n";
        }

        return $csv;
    }

    private function upload(string $url, string $contents)
    {
        return $this->actingAs($this->admin, 'sanctum')
            ->post($url, ['file' => UploadedFile::fake()->createWithContent('assets.csv', $contents)]);
    }

    private function branch(string $name = 'الريان 1'): Branch
    {
        return Branch::factory()->create([
            'name' => $name,
            'asab_company_id' => $this->company->id,
            'asab_brand_id' => $this->brand->id,
            'asab_restaurant_id' => $this->restaurant->id,
        ]);
    }

    public function test_a_branch_upload_creates_the_mobile_receive_request(): void
    {
        $branch = $this->branch();
        BranchManager::factory()->create(['branch_id' => $branch->id]);

        $this->upload("/api/v1/admin/branches/{$branch->id}/upload/fixed-assets", $this->assetsCsv([['ثلاجة', '']]))
            ->assertStatus(200)
            ->assertJsonPath('assetCount', 1);

        $asset = Asset::withoutGlobalScopes()->firstWhere('name', 'ثلاجة');
        $this->assertNotNull($asset);

        $receipt = PendingReceipt::where('asab_asset_id', $asset->id)->first();
        $this->assertNotNull($receipt, 'the branch never received a «طلب استلام» for the uploaded asset');
        $this->assertSame($branch->id, $receipt->recipient_branch_id);
        $this->assertSame('pending', $receipt->status);
    }

    public function test_re_uploading_the_same_register_does_not_duplicate_receive_requests(): void
    {
        $branch = $this->branch();
        $csv = $this->assetsCsv([['فرن', '']]);

        $this->upload("/api/v1/admin/branches/{$branch->id}/upload/fixed-assets", $csv)->assertStatus(200);
        $this->upload("/api/v1/admin/branches/{$branch->id}/upload/fixed-assets", $csv)->assertStatus(200);

        // Serial-less rows create a second asset (documented importer rule), but
        // each asset carries exactly one receive request.
        $assetIds = Asset::withoutGlobalScopes()->where('name', 'فرن')->pluck('id');
        foreach ($assetIds as $id) {
            $this->assertSame(1, PendingReceipt::where('asab_asset_id', $id)->count());
        }
    }

    /** «اسم الفرع» in a brand-level upload now places the row in that branch. */
    public function test_a_brand_upload_routes_rows_to_the_named_branch(): void
    {
        $rayan = $this->branch('الريان 1');
        $other = $this->branch('النخيل');

        $this->upload("/api/v1/admin/brands/{$this->brand->id}/upload/fixed-assets", $this->assetsCsv([
            ['ثلاجة', 'الريان 1'],
            ['فرن', 'النخيل'],
            ['ميزان', 'فرع غير معروف'],
        ]))->assertStatus(200)->assertJsonPath('assetCount', 3);

        $this->assertSame($rayan->id, Asset::withoutGlobalScopes()->firstWhere('name', 'ثلاجة')->branch_id);
        $this->assertSame($other->id, Asset::withoutGlobalScopes()->firstWhere('name', 'فرن')->branch_id);
        // An unmatched name is not an error — the row stays brand-level.
        $this->assertNull(Asset::withoutGlobalScopes()->firstWhere('name', 'ميزان')->branch_id);

        $this->assertSame(1, PendingReceipt::where('recipient_branch_id', $rayan->id)->count());
        $this->assertSame(1, PendingReceipt::where('recipient_branch_id', $other->id)->count());
    }

    /** The prod heal for registers uploaded before the bridge existed. */
    public function test_the_backfill_command_creates_missing_receive_requests(): void
    {
        $branch = $this->branch();
        $asset = Asset::withoutGlobalScope('tenant')->create([
            'company_id' => $this->company->id, 'public_id' => 'FA-900', 'name' => 'خلاط',
            'category' => 'معدات', 'branch_id' => $branch->id, 'cost' => 100000, 'book_value' => 100000,
            'useful_life_months' => 60, 'case_type' => 'branch_upload', 'status' => 'pending_branch',
        ]);

        $this->assertSame(0, PendingReceipt::where('asab_asset_id', $asset->id)->count());

        $this->artisan('asab:bridge-backfill')->assertSuccessful();

        $this->assertSame(1, PendingReceipt::where('asab_asset_id', $asset->id)->count());
    }
}
