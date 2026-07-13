<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Modules\Admin\Mail\CompanyInvitationMail;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabRestaurant;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Attachment;
use Modules\Admin\Models\CompanyInvitation;
use Modules\Admin\Models\CompanySubscription;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Models\Plan;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * T14 company-portal gap fixes: add-branch pending review (T14.1), invitation
 * email + resend (T14.2), cross-tenant accept guard (T14.3), storage quota
 * (T14.4), SSO enterprise gate (T14.5), transfer-manager validation (T14.6).
 *
 * Run: ./vendor/bin/pest tests/Feature/CompanyPortalGapsTest.php -d memory_limit=1024M
 */
class CompanyPortalGapsTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Plan $proPlan;

    private Plan $enterprisePlan;

    private CompanySubscription $subscription;

    private AsabBrand $brand;

    private AsabRestaurant $restaurant;

    private Branch $branch;

    private AsabUser $companyAdmin;

    private AsabUser $platformAdmin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Portal Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->proPlan = $this->plan('professional', 10);
        $this->enterprisePlan = $this->plan('enterprise', 1000);
        $this->subscription = CompanySubscription::create([
            'company_id' => $this->company->id, 'plan_id' => $this->proPlan->id, 'status' => 'active',
            'billing_cycle' => 'annual', 'current_period_start' => now(), 'current_period_end' => now()->addYear(),
            'start_date' => now(), 'auto_renew' => true,
        ]);
        $this->brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'علامة', 'status' => 'active']);
        $this->restaurant = AsabRestaurant::create(['company_id' => $this->company->id, 'brand_id' => $this->brand->id, 'name' => 'مطعم', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['asab_company_id' => $this->company->id, 'asab_brand_id' => $this->brand->id, 'asab_restaurant_id' => $this->restaurant->id, 'name' => 'فرع', 'asab_review_status' => 'approved']);

        $this->companyAdmin = $this->user('admin@portal.test', 'company-admin', $this->company->id);
        $this->platformAdmin = $this->user('root@portal.test', 'admin', null);
    }

    private function plan(string $code, int $storageGb): Plan
    {
        return Plan::create([
            'code' => $code, 'name_ar' => $code, 'name_en' => $code, 'price_monthly' => 0, 'price_annual' => 0,
            'annual_discount_pct' => 0, 'max_branches' => 20, 'max_users' => 50, 'max_brands' => 10,
            'max_restaurants' => 20, 'storage_gb' => $storageGb, 'modules_included' => [], 'status' => 'active', 'sort_order' => 1,
        ]);
    }

    private function user(string $email, string $role, ?string $companyId): AsabUser
    {
        $u = AsabUser::create(['company_id' => $companyId, 'name' => $role, 'email' => $email, 'password' => 'secret-password', 'status' => 'active']);
        AsabUserRole::create(['user_id' => $u->id, 'role_key' => $role, 'scope' => 'all']);

        return $u;
    }

    // ---- T14.1 add-branch pending review ----

    public function test_store_branch_is_pending_review_then_admin_approves(): void
    {
        $res = $this->actingAs($this->companyAdmin, 'sanctum')->postJson('/api/v1/company/me/branches', [
            'restaurantId' => $this->restaurant->id, 'name' => 'فرع جديد', 'city' => 'الرياض',
        ]);
        $res->assertCreated()->assertJsonPath('status', 'pending_review')->assertJsonPath('reviewStatus', 'pending_review');
        $branchId = $res->json('id');
        $this->assertDatabaseHas('branches', ['id' => $branchId, 'asab_review_status' => 'pending_review', 'status' => 'inactive']);

        $queue = $this->actingAs($this->platformAdmin, 'sanctum')->getJson('/api/v1/admin/branch-requests');
        $queue->assertOk();
        $this->assertTrue(collect($queue->json('data'))->pluck('id')->contains($branchId));

        $this->actingAs($this->platformAdmin, 'sanctum')->postJson('/api/v1/admin/branch-requests/'.$branchId.'/approve')
            ->assertOk()->assertJsonPath('reviewStatus', 'approved');
        $this->assertDatabaseHas('branches', ['id' => $branchId, 'asab_review_status' => 'approved', 'status' => 'active']);
    }

    public function test_admin_rejects_branch_request_with_reason(): void
    {
        $branchId = $this->actingAs($this->companyAdmin, 'sanctum')->postJson('/api/v1/company/me/branches', [
            'restaurantId' => $this->restaurant->id, 'name' => 'فرع مرفوض', 'city' => 'جدة',
        ])->json('id');

        $this->actingAs($this->platformAdmin, 'sanctum')->postJson('/api/v1/admin/branch-requests/'.$branchId.'/reject', ['reason' => 'موقع غير مناسب'])
            ->assertOk()->assertJsonPath('reviewStatus', 'rejected')->assertJsonPath('reviewNote', 'موقع غير مناسب');
    }

    // ---- T14.2 invitation email + resend ----

    public function test_invite_queues_email_and_hides_token(): void
    {
        Mail::fake();
        $res = $this->actingAs($this->companyAdmin, 'sanctum')->postJson('/api/v1/company/invitations', [
            'email' => 'newbie@x.test', 'roleKey' => 'head',
        ]);
        $res->assertCreated();
        $this->assertNull($res->json('token'));
        Mail::assertQueued(CompanyInvitationMail::class);
    }

    public function test_resend_regenerates_pending_and_409_on_nonpending(): void
    {
        Mail::fake();
        $inv = CompanyInvitation::create([
            'company_id' => $this->company->id, 'email' => 'p@x.test', 'role_key' => 'head', 'token' => Str::random(64),
            'status' => 'pending', 'invited_by_id' => $this->companyAdmin->id, 'expires_at' => now()->addDays(7), 'created_at' => now(),
        ]);
        $old = $inv->token;

        $this->actingAs($this->companyAdmin, 'sanctum')->postJson('/api/v1/company/invitations/'.$inv->id.'/resend')->assertStatus(202);
        Mail::assertQueued(CompanyInvitationMail::class);
        $this->assertNotSame($old, $inv->fresh()->token);

        $inv->update(['status' => 'accepted']);
        $this->actingAs($this->companyAdmin, 'sanctum')->postJson('/api/v1/company/invitations/'.$inv->id.'/resend')->assertStatus(409);
    }

    // ---- T14.3 cross-tenant accept guard ----

    public function test_accept_rejects_email_registered_to_another_company(): void
    {
        $other = AsabCompany::create(['name' => 'Other', 'plan' => 'Professional', 'status' => 'active']);
        $existing = AsabUser::create(['company_id' => $other->id, 'name' => 'x', 'email' => 'taken@x.test', 'password' => 'secret-password', 'status' => 'active']);
        $inv = CompanyInvitation::create([
            'company_id' => $this->company->id, 'email' => 'taken@x.test', 'role_key' => 'head', 'token' => Str::random(64),
            'status' => 'pending', 'expires_at' => now()->addDays(7), 'created_at' => now(),
        ]);

        $this->postJson('/api/v1/company/invitations/accept', ['token' => $inv->token, 'name' => 'X', 'password' => 'password123'])
            ->assertStatus(409);
        $this->assertSame($other->id, $existing->fresh()->company_id); // unchanged
    }

    public function test_accept_fresh_email_issues_tokens(): void
    {
        $inv = CompanyInvitation::create([
            'company_id' => $this->company->id, 'email' => 'fresh@x.test', 'role_key' => 'head', 'token' => Str::random(64),
            'status' => 'pending', 'expires_at' => now()->addDays(7), 'created_at' => now(),
        ]);

        $this->postJson('/api/v1/company/invitations/accept', ['token' => $inv->token, 'name' => 'Fresh', 'password' => 'password123'])
            ->assertOk()->assertJsonStructure(['accessToken', 'refreshToken']);
    }

    // ---- T14.4 storage quota ----

    public function test_storage_quota_reflects_company_attachments(): void
    {
        Attachment::create([
            'owner_type' => 'report', 'owner_id' => 'x', 'filename' => 'big.pdf', 'mime_type' => 'application/pdf',
            'size' => 2 * 1024 * 1024 * 1024, 'storage_key' => $this->company->id.'/reports/big.pdf', 'uploaded_at' => now(),
        ]);

        $dash = $this->actingAs($this->companyAdmin, 'sanctum')->getJson('/api/v1/company/me/dashboard');
        $dash->assertOk();
        $this->assertGreaterThanOrEqual(2, $dash->json('quotas.storage.usedGb'));
        $this->assertSame(10, $dash->json('quotas.storage.maxGb'));

        $sub = $this->actingAs($this->companyAdmin, 'sanctum')->getJson('/api/v1/company/me/subscription');
        $sub->assertOk();
        $this->assertArrayHasKey('storage', $sub->json('usage'));
    }

    // ---- T14.5 SSO enterprise gate ----

    public function test_sso_gated_on_live_subscription_plan(): void
    {
        $this->actingAs($this->companyAdmin, 'sanctum')->putJson('/api/v1/company/me/sso', ['provider' => 'saml'])
            ->assertStatus(403)->assertJsonPath('error.code', 'PLAN_REQUIRED');

        $this->subscription->update(['plan_id' => $this->enterprisePlan->id]);
        $this->actingAs($this->companyAdmin, 'sanctum')->putJson('/api/v1/company/me/sso', ['provider' => 'saml'])->assertOk();
    }

    // ---- T14.6 transfer-manager validation + scope sync ----

    public function test_transfer_manager_rejects_foreign_user_and_syncs_scope(): void
    {
        $this->actingAs($this->companyAdmin, 'sanctum')->postJson('/api/v1/company/me/branches/'.$this->branch->id.'/transfer-manager', [
            'newManagerUserId' => 'nonexistent-id',
        ])->assertStatus(422)->assertJsonPath('error.code', 'INVALID_ROLE_SCOPE');

        $mgr = AsabUser::create(['company_id' => $this->company->id, 'name' => 'مدير', 'email' => 'mgr@x.test', 'password' => 'secret-password', 'status' => 'active']);
        AsabUserRole::create(['user_id' => $mgr->id, 'role_key' => 'branch', 'scope' => 'all', 'branch_ids' => []]);
        CompanyUser::create(['company_id' => $this->company->id, 'user_id' => $mgr->id, 'role_key' => 'branch', 'status' => 'active']);

        $this->actingAs($this->companyAdmin, 'sanctum')->postJson('/api/v1/company/me/branches/'.$this->branch->id.'/transfer-manager', [
            'newManagerUserId' => $mgr->id,
        ])->assertOk();

        $this->assertDatabaseHas('branches', ['id' => $this->branch->id, 'asab_manager_user_id' => $mgr->id]);
        $this->assertSame($this->branch->id, CompanyUser::where('user_id', $mgr->id)->first()->branch_id);
        $this->assertSame([$this->branch->id], AsabUserRole::where('user_id', $mgr->id)->where('role_key', 'branch')->first()->branch_ids);
    }
}
