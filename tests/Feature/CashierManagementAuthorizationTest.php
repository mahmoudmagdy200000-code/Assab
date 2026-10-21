<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Tests\TestCase;

class CashierManagementAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private BranchManager $manager;
    private Cashier $cashier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create();
        $this->manager = BranchManager::factory()->create([
            'branch_id' => $this->branch->id,
            'is_active' => true,
        ]);
        $this->cashier = Cashier::factory()->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
            'status' => 'active',
        ]);
    }

    /**
     * Audit Finding F3 / R03: Cashier calling branch-manager/cashiers/assign-shifts
     * MUST receive HTTP 403 Forbidden, NOT HTTP 500 SERVER_ERROR.
     */
    public function test_cashier_token_calling_assign_shifts_is_denied_with_403_not_500(): void
    {
        $response = $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/v1/branch-manager/cashiers/assign-shifts', [
                'cashier_id' => $this->cashier->id,
                'shift_id' => '00000000-0000-0000-0000-000000000001',
                'date' => now()->toDateString(),
            ]);

        // Must be 403, and specifically NOT 500
        $this->assertNotSame(500, $response->status(), 'Expected HTTP 403 Forbidden but received HTTP 500 Server Error (R03 defect)');
        $response->assertStatus(403);
    }

    /**
     * Cashier calling branch-manager/cashiers/available-for-shift is denied with 403.
     */
    public function test_cashier_token_calling_available_for_shift_is_denied_with_403(): void
    {
        $response = $this->actingAs($this->cashier, 'sanctum')
            ->getJson('/api/v1/branch-manager/cashiers/available-for-shift');

        $response->assertStatus(403);
    }

    /**
     * Cashier calling branch-manager/cashiers/update-shifts is denied with 403.
     */
    public function test_cashier_token_calling_update_shifts_is_denied_with_403(): void
    {
        $response = $this->actingAs($this->cashier, 'sanctum')
            ->putJson('/api/v1/branch-manager/cashiers/update-shifts', [
                'cashier_id' => $this->cashier->id,
                'shift_ids' => ['00000000-0000-0000-0000-000000000001'],
                'shift_date' => now()->toDateString(),
            ]);

        $this->assertNotSame(500, $response->status());
        $response->assertStatus(403);
    }

    /**
     * Unauthenticated request is denied with 401.
     */
    public function test_unauthenticated_request_to_cashier_management_is_denied_with_401(): void
    {
        $response = $this->postJson('/api/v1/branch-manager/cashiers/assign-shifts');

        $response->assertStatus(401);
    }

    /**
     * Legitimate branch manager is permitted to access cashier management routes.
     */
    public function test_branch_manager_access_is_permitted(): void
    {
        $response = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/v1/branch-manager/cashiers/available-for-shift');

        // Manager is authorized (not 403, not 401, not 500)
        $this->assertNotSame(403, $response->status());
        $this->assertNotSame(401, $response->status());
        $this->assertNotSame(500, $response->status());
    }

    /**
     * Cashier token calling POST /branch-manager/cashiers (store cashier) is denied with 403.
     */
    public function test_cashier_token_calling_store_cashier_is_denied_with_403(): void
    {
        $response = $this->actingAs($this->cashier, 'sanctum')
            ->postJson('/api/v1/branch-manager/cashiers', [
                'name' => 'Unauthorized Cashier',
                'email' => 'unauth-cashier@assab.test',
                'store_branch_id' => $this->branch->id,
                'shift_ids' => ['00000000-0000-0000-0000-000000000001'],
            ]);

        $response->assertStatus(403);
    }

    /**
     * Cashier token calling PUT /branch-manager/cashiers/{cashier} (update cashier) is denied with 403.
     */
    public function test_cashier_token_calling_update_cashier_is_denied_with_403(): void
    {
        $response = $this->actingAs($this->cashier, 'sanctum')
            ->putJson("/api/v1/branch-manager/cashiers/{$this->cashier->id}", [
                'name' => 'Renamed Cashier',
            ]);

        $response->assertStatus(403);
    }

    /**
     * Manager cannot assign shifts to a cashier of another branch (Cross-Branch Isolation).
     */
    public function test_manager_cannot_assign_shifts_to_cashier_of_foreign_branch(): void
    {
        $foreignBranch = Branch::factory()->create();
        $foreignCashier = Cashier::factory()->create([
            'branch_id' => $foreignBranch->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/v1/branch-manager/cashiers/assign-shifts', [
                'cashier_id' => $foreignCashier->id,
                'shift_ids' => ['00000000-0000-0000-0000-000000000001'],
                'shift_date' => now()->toDateString(),
            ]);

        $this->assertContains($response->status(), [403, 404, 422]);
    }

    /**
     * Manager cannot update a cashier of another branch (Cross-Branch Isolation).
     */
    public function test_manager_cannot_update_cashier_of_foreign_branch(): void
    {
        $foreignBranch = Branch::factory()->create();
        $foreignCashier = Cashier::factory()->create([
            'branch_id' => $foreignBranch->id,
            'status' => 'active',
        ]);

        $response = $this->actingAs($this->manager, 'sanctum')
            ->putJson("/api/v1/branch-manager/cashiers/{$foreignCashier->id}", [
                'name' => 'Hacked Name',
            ]);

        $this->assertContains($response->status(), [403, 404]);
    }
}
