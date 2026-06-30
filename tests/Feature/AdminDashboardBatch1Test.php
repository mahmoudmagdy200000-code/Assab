<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
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
