<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Inventory\Enums\ProblemType;
use Modules\Inventory\Enums\WasteDamageReason;
use Modules\Inventory\Models\WasteDamageReport;
use Modules\Inventory\Models\WasteDamageReportItem;
use Modules\Purchase\Models\BranchItem;
use Modules\Purchase\Models\Item;
use Tests\TestCase;

class WasteDamageReportTest extends TestCase
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

    public function test_assignment_info_returns_branch_metadata(): void
    {
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/inventory/waste-damage/assignment-info');

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.branch_id', $this->branch->id)
            ->assertJsonPath('data.branch_name', $this->branch->name);
    }

    public function test_assignment_info_fails_when_actor_is_not_branch_manager(): void
    {
        /** @var Cashier $cashier */
        $cashier = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);

        $response = $this->actingAs($cashier, 'sanctum')
            ->getJson('/api/v1/inventory/waste-damage/assignment-info');

        $response->assertStatus(403)
            ->assertJsonPath('success', false);
    }

    public function test_create_report_returns_draft_report(): void
    {
        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/inventory/waste-damage/reports', [
                'assigned_to_type' => 'personal',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.branch_id', $this->branch->id);

        $this->assertDatabaseHas('waste_damage_reports', [
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
            'status' => 'pending',
        ]);
    }

    public function test_list_reports_returns_branch_scoped_reports(): void
    {
        WasteDamageReport::factory()->count(2)->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/inventory/waste-damage/reports');

        $response->assertStatus(200)
            ->assertJsonPath('success', true);
        $this->assertGreaterThanOrEqual(2, count($response->json('data')));
    }

    public function test_show_report_returns_report_with_items(): void
    {
        $report = WasteDamageReport::factory()->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/inventory/waste-damage/reports/{$report->id}");

        $response->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.id', $report->id);
    }

    public function test_submit_report_without_items_fails_validation(): void
    {
        $report = WasteDamageReport::factory()->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/inventory/waste-damage/reports/{$report->id}/submit");

        $response->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_add_item_and_submit_succeeds_for_waste_without_photo(): void
    {
        $item = Item::create([
            'name' => 'Test Product',
            'code' => 'TEST-001',
            'unit' => 'kg',
            'category' => 'Dairy',
            'is_active' => true,
        ]);
        BranchItem::create([
            'branch_id' => $this->branch->id,
            'item_id' => $item->id,
            'price' => 10,
            'quantity' => 100,
        ]);

        $report = WasteDamageReport::factory()->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
        ]);

        $addResponse = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/inventory/waste-damage/reports/{$report->id}/items", [
                'item_id' => $item->id,
                'problem_type' => ProblemType::WASTE->value,
                'quantity' => 5,
                'reason' => WasteDamageReason::EXPIRED_PRODUCT->value,
                'justification_text' => 'Test justification',
            ]);

        $addResponse->assertStatus(201);

        $submitResponse = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/inventory/waste-damage/reports/{$report->id}/submit");

        $submitResponse->assertStatus(200);
        // Personal (manager) submissions land in PENDING — pending_your_confirmation
        // is a legacy value kept only for pre-2026-05-26 staff rows.
        $this->assertDatabaseHas('waste_damage_reports', [
            'id' => $report->id,
            'status' => 'pending',
        ]);
    }

    public function test_delete_item_removes_item_from_report(): void
    {
        $item = Item::create([
            'name' => 'Test Product 2',
            'code' => 'TEST-002',
            'unit' => 'liter',
            'category' => 'Beverages',
            'is_active' => true,
        ]);
        BranchItem::create([
            'branch_id' => $this->branch->id,
            'item_id' => $item->id,
            'price' => 6,
            'quantity' => 50,
        ]);

        $report = WasteDamageReport::factory()->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
        ]);
        $reportItem = WasteDamageReportItem::create([
            'waste_damage_report_id' => $report->id,
            'branch_id' => $this->branch->id,
            'item_id' => $item->id,
            'problem_type' => ProblemType::WASTE->value,
            'quantity' => 10,
            'reason' => WasteDamageReason::EXPIRED_PRODUCT->value,
            'unit' => 'liter',
            'total_value' => -60,
            'price_per_unit' => 6,
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->deleteJson("/api/v1/inventory/waste-damage/reports/{$report->id}/items/{$reportItem->id}");

        $response->assertStatus(200);
        $this->assertSoftDeleted('waste_damage_report_items', ['id' => $reportItem->id]);
    }
}
