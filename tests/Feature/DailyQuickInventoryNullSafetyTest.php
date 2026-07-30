<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\Item;
use Tests\TestCase;

/**
 * Meeting 2026-07-30: the Daily Quick Inventory screen crashed with
 * "type 'Null' is not a subtype of type 'String' in type cast" — items seeded
 * from the dashboard Excel upload carry NULL code/unit/category/subcategory
 * (and `items.logo` is an array cast). Every string the app casts must be a
 * string, and soft-deleted catalog items must not surface as ghost rows.
 */
class DailyQuickInventoryNullSafetyTest extends TestCase
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

    /** Exactly what UploadController::importCatalogRow writes for a sparse sheet row. */
    private function uploadedItem(): Item
    {
        $item = Item::create([
            'name' => 'لحمة',
            'code' => null,
            'unit' => null,
            'category' => null,
            'subcategory' => null,
            'is_active' => true,
        ]);

        BranchItem::create([
            'branch_id' => $this->branch->id,
            'item_id' => $item->id,
            'price' => 0,
            'quantity' => 10,
        ]);

        return $item;
    }

    public function test_branch_items_returns_strings_for_sparse_uploaded_rows(): void
    {
        $this->uploadedItem();

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/inventory/daily-quick/branch-items');

        $response->assertStatus(200);
        $rows = $response->json('data');
        $this->assertNotEmpty($rows);

        foreach ($rows as $row) {
            foreach (['item_name', 'item_code', 'item_logo', 'item_unit', 'category', 'subcategory'] as $field) {
                $this->assertIsString($row[$field], "{$field} must be a string, got: ".gettype($row[$field]));
            }
        }
        $this->assertSame('لحمة', $rows[0]['item_name']);
        $this->assertSame('kg', $rows[0]['item_unit']);
    }

    public function test_populated_rows_keep_their_own_values(): void
    {
        $item = Item::create([
            'name' => 'طحين',
            'code' => 'RM-7',
            'unit' => 'حبة',
            'category' => 'جاف',
            'subcategory' => 'دقيق',
            'is_active' => true,
        ]);
        BranchItem::create([
            'branch_id' => $this->branch->id,
            'item_id' => $item->id,
            'price' => 9.25,
            'quantity' => 5,
        ]);

        $row = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/inventory/daily-quick/branch-items')
            ->assertStatus(200)
            ->json('data.0');

        $this->assertSame('طحين', $row['item_name']);
        $this->assertSame('RM-7', $row['item_code']);
        $this->assertSame('حبة', $row['item_unit']);
        $this->assertSame('جاف', $row['category']);
        $this->assertSame('دقيق', $row['subcategory']);
    }

    public function test_soft_deleted_item_ghost_row_is_hidden_from_the_picker(): void
    {
        $item = $this->uploadedItem();
        // ProcurementCatalogBridgeService::deactivateItem path: item soft-deleted
        // while the branch still holds stock.
        $item->delete();

        $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/inventory/daily-quick/branch-items')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }
}
