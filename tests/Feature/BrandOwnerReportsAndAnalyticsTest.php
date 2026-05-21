<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\Custody\Models\CustodyTransaction;
use Modules\Expense\Models\Expense;
use Modules\Expense\Models\Supplier;
use Tests\TestCase;

/**
 * Covers the Brand Owner Reports & Analytics API
 * (brand-owner-reports-and-analytics-doc.md).
 */
class BrandOwnerReportsAndAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private BranchManager $manager;

    private BrandOwner $owner;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
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
    }

    public function test_reports_and_analytics_returns_reports_and_export_history(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/brand-owner/reports-and-analytics');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'reports' => [['id', 'type', 'period_label', 'status_label']],
                    'export_history',
                ],
            ])
            ->assertJsonPath('data.reports.0.type', 'expenses')
            ->assertJsonPath('data.reports.1.type', 'custody');
    }

    public function test_branches_endpoint_returns_branch_list(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/brand-owner/branches');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['branches' => [['id', 'name', 'managerName']]]])
            ->assertJsonPath('data.branches.0.id', $this->branch->id)
            ->assertJsonPath('data.branches.0.managerName', $this->manager->name);
    }

    public function test_expense_report_details_returns_full_structure(): void
    {
        $supplier = Supplier::factory()->create();
        Expense::factory()->count(3)->create([
            'branch_manager_id' => $this->manager->id,
            'expense_type' => 'quick_cash',
            'status' => 'approved',
            'supplier_id' => $supplier->id,
        ]);

        $response = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/brand-owner/reports/expense/expenses?type=quick_cash');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'summary' => ['branch', 'period_label', 'total_requests', 'total_amount', 'trend_percent', 'trend_label', 'trend_is_up'],
                    'payment_methods',
                    'top_suppliers',
                    'expense_ratios',
                    'branch_comparisons',
                    'cash_transfer_log' => ['total_cash_in', 'total_cash_out', 'count', 'logs'],
                    'quick_summary' => ['amount', 'trend_percent', 'trend_label', 'trend_is_up'],
                    'default_branch',
                    'filters' => ['type', 'status', 'month', 'year', 'branch_id', 'branch_name'],
                ],
            ])
            ->assertJsonPath('data.summary.total_requests', 3)
            ->assertJsonPath('data.filters.type', 'quick_cash');
    }

    public function test_custody_report_details_returns_branches(): void
    {
        CustodyTransaction::create([
            'branch_manager_id' => $this->manager->id,
            'branch_id' => $this->branch->id,
            'type' => 'Cash Transfer',
            'handover_method' => 'Cash Handover',
            'amount' => 5000,
            'is_cash_in' => true,
            'transaction_date' => now(),
        ]);

        $response = $this->actingAs($this->owner, 'sanctum')
            ->getJson('/api/brand-owner/reports/custody/custody');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'period_label',
                    'branches' => [[
                        'image_url', 'name', 'branch', 'current_balance',
                        'amounts' => ['month_name', 'month_opening_balance', 'total_cash_in', 'total_cash_out', 'current_balance'],
                    ]],
                ],
            ])
            ->assertJsonPath('data.branches.0.amounts.total_cash_in', 5000);
    }

    public function test_export_expense_report_creates_file_and_history(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/brand-owner/reports/expense/export', [
                'expense_type' => 'quick_cash',
                'year' => (int) now()->year,
                'month_number' => (int) now()->month,
                'format_type' => 'Excel',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['file_url']]);

        $this->assertDatabaseHas('brand_owner_report_exports', [
            'brand_owner_id' => $this->owner->id,
            'report_kind' => 'expenses',
            'format' => 'excel',
        ]);
    }

    public function test_export_custody_report_creates_file_and_history(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/brand-owner/reports/custody/export', [
                'year' => (int) now()->year,
                'month_number' => (int) now()->month,
                'format_type' => 'Excel',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure(['data' => ['file_url']]);

        $this->assertDatabaseHas('brand_owner_report_exports', [
            'brand_owner_id' => $this->owner->id,
            'report_kind' => 'custody',
        ]);
    }

    public function test_export_expense_report_validates_body(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')
            ->postJson('/api/brand-owner/reports/expense/export', [
                'expense_type' => 'invalid',
                'month_number' => 13,
                'format_type' => 'Word',
            ]);

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_non_brand_owner_is_forbidden(): void
    {
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/brand-owner/reports-and-analytics');

        $response->assertStatus(403)
            ->assertJsonPath('success', false);
    }
}
