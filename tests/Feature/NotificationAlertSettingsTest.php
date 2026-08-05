<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\BrandOwner\Models\BrandOwnerSettingNotification;
use Modules\Cashier\Models\Cashier;
use Tests\TestCase;

/**
 * Meeting 2026-08-04 «دي أكسس لكل اليوزرز ع الإندبوينت دي»: the mobile
 * «Notifications & alerts» screen is shown to every role, but the only endpoint
 * behind it was the brand-owner one — a branch manager or cashier opening it got
 * «Unauthorized. Brand Owner access required.»
 */
class NotificationAlertSettingsTest extends TestCase
{
    use RefreshDatabase;

    private const URL = '/api/v1/settings/notifications';

    public function test_a_branch_manager_can_read_and_save_their_alerts(): void
    {
        $manager = BranchManager::factory()->create(['branch_id' => Branch::factory()->create()->id]);

        $this->actingAs($manager, 'sanctum')
            ->getJson(self::URL)
            ->assertSuccessful()
            ->assertJsonPath('data.notifications.0.type', 'audit')
            ->assertJsonPath('data.notifications.0.enabled', false)
            ->assertJsonCount(5, 'data.notifications');

        $this->actingAs($manager, 'sanctum')
            ->patchJson(self::URL, ['notifications' => [
                ['type' => 'handover', 'enabled' => true],
            ]])
            ->assertSuccessful();

        $rows = collect($this->actingAs($manager, 'sanctum')->getJson(self::URL)->json('data.notifications'));
        $this->assertTrue($rows->firstWhere('type', 'handover')['enabled']);
        $this->assertFalse($rows->firstWhere('type', 'audit')['enabled']);
    }

    public function test_a_cashier_gets_their_own_settings_not_someone_elses(): void
    {
        $branch = Branch::factory()->create();
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $cashier = Cashier::factory()->create(['branch_id' => $branch->id]);

        $this->actingAs($manager, 'sanctum')
            ->patchJson(self::URL, ['notifications' => [['type' => 'transfer', 'enabled' => true]]])
            ->assertSuccessful();

        $rows = collect($this->actingAs($cashier, 'sanctum')->getJson(self::URL)->json('data.notifications'));
        $this->assertFalse($rows->firstWhere('type', 'transfer')['enabled'], 'settings are per actor');
    }

    /** A brand owner keeps whatever they had saved on the old screen. */
    public function test_a_brand_owners_existing_toggles_are_carried_over(): void
    {
        $owner = BrandOwner::create([
            'name' => 'مالك', 'email' => 'owner@alerts.test', 'phone' => '0500000010',
            'password' => 'secret-password', 'is_active' => true, 'is_first_login' => false, 'status' => 'active',
        ]);
        BrandOwnerSettingNotification::create([
            'brand_owner_id' => $owner->id, 'type' => 'major_discrepancies', 'enabled' => true,
        ]);

        $rows = collect($this->actingAs($owner, 'sanctum')->getJson(self::URL)->json('data.notifications'));
        $this->assertTrue($rows->firstWhere('type', 'major_discrepancies')['enabled']);

        // …and a save here reaches the legacy store too, so the two endpoints
        // never disagree.
        $this->actingAs($owner, 'sanctum')
            ->patchJson(self::URL, ['notifications' => [['type' => 'major_discrepancies', 'enabled' => false]]])
            ->assertSuccessful();

        $this->assertFalse(
            (bool) BrandOwnerSettingNotification::where('brand_owner_id', $owner->id)
                ->where('type', 'major_discrepancies')->value('enabled')
        );
    }

    public function test_an_unknown_type_is_rejected(): void
    {
        $manager = BranchManager::factory()->create(['branch_id' => Branch::factory()->create()->id]);

        $this->actingAs($manager, 'sanctum')
            ->patchJson(self::URL, ['notifications' => [['type' => 'not_a_type', 'enabled' => true]]])
            ->assertStatus(422);
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $this->getJson(self::URL)->assertStatus(401);
    }
}
