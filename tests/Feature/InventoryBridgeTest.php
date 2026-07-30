<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\Operation;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Inventory\Models\InventoryItem;
use Modules\Inventory\Models\InventorySession;
use Modules\Inventory\Services\InventorySessionService;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\Item;
use Tests\TestCase;

/**
 * Meeting 2026-07-30: a submitted mobile daily inventory must reach the
 * accountant as an INV- operation with payload.items (the key the ASAB
 * readers iterate), dated by inventory_date.
 */
class InventoryBridgeTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private BranchManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = AsabCompany::create(['name' => 'Co', 'plan' => 'Basic', 'status' => 'active'])->id;
        $brand = AsabBrand::create([
            'company_id' => $companyId, 'name' => 'برجر بيت', 'abbr' => 'BB',
            'sub_status' => 'active', 'status' => 'active',
        ]);
        $this->branch = Branch::factory()->create([
            'asab_company_id' => $companyId,
            'asab_brand_id' => $brand->id,
        ]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
    }

    private function submittedSession(): InventorySession
    {
        $item = Item::create(['name' => 'لحمة', 'unit' => 'kg', 'category' => 'لحوم', 'is_active' => true]);
        BranchItem::create(['branch_id' => $this->branch->id, 'item_id' => $item->id, 'price' => 50, 'quantity' => 10]);

        $session = InventorySession::create([
            'branch_id' => $this->branch->id,
            'inventory_date' => now()->subDay()->toDateString(),
            'status' => \Modules\Inventory\Enums\InventorySessionStatus::DRAFT,
            'created_by' => $this->manager->id,
        ]);
        InventoryItem::create([
            'inventory_session_id' => $session->id,
            'branch_id' => $this->branch->id,
            'item_id' => $item->id,
            'item_name' => 'لحمة',
            'quantity_inventory' => 7.5,
        ]);

        $this->actingAs($this->manager, 'sanctum');

        return app(InventorySessionService::class)->submitSession($session->id, $this->manager);
    }

    public function test_submitting_a_session_mints_an_inv_operation(): void
    {
        $session = $this->submittedSession();

        $op = Operation::withoutGlobalScopes()
            ->where('source_module', 'inventory')->where('source_id', $session->id)->first();

        $this->assertNotNull($op, 'a submitted inventory must reach the accountant inbox');
        $this->assertSame('inventory', $op->module_key);
        $this->assertSame($this->branch->id, $op->branch_id);
        $this->assertStringStartsWith('INV-', $op->public_id);
        $this->assertSame('daily', $op->payload['countType']);
        // operation_date = inventory day, not the bridge-run day.
        $this->assertSame(now()->subDay()->toDateString(), $op->operation_date->toDateString());

        $row = $op->payload['items'][0];
        $this->assertSame('لحمة', $row['name']);
        $this->assertSame('لحوم', $row['category']);
        $this->assertEquals(7.5, $row['actualQty']);
    }

    public function test_resubmit_is_idempotent(): void
    {
        $session = $this->submittedSession();

        event(new \Modules\Inventory\Events\InventorySessionSubmittedEvent($session->fresh()));

        $this->assertSame(1, Operation::withoutGlobalScopes()
            ->where('source_module', 'inventory')->where('source_id', $session->id)->count());
    }

    public function test_unlinked_branch_mints_nothing(): void
    {
        $unlinked = Branch::factory()->create(['asab_company_id' => null, 'asab_brand_id' => null, 'asab_restaurant_id' => null]);
        $manager = BranchManager::factory()->create(['branch_id' => $unlinked->id]);
        $item = Item::create(['name' => 'خبز', 'unit' => 'pk', 'is_active' => true]);

        $session = InventorySession::create([
            'branch_id' => $unlinked->id,
            'inventory_date' => now()->toDateString(),
            'status' => \Modules\Inventory\Enums\InventorySessionStatus::DRAFT,
            'created_by' => $manager->id,
        ]);
        InventoryItem::create([
            'inventory_session_id' => $session->id,
            'branch_id' => $unlinked->id,
            'item_id' => $item->id,
            'item_name' => 'خبز',
            'quantity_inventory' => 3,
        ]);

        $this->actingAs($manager, 'sanctum');
        app(InventorySessionService::class)->submitSession($session->id, $manager);

        $this->assertSame(0, Operation::withoutGlobalScopes()
            ->where('source_module', 'inventory')->where('source_id', $session->id)->count());
    }
}
