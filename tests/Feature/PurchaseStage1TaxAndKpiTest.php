<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\OperationSequence;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Purchase\Enums\OrderItemStatus;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Models\Item;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Purchase\Services\CalculationService;
use Modules\Supplier\Models\Supplier;
use Tests\TestCase;

class PurchaseStage1TaxAndKpiTest extends TestCase
{
    use RefreshDatabase;

    public function test_t1_3_calculation_service_pre_vat_discount_cancelled_exclusion_and_explicit_rates(): void
    {
        $calc = app(CalculationService::class);

        // 300 / 45 / 345 with 15% VAT
        $r15 = $calc->calculateOrderTotals([
            ['quantity' => 10, 'unit_price' => 30],
        ], 0.0, 15.0);
        $this->assertSame(300.0, $r15['subtotal']);
        $this->assertSame(45.0, $r15['tax_amount']);
        $this->assertSame(345.0, $r15['total_amount']);

        // 300 - 40 pre-VAT discount = 260 taxable base -> 39 VAT -> 299 total
        $rDisc = $calc->calculateOrderTotals([
            ['quantity' => 10, 'unit_price' => 30],
            ['quantity' => 5, 'unit_price' => 20, 'status' => OrderItemStatus::CANCELLED->value],
            ['quantity' => 2, 'unit_price' => 50, 'status' => OrderItemStatus::REJECTED->value],
        ], 40.0, 15.0);
        $this->assertSame(300.0, $rDisc['subtotal']);
        $this->assertSame(40.0, $rDisc['discount_amount']);
        $this->assertSame(39.0, $rDisc['tax_amount']);
        $this->assertSame(299.0, $rDisc['total_amount']);
        $this->assertSame(1, $rDisc['total_items']);

        // 0% explicit VAT
        $r0 = $calc->calculateOrderTotals([
            ['quantity' => 10, 'unit_price' => 30],
        ], 40.0, 0.0);
        $this->assertSame(0.0, $r0['tax_amount']);
        $this->assertSame(260.0, $r0['total_amount']);

        // Missing or invalid tax rate must be rejected without silently defaulting to 15%
        $this->expectException(\InvalidArgumentException::class);
        $calc->calculateOrderTotals([['quantity' => 1, 'unit_price' => 10]], 0.0, null);
    }

    public function test_t1_3_purchase_order_calculate_totals_applies_pre_vat_discount_and_excludes_cancelled_items(): void
    {
        $company = AsabCompany::create(['name' => 'Tax Co', 'plan' => 'Basic', 'status' => 'active']);
        $branch = Branch::factory()->create(['asab_company_id' => $company->id]);
        $bm = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $supplier = Supplier::create([
            'name' => 'Tax Supplier',
            'email' => 'tax-sup@test.local',
            'password' => 'secret123',
            'is_active' => true,
        ]);
        $item = Item::create(['name' => 'Flour', 'unit' => 'kg', 'is_active' => true]);

        $order = PurchaseOrder::create([
            'order_type' => OrderType::DIRECT_SUPPLIER,
            'status' => OrderStatus::PENDING,
            'branch_id' => $branch->id,
            'requested_by' => $bm->id,
            'sourceable_type' => Supplier::class,
            'sourceable_id' => $supplier->id,
            'supplier_id' => $supplier->id,
            'tax_rate' => 15.0,
            'discount_amount' => 40.0,
        ]);

        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'item_id' => $item->id,
            'item_name' => $item->name,
            'quantity_ordered' => 10,
            'unit_price' => 30,
            'total_price' => 300,
            'status' => OrderItemStatus::CONFIRMED,
        ]);
        PurchaseOrderItem::create([
            'purchase_order_id' => $order->id,
            'item_id' => $item->id,
            'item_name' => $item->name,
            'quantity_ordered' => 5,
            'unit_price' => 20,
            'total_price' => 100,
            'status' => OrderItemStatus::CANCELLED_BY_SUPPLIER,
        ]);

        $order->calculateTotals();
        $order->refresh();

        $this->assertSame(300.0, (float) $order->subtotal);
        $this->assertSame(39.0, (float) $order->tax_amount);
        $this->assertSame(299.0, (float) $order->total_amount);
        $this->assertSame(1, $order->total_items);
    }

    public function test_t1_3_purchase_board_and_operations_kpis_exclude_rejected_and_cancelled_operations(): void
    {
        $company = AsabCompany::create(['name' => 'KPI Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => 'KPI Brand', 'sub_status' => 'active', 'status' => 'active']);
        $branch = Branch::factory()->create(['asab_company_id' => $company->id, 'asab_brand_id' => $brand->id]);
        $accountant = AsabUser::create([
            'company_id' => $company->id,
            'name' => 'Accountant KPI',
            'email' => 'acc-kpi@test.local',
            'password' => 'secret123',
            'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $accountant->id, 'role_key' => 'accountant', 'scope' => 'all']);
        CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $accountant->id,
            'role_key' => 'accountant',
            'status' => 'active',
        ]);
        $supplier = AsabSupplier::create([
            'company_id' => $company->id,
            'name' => 'KPI Supplier',
            'category' => 'Food',
            'rating' => 45,
            'status' => 'active',
        ]);

        Operation::create([
            'public_id' => OperationSequence::next('PUR'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'module_key' => 'purchases',
            'amount' => 34500,
            'match' => 'exact',
            'origin' => 'procurement',
            'status' => Operation::STATUS_APPROVED,
            'operation_date' => now(),
            'payload' => ['supplierId' => $supplier->id, 'items' => []],
        ]);
        Operation::create([
            'public_id' => OperationSequence::next('PUR'),
            'company_id' => $company->id,
            'branch_id' => $branch->id,
            'module_key' => 'purchases',
            'amount' => 99900,
            'match' => 'diff',
            'origin' => 'procurement',
            'status' => Operation::STATUS_REJECTED,
            'operation_date' => now(),
            'payload' => ['supplierId' => $supplier->id, 'items' => []],
        ]);

        $board = $this->actingAs($accountant, 'sanctum')
            ->getJson('/api/v1/company/me/purchases')
            ->assertOk()
            ->json();

        $this->assertSame(34500, $board['meta']['kpis']['totalPurchasesHalalas']);
        $this->assertSame(345.0, (float) $board['meta']['kpis']['totalPurchasesSar']);
        $this->assertSame(1, $board['meta']['kpis']['invoices']);
        $this->assertSame(0, $board['meta']['kpis']['diffInvoices']);

        $opsSummary = $this->actingAs($accountant, 'sanctum')
            ->getJson('/api/v1/company/me/operations?moduleKey=purchases')
            ->assertOk()
            ->json('meta.summary.purchases');

        $this->assertSame(34500, $opsSummary['todayTotalHalalas']);
    }
}
