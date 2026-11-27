<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Attachment;
use Modules\Admin\Models\AuditLog;
use Modules\Admin\Models\ReportDistribution;
use Modules\Admin\Notifications\UserPasswordResetNotification;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * Admin dashboard frontend-contract batch 1 (11 new endpoints + response-field
 * additions). Asserts the exact response shapes the rebuilt FE reads, and the
 * zero-trust guarantees (no plaintext password ever returned).
 */
class AdminDashboardBatch1Test extends TestCase
{
    use RefreshDatabase;

    private AsabUser $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->makeAdmin();
    }

    private function makeAdmin(): AsabUser
    {
        $user = AsabUser::create([
            'name' => 'Platform Admin',
            'email' => 'admin@asab.test',
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $user->id, 'role_key' => 'admin', 'scope' => 'all']);

        return $user;
    }

    /** A company with its company-admin user. */
    private function makeCompanyWithAdmin(string $email = 'co-admin@asab.test'): array
    {
        $company = AsabCompany::create([
            'name' => 'Acme Foods',
            'plan' => 'Professional',
            'status' => 'active',
            'admin_email' => $email,
            'max_branches' => 20,
            'max_users' => 60,
            'monthly_revenue' => 175000,
            'next_billing' => now()->addMonth(),
        ]);
        $coAdmin = AsabUser::create([
            'company_id' => $company->id,
            'name' => 'Company Admin',
            'email' => $email,
            'password' => 'secret-password',
            'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $coAdmin->id, 'role_key' => 'company-admin', 'scope' => 'all']);

        return [$company, $coAdmin];
    }

    private function brand(?string $companyId = null): AsabBrand
    {
        $companyId ??= AsabCompany::create(['name' => 'Brand Co', 'plan' => 'Basic', 'status' => 'active'])->id;

        return AsabBrand::create([
            'company_id' => $companyId,
            'name' => 'Burger Brand',
            'abbr' => 'BB',
            'sub_status' => 'expired',
            'status' => 'active',
            'plan' => 'ذهبي',
            'modules' => ['sales'],
        ]);
    }

    // ---- A1 / A2 brand subscription ----

    public function test_a1_brand_subscription_renew(): void
    {
        $brand = $this->brand();

        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/brands/{$brand->id}/subscription/renew", ['months' => 6]);

        $res->assertStatus(200)
            ->assertJsonPath('brandId', $brand->id)
            ->assertJsonPath('subStatus', 'active')
            ->assertJsonStructure(['brandId', 'subStatus', 'daysLeft', 'expiresAt']);

        $this->assertIsInt($res->json('daysLeft'));
        $this->assertNotNull($res->json('expiresAt'));
    }

    public function test_a2_brand_subscription_activate(): void
    {
        $brand = $this->brand();

        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/brands/{$brand->id}/subscription/activate");

        $res->assertStatus(200)->assertJsonPath('subStatus', 'active');
        $this->assertNotNull($res->json('expiresAt'));
    }

    // ---- A3 user reset-password ----

    public function test_a3_user_reset_password_emails_and_hides_plaintext(): void
    {
        Notification::fake();
        $user = AsabUser::create([
            'name' => 'Target', 'email' => 'target@asab.test',
            'password' => 'old-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $user->id, 'role_key' => 'accountant', 'scope' => 'all']);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/users/{$user->id}/reset-password", ['sendEmail' => true]);

        $res->assertStatus(200)->assertJsonPath('ok', true)->assertJsonPath('emailSent', true);
        $this->assertArrayNotHasKey('tempPassword', $res->json());
        $this->assertArrayNotHasKey('password', $res->json());
        Notification::assertSentTo($user, UserPasswordResetNotification::class);
    }

    public function test_a3_manual_reset_sets_confirmed_password_without_email_and_requires_change(): void
    {
        Notification::fake();
        $user = AsabUser::create([
            'name' => 'Manual Target', 'email' => 'manual-target@asab.test',
            'password' => 'old-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $user->id, 'role_key' => 'accountant', 'scope' => 'all']);
        $user->createToken('existing');

        $password = 'Temp-Reset-Pass-42!';
        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/users/{$user->id}/reset-password", [
                'mode' => 'manual', 'password' => $password, 'passwordConfirmation' => $password,
            ]);

        $res->assertOk()->assertJsonPath('ok', true)->assertJsonPath('mode', 'manual')
            ->assertJsonPath('emailSent', null);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check($password, $user->fresh()->password));
        $this->assertTrue((bool) $user->fresh()->must_change_password);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $user->id, 'name' => 'existing']);
        $session = \Modules\Admin\Models\AdminSession::create([
            'user_id' => $user->id, 'expires_at' => now()->addHour(),
        ]);
        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/admin/users/{$user->id}/reset-password", [
            'mode' => 'manual', 'password' => $password, 'passwordConfirmation' => $password,
        ])->assertOk();
        $this->assertNotNull($session->fresh()->revoked_at);
        $auditContent = AuditLog::where('action', 'post.users')->get()->toJson();
        $this->assertGreaterThan(0, AuditLog::where('action', 'post.users')->count());
        $this->assertStringNotContainsString($password, $auditContent);
        $this->assertArrayNotHasKey('password', $res->json());
        $this->assertArrayNotHasKey('tempPassword', $res->json());
        Notification::assertNothingSent();
    }

    public function test_manual_reset_hashes_hash_shaped_plaintext_instead_of_treating_it_as_a_hash(): void
    {
        $user = AsabUser::create([
            'name' => 'Hash Shaped Input', 'email' => 'hash-shaped@asab.test',
            'password' => 'old-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $user->id, 'role_key' => 'accountant', 'scope' => 'all']);
        $submitted = '$2y$12$'.str_repeat('a', 53);

        $this->actingAs($this->admin, 'sanctum')->postJson("/api/v1/admin/users/{$user->id}/reset-password", [
            'mode' => 'manual', 'password' => $submitted, 'passwordConfirmation' => $submitted,
        ])->assertOk();

        $stored = $user->fresh()->password;
        $this->assertNotSame($submitted, $stored);
        $this->assertTrue(Hash::check($submitted, $stored));
    }

    public function test_manual_user_creation_hashes_hash_shaped_plaintext(): void
    {
        $submitted = '$2y$12$'.str_repeat('b', 53);

        $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/users', [
            'name' => 'Hash Shaped Create', 'email' => 'hash-shaped-create@asab.test', 'role' => 'procurement',
            'credentialMode' => 'manual', 'password' => $submitted, 'passwordConfirmation' => $submitted,
        ])->assertCreated();

        $stored = AsabUser::where('email', 'hash-shaped-create@asab.test')->firstOrFail()->password;
        $this->assertNotSame($submitted, $stored);
        $this->assertTrue(Hash::check($submitted, $stored));
    }

    public function test_a3_manual_reset_requires_matching_valid_password_confirmation(): void
    {
        $user = AsabUser::create([
            'name' => 'Invalid Target', 'email' => 'invalid-target@asab.test',
            'password' => 'old-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $user->id, 'role_key' => 'accountant', 'scope' => 'all']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/users/{$user->id}/reset-password", [
                'mode' => 'manual', 'password' => 'short', 'passwordConfirmation' => 'different',
            ])->assertUnprocessable();

        $this->assertFalse((bool) $user->fresh()->must_change_password);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('old-password', $user->fresh()->password));
    }

    public function test_a3_admin_reset_enforces_change_until_user_changes_password(): void
    {
        $user = AsabUser::create([
            'name' => 'Forced Change', 'email' => 'forced-change@asab.test',
            'password' => 'old-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $user->id, 'role_key' => 'accountant', 'scope' => 'all']);
        $password = 'Forced-Reset-Pass-43!';

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/users/{$user->id}/reset-password", [
                'mode' => 'manual', 'password' => $password, 'passwordConfirmation' => $password,
            ])->assertOk();

        $this->actingAs($user->fresh(), 'sanctum')
            ->getJson('/api/v1/admin/users')
            ->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('mustChangePassword', true);
        $this->postJson('/api/v1/auth/change-password', [
            'currentPassword' => $password, 'newPassword' => 'User-Chosen-Pass-44!',
        ])->assertNoContent();

        $this->assertFalse((bool) $user->fresh()->must_change_password);
    }

    public function test_password_change_rejects_reuse_of_the_admin_issued_temporary_password(): void
    {
        $user = AsabUser::create([
            'name' => 'Reuse Target', 'email' => 'reuse-target@asab.test',
            'password' => 'old-password', 'status' => 'active', 'must_change_password' => true,
        ]);
        $temporary = 'Temporary-Password-55!';
        $user->forceFill(['password' => Hash::make($temporary)])->save();

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/change-password', [
            'currentPassword' => $temporary, 'newPassword' => $temporary,
        ])->assertUnprocessable()->assertJsonPath('error.code', 'PASSWORD_REUSE');

        $this->assertTrue((bool) $user->fresh()->must_change_password);
        $this->assertTrue(Hash::check($temporary, $user->fresh()->password));
    }

    public function test_user_password_change_hashes_hash_shaped_plaintext(): void
    {
        $user = AsabUser::create([
            'name' => 'Hash Change', 'email' => 'hash-change@asab.test',
            'password' => 'current-password', 'status' => 'active',
        ]);
        $submitted = '$2y$12$'.str_repeat('e', 53);

        $this->actingAs($user, 'sanctum')->postJson('/api/v1/auth/change-password', [
            'currentPassword' => 'current-password', 'newPassword' => $submitted,
        ])->assertNoContent();

        $stored = $user->fresh()->password;
        $this->assertNotSame($submitted, $stored);
        $this->assertTrue(Hash::check($submitted, $stored));
    }

    public function test_password_change_gate_covers_a_protected_route_outside_admin_module(): void
    {
        $user = AsabUser::create([
            'name' => 'Cross Module Gate', 'email' => 'cross-module-gate@asab.test',
            'password' => 'temporary-password', 'status' => 'active', 'must_change_password' => true,
        ]);

        $this->actingAs($user, 'sanctum')->getJson('/api/v1/branches')
            ->assertForbidden()->assertJsonPath('code', 'PASSWORD_CHANGE_REQUIRED');
    }

    public function test_a3_reset_reports_suppressed_credential_email_as_not_sent(): void
    {
        Config::set('mail.default', 'log');
        $user = AsabUser::create([
            'name' => 'Mail Failure', 'email' => 'mail-failure@asab.test',
            'password' => 'old-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $user->id, 'role_key' => 'accountant', 'scope' => 'all']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/users/{$user->id}/reset-password", ['mode' => 'email'])
            ->assertOk()->assertJsonPath('emailSent', false)->assertJsonPath('mode', 'email');
    }

    public function test_nested_mail_failover_that_reaches_log_reports_email_not_sent(): void
    {
        Config::set('mail.default', 'primary');
        Config::set('mail.mailers', [
            'primary' => ['transport' => 'failover', 'mailers' => ['smtp', 'secondary']],
            'smtp' => ['transport' => 'smtp'],
            'secondary' => ['transport' => 'failover', 'mailers' => ['log']],
            'log' => ['transport' => 'log'],
        ]);
        $user = AsabUser::create([
            'name' => 'Nested Mail Failure', 'email' => 'nested-mail-failure@asab.test',
            'password' => 'old-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $user->id, 'role_key' => 'accountant', 'scope' => 'all']);

        $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/users/{$user->id}/reset-password", ['mode' => 'email'])
            ->assertOk()->assertJsonPath('emailSent', false);
    }

    public function test_existing_accounts_default_to_not_requiring_password_change(): void
    {
        $user = AsabUser::create([
            'name' => 'Existing User', 'email' => 'existing-user@asab.test',
            'password' => 'old-password', 'status' => 'active',
        ]);

        $this->assertFalse((bool) $user->fresh()->must_change_password);
    }

    public function test_admin_can_create_user_with_manual_temporary_password_without_email(): void
    {
        Notification::fake();
        $password = 'Create-Manual-Pass-45!';

        $res = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/users', [
            'name' => 'Manual Create', 'email' => 'manual-create@asab.test', 'role' => 'procurement',
            'credentialMode' => 'manual', 'password' => $password, 'passwordConfirmation' => $password,
        ]);

        $res->assertCreated()->assertJsonPath('credentialMode', 'manual')->assertJsonPath('emailSent', null);
        $user = AsabUser::where('email', 'manual-create@asab.test')->firstOrFail();
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check($password, $user->password));
        $this->assertTrue((bool) $user->must_change_password);
        $this->assertArrayNotHasKey('password', $res->json());
        Notification::assertNothingSent();
    }

    public function test_admin_can_create_user_with_generated_password_email_and_first_login_change(): void
    {
        Notification::fake();

        $res = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/users', [
            'name' => 'Emailed Create', 'email' => 'emailed-create@asab.test', 'role' => 'procurement',
            'credentialMode' => 'email',
        ]);

        $res->assertCreated()->assertJsonPath('credentialMode', 'email')->assertJsonPath('emailSent', true);
        $user = AsabUser::where('email', 'emailed-create@asab.test')->firstOrFail();
        $this->assertTrue((bool) $user->must_change_password);
        $this->assertArrayNotHasKey('password', $res->json());
        Notification::assertSentTo($user, UserPasswordResetNotification::class);
    }

    public function test_admin_user_creation_reports_suppressed_credential_email_as_not_sent(): void
    {
        Config::set('mail.default', 'log');

        $res = $this->actingAs($this->admin, 'sanctum')->postJson('/api/v1/admin/users', [
            'name' => 'Undelivered Create', 'email' => 'undelivered-create@asab.test', 'role' => 'procurement',
            'credentialMode' => 'email',
        ]);

        $res->assertCreated()->assertJsonPath('credentialMode', 'email')->assertJsonPath('emailSent', false);
        $user = AsabUser::where('email', 'undelivered-create@asab.test')->firstOrFail();
        $this->assertTrue((bool) $user->must_change_password);
    }

    public function test_only_system_admin_can_create_or_reset_accounts(): void
    {
        $accountant = AsabUser::create([
            'name' => 'Accountant Actor', 'email' => 'accountant-actor@asab.test',
            'password' => 'old-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $accountant->id, 'role_key' => 'accountant', 'scope' => 'all']);
        $target = AsabUser::create([
            'name' => 'No Reset', 'email' => 'no-reset@asab.test',
            'password' => 'old-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $target->id, 'role_key' => 'accountant', 'scope' => 'all']);

        $this->actingAs($accountant, 'sanctum')
            ->postJson("/api/v1/admin/users/{$target->id}/reset-password", ['mode' => 'email'])
            ->assertForbidden();
        $this->postJson('/api/v1/admin/users', [
            'name' => 'Unauthorized Create', 'email' => 'unauthorized-create@asab.test', 'role' => 'procurement',
        ])->assertForbidden();

        $this->assertDatabaseMissing('asab_users', ['email' => 'unauthorized-create@asab.test']);
        $this->assertFalse((bool) $target->fresh()->must_change_password);
    }

    // ---- A4 / A5 subscriptions ----

    public function test_a4_create_subscription_and_a5_update_modules(): void
    {
        $brand = $this->brand();

        $create = $this->actingAs($this->admin, 'sanctum')
            ->postJson('/api/v1/admin/subscriptions', [
                'brandId' => $brand->id,
                'plan' => 'gold',
                'months' => 12,
                'modules' => ['sales', 'expenses'],
            ]);

        $create->assertStatus(201)
            ->assertJsonPath('brandId', $brand->id)
            ->assertJsonPath('plan', 'ذهبي')
            ->assertJsonPath('monthlyPrice', 175000)
            ->assertJsonPath('modules', ['sales', 'expenses'])
            ->assertJsonStructure(['id', 'modules', 'restaurants']);

        $id = $create->json('id');

        $patch = $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/v1/admin/subscriptions/{$id}/modules", ['modules' => ['sales']]);

        $patch->assertStatus(200)->assertJsonPath('modules', ['sales']);
    }

    // ---- A6 / A7 / A8 company admin actions ----

    public function test_a6_company_admin_reset_password_email_only(): void
    {
        Notification::fake();
        [$company, $coAdmin] = $this->makeCompanyWithAdmin();

        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/companies/{$company->id}/admin/reset-password", ['notify' => true]);

        $res->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('emailedTo', $coAdmin->email)
            ->assertJsonStructure(['ok', 'emailedTo', 'resetAt']);
        $this->assertArrayNotHasKey('tempPassword', $res->json());
        Notification::assertSentTo($coAdmin, UserPasswordResetNotification::class);
    }

    public function test_a7_impersonate_returns_short_lived_token_and_audits(): void
    {
        [$company, $coAdmin] = $this->makeCompanyWithAdmin('imp@asab.test');

        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/companies/{$company->id}/impersonate");

        $res->assertStatus(200)
            ->assertJsonPath('userId', $coAdmin->id)
            ->assertJsonStructure(['token', 'expiresAt', 'userId']);
        $this->assertNotEmpty($res->json('token'));

        $this->assertTrue(
            AuditLog::where('action', 'impersonate')->where('entity_id', $company->id)->exists()
        );
    }

    public function test_a8_send_reminder(): void
    {
        [$company] = $this->makeCompanyWithAdmin('rem@asab.test');

        $res = $this->actingAs($this->admin, 'sanctum')
            ->postJson("/api/v1/admin/companies/{$company->id}/send-reminder", [
                'channels' => ['inApp', 'email'],
                'message' => 'Please renew',
            ]);

        $res->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('channels', ['inApp', 'email'])
            ->assertJsonStructure(['ok', 'sentAt', 'channels']);
    }

    // ---- A9 / A10 / A11 reports ----

    public function test_a9_report_preview_parses_uploaded_csv(): void
    {
        Storage::fake('public');
        $key = 'platform/reports/pl/sample.csv';
        Storage::disk('public')->put($key, "Metric,Value\nالسعر,1000\nالكمية,5\n");

        $attachment = Attachment::create([
            'owner_type' => 'report',
            'owner_id' => 'pl',
            'filename' => 'sample.csv',
            'mime_type' => 'text/csv',
            'size' => 32,
            'storage_key' => $key,
            'label' => 'pl',
            'uploaded_at' => now(),
        ]);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/v1/admin/reports/pl/preview?uploadId={$attachment->id}");

        $res->assertStatus(200)
            ->assertJsonStructure(['rows' => [['label', 'value', 'type', 'header']]])
            ->assertJsonPath('rows.0.header', 'Value');
    }

    public function test_a10_report_status_summary(): void
    {
        $company = AsabCompany::create(['name' => 'Co', 'plan' => 'Basic', 'status' => 'active']);
        $brand = $this->brand($company->id);
        $rest = AsabRestaurant::create([
            'brand_id' => $brand->id, 'company_id' => $company->id,
            'name' => 'R1', 'city' => 'Riyadh', 'status' => 'active',
        ]);
        ReportDistribution::create([
            'company_id' => $company->id, 'report_key' => 'pl', 'restaurant_id' => $rest->id,
            'channels' => ['inApp'], 'period_from' => '2026-06-01', 'period_to' => '2026-06-30',
            'sent' => true, 'sent_at' => now(),
        ]);

        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/reports/pl/status?period=2026-06');

        $res->assertStatus(200)
            ->assertJsonPath('reportKey', 'pl')
            ->assertJsonPath('summary.sent', 1)
            ->assertJsonStructure(['summary' => ['sent', 'notSent', 'viewed', 'notViewed'], 'rows']);
    }

    public function test_a11_report_periods(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/reports/periods');

        $res->assertStatus(200)->assertJsonStructure(['periods']);
        $this->assertNotEmpty($res->json('periods'));
    }

    // ---- Part B response-field additions ----

    public function test_part_b_company_index_includes_counts(): void
    {
        $company = AsabCompany::create([
            'name' => 'Counted Co', 'plan' => 'Basic', 'status' => 'active',
            'next_billing' => now()->addMonth(),
        ]);
        $brand = $this->brand($company->id);
        AsabRestaurant::create([
            'brand_id' => $brand->id, 'company_id' => $company->id,
            'name' => 'R', 'city' => 'Jeddah', 'status' => 'active',
        ]);
        AsabUser::create([
            'company_id' => $company->id, 'name' => 'U', 'email' => 'u@asab.test',
            'password' => 'x', 'status' => 'active',
        ]);

        $res = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/companies');

        $res->assertStatus(200)->assertJsonStructure([
            'data' => [['brands', 'restaurants', 'users', 'usedBranches', 'daysLeft']],
            'meta',
        ]);
    }

    public function test_part_b_user_index_includes_last_login_at(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/users');

        $res->assertStatus(200)
            ->assertJsonStructure(['data' => [['id', 'lastLoginAt', 'createdAt']], 'meta']);
    }

    public function test_part_b_brand_tree_nests_branches_and_accountants(): void
    {
        $company = AsabCompany::create(['name' => 'Tree Co', 'plan' => 'Basic', 'status' => 'active']);
        $brand = $this->brand($company->id);
        $rest = AsabRestaurant::create([
            'brand_id' => $brand->id, 'company_id' => $company->id,
            'name' => 'R', 'city' => 'Riyadh', 'status' => 'active', 'accountant_count' => 3,
        ]);
        Branch::factory()->create([
            'name' => 'Main Branch', 'manager' => 'Sara',
            'asab_restaurant_id' => $rest->id, 'asab_brand_id' => $brand->id, 'asab_company_id' => $company->id,
        ]);

        $res = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/brands');

        $res->assertStatus(200)->assertJsonStructure([
            'data' => [['restaurants' => [['accountants', 'branches' => [['id', 'name', 'manager']]]]]],
        ]);
    }

    public function test_part_b_report_catalog_has_category(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/reports/catalog');

        $res->assertStatus(200)
            ->assertJsonStructure(['data' => [['key', 'category']]])
            ->assertJsonPath('data.0.category', 'core');
    }

    // ---- B1 / B2 / B3 follow-ups ----

    public function test_b1_admin_notification_preferences_get_and_patch(): void
    {
        $get = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/notifications/preferences');

        $get->assertStatus(200)->assertJsonStructure([
            'channels' => ['inApp' => ['enabled'], 'email' => ['enabled', 'address'], 'push' => ['enabled'], 'whatsapp' => ['enabled']],
            'events',
            'quietHours' => ['enabled', 'startsAt', 'endsAt'],
        ]);

        $patch = $this->actingAs($this->admin, 'sanctum')
            ->patchJson('/api/v1/admin/notifications/preferences', [
                'channels' => ['push' => ['enabled' => true]],
            ]);

        $patch->assertStatus(200)->assertJsonPath('channels.push.enabled', true);
    }

    public function test_b2_admin_modules_lookup_exposes_value_and_key(): void
    {
        $res = $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/v1/admin/lookups/modules');

        $res->assertStatus(200)
            ->assertJsonStructure(['data' => [['value', 'key', 'labelAr', 'labelEn']]])
            ->assertJsonPath('data.0.value', 'sales')
            ->assertJsonPath('data.0.key', 'sales');
    }

    public function test_b3_audit_logs_include_human_labels(): void
    {
        AuditLog::create([
            'actor_user_id' => $this->admin->id,
            'actor_label' => 'أمين النظام',
            'actor_role' => 'admin',
            'action' => 'post.companies',
            'entity_type' => 'companies',
            'description' => 'POST companies',
            'occurred_at' => now(),
        ]);

        $res = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/audit-logs');

        $res->assertStatus(200)
            ->assertJsonStructure(['data' => [['action', 'descriptionAr', 'descriptionEn']], 'meta'])
            ->assertJsonPath('data.0.descriptionAr', 'إنشاء شركة')
            ->assertJsonPath('data.0.descriptionEn', 'Created company');
    }
}
