<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Inventory\Events\MonthlyInventorySessionUpdated;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\Item;
use Tests\TestCase;

class MonthlyInventoryRealtimeParityTest extends TestCase
{
    use RefreshDatabase;

    public function test_monthly_inventory_seeds_products_from_branch_assignments(): void
    {
        $branch = Branch::factory()->create();
        /** @var BranchManager $manager */
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);

        $staff = Cashier::factory()->count(3)->create([
            'branch_id' => $branch->id,
            'created_by' => $manager->id,
            'status' => 'active',
        ]);

        $itemA = Item::create([
            'name' => 'Milk',
            'code' => 'MILK-1',
            'unit' => 'liter',
            'is_active' => true,
        ]);
        $itemB = Item::create([
            'name' => 'Flour',
            'code' => 'FLOUR-1',
            'unit' => 'kg',
            'is_active' => true,
        ]);

        BranchItem::create([
            'branch_id' => $branch->id,
            'item_id' => $itemA->id,
            'price' => 12.5,
            'quantity' => 100,
        ]);
        BranchItem::create([
            'branch_id' => $branch->id,
            'item_id' => $itemB->id,
            'price' => 7.0,
            'quantity' => 50,
        ]);

        $response = $this->actingAs($manager, 'sanctum')
            ->postJson('/api/v1/inventory/monthly', [
                'inventory_date' => now()->toDateString(),
                'staff' => $staff->pluck('id')->all(),
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true);

        $inventoryId = $response->json('data.id');
        $this->assertDatabaseHas('monthly_inventory_products', [
            'monthly_inventory_id' => $inventoryId,
            'item_id' => $itemA->id,
            'branch_id' => $branch->id,
        ]);
        $this->assertDatabaseHas('monthly_inventory_products', [
            'monthly_inventory_id' => $inventoryId,
            'item_id' => $itemB->id,
            'branch_id' => $branch->id,
        ]);
    }

    public function test_realtime_events_are_dispatched_and_claim_conflict_returns_409(): void
    {
        Event::fake([MonthlyInventorySessionUpdated::class]);

        $branch = Branch::factory()->create();
        /** @var BranchManager $manager */
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        /** @var Cashier $cashierOne */
        $cashierOne = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id, 'status' => 'active']);
        /** @var Cashier $cashierTwo */
        $cashierTwo = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id, 'status' => 'active']);
        /** @var Cashier $cashierThree */
        $cashierThree = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id, 'status' => 'active']);

        $item = Item::create([
            'name' => 'Sugar',
            'code' => 'SUGAR-1',
            'unit' => 'kg',
            'is_active' => true,
        ]);
        BranchItem::create([
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'price' => 9.5,
            'quantity' => 100,
        ]);

        $create = $this->actingAs($manager, 'sanctum')
            ->postJson('/api/v1/inventory/monthly', [
                'inventory_date' => now()->toDateString(),
                'staff' => [$cashierOne->id, $cashierTwo->id, $cashierThree->id],
            ]);
        $create->assertStatus(201);

        $inventoryId = $create->json('data.id');
        $productId = $this->actingAs($manager, 'sanctum')
            ->getJson('/api/v1/inventory/monthly/'.$inventoryId.'/products')
            ->json('data.0.id');

        $claimOne = $this->actingAs($cashierOne, 'sanctum')
            ->postJson("/api/v1/inventory/monthly/{$inventoryId}/products/{$productId}/claim");
        $claimOne->assertStatus(200);

        $claimTwo = $this->actingAs($cashierTwo, 'sanctum')
            ->postJson("/api/v1/inventory/monthly/{$inventoryId}/products/{$productId}/claim");
        $claimTwo->assertStatus(409)
            ->assertJsonPath('success', false);

        $updateByHandler = $this->actingAs($cashierOne, 'sanctum')
            ->putJson("/api/v1/inventory/monthly/{$inventoryId}/products/{$productId}", [
                'quantity_inventory' => 12,
            ]);
        $updateByHandler->assertStatus(200)
            ->assertJsonPath('data.counted_by.id', $cashierOne->id);

        $listAfterCount = $this->actingAs($cashierTwo, 'sanctum')
            ->getJson("/api/v1/inventory/monthly/{$inventoryId}/products");
        $listAfterCount->assertStatus(200)
            ->assertHeader('Cache-Control');
        $row = collect($listAfterCount->json('data'))->firstWhere('id', $productId);
        $this->assertNotNull($row, 'Product row should exist in list');
        $this->assertEquals($cashierOne->id, $row['handled_by']['id']);
        $this->assertEquals($cashierOne->id, $row['counted_by']['id']);

        $countedOnly = $this->actingAs($manager, 'sanctum')
            ->getJson("/api/v1/inventory/monthly/{$inventoryId}/products?counted_only=1");
        $countedOnly->assertStatus(200);
        $this->assertNotNull(collect($countedOnly->json('data'))->firstWhere('id', $productId));

        $releaseByOther = $this->actingAs($cashierTwo, 'sanctum')
            ->postJson("/api/v1/inventory/monthly/{$inventoryId}/products/{$productId}/release");
        $releaseByOther->assertStatus(403);

        $releaseByHandler = $this->actingAs($cashierOne, 'sanctum')
            ->postJson("/api/v1/inventory/monthly/{$inventoryId}/products/{$productId}/release");
        $releaseByHandler->assertStatus(200);

        Event::assertDispatched(MonthlyInventorySessionUpdated::class, function (MonthlyInventorySessionUpdated $event) use ($inventoryId) {
            return $event->inventoryId === $inventoryId && in_array($event->eventType, [
                'product.claimed',
                'product.updated',
                'product.released',
            ], true);
        });
    }
}
