<?php

namespace Tests\Feature;

use Database\Seeders\FullDemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabIdentityMap;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Operation;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\FixedAssets\Models\AssetType;
use Modules\FixedAssets\Models\AssetZone;
use Modules\Shift\Models\CashierShiftHandover;
use Tests\TestCase;

/**
 * The production E2E sweep (2026-07-31) found the demo unusable in four spots:
 * empty asset zones/types (receive-assets confirm structurally impossible),
 * zero sales operations, zero handover rows, and no branch-portal login.
 * This smoke run pins the seeder's contract so a reseeded demo stays complete.
 */
class FullDemoSeederSmokeTest extends TestCase
{
    use RefreshDatabase;

    public function test_full_demo_seeder_produces_a_complete_demo(): void
    {
        $this->seed(FullDemoSeeder::class);

        // Receive-assets confirm needs FK-valid zone/type pickers.
        $this->assertGreaterThan(0, AssetType::count(), 'asset types must be seeded');
        $this->assertGreaterThan(0, AssetZone::count(), 'asset zones must be seeded per branch');

        // The accountant المبيعات screen lists module_key=sales operations.
        $this->assertTrue(
            Operation::withoutGlobalScopes()->where('module_key', 'sales')->exists(),
            'a manager daily close must mint at least one sales operation',
        );

        // Handover surfaces need at least one pending and one approved row.
        $this->assertTrue(CashierShiftHandover::where('status', 'pending')->exists());
        $this->assertTrue(CashierShiftHandover::where('status', 'approved')->exists());

        // The dashboard branch portal (asab.role:branch) has a demo login.
        $branchUser = AsabUser::withoutGlobalScopes()->where('email', 'branch@nakhat.sa')->first();
        $this->assertNotNull($branchUser, 'branch portal user must exist');
        $this->assertTrue($branchUser->roleAssignments()->where('role_key', 'branch')->exists());

        // The brand owner's screens live on the MOBILE surface, behind
        // BrandOwnerMiddleware. A demo owner with no legacy row authenticates
        // and then 403s on every screen (prod E2E 2026-07-31), so the seeded
        // owner must exist in both worlds on one credential.
        $owner = AsabUser::withoutGlobalScopes()->where('email', 'owner@nakhat.sa')->firstOrFail();
        $mobileOwner = BrandOwner::where('email', 'owner@nakhat.sa')->first();
        $this->assertNotNull($mobileOwner, 'brand owner must exist in the mobile world too');
        $this->assertSame($owner->password, $mobileOwner->password, 'one credential must open both worlds');
        $this->assertTrue(
            AsabIdentityMap::where('dashboard_id', $owner->id)
                ->where('legacy_id', $mobileOwner->id)
                ->where('entity_type', AsabIdentityMap::ENTITY_BRAND_OWNER)->exists(),
            'the cross-world identity link must be recorded',
        );
    }
}
