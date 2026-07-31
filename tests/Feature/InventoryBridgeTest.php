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

    /**
     * The branch item's own price must cross the bridge: the reconciliation screen
     * prices variances from the ASAB catalog, and a mobile payload carries LEGACY
     * item ids, so every variance was valued at 0.00 ر.س (prod E2E 2026-07-31).
     */
    public function test_the_payload_carries_the_branch_unit_price(): void
    {
        $session = $this->submittedSession();

        $op = Operation::withoutGlobalScopes()
            ->where('source_module', 'inventory')->where('source_id', $session->id)->firstOrFail();

        $this->assertSame(5000, $op->payload['items'][0]['unitPriceHalalas']);
    }

    /**
     * A first count has no prior approved session, so the mobile calculation opens
     * at zero and subtracts the day's sales and waste — yielding a NEGATIVE
     * theoretical stock. Stock is never negative; the baseline is simply unknown,
     * and the contract renders a null expectedQty as «—».
     */
    public function test_a_negative_theoretical_expectation_is_reported_as_unknown(): void
    {
        $session = $this->submittedSession();

        $op = Operation::withoutGlobalScopes()
            ->where('source_module', 'inventory')->where('source_id', $session->id)->firstOrFail();

        \Modules\Inventory\Models\DailyInventoryDiscrepancy::updateOrCreate(
            [
                'inventory_session_id' => $session->id,
                'inventory_item_id' => $session->items()->first()->id,
            ],
            [
                'item_id' => $session->items()->first()->item_id,
                'opening_balance' => 0, 'purchases' => 0, 'sales' => 1.0, 'recorded_waste' => 0.5,
                'net_transfer_in' => 0, 'net_transfer_out' => 0,
                'theoretically_expected' => -1.5, 'actual' => 3,
                'difference_quantity' => -4.5,
            ],
        );

        app(\Modules\Admin\Services\InventoryBridgeService::class)->sync($session->fresh());

        $this->assertNull($op->fresh()->payload['items'][0]['expectedQty']);
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
