<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Services\BrandOwnerProvisioningService;
use Modules\BrandOwner\Models\BrandOwner as MobileBrandOwner;
use Modules\BrandOwner\Services\AuthService as MobileAuthService;
use Tests\TestCase;

/**
 * One credential, both worlds (client requirement: "the same password works on
 * the mobile app and the dashboard"). The dashboard authenticates against
 * asab_users and the app against brand_owners, so every path that writes a
 * password has to reach both tables or the emailed password half-works.
 */
class BrandOwnerCredentialSyncTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabBrand $brand;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Sync Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->brand = AsabBrand::create([
            'company_id' => $this->company->id,
            'name' => 'Sync Brand',
            'sub_status' => 'active',
            'status' => 'active',
        ]);
    }

    private function provision(string $email): array
    {
        return app(BrandOwnerProvisioningService::class)->provision($this->brand, $email, 'Owner Name');
    }

    /** Extract the one-time password out of the built (unsent) welcome notification. */
    private function oneTimePasswordFrom(array $result): ?string
    {
        $reflected = new \ReflectionProperty($result['notification'], 'oneTimePassword');

        return $reflected->getValue($result['notification']);
    }

    public function test_fresh_provision_emails_a_password_that_opens_both_worlds(): void
    {
        $result = $this->provision('fresh@example.com');
        $otp = $this->oneTimePasswordFrom($result);

        $this->assertNotNull($otp, 'a brand-new owner must be issued a one-time password');
        $this->assertTrue(Hash::check($otp, $result['user']->password), 'the emailed password must open the dashboard');

        $mobile = MobileBrandOwner::where('email', 'fresh@example.com')->first();
        $this->assertNotNull($mobile);
        $this->assertTrue(Hash::check($otp, $mobile->password), 'the emailed password must open the mobile app');
    }

    public function test_existing_dashboard_user_keeps_its_password_and_the_mobile_row_inherits_it(): void
    {
        $user = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'Already Here',
            'email' => 'existing@example.com',
            'password' => 'known-password',
            'status' => 'active',
        ]);

        $result = $this->provision('existing@example.com');

        $this->assertNull($this->oneTimePasswordFrom($result), 'a live account must not be handed a new password');
        $this->assertTrue(
            Hash::check('known-password', $user->fresh()->password),
            'provisioning must not reset a working dashboard login',
        );

        $mobile = MobileBrandOwner::where('email', 'existing@example.com')->first();
        $this->assertTrue(
            Hash::check('known-password', $mobile->password),
            'the mobile row must inherit the existing dashboard credential',
        );
    }

    public function test_existing_mobile_owner_credential_is_copied_onto_the_new_dashboard_user(): void
    {
        MobileBrandOwner::create([
            'name' => 'Mobile First',
            'email' => 'mobile@example.com',
            'password' => 'mobile-password',
            'is_active' => true,
            'is_first_login' => false,
            'status' => 'active',
        ]);

        $result = $this->provision('mobile@example.com');

        $this->assertNull($this->oneTimePasswordFrom($result));
        $this->assertTrue(
            Hash::check('mobile-password', $result['user']->password),
            'the dashboard account must accept the password the owner already uses on mobile',
        );
    }

    public function test_soft_deleted_dashboard_user_is_restored_instead_of_colliding(): void
    {
        $user = AsabUser::create([
            'company_id' => $this->company->id,
            'name' => 'Gone',
            'email' => 'deleted@example.com',
            'password' => 'old-password',
            'status' => 'active',
        ]);
        $user->delete();

        // asab_users.email is unique regardless of deleted_at, so the pre-fix
        // lookup missed the row and create() hit the index with a 500.
        $result = $this->provision('deleted@example.com');
        $otp = $this->oneTimePasswordFrom($result);

        $this->assertNotNull($otp);
        $this->assertSame($user->id, $result['user']->id, 'the trashed account must be restored, not duplicated');
        $this->assertNull($result['user']->fresh()->deleted_at);
        $this->assertTrue(Hash::check($otp, $result['user']->fresh()->password));
    }

    public function test_password_set_in_the_mobile_app_also_opens_the_dashboard(): void
    {
        $result = $this->provision('firstlogin@example.com');
        $mobile = MobileBrandOwner::where('email', 'firstlogin@example.com')->first();

        app(MobileAuthService::class)->resetPasswordFirstLogin($mobile, 'chosen-by-owner');

        $this->assertTrue(
            Hash::check('chosen-by-owner', $result['user']->fresh()->password),
            'the first-login reset must be mirrored onto the dashboard credential',
        );
    }
}
