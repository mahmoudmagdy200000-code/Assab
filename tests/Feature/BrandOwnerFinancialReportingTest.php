<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Modules\Aggregator\Models\Aggregator;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BrandOwner\Mail\FinancialReportMail;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\Cashier\Models\Cashier;
use Modules\Expense\Models\Expense;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\Item;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftSalesBreakdown;
use Tests\TestCase;

/**
 * Covers the Brand Owner Financial Reporting API
 * (docs/tasks/brand-owner-financial-reporting-doc).
 *
 * Seeds real revenue (cashier_shifts + shift_sales_breakdown), costs (expenses)
 * and menu items (branch_item) so the computed figures — not just the shapes —
 * can be asserted against the documented P&L mapping.
 */
class BrandOwnerFinancialReportingTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private BranchManager $manager;

    private BrandOwner $owner;

    private Aggregator $aggregator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create(['name' => 'Riyadh Branch']);
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

        $this->seedRevenue();
        $this->seedCosts();
        $this->seedMenu();
    }

    // ----------------------------------------------------------------
    // Seeders
    // ----------------------------------------------------------------

    /** Two completed shifts totalling 500,000 turnover, split across one channel. */
    private function seedRevenue(): void
    {
        $shift = Shift::factory()->create(['branch_id' => $this->branch->id]);
        $cashier = Cashier::factory()->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
        ]);

        $this->aggregator = Aggregator::factory()->create([
            'name' => 'Delivery A',
            'commission_rate' => 10,
        ]);

        foreach ([300000.0, 200000.0] as $i => $sales) {
            $cs = CashierShift::factory()->create([
                'cashier_id' => $cashier->id,
                'shift_id' => $shift->id,
                'shift_date' => now()->startOfMonth()->addDays($i)->toDateString(),
                'status' => ShiftStatus::COMPLETED,
                'total_sales' => $sales,
                'net_sales' => $sales * 0.85,
                'vat_amount' => $sales * 0.15,
            ]);

            ShiftSalesBreakdown::create([
                'cashier_shift_id' => $cs->id,
                'aggregator_id' => $this->aggregator->id,
                'amount' => $sales,
            ]);
        }
    }

    /** 200,000 direct cost (approved invoices) + 80,000 operating (quick cash). */
    private function seedCosts(): void
    {
        Expense::factory()->create([
            'branch_manager_id' => $this->manager->id,
            'expense_type' => 'single_invoice',
            'status' => 'approved',
            'total_amount' => 200000,
            'vat_amount' => 30000,
        ]);

        Expense::factory()->create([
            'branch_manager_id' => $this->manager->id,
            'expense_type' => 'quick_cash',
            'status' => 'approved',
            'total_amount' => 80000,
        ]);
    }

    /** Four menu items with varied price/quantity to populate all quadrants. */
    private function seedMenu(): void
    {
        $specs = [
            ['Grilled Chicken', 'Grills', 60, 400],
            ['Beef Burger', 'Burgers', 45, 300],
            ['Garden Salad', 'Salads', 25, 80],
            ['Cola', 'Beverages', 10, 500],
        ];

        foreach ($specs as [$name, $category, $price, $qty]) {
            $item = Item::create(['name' => $name, 'category' => $category, 'is_active' => true]);
            BranchItem::create([
                'branch_id' => $this->branch->id,
                'item_id' => $item->id,
                'price' => $price,
                'quantity' => $qty,
            ]);
        }
    }

    private function actingAsOwner()
    {
        return $this->actingAs($this->owner, 'sanctum');
    }

    // ----------------------------------------------------------------
    // 1. Profit & Loss
    // ----------------------------------------------------------------

    public function test_profit_and_loss_shape_and_values(): void
    {
        $res = $this->actingAsOwner()->getJson(
            '/api/brand-owner/financial/profit-and-loss?branch_id='.$this->branch->id
        );

        $res->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'data' => [
                    'year', 'month', 'branch_id', 'branch_name',
                    'summary' => ['total_revenue', 'total_expenses', 'net_profit', 'profit_margin_percentage', 'is_profit'],
                    'chart' => ['turnover_total', 'direct_cost_total', 'gross_profit', 'grand_total_cost', 'profitability_amount', 'g_and_a_expenses', 'other_income_expenses', 'net_profit_and_loss'],
                ],
            ]);

        $this->assertEquals($this->branch->id, $res->json('data.branch_id'));
        $this->assertEquals($this->branch->name, $res->json('data.branch_name'));

        // turnover 500k; direct 200k; operating 80k => gross 300k, total cost 280k, net 220k, margin 44%.
        $this->assertEquals(500000.0, $res->json('data.summary.total_revenue'));
        $this->assertEquals(280000.0, $res->json('data.summary.total_expenses'));
        $this->assertEquals(220000.0, $res->json('data.summary.net_profit'));
        $this->assertEquals(44.0, $res->json('data.summary.profit_margin_percentage'));
        $this->assertTrue($res->json('data.summary.is_profit'));
        $this->assertEquals(300000.0, $res->json('data.chart.gross_profit'));
        $this->assertEquals(200000.0, $res->json('data.chart.direct_cost_total'));
        $this->assertEquals(80000.0, $res->json('data.chart.g_and_a_expenses'));
        $this->assertEquals(0.0, $res->json('data.chart.other_income_expenses'));
        // profitability_amount = turnover − grand_total_cost = net profit.
        $this->assertEquals(220000.0, $res->json('data.chart.profitability_amount'));
        $this->assertEquals(220000.0, $res->json('data.chart.net_profit_and_loss'));
    }

    public function test_profit_and_loss_defaults_to_first_branch_when_branch_id_omitted(): void
    {
        $res = $this->actingAsOwner()->getJson('/api/brand-owner/financial/profit-and-loss');

        $res->assertStatus(200)->assertJsonPath('success', true);
        // Omitting branch_id must resolve to a real branch (the first), not null/all.
        $this->assertEquals($this->branch->id, $res->json('data.branch_id'));
        $this->assertEquals('Riyadh Branch', $res->json('data.branch_name'));
        $this->assertEquals(500000.0, $res->json('data.summary.total_revenue'));
    }

    public function test_profit_and_loss_export_returns_file_url(): void
    {
        Storage::fake('public');

        $res = $this->actingAsOwner()->postJson('/api/brand-owner/financial/profit-and-loss/export', [
            'year' => (int) now()->year,
            'month' => (int) now()->month,
            'branch_id' => $this->branch->id,
            'format_type' => 'PDF',
        ]);

        $res->assertStatus(200)
            ->assertJsonStructure(['data' => ['file_url']]);
        $this->assertNotEmpty($res->json('data.file_url'));
    }

    public function test_profit_and_loss_export_accepts_lowercase_format_type(): void
    {
        Storage::fake('public');

        $res = $this->actingAsOwner()->postJson('/api/brand-owner/financial/profit-and-loss/export', [
            'year' => (int) now()->year,
            'month' => (int) now()->month,
            'branch_id' => $this->branch->id,
            'format_type' => 'pdf',
        ]);

        $res->assertStatus(200)
            ->assertJsonStructure(['data' => ['file_url']]);
        $this->assertNotEmpty($res->json('data.file_url'));
    }

    public function test_profit_and_loss_email_dispatches_mail(): void
    {
        Storage::fake('public');
        Mail::fake();

        $res = $this->actingAsOwner()->postJson('/api/brand-owner/financial/profit-and-loss/email', [
            'year' => (int) now()->year,
            'month' => (int) now()->month,
            'branch_id' => $this->branch->id,
            'email' => 'boss@example.com',
        ]);

        $res->assertStatus(200)->assertJsonPath('success', true);
        Mail::assertSent(FinancialReportMail::class);
    }

    // ----------------------------------------------------------------
    // 2/3. Sales channel
    // ----------------------------------------------------------------

    public function test_sales_channel_analysis_shape(): void
    {
        $res = $this->actingAsOwner()->getJson(
            '/api/brand-owner/financial/sales-channel-analysis?branch_id='.$this->branch->id
        );

        $res->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'year', 'month', 'compared_year', 'compared_month', 'month_name', 'branch_id', 'branch_name',
                    'summary' => ['total_sales', 'total_profitability', 'total_profitability_percentage', 'net_profit_percentage', 'profit_margin_percentage'],
                    'chart_data' => ['main_month', 'main_year', 'main_value', 'compared_month', 'compared_year', 'compared_value'],
                ],
            ]);

        // One channel carrying the full 500k of sales.
        $this->assertEquals(500000.0, $res->json('data.summary.total_sales'));
        $this->assertEquals(500000.0, $res->json('data.chart_data.main_value'));
    }

    public function test_sales_channel_analysis_defaults_to_first_branch_when_branch_id_omitted(): void
    {
        $res = $this->actingAsOwner()->getJson('/api/brand-owner/financial/sales-channel-analysis');

        $res->assertStatus(200)->assertJsonPath('success', true);
        // branch_id is now optional and defaults to the first branch from the DB.
        $this->assertEquals($this->branch->id, $res->json('data.branch_id'));
        $this->assertEquals('Riyadh Branch', $res->json('data.branch_name'));
        $this->assertEquals(500000.0, $res->json('data.summary.total_sales'));
    }

    public function test_sales_channel_level2_shape(): void
    {
        $res = $this->actingAsOwner()->getJson(
            '/api/brand-owner/financial/sales-channel-level2?branch_id='.$this->branch->id
        );

        $res->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'year', 'month', 'compared_year', 'compared_month', 'month_name', 'total_count',
                    'items' => [['id', 'name', 'image', 'sales_amount', 'percentage_change', 'is_positive', 'comparison_text', 'commission_amount', 'commission_percentage', 'profitability_amount', 'profitability_percentage', 'order_count', 'average_order_value']],
                    'chart_data' => [['month', 'value']],
                ],
            ]);

        $this->assertEquals(1, $res->json('data.total_count'));
        // 10% commission on 500k = 50k; profitability = 450k.
        $this->assertEquals(50000.0, $res->json('data.items.0.commission_amount'));
        $this->assertEquals(450000.0, $res->json('data.items.0.profitability_amount'));
    }

    // ----------------------------------------------------------------
    // 4. Smart comparison
    // ----------------------------------------------------------------

    public function test_smart_comparison_month_type(): void
    {
        $res = $this->actingAsOwner()->getJson(
            '/api/brand-owner/financial/smart-comparison?type=month&branch_id='.$this->branch->id
        );

        $res->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'year', 'month', 'compared_year', 'compared_month', 'month_name', 'compared_month_name', 'type',
                    'branch_id', 'branch_name', 'compared_branch_id', 'compared_branch_name',
                    'summary' => ['total_sales', 'total_profitability', 'total_profitability_percentage', 'net_profit_percentage', 'profit_margin_percentage'],
                    'chart_data' => ['main_month', 'main_year', 'main_value', 'compared_month', 'compared_year', 'compared_value'],
                ],
            ])
            ->assertJsonPath('data.type', 'month');
        $this->assertEquals(500000.0, $res->json('data.chart_data.main_value'));
    }

    // ----------------------------------------------------------------
    // 5. Profit vs cash
    // ----------------------------------------------------------------

    public function test_profit_vs_cash_reconciliation_shape(): void
    {
        $this->actingAsOwner()->getJson('/api/brand-owner/financial/profit-vs-cash-reconciliation')
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'date_range_label', 'monthly_profits', 'current_cash', 'difference',
                    'pending_sales' => ['total', 'paid_amount', 'shown_in_profits', 'reason', 'items'],
                    'rent_paid' => ['amount', 'paid_amount', 'shown_in_profits'],
                    'previous_expense_reconciliation' => ['amount'],
                    'advances_fixed_assets' => ['amount'],
                    'zakat_taxes' => ['amount', 'bought_amount', 'used_amount'],
                    'unused_inventory' => ['total', 'reason', 'items'],
                    'non_cash_expenses' => ['depreciation', 'others', 'total', 'reason'],
                    'supplier_obligations' => ['total', 'reason', 'items'],
                    'accrued_salaries' => ['total', 'reason', 'items'],
                    'expected_cash', 'final_result_info',
                ],
            ]);
    }

    // ----------------------------------------------------------------
    // 6. Break-even
    // ----------------------------------------------------------------

    public function test_break_even_analysis_shape_and_status(): void
    {
        $res = $this->actingAsOwner()->getJson(
            '/api/brand-owner/financial/break-even-analysis?branch_id='.$this->branch->id
        );

        $res->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'year', 'month', 'month_name', 'previous_month_name', 'branch_id', 'branch_name',
                    'status', 'current_point_position',
                    'metrics' => ['current_point_x', 'current_point_y', 'fixed_cost_x', 'fixed_cost_y'],
                    'fixed_costs' => ['rent_and_utilities', 'salaries', 'insurance_and_licenses', 'total_fixed'],
                    'contribution_margin' => ['gross_profit_margin', 'variable_costs'],
                    'formula' => ['fixed_costs', 'contribution_margin', 'result'],
                ],
            ]);

        $this->assertContains($res->json('data.status'), ['very_safe', 'safe', 'at_break_even', 'risk', 'high_risk']);
        // variable costs = direct cost 200k; contribution ratio 0.6.
        $this->assertEquals(200000.0, $res->json('data.contribution_margin.variable_costs'));
    }

    public function test_break_even_analysis_defaults_to_first_branch_when_branch_id_omitted(): void
    {
        $res = $this->actingAsOwner()->getJson('/api/brand-owner/financial/break-even-analysis');

        $res->assertStatus(200)->assertJsonPath('success', true);
        $this->assertEquals($this->branch->id, $res->json('data.branch_id'));
        $this->assertEquals('Riyadh Branch', $res->json('data.branch_name'));
        $this->assertEquals(200000.0, $res->json('data.contribution_margin.variable_costs'));
    }

    // ----------------------------------------------------------------
    // 7. Operational profitability
    // ----------------------------------------------------------------

    public function test_operational_profitability_shape(): void
    {
        $this->actingAsOwner()->getJson('/api/brand-owner/financial/operational-profitability')
            ->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'metrics' => ['overall_performance', 'overall_score', 'stars_count'],
                    'core_indicators' => ['operating_profit', 'operating_profit_change', 'operating_profit_is_positive', 'ebitda', 'ebitda_change', 'ebitda_is_positive', 'gross_profit_margin', 'gross_profit_margin_change', 'gross_profit_margin_is_positive', 'return_on_sales', 'return_on_sales_label'],
                    'efficiency' => ['productivity_per_employee', 'productivity_per_employee_change', 'productivity_is_positive', 'labor_cost', 'labor_cost_label', 'inventory_turnover', 'inventory_turnover_unit', 'average_transaction_value', 'space_efficiency', 'space_efficiency_unit'],
                    'benchmark' => ['profit_margin_your_restaurant', 'profit_margin_industry', 'labor_cost_your_restaurant', 'labor_cost_industry', 'inventory_turnover_your_restaurant', 'inventory_turnover_industry'],
                ],
            ]);
    }

    // ----------------------------------------------------------------
    // 8. Menu engineering
    // ----------------------------------------------------------------

    public function test_menu_engineering_shape(): void
    {
        $res = $this->actingAsOwner()->getJson(
            '/api/brand-owner/financial/menu-engineering?branch_id='.$this->branch->id
        );

        $res->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'year', 'month', 'month_name', 'compared_year', 'compared_month', 'compared_month_name', 'branch_id', 'branch_name',
                    'puzzles' => ['items_count', 'percentage', 'is_high_profitability'],
                    'stars' => ['items_count', 'percentage', 'is_high_profitability'],
                    'dogs' => ['items_count', 'percentage', 'is_high_profitability'],
                    'workhorses' => ['items_count', 'percentage', 'is_high_profitability'],
                    'puzzles_category' => ['items_count', 'percentage', 'amount', 'amount_percentage', 'items'],
                    'stars_category' => ['items_count', 'percentage', 'amount', 'amount_percentage', 'items'],
                    'dogs_category' => ['items_count', 'percentage', 'amount', 'amount_percentage', 'items'],
                    'workhorses_category' => ['items_count', 'percentage', 'amount', 'amount_percentage', 'items'],
                ],
            ]);

        $total = $res->json('data.stars.items_count')
            + $res->json('data.puzzles.items_count')
            + $res->json('data.dogs.items_count')
            + $res->json('data.workhorses.items_count');
        $this->assertEquals(4, $total);
        $this->assertTrue($res->json('data.stars.is_high_profitability'));
        $this->assertFalse($res->json('data.dogs.is_high_profitability'));
    }

    public function test_menu_engineering_defaults_to_first_branch_when_branch_id_omitted(): void
    {
        $res = $this->actingAsOwner()->getJson('/api/brand-owner/financial/menu-engineering');

        $res->assertStatus(200)->assertJsonPath('success', true);
        $this->assertEquals($this->branch->id, $res->json('data.branch_id'));
        $this->assertEquals('Riyadh Branch', $res->json('data.branch_name'));
        $total = $res->json('data.stars.items_count')
            + $res->json('data.puzzles.items_count')
            + $res->json('data.dogs.items_count')
            + $res->json('data.workhorses.items_count');
        $this->assertEquals(4, $total);
    }

    // ----------------------------------------------------------------
    // 9. Item test
    // ----------------------------------------------------------------

    public function test_item_test_submit_and_saved(): void
    {
        $res = $this->actingAsOwner()->postJson('/api/brand-owner/financial/item-test/submit', [
            'branch_id' => $this->branch->id,
            'item_name' => 'New Chicken Burger',
            'expected_selling_price' => 45.0,
            'production_cost' => 25.0,
            'expected_sales' => 500,
            'expected_growth' => 10.0,
        ]);

        $res->assertStatus(201)
            ->assertJsonStructure([
                'data' => ['id', 'profit_margin', 'profit_margin_label', 'expected_monthly_profit', 'expected_classification', 'menu_impact', 'date', 'branch_name', 'item_name', 'expected_selling_price', 'production_cost', 'expected_sales', 'expected_growth'],
            ]);

        // (45-25)/45 = 44.44%; monthly profit = 20 * 500 = 10000.
        $this->assertEquals(44.44, $res->json('data.profit_margin'));
        $this->assertEquals(10000.0, $res->json('data.expected_monthly_profit'));

        $this->actingAsOwner()->getJson('/api/brand-owner/financial/item-test/saved-tests')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonCount(1, 'data');
    }

    public function test_item_test_submit_defaults_to_first_branch_when_branch_id_omitted(): void
    {
        $res = $this->actingAsOwner()->postJson('/api/brand-owner/financial/item-test/submit', [
            'item_name' => 'No-Branch Item',
            'expected_selling_price' => 45.0,
            'production_cost' => 25.0,
            'expected_sales' => 500,
            'expected_growth' => 10.0,
        ]);

        // branch_id omitted → resolved to the first branch and stored on the test.
        $res->assertStatus(201)->assertJsonPath('success', true);
        $this->assertEquals('Riyadh Branch', $res->json('data.branch_name'));
        $this->assertEquals(44.44, $res->json('data.profit_margin'));
    }

    // ----------------------------------------------------------------
    // 10. Price simulator
    // ----------------------------------------------------------------

    public function test_price_simulator_flow(): void
    {
        $item = Item::query()->where('name', 'Beef Burger')->first();

        $this->actingAsOwner()->getJson('/api/brand-owner/financial/price-simulator/items')
            ->assertStatus(200)
            ->assertJsonStructure(['data' => [['id', 'name']]]);

        $info = $this->actingAsOwner()->getJson(
            '/api/brand-owner/financial/price-simulator/item-info?branch_id='.$this->branch->id.'&item_id='.$item->id
        );
        $info->assertStatus(200)
            ->assertJsonStructure(['data' => ['item_id', 'item_name', 'current_selling_price', 'production_cost', 'current_monthly_sales', 'expected_growth_percentage']]);
        $this->assertEquals(45.0, $info->json('data.current_selling_price'));

        $sim = $this->actingAsOwner()->postJson('/api/brand-owner/financial/price-simulator/simulate', [
            'branch_id' => $this->branch->id,
            'item_id' => $item->id,
            'change_price' => 50.0,
            'expected_growth_decline' => -10.0,
        ]);
        $sim->assertStatus(201)
            ->assertJsonStructure([
                'data' => ['new_price', 'expected_sales', 'expected_sales_change_percentage', 'new_unit_profit', 'new_unit_profit_change_percentage', 'new_monthly_profit', 'profit_change', 'profit_change_percentage', 'date', 'branch_name', 'item_name', 'current_selling_price', 'production_cost', 'current_monthly_sales', 'expected_growth_percentage'],
            ]);
        $this->assertEquals(50.0, $sim->json('data.new_price'));

        $this->actingAsOwner()->getJson('/api/brand-owner/financial/price-simulator/saved-scenarios')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    public function test_item_info_uses_real_latest_purchase_cost(): void
    {
        // Beef Burger sells at 45; ratio fallback would be 18.0 (45 * 0.40).
        $item = Item::query()->where('name', 'Beef Burger')->first();

        $po = PurchaseOrder::factory()->create(['branch_id' => $this->branch->id]);
        // Older purchase at 20, newer at 12 — the latest (12) must win.
        PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'unit_price' => 20.0,
            'created_at' => now()->subDays(10),
        ]);
        PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $po->id,
            'item_id' => $item->id,
            'unit_price' => 12.0,
            'created_at' => now()->subDay(),
        ]);

        $info = $this->actingAsOwner()->getJson(
            '/api/brand-owner/financial/price-simulator/item-info?branch_id='.$this->branch->id.'&item_id='.$item->id
        );

        $info->assertStatus(200);
        // Real cost (latest purchase 12.0), not the 18.0 ratio estimate.
        $this->assertEquals(12.0, $info->json('data.production_cost'));
    }

    // ----------------------------------------------------------------
    // Authorization
    // ----------------------------------------------------------------

    public function test_financial_endpoints_forbidden_for_non_brand_owner(): void
    {
        $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/brand-owner/financial/profit-and-loss?branch_id='.$this->branch->id)
            ->assertStatus(403)
            ->assertJsonPath('success', false);
    }
}
