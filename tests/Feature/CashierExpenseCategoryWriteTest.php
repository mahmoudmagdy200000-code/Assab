<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Cashier\Models\Cashier;
use Modules\Expense\Models\Category;
use Tests\Concerns\BuildsExpenseTenantFixtures;
use Tests\TestCase;

/**
 * BRC v3.4 N-04 — a cashier must not create, edit or delete expense categories (same refusal as EXP-09 writes);
 * reads and the branch manager's own category management are unchanged.
 */
class CashierExpenseCategoryWriteTest extends TestCase
{
    use BuildsExpenseTenantFixtures;
    use RefreshDatabase;

    private Cashier $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildExpenseTenants();
        $this->cashier = Cashier::factory()->create(['branch_id' => $this->branchA1->id, 'created_by' => $this->bmA1->id]);
    }

    private function make(string $name = 'Utilities'): Category
    {
        return Category::create(['name' => $name, 'type' => 'expense', 'is_active' => true]);
    }

    public function test_cashier_cannot_create_update_or_delete_a_category_and_nothing_is_written(): void
    {
        $cat = $this->make();
        $before = DB::table($cat->getTable())->get()->toJson();
        $c = $this->actingAs($this->cashier, 'sanctum');

        foreach ([
            $c->postJson('/api/branch-manager/expenses/categories', ['name' => 'Sneaky', 'type' => 'expense', 'is_active' => true]),
            $c->putJson("/api/branch-manager/expenses/categories/{$cat->id}", ['name' => 'Renamed', 'type' => 'expense']),
            $c->deleteJson("/api/branch-manager/expenses/categories/{$cat->id}"),
        ] as $r) {
            $r->assertForbidden()->assertJsonPath('errors.code', 'EXPENSE_DELEGATION_REQUIRED');
        }
        $this->assertSame($before, DB::table($cat->getTable())->get()->toJson());
    }

    public function test_cashier_keeps_read_access_to_categories(): void
    {
        $this->make();
        $this->actingAs($this->cashier, 'sanctum')->getJson('/api/branch-manager/expenses/categories')->assertOk();
    }

    public function test_branch_manager_category_management_is_unchanged(): void
    {
        $this->actingAs($this->bmA1, 'sanctum')->postJson('/api/branch-manager/expenses/categories', ['name' => 'Cleaning', 'type' => 'expense', 'is_active' => true])->assertSuccessful();
        $this->assertTrue(Category::where('name', 'Cleaning')->exists());
    }
}
