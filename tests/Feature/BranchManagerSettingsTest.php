<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Aggregator\Models\Aggregator;
use Modules\Aggregator\Models\BranchAggregator;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Settings\Models\UserSetting;
use Tests\TestCase;

/**
 * Covers the Branch Manager Settings & Aggregators API
 * (branch-manager-settings-and-aggregators-doc.md).
 */
class BranchManagerSettingsTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private BranchManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->branch = Branch::factory()->create([
            'name' => 'Riyadh Central Branch',
            'opening_hours' => '08:00:00',
            'closing_hours' => '22:00:00',
        ]);

        $this->manager = BranchManager::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'Sami Al-Hakim',
            'password' => Hash::make('password123'),
        ]);
    }

    private function asManager(): self
    {
        return $this->actingAs($this->manager, 'sanctum');
    }

    // ----- 1. Account details -------------------------------------------------

    public function test_account_details_returns_the_managers_account(): void
    {
        $this->asManager()
            ->getJson('/api/branch-manager/settings/account-details')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.first_name', 'Sami')
            ->assertJsonPath('data.last_name', 'Al-Hakim')
            ->assertJsonPath('data.email_address', $this->manager->email)
            ->assertJsonPath('data.role', 'Branch Manager')
            ->assertJsonPath('data.assigned_to', 'Riyadh Central Branch')
            ->assertJsonPath('data.weekdays', 'Sun - Thu')
            ->assertJsonPath('data.work_shift', 'Morning (8:00 AM - 10:00 PM)');
    }

    public function test_account_details_requires_authentication(): void
    {
        $this->getJson('/api/branch-manager/settings/account-details')
            ->assertStatus(401);
    }

    // ----- 2. Settings snapshot ----------------------------------------------

    public function test_settings_snapshot_returns_branch_info_aggregators_and_notifications(): void
    {
        $added = Aggregator::factory()->create(['name' => 'Hunger Station']);
        BranchAggregator::create([
            'branch_id' => $this->branch->id,
            'aggregator_id' => $added->id,
            'is_enabled' => true,
        ]);
        Aggregator::factory()->create(['name' => 'Jahez']);

        $response = $this->asManager()
            ->getJson('/api/branch-manager/settings/aggregators')
            ->assertStatus(200)
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.branch_info.branch_id', $this->branch->id)
            ->assertJsonPath('data.branch_info.branch_name', 'Riyadh Central Branch')
            ->assertJsonPath('data.branch_info.branch_manager_name', 'Sami Al-Hakim')
            ->assertJsonPath('data.branch_info.branch_opening', 'Sun - Thu / 8:00 AM - 10:00 PM')
            ->assertJsonPath('data.branch_info.total_aggregators', 1)
            ->assertJsonCount(1, 'data.available_aggregators')
            ->assertJsonCount(1, 'data.added_aggregators')
            ->assertJsonCount(5, 'data.notifications');

        $this->assertSame('Jahez', $response->json('data.available_aggregators.0.name'));
        $this->assertSame('Hunger Station', $response->json('data.added_aggregators.0.name'));
        $this->assertTrue($response->json('data.added_aggregators.0.enabled'));
    }

    // ----- 3 & 4. Available / assigned aggregators ---------------------------

    public function test_available_aggregators_excludes_those_already_added(): void
    {
        $added = Aggregator::factory()->create(['name' => 'Hunger Station']);
        BranchAggregator::create([
            'branch_id' => $this->branch->id,
            'aggregator_id' => $added->id,
            'is_enabled' => true,
        ]);
        Aggregator::factory()->create(['name' => 'Jahez']);
        Aggregator::factory()->inactive()->create(['name' => 'Inactive One']);

        $response = $this->asManager()
            ->getJson('/api/branch-manager/settings/aggregators/available')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data');

        $this->assertSame('Jahez', $response->json('data.0.name'));
        $this->assertFalse($response->json('data.0.enabled'));
    }

    public function test_assigned_aggregators_returns_branch_aggregators(): void
    {
        $first = Aggregator::factory()->create(['name' => 'Hunger Station']);
        $second = Aggregator::factory()->create(['name' => 'Delivery Hero']);
        BranchAggregator::create(['branch_id' => $this->branch->id, 'aggregator_id' => $first->id, 'is_enabled' => true]);
        BranchAggregator::create(['branch_id' => $this->branch->id, 'aggregator_id' => $second->id, 'is_enabled' => false]);

        $this->asManager()
            ->getJson('/api/branch-manager/settings/aggregators/assigned')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.name', 'Delivery Hero')
            ->assertJsonPath('data.0.enabled', false)
            ->assertJsonPath('data.1.name', 'Hunger Station')
            ->assertJsonPath('data.1.enabled', true);
    }

    // ----- 5. Add aggregator -------------------------------------------------

    public function test_add_aggregator_attaches_it_to_the_branch(): void
    {
        $aggregator = Aggregator::factory()->create(['name' => 'Jahez']);

        $this->asManager()
            ->postJson('/api/branch-manager/settings/aggregators', [
                'aggregator_id' => $aggregator->id,
            ])
            ->assertStatus(201)
            ->assertJsonPath('data.id', $aggregator->id)
            ->assertJsonPath('data.name', 'Jahez')
            ->assertJsonPath('data.enabled', true);

        $this->assertDatabaseHas('branch_aggregators', [
            'branch_id' => $this->branch->id,
            'aggregator_id' => $aggregator->id,
            'is_enabled' => true,
        ]);
    }

    public function test_add_aggregator_rejects_a_duplicate(): void
    {
        $aggregator = Aggregator::factory()->create();
        BranchAggregator::create([
            'branch_id' => $this->branch->id,
            'aggregator_id' => $aggregator->id,
            'is_enabled' => true,
        ]);

        $this->asManager()
            ->postJson('/api/branch-manager/settings/aggregators', [
                'aggregator_id' => $aggregator->id,
            ])
            ->assertStatus(409)
            ->assertJsonPath('success', false);
    }

    public function test_add_aggregator_validates_the_aggregator_exists(): void
    {
        $this->asManager()
            ->postJson('/api/branch-manager/settings/aggregators', [
                'aggregator_id' => 'non-existent-id',
            ])
            ->assertStatus(422);
    }

    // ----- 6. Delete aggregator ----------------------------------------------

    public function test_delete_aggregator_detaches_it_from_the_branch(): void
    {
        $aggregator = Aggregator::factory()->create();
        BranchAggregator::create([
            'branch_id' => $this->branch->id,
            'aggregator_id' => $aggregator->id,
            'is_enabled' => true,
        ]);

        $this->asManager()
            ->deleteJson('/api/branch-manager/settings/aggregators/'.$aggregator->id)
            ->assertStatus(200)
            ->assertJsonPath('data.id', $aggregator->id);

        $this->assertDatabaseMissing('branch_aggregators', [
            'branch_id' => $this->branch->id,
            'aggregator_id' => $aggregator->id,
        ]);
    }

    public function test_delete_aggregator_not_added_returns_not_found(): void
    {
        $aggregator = Aggregator::factory()->create();

        $this->asManager()
            ->deleteJson('/api/branch-manager/settings/aggregators/'.$aggregator->id)
            ->assertStatus(404);
    }

    // ----- 7. Set aggregator enabled -----------------------------------------

    public function test_update_aggregator_status_toggles_the_enabled_flag(): void
    {
        $aggregator = Aggregator::factory()->create();
        BranchAggregator::create([
            'branch_id' => $this->branch->id,
            'aggregator_id' => $aggregator->id,
            'is_enabled' => true,
        ]);

        $this->asManager()
            ->patchJson('/api/branch-manager/settings/aggregators/'.$aggregator->id.'/status', [
                'enabled' => false,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.enabled', false);

        $this->assertDatabaseHas('branch_aggregators', [
            'branch_id' => $this->branch->id,
            'aggregator_id' => $aggregator->id,
            'is_enabled' => false,
        ]);
    }

    // ----- 8-12. Notification toggles ----------------------------------------

    public function test_notification_toggle_updates_the_preference(): void
    {
        $this->asManager()
            ->patchJson('/api/branch-manager/settings/notifications/shift-variance-alerts', [
                'enabled' => false,
            ])
            ->assertStatus(200)
            ->assertJsonPath('data.type', 'shiftVarianceAlerts')
            ->assertJsonPath('data.title', 'Shift Variance Alerts')
            ->assertJsonPath('data.enabled', false)
            ->assertJsonStructure(['data' => ['type', 'title', 'description', 'enabled']]);

        $this->assertDatabaseHas('user_settings', [
            'userable_id' => $this->manager->id,
            'userable_type' => BranchManager::class,
            'notification_shift_variance' => false,
        ]);
    }

    public function test_all_notification_toggle_endpoints_are_reachable(): void
    {
        $endpoints = [
            'shift-variance-alerts',
            'daily-inventory-reminders',
            'approved-aggregators-only',
            'asset-transfer-requests',
            'allow-split-shift-handovers',
        ];

        foreach ($endpoints as $endpoint) {
            $this->asManager()
                ->patchJson('/api/branch-manager/settings/notifications/'.$endpoint, ['enabled' => true])
                ->assertStatus(200)
                ->assertJsonPath('data.enabled', true);
        }
    }

    public function test_notification_toggle_requires_the_enabled_field(): void
    {
        $this->asManager()
            ->patchJson('/api/branch-manager/settings/notifications/shift-variance-alerts', [])
            ->assertStatus(422);
    }

    // ----- 13. Reset password ------------------------------------------------

    public function test_reset_password_updates_the_password(): void
    {
        $this->asManager()
            ->postJson('/api/branch-manager/settings/reset-password', [
                'old_password' => 'password123',
                'password' => 'NewPassword123!',
                'password_confirmation' => 'NewPassword123!',
            ])
            ->assertStatus(200)
            ->assertJsonPath('success', true);

        $this->assertTrue(
            Hash::check('NewPassword123!', $this->manager->fresh()->password)
        );
    }

    public function test_reset_password_rejects_a_wrong_current_password(): void
    {
        $this->asManager()
            ->postJson('/api/branch-manager/settings/reset-password', [
                'old_password' => 'wrong-password',
                'password' => 'NewPassword123!',
                'password_confirmation' => 'NewPassword123!',
            ])
            ->assertStatus(422)
            ->assertJsonPath('success', false);
    }

    public function test_reset_password_requires_matching_confirmation(): void
    {
        $this->asManager()
            ->postJson('/api/branch-manager/settings/reset-password', [
                'old_password' => 'password123',
                'password' => 'NewPassword123!',
                'password_confirmation' => 'Mismatch123!',
            ])
            ->assertStatus(422);
    }

    public function test_notifications_default_to_enabled(): void
    {
        $this->assertDatabaseCount('user_settings', 0);

        $this->asManager()
            ->getJson('/api/branch-manager/settings/aggregators')
            ->assertStatus(200)
            ->assertJsonPath('data.notifications.0.enabled', true);

        // Snapshot lazily creates the settings row with defaults.
        $settings = UserSetting::forBranchManager($this->manager->id)->first();
        $this->assertNotNull($settings);
        $this->assertTrue((bool) $settings->notification_shift_variance);
    }
}
