<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Inventory\Models\DailyInventorySchedule;
use Modules\Inventory\Models\DailyInventoryScheduleItem;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\Item;
use Tests\TestCase;

/**
 * Meeting 2026-08-15 «الأصناف ظاهرة في الموبايل قبل ما أحددها من الداشبورد»:
 * the app's Daily Quick Inventory listed the branch's whole `branch_item` pivot
 * under «Products pre-selected by management», so every uploaded item showed up
 * before the accountant selected — or sent — anything. The count sheet is the
 * branch's daily schedule and nothing else.
 */
class DailyInventoryCountSheetTest extends TestCase
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

    private function stockedItem(string $name, string $code): Item
    {
        $item = Item::create(['name' => $name, 'code' => $code, 'unit' => 'كجم', 'is_active' => true]);

        BranchItem::create([
            'branch_id' => $this->branch->id,
            'item_id' => $item->id,
            'price' => 5,
            'quantity' => 0,
        ]);

        return $item;
    }

    private function scheduleFor(Item ...$items): DailyInventorySchedule
    {
        $schedule = DailyInventorySchedule::create([
            'branch_id' => $this->branch->id,
            'start_date' => today()->toDateString(),
            'start_time' => '20:00',
            'is_active' => true,
        ]);

        foreach ($items as $index => $item) {
            DailyInventoryScheduleItem::create([
                'daily_inventory_schedule_id' => $schedule->id,
                'item_id' => $item->id,
                'sort_order' => $index,
            ]);
        }

        return $schedule;
    }

    public function test_nothing_is_listed_before_management_selects_a_list(): void
    {
        $this->stockedItem('بيتزا', 'D005');
        $this->stockedItem('خبز', 'D006');

        $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/inventory/daily-quick/branch-items')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_only_the_scheduled_items_are_listed(): void
    {
        $pizza = $this->stockedItem('بيتزا', 'D005');
        $this->stockedItem('خبز', 'D006');

        $this->scheduleFor($pizza);

        $rows = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/inventory/daily-quick/branch-items')
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame('بيتزا', $rows[0]['item_name']);
        $this->assertSame($pizza->id, $rows[0]['item_id']);
    }

    /** Emptying the selection empties the app's sheet — «0 صنف» on both sides. */
    public function test_an_emptied_schedule_empties_the_app_list(): void
    {
        $pizza = $this->stockedItem('بيتزا', 'D005');
        $schedule = $this->scheduleFor($pizza);

        DailyInventoryScheduleItem::where('daily_inventory_schedule_id', $schedule->id)->delete();

        $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/inventory/daily-quick/branch-items')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    /** A scheduled item the branch never stocked is still countable. */
    public function test_a_scheduled_item_without_a_branch_row_is_listed_and_countable(): void
    {
        $item = Item::create(['name' => 'خضار', 'code' => 'D007', 'unit' => 'كجم', 'is_active' => true]);
        $this->scheduleFor($item);

        $rows = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/inventory/daily-quick/branch-items')
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($item->id, $rows[0]['item_id']);
        $this->assertNull($rows[0]['branch_item_id']);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/inventory/daily-quick/sessions', [
                'assigned_to_type' => 'personal',
                'inventory_date' => today()->toDateString(),
                'start_time' => now()->format('Y-m-d H:i:s'),
                'items' => [['item_id' => $item->id, 'quantity' => 3]],
            ])
            ->assertStatus(200);

        $this->assertNotNull(
            BranchItem::where('branch_id', $this->branch->id)->where('item_id', $item->id)->first(),
            'counting a scheduled item must not fail on a missing pivot row'
        );
    }
}
