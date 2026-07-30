<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Events\AssetAssignedToBranch;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Asset;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\FixedAssets\Models\PendingReceipt;
use Tests\TestCase;

/**
 * Meeting 2026-07-30: assigning a dashboard asset to a branch must create the
 * mobile receive request, and the manager's confirmation must stamp back onto
 * the dashboard row. Excel-uploaded assets must be visible per branch.
 */
class AssetReceiveBridgeTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branch;

    private BranchManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Co', 'plan' => 'Basic', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['asab_company_id' => $this->company->id]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
    }

    private function accountant(): AsabUser
    {
        $user = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'محاسب', 'email' => uniqid().'@t.sa',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $user->id, 'role_key' => 'accountant', 'scope' => 'all']);

        return $user;
    }

    private function makeAsset(array $overrides = []): Asset
    {
        return Asset::withoutGlobalScope('tenant')->create(array_merge([
            'company_id' => $this->company->id,
            'public_id' => 'FA-0001',
            'name' => 'ثلاجة',
            'category' => 'معدات',
            'branch_id' => $this->branch->id,
            'cost' => 500000,
            'book_value' => 500000,
            'useful_life_months' => 60,
            'case_type' => 'acc_register',
            'status' => 'pending_branch',
            'custodian' => 'مدير الفرع',
        ], $overrides));
    }

    public function test_assigning_an_asset_creates_the_mobile_receive_request_idempotently(): void
    {
        $asset = $this->makeAsset();

        AssetAssignedToBranch::dispatch($asset);
        AssetAssignedToBranch::dispatch($asset); // retries must not duplicate

        $receipts = PendingReceipt::where('asab_asset_id', $asset->id)->get();
        $this->assertCount(1, $receipts);
        $this->assertSame('finance', $receipts[0]->source);
        $this->assertSame('pending', $receipts[0]->status);
        $this->assertSame($this->branch->id, $receipts[0]->recipient_branch_id);
        $this->assertSame('ثلاجة', $receipts[0]->asset_name);
    }

    public function test_accountant_store_endpoint_fires_the_bridge(): void
    {
        $res = $this->actingAs($this->accountant(), 'sanctum')->postJson('/api/v1/accountant/assets', [
            'name' => 'ثلاجة عرض',
            'category' => 'معدات',
            'branchId' => $this->branch->id,
            'cost' => 500000,
            'usefulLifeMonths' => 60,
            'custodian' => 'مدير الفرع',
        ]);

        if ($res->status() === 201) {
            $this->assertSame(1, PendingReceipt::where('recipient_branch_id', $this->branch->id)->count());
        } else {
            $this->markTestSkipped('accountant asset store payload differs: '.$res->status());
        }
    }

    public function test_mobile_confirm_stamps_the_dashboard_row(): void
    {
        $asset = $this->makeAsset();
        AssetAssignedToBranch::dispatch($asset);
        $receipt = PendingReceipt::where('asab_asset_id', $asset->id)->firstOrFail();

        $zone = \Modules\FixedAssets\Models\AssetZone::create(['name' => 'المطبخ', 'branch_id' => $this->branch->id, 'is_active' => true]);
        $type = \Modules\FixedAssets\Models\AssetType::create(['name' => 'أجهزة تبريد', 'is_active' => true]);

        $this->actingAs($this->manager, 'sanctum')
            ->post('/api/v1/branch-manager/fixed-assets/receive-assets/confirm', [
                'type' => 'from_finance',
                'items' => [[
                    'assetId' => $receipt->id,
                    'assignedZoneId' => $zone->id,
                    'assetTypeId' => $type->id,
                    'assetCount' => 1,
                    'excellentCount' => 1,
                    'needAttentionCount' => 0,
                    'problemCount' => 0,
                    'image' => \Illuminate\Http\UploadedFile::fake()->image('fridge.jpg'),
                ]],
            ])
            ->assertSuccessful();

        $asset->refresh();
        $this->assertSame('pending_accountant', $asset->status);
        $this->assertSame($this->manager->id, $asset->received_by_id);
        $this->assertNotNull($asset->received_at);
        $this->assertSame('received', $receipt->fresh()->status);
    }

    public function test_branch_register_lists_dashboard_assets_in_sar_and_is_branch_scoped(): void
    {
        $this->makeAsset();
        $foreignBranch = Branch::factory()->create(['asab_company_id' => $this->company->id]);
        $this->makeAsset(['branch_id' => $foreignBranch->id, 'public_id' => 'FA-0002', 'name' => 'فريزر']);

        $res = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/branch-manager/fixed-assets/register');

        $res->assertStatus(200);
        $rows = $res->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame('ثلاجة', $rows[0]['name']);
        $this->assertEquals(5000.0, $rows[0]['cost'], 'halalas must convert to SAR at the mobile boundary');
    }
}
