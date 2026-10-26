<?php

namespace Tests\Feature\Company;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\AssetDraft;
use Modules\Admin\Models\CompanyUser;
use Tests\TestCase;

class CompanyAssetDraftsRouteTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $companyA;
    private AsabCompany $companyB;
    private AsabUser $accountantA;
    private AsabUser $accountantB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->companyA = AsabCompany::create(['name' => 'Company A', 'plan' => 'Professional', 'status' => 'active']);
        $brandA = AsabBrand::create(['company_id' => $this->companyA->id, 'name' => 'Brand A', 'sub_status' => 'active', 'status' => 'active']);

        $this->accountantA = AsabUser::create([
            'company_id' => $this->companyA->id,
            'name' => 'Accountant A',
            'email' => 'accA@test.com',
            'password' => 'secret',
            'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->accountantA->id, 'role_key' => 'accountant', 'scope' => 'all']);
        CompanyUser::create([
            'company_id' => $this->companyA->id,
            'user_id' => $this->accountantA->id,
            'role_key' => 'accountant',
            'status' => 'active',
        ]);

        $this->companyB = AsabCompany::create(['name' => 'Company B', 'plan' => 'Professional', 'status' => 'active']);
        $brandB = AsabBrand::create(['company_id' => $this->companyB->id, 'name' => 'Brand B', 'sub_status' => 'active', 'status' => 'active']);

        $this->accountantB = AsabUser::create([
            'company_id' => $this->companyB->id,
            'name' => 'Accountant B',
            'email' => 'accB@test.com',
            'password' => 'secret',
            'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->accountantB->id, 'role_key' => 'accountant', 'scope' => 'all']);
        CompanyUser::create([
            'company_id' => $this->companyB->id,
            'user_id' => $this->accountantB->id,
            'role_key' => 'accountant',
            'status' => 'active',
        ]);
    }

    public function test_unauthenticated_user_cannot_access_company_asset_drafts(): void
    {
        $response = $this->getJson('/api/v1/company/me/asset-drafts');
        $response->assertStatus(401);
    }

    public function test_company_asset_drafts_returns_tenant_scoped_drafts(): void
    {
        $draftA = AssetDraft::create([
            'draft_id' => 'AD-AAA1',
            'company_id' => $this->companyA->id,
            'asset_name' => 'Espresso Machine Company A',
            'category' => 'kitchen',
            'amount' => 500000,
            'useful_life_months' => 36,
            'status' => 'draft',
            'target_branches' => null,
            'created_by_id' => $this->accountantA->id,
        ]);

        $draftB = AssetDraft::create([
            'draft_id' => 'AD-BBB1',
            'company_id' => $this->companyB->id,
            'asset_name' => 'Oven Company B',
            'category' => 'kitchen',
            'amount' => 800000,
            'useful_life_months' => 48,
            'status' => 'draft',
            'target_branches' => null,
            'created_by_id' => $this->accountantB->id,
        ]);

        $response = $this->actingAs($this->accountantA, 'sanctum')
            ->getJson('/api/v1/company/me/asset-drafts');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertSame('AD-AAA1', $data[0]['draftId']);
        $this->assertSame('Espresso Machine Company A', $data[0]['assetName'] ?? $data[0]['name'] ?? null);

        // And verify accountant B sees only their company's drafts
        $responseB = $this->actingAs($this->accountantB, 'sanctum')
            ->getJson('/api/v1/company/me/asset-drafts');

        $responseB->assertStatus(200);
        $dataB = $responseB->json('data');
        $this->assertCount(1, $dataB);
        $this->assertSame('AD-BBB1', $dataB[0]['draftId']);
    }

    public function test_company_asset_draft_can_be_discarded_via_delete_method(): void
    {
        $draft = AssetDraft::create([
            'draft_id' => 'AD-DEL1',
            'company_id' => $this->companyA->id,
            'asset_name' => 'Delete Me Draft',
            'category' => 'kitchen',
            'amount' => 300000,
            'useful_life_months' => 24,
            'status' => 'draft',
            'target_branches' => null,
            'created_by_id' => $this->accountantA->id,
        ]);

        $response = $this->actingAs($this->accountantA, 'sanctum')
            ->deleteJson("/api/v1/company/me/asset-drafts/{$draft->draft_id}");

        $response->assertStatus(204);
        $this->assertSame('discarded', $draft->fresh()->status);
    }
}
