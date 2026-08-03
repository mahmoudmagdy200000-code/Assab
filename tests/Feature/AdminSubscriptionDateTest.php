<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabSubscription;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Tests\TestCase;

/**
 * «التاريخ هنا غير صحيح» on the subscriptions cards (2026-08-03).
 *
 * Two defects behind it: the payload carried no date-only field, so the card
 * printed the raw ISO timestamp; and `daysLeft` was read straight off the
 * STORED column, which only CheckExpiringSubscriptions ever writes — and only
 * for rows already inside the warning window. Every other card therefore froze
 * on the number it was created with.
 */
class AdminSubscriptionDateTest extends TestCase
{
    use RefreshDatabase;

    private AsabUser $admin;

    private AsabBrand $brand;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = AsabUser::create([
            'name' => 'Platform Admin', 'email' => 'admin@subs.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->admin->id, 'role_key' => 'admin', 'scope' => 'all']);

        $company = AsabCompany::create(['name' => 'جورمية', 'plan' => 'Basic', 'status' => 'active']);
        $this->brand = AsabBrand::create([
            'company_id' => $company->id, 'name' => 'جورمية كافيه', 'abbr' => 'JK',
            'sub_status' => 'active', 'status' => 'active',
        ]);
    }

    private function subscription(Carbon $expires, int $storedDaysLeft): AsabSubscription
    {
        return AsabSubscription::create([
            'company_id' => $this->brand->company_id,
            'brand_id' => $this->brand->id,
            'plan' => 'باقة متقدمة',
            'status' => 'active',
            'expires_at' => $expires,
            'days_left' => $storedDaysLeft,
            'monthly_price' => 1000,
            'modules' => [],
        ]);
    }

    public function test_days_left_is_derived_from_the_expiry_not_the_stale_column(): void
    {
        // Written 300 days ago and never touched since.
        $this->subscription(now()->addDays(31)->setTime(15, 55), 331);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/subscriptions')
            ->assertStatus(200);

        $this->assertSame(31, $res->json('data.0.daysLeft'));
        $this->assertFalse($res->json('data.0.isExpired'));
    }

    public function test_the_card_gets_a_date_only_field_alongside_the_iso_timestamp(): void
    {
        $expires = now()->addMonth()->setTime(15, 55, 41);
        $this->subscription($expires, 30);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/subscriptions')
            ->assertStatus(200);

        $this->assertSame($expires->toDateString(), $res->json('data.0.expiresAtDate'));
        $this->assertSame($expires->toIso8601String(), $res->json('data.0.expiresAt'));
    }

    public function test_an_expired_subscription_reports_zero_days_and_the_flag(): void
    {
        $this->subscription(now()->subDays(10), 90);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/subscriptions')
            ->assertStatus(200);

        $this->assertSame(0, $res->json('data.0.daysLeft'));
        $this->assertTrue($res->json('data.0.isExpired'));
    }

    /** A renewal writes the same day-boundary count the card reads back. */
    public function test_renewal_stores_the_day_boundary_count(): void
    {
        $sub = $this->subscription(now()->addDays(5)->setTime(23, 0), 5);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/subscriptions/{$sub->id}/renew", ['months' => 1])
            ->assertStatus(200);

        $this->assertSame($res->json('daysLeft'), $sub->fresh()->days_left);
    }
}
