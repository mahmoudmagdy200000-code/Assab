<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\Item;
use Tests\TestCase;

/**
 * Meeting 2026-08-05 «منتجات المشتريات لا تظهر في الهدر والتالف لمدير فرع
 * الريان»: the picker listed items from CLOSED purchase orders only, so a
 * branch that had not closed an order yet could not report waste on anything —
 * however full its purchase catalog was.
 */
class WasteDamageBranchCatalogProductsTest extends TestCase
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

    private function assign(string $name, ?string $unit, string $code, float $price = 12.5): Item
    {
        $item = Item::create(['name' => $name, 'code' => $code, 'unit' => $unit, 'category' => 'مواد خام', 'is_active' => true]);
        BranchItem::create(['branch_id' => $this->branch->id, 'item_id' => $item->id, 'price' => $price, 'quantity' => 0]);

        return $item;
    }

    public function test_branch_catalog_products_are_listed_without_any_closed_order(): void
    {
        $item = $this->assign('شرائح دجاج متبلة', 'كجم', 'RM-002');

        $res = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/inventory/waste-damage/products-from-closed-orders')
            ->assertStatus(200);

        $rows = collect($res->json('data'));
        $this->assertCount(1, $rows);
        $this->assertSame($item->id, $rows[0]['item_id']);
        // No order line behind it. Sent as '' rather than null because the app
        // casts it with `as String`; ConvertEmptyStringsToNull turns it back
        // into the null the report writer expects on submit.
        $this->assertSame('', $rows[0]['purchase_order_item_id']);
        $this->assertSame('كجم', $rows[0]['item_unit']);
        $this->assertSame(12.5, (float) $rows[0]['price_per_unit']);

        // «type 'Null' is not a subtype of type 'String'» — one null anywhere
        // in this payload takes the whole screen down.
        foreach ($rows[0] as $key => $value) {
            $this->assertNotNull($value, "field {$key} must never be null");
        }
    }

    public function test_the_search_filter_applies_to_the_catalog_half_too(): void
    {
        $this->assign('صوص باربكيو', 'كجم', 'RM-015');
        $this->assign('بصل أبيض', 'كجم', 'RM-010');

        $res = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/inventory/waste-damage/products-from-closed-orders?search=بصل')
            ->assertStatus(200);

        $rows = collect($res->json('data'));
        $this->assertCount(1, $rows);
        $this->assertSame('بصل أبيض', $rows[0]['item_name']);
    }

    /** Another branch's assigned items must not leak into this list. */
    public function test_other_branches_items_do_not_leak(): void
    {
        $this->assign('زيت قلي نباتي', 'لتر', 'RM-017');

        $other = Branch::factory()->create();
        $foreign = Item::create(['name' => 'صنف فرع آخر', 'code' => 'RM-999', 'unit' => 'كجم', 'is_active' => true]);
        BranchItem::create(['branch_id' => $other->id, 'item_id' => $foreign->id, 'price' => 1, 'quantity' => 0]);

        $res = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/inventory/waste-damage/products-from-closed-orders')
            ->assertStatus(200);

        $names = collect($res->json('data'))->pluck('item_name');
        $this->assertTrue($names->contains('زيت قلي نباتي'));
        $this->assertFalse($names->contains('صنف فرع آخر'));
    }

    /** A blank legacy unit falls back to a neutral label, never to «kg». */
    public function test_a_blank_unit_is_not_reported_as_kg(): void
    {
        $this->assign('بهارات دجاج', null, 'RM-020');

        $res = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/inventory/waste-damage/products-from-closed-orders')
            ->assertStatus(200);

        $this->assertSame('unit', $res->json('data.0.item_unit'));
    }
}
