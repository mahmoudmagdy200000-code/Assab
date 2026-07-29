<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Operation;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * Meeting 2026-07-29 «فلترة بحسب العلامة التجارية»: the operations list (sales /
 * expenses tabs) must narrow to one brand's branches via ?brandId=.
 */
class OperationBrandFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_brand_id_narrows_the_list_and_the_summary(): void
    {
        $company = AsabCompany::create(['name' => 'Filter Co', 'plan' => 'Professional', 'status' => 'active']);
        $brandA = AsabBrand::create(['company_id' => $company->id, 'name' => 'براند أ', 'sub_status' => 'active', 'status' => 'active']);
        $brandB = AsabBrand::create(['company_id' => $company->id, 'name' => 'براند ب', 'sub_status' => 'active', 'status' => 'active']);
        $branchA = Branch::factory()->create(['asab_brand_id' => $brandA->id, 'asab_company_id' => $company->id]);
        $branchB = Branch::factory()->create(['asab_brand_id' => $brandB->id, 'asab_company_id' => $company->id]);

        foreach ([['EXP-A', $branchA], ['EXP-B', $branchB]] as [$publicId, $branch]) {
            Operation::withoutGlobalScopes()->create([
                'public_id' => $publicId,
                'company_id' => $company->id,
                'branch_id' => $branch->id,
                'module_key' => 'expenses',
                'payload' => [],
                'amount' => 1000,
                'origin' => 'mobile',
                'status' => Operation::STATUS_PENDING,
                'operation_date' => now(),
                'submitted_at' => now(),
            ]);
        }

        $head = AsabUser::create([
            'company_id' => $company->id, 'name' => 'رئيس الحسابات', 'email' => 'head@filter.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $head->id, 'role_key' => 'head', 'scope' => 'all']);

        $response = $this->actingAs($head, 'sanctum')
            ->getJson("/api/v1/operations?brandId={$brandA->id}")
            ->assertOk();

        $this->assertCount(1, $response->json('data'));
        $this->assertSame('EXP-A', $response->json('data.0.publicId') ?? $response->json('data.0.public_id'));
        $this->assertSame(1, $response->json('meta.summary.total'));

        // No filter → both brands' rows.
        $this->actingAs($head, 'sanctum')
            ->getJson('/api/v1/operations')
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }
}
