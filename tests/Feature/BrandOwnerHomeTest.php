<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\Expense\Models\Expense;
use Tests\Concerns\LinksMobileBrandScope;
use Tests\TestCase;

/**
 * Covers the Brand Owner Home dashboard API (brand-owner-home-doc.md).
 */
class BrandOwnerHomeTest extends TestCase
{
    use LinksMobileBrandScope, RefreshDatabase;

    private Branch $branch;

    private BranchManager $manager;

    private BrandOwner $owner;

    private \Modules\Admin\Models\AsabBrand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create(['name' => 'Branch 1']);
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
        $this->owner = BrandOwner::create([
            'name' => 'Owner One',
            'email' => 'owner@example.com',
            'phone' => '0500000000',
            'password' => 'secret-password',
            'is_active' => true,
            'is_first_login' => false,
            'status' => 'active',
        ]);

        // Mobile branch lists are brand-isolated, so the owner must own a brand
        // this branch belongs to (see MobileBranchScopeService).
        $this->brand = $this->linkBrandOwner($this->owner, $this->branch);
    }

    public function test_dashboard_branches_returns_branch_list(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/brand-owner/dashboard/branches');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [['id', 'name', 'image_url', 'opening_hours', 'google_map_url']],
            ])
            ->assertJsonPath('data.0.id', $this->branch->id)
            ->assertJsonPath('data.0.name', 'Branch 1');
    }

    public function test_dashboard_returns_full_structure(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/brand-owner/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'approved_invoices',
                    'total_invoice_amount',
                    'pending_invoices',
                    'approved_expenses',
                    'total_expense_amount',
                    'pending_expenses',
                    'granularity_chart_data' => [
                        'daily' => ['trend_period', 'approved', 'total_amount', 'increase_percentage', 'chart_data'],
                        'weekly' => ['trend_period', 'approved', 'total_amount', 'increase_percentage', 'chart_data'],
                        'monthly' => ['trend_period', 'approved', 'total_amount', 'increase_percentage', 'chart_data'],
                    ],
                    'response_month',
                    'response_year',
                    'response_branch_name',
                ],
            ])
            ->assertJsonPath('data.response_branch_name', 'Branch 1')
            ->assertJsonPath('data.response_month', (int) now()->month)
            ->assertJsonPath('data.response_year', (int) now()->year);

        // Monthly granularity always covers the 12 months of the year.
        $this->assertCount(12, $response->json('data.granularity_chart_data.monthly.chart_data'));
    }

    public function test_dashboard_counts_invoices_and_expenses(): void
    {
        Expense::factory()->count(2)->create([
            'branch_manager_id' => $this->manager->id,
            'expense_type' => 'single_invoice',
            'status' => 'approved',
            'total_amount' => 1000,
        ]);
        Expense::factory()->create([
            'branch_manager_id' => $this->manager->id,
            'expense_type' => 'grouped_invoice',
            'status' => 'pending',
            'total_amount' => 500,
        ]);
        Expense::factory()->count(3)->create([
            'branch_manager_id' => $this->manager->id,
            'expense_type' => 'quick_cash',
            'status' => 'approved',
            'total_amount' => 200,
        ]);
        Expense::factory()->create([
            'branch_manager_id' => $this->manager->id,
            'expense_type' => 'quick_cash',
            'status' => 'pending',
            'total_amount' => 75,
        ]);

        $response = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/brand-owner/dashboard');

        $response->assertStatus(200)
            ->assertJsonPath('data.approved_invoices', 2)
            ->assertJsonPath('data.pending_invoices', 1)
            ->assertJsonPath('data.approved_expenses', 3)
            ->assertJsonPath('data.pending_expenses', 1);

        // Totals reflect approved spend only.
        $this->assertEquals(2000.0, $response->json('data.total_invoice_amount'));
        $this->assertEquals(600.0, $response->json('data.total_expense_amount'));

        // The trend chart covers every approved expense row (invoices + quick cash).
        $this->assertEquals(2600.0, $response->json('data.granularity_chart_data.monthly.total_amount'));
    }

    public function test_dashboard_filters_by_branch(): void
    {
        $otherBranch = Branch::factory()->create(['name' => 'Branch 2']);
        $this->tagBranchesWithBrand($this->brand, $otherBranch);
        $otherManager = BranchManager::factory()->create(['branch_id' => $otherBranch->id]);

        Expense::factory()->create([
            'branch_manager_id' => $this->manager->id,
            'expense_type' => 'single_invoice',
            'status' => 'approved',
            'total_amount' => 1000,
        ]);
        Expense::factory()->count(4)->create([
            'branch_manager_id' => $otherManager->id,
            'expense_type' => 'single_invoice',
            'status' => 'approved',
            'total_amount' => 1000,
        ]);

        $response = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/brand-owner/dashboard?branch_id='.$this->branch->id);

        $response->assertStatus(200)
            ->assertJsonPath('data.approved_invoices', 1)
            ->assertJsonPath('data.response_branch_name', 'Branch 1');
    }

    public function test_dashboard_validates_query_parameters(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/brand-owner/dashboard?month=13&granularity=yearly');

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_dashboard_is_forbidden_for_non_brand_owner(): void
    {
        $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/brand-owner/dashboard')
            ->assertStatus(403)
            ->assertJsonPath('success', false);

        $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/brand-owner/dashboard/branches')
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }
}
