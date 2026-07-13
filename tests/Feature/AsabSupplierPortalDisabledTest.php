<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Tests\TestCase;

/**
 * With FEATURE_ASAB_SUPPLIER_PORTAL off (v1 default) the /api/v1/asab/supplier/*
 * group is never registered → 404 (not 403), while the legacy mobile portal
 * /api/v1/supplier/* stays registered. Separate class so the flag is off for the
 * whole app boot (Pest runs one process; each class's createApplication wins).
 */
class AsabSupplierPortalDisabledTest extends TestCase
{
    use RefreshDatabase;

    /** Force the portal flag OFF before the framework loads config/routes. */
    public function createApplication()
    {
        putenv('FEATURE_ASAB_SUPPLIER_PORTAL=false');
        $_ENV['FEATURE_ASAB_SUPPLIER_PORTAL'] = 'false';
        $_SERVER['FEATURE_ASAB_SUPPLIER_PORTAL'] = 'false';

        return parent::createApplication();
    }

    private function supplierUser(): AsabUser
    {
        $company = AsabCompany::create(['name' => 'Off Co', 'plan' => 'Professional', 'status' => 'active']);
        $user = AsabUser::create(['company_id' => $company->id, 'name' => 'مورد', 'email' => 'off@sup.test', 'password' => 'secret-password', 'status' => 'active']);
        AsabUserRole::create(['user_id' => $user->id, 'role_key' => 'supplier', 'scope' => 'all']);

        return $user;
    }

    public function test_portal_routes_are_unregistered_when_flag_off(): void
    {
        // Authenticated as a supplier: a registered-but-forbidden route would 403;
        // an unregistered route 404s. We assert 404 → the group never registered.
        $this->actingAs($this->supplierUser(), 'sanctum')
            ->getJson('/api/v1/asab/supplier/overview')
            ->assertStatus(404);
    }

    public function test_legacy_mobile_supplier_routes_stay_registered(): void
    {
        $uris = collect(app('router')->getRoutes()->getRoutes())->map->uri();

        $this->assertTrue(
            $uris->contains(fn ($uri) => str_starts_with($uri, 'api/v1/supplier/')),
            'legacy /api/v1/supplier/* must remain registered'
        );
        $this->assertFalse(
            $uris->contains(fn ($uri) => str_starts_with($uri, 'api/v1/asab/supplier/')),
            'asab supplier group must be absent when the flag is off'
        );
    }
}
