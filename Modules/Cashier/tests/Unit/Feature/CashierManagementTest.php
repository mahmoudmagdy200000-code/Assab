<?php

namespace Modules\Cashier\Tests\Unit\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Tests\TestCase;

class CashierManagementTest extends TestCase
{
    use RefreshDatabase;

    protected BranchManager $manager;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->manager = BranchManager::factory()->create([
            'branch_id' => $this->branch->id,
        ]);
    }

    /** @test */
    public function branch_manager_can_view_cashiers_list()
    {
        Cashier::factory()->count(3)->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/branch-manager/cashiers');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'email',
                        'status',
                    ],
                ],
            ]);
    }

    /** @test */
    public function branch_manager_can_create_cashier()
    {
        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/branch-manager/cashiers', [
                'name' => 'Test Cashier',
                'email' => 'testcashier@assab.com',
                'phone' => '+966500000001',
                'shift_ids' => [1, 2],
            ]);

        $response->assertStatus(201)
            ->assertJsonStructure([
                'success',
                'message',
                'data' => [
                    'id',
                    'name',
                    'email',
                    'status',
                ],
            ]);

        $this->assertDatabaseHas('cashiers', [
            'email' => 'testcashier@assab.com',
            'branch_id' => $this->branch->id,
            'status' => 'pending',
        ]);
    }

    /** @test */
    public function branch_manager_can_activate_cashier()
    {
        $cashier = Cashier::factory()->pending()->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/branch-manager/cashiers/{$cashier->id}/activate");

        $response->assertStatus(200);

        $this->assertDatabaseHas('cashiers', [
            'id' => $cashier->id,
            'status' => 'active',
        ]);
    }

    /** @test */
    public function branch_manager_can_deactivate_cashier()
    {
        $cashier = Cashier::factory()->active()->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/branch-manager/cashiers/{$cashier->id}/deactivate");

        $response->assertStatus(200);

        $this->assertDatabaseHas('cashiers', [
            'id' => $cashier->id,
            'status' => 'deactivated',
        ]);
    }

    /** @test */
    public function branch_manager_cannot_view_cashiers_from_other_branch()
    {
        $otherBranch = Branch::factory()->create();
        $otherCashier = Cashier::factory()->create([
            'branch_id' => $otherBranch->id,
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/v1/branch-manager/cashiers/{$otherCashier->id}");

        $response->assertStatus(403);
    }

    /** @test */
    public function cashier_email_must_be_unique()
    {
        $existingCashier = Cashier::factory()->create([
            'email' => 'existing@assab.com',
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/branch-manager/cashiers', [
                'name' => 'Test Cashier',
                'email' => 'existing@assab.com',
                'shift_ids' => [1],
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors('email');
    }

    /** @test */
    public function branch_manager_can_search_cashiers()
    {
        Cashier::factory()->create([
            'name' => 'Ahmed Ali',
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
        ]);

        Cashier::factory()->create([
            'name' => 'Mohammed Hassan',
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/branch-manager/cashiers/search?query=Ahmed');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data');
    }

    /** @test */
    public function branch_manager_can_filter_cashiers_by_status()
    {
        Cashier::factory()->count(2)->active()->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
        ]);

        Cashier::factory()->pending()->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/branch-manager/cashiers?status=active');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }
}
