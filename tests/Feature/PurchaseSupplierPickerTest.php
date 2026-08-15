<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabSupplier;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\Item;
use Modules\Purchase\Models\SupplierItem;
use Modules\Supplier\Models\Supplier;
use Tests\TestCase;

/**
 * Prod 2026-08-01, «New Purchase Order»: the supplier picker came back empty
 * for a supplier that had a full catalog, and the products screen crashed with
 * "type 'Null' is not a subtype of type 'num' in type cast".
 *
 * Three causes, all locked here:
 *  1. Supplier had no available/byStatus/byDeliveryTime/byRating/search scopes,
 *     so ANY filtered supplier request 500'd (the picker sends `search=` on
 *     every load) and `GET /orders/suppliers` with no item_id hit a TypeError.
 *  2. `/orders/supplier-items` paginated branch_item rows, so a catalog item the
 *     branch had never stocked was dropped from the supplier's list.
 *  3. Nullable numerics (rating, delivery_hours, min/max qty, prices…) reached
 *     the app raw.
 */
class PurchaseSupplierPickerTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private BranchManager $manager;

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $company = AsabCompany::create(['name' => 'Picker Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => 'براند', 'sub_status' => 'active', 'status' => 'active']);

        $this->branch = Branch::factory()->create([
            'asab_brand_id' => $brand->id,
            'asab_company_id' => $company->id,
        ]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);

        // Exactly what ProcurementCatalogBridgeService::provisionSupplier writes:
        // name + email, every metric column left null.
        $this->supplier = Supplier::forceCreate([
            'name' => 'مورد عصب',
            'email' => 'asab@supplier.test',
            'is_active' => true,
        ]);

        AsabSupplier::create([
            'company_id' => $company->id,
            'brand_id' => $brand->id,
            'name' => 'مورد عصب',
            'status' => 'active',
        ])->forceFill(['legacy_supplier_id' => $this->supplier->id])->save();
    }

    /** A catalog row as sparse as the bridge writes it: price only. */
    private function catalogItem(string $name, bool $stockedByBranch): Item
    {
        $item = Item::create(['name' => $name, 'is_active' => true]);

        SupplierItem::create([
            'supplier_id' => $this->supplier->id,
            'item_id' => $item->id,
            'unit_price' => 12.5,
            'is_available' => true,
        ]);

        if ($stockedByBranch) {
            BranchItem::create([
                'branch_id' => $this->branch->id,
                'item_id' => $item->id,
                'price' => 11,
                'quantity' => 0,
            ]);
        }

        return $item;
    }

    /** @param  array<int, string>  $fields */
    private function assertNumeric(array $row, array $fields): void
    {
        foreach ($fields as $field) {
            $this->assertArrayHasKey($field, $row);
            $this->assertNotNull($row[$field], "{$field} must never be null — the app casts it with `as num`");
            $this->assertIsNumeric($row[$field], "{$field} must be numeric, got: ".gettype($row[$field]));
        }
    }

    public function test_the_supplier_list_survives_the_filters_the_picker_sends(): void
    {
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/purchase/suppliers?search=&status=&min_rating=&max_delivery_hours=');

        $response->assertStatus(200);
        $this->assertSame($this->supplier->id, $response->json('data.0.id'));
    }

    public function test_the_supplier_list_filters_by_search_term(): void
    {
        Supplier::forceCreate(['name' => 'مورد آخر', 'email' => 'other@supplier.test', 'is_active' => true]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/purchase/suppliers?search=عصب');

        $response->assertStatus(200)->assertJsonCount(1, 'data');
    }

    public function test_an_invalid_status_filter_is_a_400_not_a_500(): void
    {
        $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/purchase/suppliers?status=sleeping')
            ->assertStatus(400);
    }

    public function test_all_suppliers_without_an_item_id_returns_the_brands_suppliers(): void
    {
        $this->catalogItem('طماطم', stockedByBranch: true);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/purchase/orders/suppliers');

        $response->assertStatus(200);

        $rows = $response->json('data');
        $this->assertCount(1, $rows);
        $this->assertSame($this->supplier->id, $rows[0]['supplier_id']);
        $this->assertNumeric($rows[0], ['unit_price', 'economy_price', 'standard_price', 'premium_price', 'delivery_hours', 'rating']);
        $this->assertNumeric($rows[0]['supplier'], [
            'default_delivery_hours', 'min_order_amount', 'average_response_time_hours',
            'response_rate_percentage', 'rating', 'total_orders', 'completed_orders',
        ]);
    }

    public function test_an_unlinked_branch_still_gets_an_empty_supplier_list(): void
    {
        $branch = Branch::factory()->create(['asab_brand_id' => null, 'asab_company_id' => null, 'asab_restaurant_id' => null]);
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/purchase/orders/suppliers')
            ->assertStatus(200)
            ->assertJsonCount(0, 'data');
    }

    public function test_supplier_items_include_an_item_the_branch_does_not_stock(): void
    {
        $stocked = $this->catalogItem('طماطم', stockedByBranch: true);
        $unstocked = $this->catalogItem('بيتزا', stockedByBranch: false);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/purchase/orders/supplier-items?supplier_id='.$this->supplier->id);

        $response->assertStatus(200);

        $itemIds = collect($response->json('data'))->pluck('item_id')->all();
        $this->assertContains($stocked->id, $itemIds);
        $this->assertContains($unstocked->id, $itemIds, 'a supplier catalog item the branch never stocked must still be orderable');
    }

    public function test_supplier_item_rows_carry_no_null_numerics(): void
    {
        $this->catalogItem('بيتزا', stockedByBranch: false);

        $row = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/purchase/orders/supplier-items?supplier_id='.$this->supplier->id)
            ->assertStatus(200)
            ->json('data.0');

        $this->assertNumeric($row, [
            'item_price', 'total_amount', 'price_rate', 'economy_price', 'standard_price',
            'premium_price', 'min_order_quantity', 'max_order_quantity', 'delivery_hours',
            'delivery_days', 'rating',
        ]);
    }

    public function test_direct_supplier_items_work_for_an_item_the_branch_does_not_stock(): void
    {
        $unstocked = $this->catalogItem('بيتزا', stockedByBranch: false);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/purchase/orders/direct-supplier-items?item_id='.$unstocked->id);

        $response->assertStatus(200);

        $row = $response->json('data.0');
        $this->assertSame($this->supplier->id, $row['supplier_id']);
        $this->assertNumeric($row, [
            'item_price', 'total_amount', 'price_rate', 'economy_price', 'standard_price', 'premium_price',
            'delivery_hours', 'delivery_days', 'availability_percentage', 'min_order_quantity',
            'max_order_quantity', 'distance_km', 'estimated_hours', 'rating', 'response_rate',
        ]);
    }

    public function test_suppliers_for_an_item_are_found_by_item_id(): void
    {
        $item = $this->catalogItem('طماطم', stockedByBranch: true);

        $rows = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/purchase/orders/suppliers?item_id='.$item->id.'&search=')
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($this->supplier->id, $rows[0]['supplier_id']);
    }

    /**
     * The item cards carry both ids (`id` = the branch_item pivot row,
     * `item_id` = the catalog item). Sending the pivot id matched no supplier
     * catalog row — «All Suppliers (n)» above an empty list (2026-08-15).
     */
    public function test_suppliers_for_an_item_are_found_by_branch_item_id(): void
    {
        $item = $this->catalogItem('طماطم', stockedByBranch: true);
        $pivotId = BranchItem::where('branch_id', $this->branch->id)->where('item_id', $item->id)->value('id');

        $rows = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/purchase/orders/suppliers?item_id='.$pivotId.'&search=')
            ->assertStatus(200)
            ->json('data');

        $this->assertCount(1, $rows);
        $this->assertSame($this->supplier->id, $rows[0]['supplier_id']);
    }

    public function test_the_item_cards_supplier_count_matches_the_picker(): void
    {
        $sold = $this->catalogItem('طماطم', stockedByBranch: true);

        $unsold = Item::create(['name' => 'ملح', 'is_active' => true]);
        BranchItem::create(['branch_id' => $this->branch->id, 'item_id' => $unsold->id, 'price' => 3, 'quantity' => 0]);

        $rows = collect(
            $this->actingAs($this->manager, 'sanctum')
                ->getJson('/api/v1/purchase/orders/branch-items')
                ->assertStatus(200)
                ->json('data')
        )->keyBy('item_id');

        $this->assertSame(1, $rows[$sold->id]['suppliers_count']);
        // Counted from expense invoices before, which had nothing to do with
        // the suppliers the picker offers.
        $this->assertSame(0, $rows[$unsold->id]['suppliers_count']);
    }
}
