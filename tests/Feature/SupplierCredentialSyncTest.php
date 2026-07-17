<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabIdentityMap;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Notifications\UserPasswordResetNotification;
use Modules\Admin\Services\ProcurementCatalogBridgeService;
use Modules\Supplier\Models\Supplier as LegacySupplier;
use Modules\Supplier\Services\AuthService as MobileAuthService;
use Tests\TestCase;

/**
 * One emailed password opens both worlds for role=supplier (client requirement:
 * "the supplier can log in on mobile and dashboard with the same password").
 * The dashboard authenticates against asab_users via the `asab` guard and the
 * app against suppliers via the `supplier` guard, so every path that writes a
 * password has to reach both tables or the emailed password half-works.
 */
class SupplierCredentialSyncTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabUser $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Sup Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->admin = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'أدمن',
            'email' => 'admin@sup.test', 'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->admin->id, 'role_key' => 'admin', 'scope' => 'all']);
    }

    private function asabSupplier(string $email, array $overrides = []): AsabSupplier
    {
        return AsabSupplier::create(array_merge([
            'company_id' => $this->company->id,
            'name' => 'مورد',
            'category' => 'خضار',
            'contact_email' => $email,
            'status' => 'active',
        ], $overrides));
    }

    /** POST /admin/users with role=supplier — the real provisioning entry point. */
    private function createSupplierUser(string $email, AsabSupplier $supplier): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/users', [
            'name' => 'مستخدم المورد',
            'email' => $email,
            'role' => 'supplier',
            'companyId' => $this->company->id,
            'supplierId' => $supplier->id,
        ]);
    }

    /** Capture the temporary password out of the notification the endpoint sends. */
    private function emailedPassword(AsabUser $user): string
    {
        $captured = null;
        Notification::assertSentTo($user, UserPasswordResetNotification::class, function ($notification) use (&$captured) {
            $captured = (new \ReflectionProperty($notification, 'temporaryPassword'))->getValue($notification);

            return true;
        });

        return $captured;
    }

    public function test_adding_a_supplier_user_emails_a_password_that_opens_both_worlds(): void
    {
        Notification::fake();
        $supplier = $this->asabSupplier('vendor@sup.test');

        $this->createSupplierUser('vendor@sup.test', $supplier)->assertCreated();

        $user = AsabUser::where('email', 'vendor@sup.test')->firstOrFail();
        $password = $this->emailedPassword($user);

        $this->assertNotNull($password, 'the new user must be emailed a password');
        $this->assertTrue(Hash::check($password, $user->password), 'the emailed password must open the dashboard');

        $legacy = LegacySupplier::find($supplier->fresh()->legacy_supplier_id);
        $this->assertNotNull($legacy, 'a legacy supplier row must exist to authenticate against');
        $this->assertTrue(Hash::check($password, $legacy->password), 'the emailed password must open the mobile app');
        $this->assertTrue($legacy->is_first_login, 'an admin-issued password must force a reset on the app');
    }

    public function test_deleting_the_dashboard_user_revokes_the_mobile_login(): void
    {
        Notification::fake();
        $supplier = $this->asabSupplier('fired@sup.test');
        $this->createSupplierUser('fired@sup.test', $supplier)->assertCreated();

        $user = AsabUser::where('email', 'fired@sup.test')->firstOrFail();
        $legacy = LegacySupplier::find($supplier->fresh()->legacy_supplier_id);
        $legacy->createToken('app-session');
        $this->assertSame(1, $legacy->tokens()->count());

        $this->actingAs($this->admin, 'sanctum')
            ->deleteJson("/api/v1/admin/users/{$user->id}")
            ->assertNoContent();

        // Provisioning created real app access; deleting the user has to close
        // it, or a removed supplier keeps ordering with the emailed password.
        $this->assertFalse((bool) $legacy->fresh()->is_active);
        $this->assertSame(0, $legacy->fresh()->tokens()->count(), 'a live app session must not survive the delete');
    }

    public function test_deactivating_the_dashboard_user_revokes_the_mobile_login(): void
    {
        Notification::fake();
        $supplier = $this->asabSupplier('paused@sup.test');
        $this->createSupplierUser('paused@sup.test', $supplier)->assertCreated();

        $user = AsabUser::where('email', 'paused@sup.test')->firstOrFail();
        $legacy = LegacySupplier::find($supplier->fresh()->legacy_supplier_id);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/users/{$user->id}/deactivate")
            ->assertStatus(200);

        $this->assertFalse((bool) $legacy->fresh()->is_active);
    }

    public function test_provisioning_writes_the_supplier_user_id_that_the_portal_reads(): void
    {
        Notification::fake();
        $supplier = $this->asabSupplier('owner@sup.test');

        $this->createSupplierUser('owner@sup.test', $supplier)->assertCreated();

        $user = AsabUser::where('email', 'owner@sup.test')->firstOrFail();
        $this->assertSame($user->id, $supplier->fresh()->user_id);
    }

    public function test_supplier_user_link_coexists_with_the_commercial_supplier_link(): void
    {
        Notification::fake();
        $supplier = $this->asabSupplier('both@sup.test');
        // Claim the legacy row under ENTITY_SUPPLIER first, exactly as the
        // procurement catalog does when a purchasing manager adds a supplier.
        app(ProcurementCatalogBridgeService::class)->provisionSupplier($supplier);
        $legacyId = $supplier->fresh()->legacy_supplier_id;

        $this->createSupplierUser('both@sup.test', $supplier)->assertCreated();

        $user = AsabUser::where('email', 'both@sup.test')->firstOrFail();

        // Both rows name the SAME legacy supplier. They may only coexist because
        // both unique indexes are composite with entity_type leading.
        $commercial = AsabIdentityMap::where('entity_type', AsabIdentityMap::ENTITY_SUPPLIER)
            ->where('legacy_id', $legacyId)->first();
        $login = AsabIdentityMap::where('entity_type', AsabIdentityMap::ENTITY_SUPPLIER_USER)
            ->where('legacy_id', $legacyId)->first();

        $this->assertNotNull($commercial);
        $this->assertNotNull($login);
        $this->assertSame($supplier->id, $commercial->dashboard_id);
        $this->assertSame('asab_supplier', $commercial->dashboard_type);
        $this->assertSame($user->id, $login->dashboard_id);
        $this->assertSame('asab_user', $login->dashboard_type);
    }

    public function test_soft_deleted_legacy_supplier_is_restored_instead_of_colliding(): void
    {
        Notification::fake();
        $supplier = $this->asabSupplier('trashed@sup.test');
        app(ProcurementCatalogBridgeService::class)->provisionSupplier($supplier);
        $legacyId = $supplier->fresh()->legacy_supplier_id;
        LegacySupplier::find($legacyId)->delete();

        $this->createSupplierUser('trashed@sup.test', $supplier)->assertCreated();

        $user = AsabUser::where('email', 'trashed@sup.test')->firstOrFail();
        $legacy = LegacySupplier::find($legacyId);

        $this->assertNotNull($legacy, 'the trashed legacy row must be restored, not duplicated');
        $this->assertNull($legacy->deleted_at);
        $this->assertTrue(Hash::check($this->emailedPassword($user), $legacy->password));
    }

    public function test_password_set_in_the_mobile_app_also_opens_the_dashboard(): void
    {
        Notification::fake();
        $supplier = $this->asabSupplier('firstlogin@sup.test');
        $this->createSupplierUser('firstlogin@sup.test', $supplier)->assertCreated();

        $user = AsabUser::where('email', 'firstlogin@sup.test')->firstOrFail();
        $legacy = LegacySupplier::find($supplier->fresh()->legacy_supplier_id);

        app(MobileAuthService::class)->resetPasswordFirstLogin($legacy, 'chosen-by-supplier');

        $this->assertTrue(
            Hash::check('chosen-by-supplier', $user->fresh()->password),
            'the first-login reset must be mirrored onto the dashboard credential',
        );
    }

    public function test_a_live_legacy_login_is_reset_to_the_emailed_password(): void
    {
        Notification::fake();
        $supplier = $this->asabSupplier('live@sup.test');
        app(ProcurementCatalogBridgeService::class)->provisionSupplier($supplier);
        $legacy = LegacySupplier::find($supplier->fresh()->legacy_supplier_id);
        // The supplier had already chosen their own password on the app.
        app(MobileAuthService::class)->resetPasswordFirstLogin($legacy, 'chosen-earlier');

        $this->createSupplierUser('live@sup.test', $supplier)->assertCreated();

        $user = AsabUser::where('email', 'live@sup.test')->firstOrFail();
        $fresh = LegacySupplier::find($legacy->id);

        // DESTRUCTIVE BY DESIGN, and the opposite of the brand-owner rule. store()
        // unconditionally mints a password, writes it to the (always new) AsabUser
        // and emails it, so copying the surviving legacy hash instead would email a
        // password that opens the dashboard only — the exact half-delivery this
        // feature removes. is_first_login makes the app force a fresh choice, and
        // the user is told by the email, so the reset is recoverable and visible.
        $this->assertFalse(Hash::check('chosen-earlier', $fresh->password), 'the live mobile password is superseded');
        $this->assertTrue(Hash::check($this->emailedPassword($user), $fresh->password));
        $this->assertTrue($fresh->is_first_login);
    }

    public function test_a_login_email_that_does_not_match_the_supplier_fails_closed(): void
    {
        Notification::fake();
        // The mobile app authenticates by the legacy row's email, so a mismatched
        // login would leave the emailed password opening the dashboard only.
        $supplier = $this->asabSupplier('contact@sup.test');

        $this->createSupplierUser('someone.else@sup.test', $supplier)->assertStatus(422);

        $this->assertNull(AsabUser::where('email', 'someone.else@sup.test')->first(), 'the transaction must roll back');
    }

    public function test_a_supplier_from_another_company_is_refused(): void
    {
        Notification::fake();
        $other = AsabCompany::create(['name' => 'Other Co', 'plan' => 'Professional', 'status' => 'active']);
        $foreign = AsabSupplier::create([
            'company_id' => $other->id, 'name' => 'مورد آخر',
            'contact_email' => 'foreign@sup.test', 'status' => 'active',
        ]);

        $this->createSupplierUser('foreign@sup.test', $foreign)->assertStatus(422);

        $this->assertNull(AsabUser::where('email', 'foreign@sup.test')->first());
    }

    public function test_two_supplier_records_sharing_a_contact_email_do_not_break_provisioning(): void
    {
        // Pre-existing bug: both asab_suppliers resolve to ONE legacy row, and the
        // second ENTITY_SUPPLIER link violated unique(entity_type, legacy_id) — a
        // 500 on the second POST. The first claim wins; the loser keeps its own
        // legacy_supplier_id, which is what the order flow actually reads.
        $bridge = app(ProcurementCatalogBridgeService::class);
        $first = $this->asabSupplier('dup@sup.test', ['name' => 'الأول']);
        $second = $this->asabSupplier('dup@sup.test', ['name' => 'الثاني']);

        $bridge->provisionSupplier($first);
        $bridge->provisionSupplier($second);

        $legacyId = $first->fresh()->legacy_supplier_id;
        $this->assertSame($legacyId, $second->fresh()->legacy_supplier_id, 'both records share the one legacy row');
        $this->assertSame(
            1,
            AsabIdentityMap::where('entity_type', AsabIdentityMap::ENTITY_SUPPLIER)->where('legacy_id', $legacyId)->count(),
            'the map must hold exactly one commercial link for the legacy row',
        );
    }
}
