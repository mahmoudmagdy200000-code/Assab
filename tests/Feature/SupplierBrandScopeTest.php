<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabSupplier;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Expense\Models\Supplier;
use Modules\Expense\Services\QuickCashExpenseService;
use Tests\TestCase;

/**
 * Meeting 2026-07-29 «تقييد قائمة الموردين بحسب العلامة/الفرع»: the mobile
 * expense supplier picker must show ONLY the caller brand's suppliers, and an
 * expense write naming a foreign supplier must be rejected.
 */
class SupplierBrandScopeTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: AsabBrand, 1: Branch, 2: BranchManager} */
    private function brandBranchManager(string $suffix = 'a'): array
    {
        $company = AsabCompany::create(['name' => "Scope Co {$suffix}", 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => "براند {$suffix}", 'sub_status' => 'active', 'status' => 'active']);
        $branch = Branch::factory()->create(['asab_brand_id' => $brand->id, 'asab_company_id' => $company->id]);
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);

        return [$brand, $branch, $manager];
    }

    private function brandSupplier(AsabBrand $brand, string $name): Supplier
    {
        $legacy = Supplier::factory()->create(['name' => $name, 'is_active' => true]);
        AsabSupplier::create([
            'company_id' => $brand->company_id, 'brand_id' => $brand->id,
            'name' => $name, 'status' => 'active',
        ])->forceFill(['legacy_supplier_id' => $legacy->id])->save();

        return $legacy;
    }

    public function test_the_picker_lists_only_the_callers_brand_suppliers(): void
    {
        [$brandA, , $manager] = $this->brandBranchManager('a');
        [$brandB] = $this->brandBranchManager('b');

        $mine = $this->brandSupplier($brandA, 'مورد علامتي');
        $this->brandSupplier($brandB, 'مورد علامة أخرى');
        Supplier::factory()->create(['name' => 'مورد قديم غير مربوط', 'is_active' => true]);

        $names = $this->actingAs($manager, 'sanctum')
            ->getJson('/api/branch-manager/expenses/suppliers')
            ->assertOk()
            ->json('data');

        $this->assertCount(1, $names);
        $this->assertSame($mine->id, $names[0]['id']);
    }

    public function test_company_wide_suppliers_with_no_brand_pin_are_visible_too(): void
    {
        [$brandA, , $manager] = $this->brandBranchManager('a');

        $legacy = Supplier::factory()->create(['name' => 'مورد الشركة', 'is_active' => true]);
        AsabSupplier::create([
            'company_id' => $brandA->company_id, 'brand_id' => null,
            'name' => 'مورد الشركة', 'status' => 'active',
        ])->forceFill(['legacy_supplier_id' => $legacy->id])->save();

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/branch-manager/expenses/suppliers')
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_an_unlinked_branch_gets_an_empty_list_not_everyones_suppliers(): void
    {
        $branch = Branch::factory()->create(['asab_brand_id' => null, 'asab_company_id' => null, 'asab_restaurant_id' => null]);
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        Supplier::factory()->count(3)->create(['is_active' => true]);

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/branch-manager/expenses/suppliers')
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    public function test_an_expense_naming_a_foreign_supplier_is_rejected(): void
    {
        [, , $manager] = $this->brandBranchManager('a');
        [$brandB] = $this->brandBranchManager('b');
        $foreign = $this->brandSupplier($brandB, 'مورد أجنبي');

        $this->actingAs($manager, 'sanctum');

        $this->expectException(ValidationException::class);

        app(QuickCashExpenseService::class)->createQuickCashExpense([
            'total_amount' => 100.0,
            'net_amount' => 100.0,
            'vat_amount' => 0.0,
            'payment_supplier_id' => $foreign->id,
        ]);
    }

    public function test_an_expense_naming_an_own_brand_supplier_passes_the_guard(): void
    {
        [$brandA, , $manager] = $this->brandBranchManager('a');
        $mine = $this->brandSupplier($brandA, 'موردي');

        $this->actingAs($manager, 'sanctum');

        $expense = app(QuickCashExpenseService::class)->createQuickCashExpense([
            'total_amount' => 100.0,
            'net_amount' => 100.0,
            'vat_amount' => 0.0,
            'payment_method' => 'cash',
            'expense_date' => '2026-07-29',
            'expense_name' => 'مصروف نثري',
            'payment_supplier_id' => $mine->id,
        ]);

        $this->assertSame('pending', $expense->status);
    }
}
