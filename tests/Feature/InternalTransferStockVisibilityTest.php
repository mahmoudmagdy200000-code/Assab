<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Inventory\Enums\InventorySessionStatus;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\InventorySession;
use Modules\Purchase\Models\Item;
use Tests\TestCase;

/**
 * «من فرع آخر» reads the counted quantity of the OTHER branches. It filtered on
 * COMPLETED sessions only, but a جرد whose figures disagree with the theoretical
 * stock ends at PENDING_YOUR_CONFIRMATION and stays there (reviewing the report
 * only logs a timeline event) — so a branch that had just counted was invisible
 * to internal transfer (2026-08-15).
 */
class InternalTransferStockVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private BranchManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::factory()->create();
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
    }

    private function countedElsewhere(Item $item, InventorySessionStatus $status, float $quantity): Branch
    {
        $other = Branch::factory()->create();
        $otherManager = BranchManager::factory()->create(['branch_id' => $other->id]);

        $session = InventorySession::create([
            'session_number' => 'INV-'.uniqid(),
            'branch_id' => $other->id,
            'created_by' => $otherManager->id,
            'created_by_type' => 'branch_manager',
            'assigned_to_type' => 'personal',
            'inventory_date' => today(),
            'status' => $status,
            'submitted_at' => now(),
        ]);

        InventoryItem::create([
            'inventory_session_id' => $session->id,
            'branch_id' => $other->id,
            'item_id' => $item->id,
            'item_name' => $item->name,
            'quantity_inventory' => $quantity,
        ]);

        return $other;
    }

    public function test_a_branch_whose_count_awaits_discrepancy_confirmation_is_offered(): void
    {
        $item = Item::create(['name' => 'دجاج', 'unit' => 'كجم', 'is_active' => true]);
        $other = $this->countedElsewhere($item, InventorySessionStatus::PENDING_YOUR_CONFIRMATION, 12);

        $rows = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/purchase/orders/branches?item_id='.$item->id.'&quantity=1')
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($other->id, $rows[0]['branch_id']);
        $this->assertEquals(12.0, $rows[0]['available_quantity']);
    }

    public function test_a_completed_count_is_still_offered(): void
    {
        $item = Item::create(['name' => 'خبز', 'unit' => 'كجم', 'is_active' => true]);
        $other = $this->countedElsewhere($item, InventorySessionStatus::COMPLETED, 4);

        $rows = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/purchase/orders/branches?item_id='.$item->id.'&quantity=1')
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($other->id, $rows[0]['branch_id']);
    }

    /** An unfinished count is not stock — a draft branch must not be offered. */
    public function test_a_draft_count_is_not_offered(): void
    {
        $item = Item::create(['name' => 'خضار', 'unit' => 'كجم', 'is_active' => true]);
        $this->countedElsewhere($item, InventorySessionStatus::DRAFT, 9);

        $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/purchase/orders/branches?item_id='.$item->id.'&quantity=1')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }
}
