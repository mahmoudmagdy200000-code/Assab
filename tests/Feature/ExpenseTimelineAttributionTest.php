<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsExpenseTenantFixtures;
use Tests\TestCase;

/**
 * G-1 — expense timeline attribution.
 *
 * After the performed_by_type column is widened (enum -> string), a brand
 * manager's decision is recorded as 'brand_manager' and surfaces with the
 * manager's real name through the timeline API; a brand owner's decision stays
 * 'brand_owner'. The real actor id is preserved either way.
 */
class ExpenseTimelineAttributionTest extends TestCase
{
    use BuildsExpenseTenantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildExpenseTenants();
    }

    private function approveAs($actor, string $expenseId): void
    {
        $this->actingAs($actor, 'sanctum')
            ->postJson("/api/brand-owner/expenses/{$expenseId}/approve")
            ->assertOk();
    }

    private function approveRow(string $expenseId): array
    {
        $r = $this->actingAs($this->ownerA1, 'sanctum')
            ->getJson("/api/brand-owner/expenses/{$expenseId}/timeline")
            ->assertOk();

        return collect($r->json('data'))->firstWhere('event_type', 'expense_approve');
    }

    public function test_brand_manager_decision_is_recorded_and_named_as_a_brand_manager(): void
    {
        $expense = $this->pendingExpense($this->bmA1);
        $this->approveAs($this->managerA1, $expense->id);

        // DB: the new value is accepted (migration) and the actor id is real.
        $row = DB::table('expense_timelines')->where('expense_id', $expense->id)->where('action', 'approve')->first();
        $this->assertSame('brand_manager', $row->performed_by_type);
        $this->assertSame($this->managerA1->id, $row->performed_by);

        // API: the timeline shows the actual manager name, not the generic label.
        $this->assertSame('Manager A1', $this->approveRow($expense->id)['name']);
    }

    public function test_brand_owner_decision_stays_attributed_to_brand_owner(): void
    {
        $expense = $this->pendingExpense($this->bmA1);
        $this->approveAs($this->ownerA1, $expense->id);

        $row = DB::table('expense_timelines')->where('expense_id', $expense->id)->where('action', 'approve')->first();
        $this->assertSame('brand_owner', $row->performed_by_type);
        $this->assertSame($this->ownerA1->id, $row->performed_by);

        $this->assertSame('Brand Owner', $this->approveRow($expense->id)['name']);
    }
}
