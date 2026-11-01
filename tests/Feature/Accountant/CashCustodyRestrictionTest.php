<?php

namespace Tests\Feature\Accountant;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Models\CashCustody;
use Modules\Admin\Models\CashTransaction;
use Modules\Admin\Models\SettlementRequest;
use Modules\Admin\Services\BrandOwnerProvisioningService;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * BE-10 Dedicated Regression Suite — Cash Custody Authorization and Tenant Isolation
 *
 * Enforces Business Rules v3.5 (FUND-01 through FUND-07):
 * - FUND-04: owner and assigned brand roles are scoped; Company Admin is not an owner grant.
 * - FUND-06: generic credits cannot bypass the approved funding/receipt workflow.
 * - FUND-07: direct credits and settlement deposits do not mutate custody balance.
 * - Tenant Isolation: Cross-company and cross-brand access must fail-closed with 404 Not Found before role authorization.
 */
class CashCustodyRestrictionTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $companyA;

    private AsabCompany $companyB;

    private AsabBrand $brandA1;

    private AsabBrand $brandA2;

    private AsabBrand $brandB1;

    private Branch $branchA1;

    private Branch $branchA2;

    private Branch $branchB1;

    private AsabUser $accountant;

    private AsabUser $head;

    private AsabUser $owner;

    private AsabUser $brandOwner;

    private AsabUser $brandManager;

    private AsabUser $companyAdmin;

    private AsabUser $otherAccountant;

    private CashCustody $custodyA1;

    private CashCustody $custodyA2;

    private CashCustody $custodyB1;

    protected function setUp(): void
    {
        parent::setUp();

        // Company A setup (Multi-brand)
        $this->companyA = AsabCompany::create(['name' => 'Company A', 'plan' => 'Professional', 'status' => 'active']);
        $this->brandA1 = AsabBrand::create(['company_id' => $this->companyA->id, 'name' => 'براند أ1', 'sub_status' => 'active', 'status' => 'active']);
        $this->brandA2 = AsabBrand::create(['company_id' => $this->companyA->id, 'name' => 'براند أ2', 'sub_status' => 'active', 'status' => 'active']);

        $this->branchA1 = Branch::factory()->create(['name' => 'فرع 1 براند 1', 'asab_brand_id' => $this->brandA1->id, 'asab_company_id' => $this->companyA->id]);
        $this->branchA2 = Branch::factory()->create(['name' => 'فرع 2 براند 2', 'asab_brand_id' => $this->brandA2->id, 'asab_company_id' => $this->companyA->id]);

        // Company B setup (Foreign tenant)
        $this->companyB = AsabCompany::create(['name' => 'Company B', 'plan' => 'Professional', 'status' => 'active']);
        $this->brandB1 = AsabBrand::create(['company_id' => $this->companyB->id, 'name' => 'براند ب1', 'sub_status' => 'active', 'status' => 'active']);
        $this->branchB1 = Branch::factory()->create(['name' => 'فرع 1 براند ب', 'asab_brand_id' => $this->brandB1->id, 'asab_company_id' => $this->companyB->id]);

        // Company A Users with explicit CompanyUser fixtures
        $this->accountant = $this->createFixtureUser($this->companyA, 'acc_be10@test.com', 'accountant', 'branch', [$this->branchA1->id]);
        $this->head = $this->createFixtureUser($this->companyA, 'head_be10@test.com', 'head', 'all');
        $this->owner = $this->createFixtureUser($this->companyA, 'owner_be10@test.com', 'owner', 'brand', [], [$this->brandA1->id]);
        $this->brandA1->update(['owner_user_id' => $this->owner->id]);
        $this->brandOwner = $this->createFixtureUser($this->companyA, 'brand_owner_be10@test.com', 'brand-owner', 'brand', [], [$this->brandA1->id]);
        $this->brandManager = $this->createFixtureUser($this->companyA, 'bm_be10@test.com', 'brand-manager', 'brand', [], [$this->brandA1->id]);
        $this->companyAdmin = $this->createFixtureUser($this->companyA, 'company_admin_be10@test.com', 'company-admin', 'all');

        // Company B User (Foreign tenant user)
        $this->otherAccountant = $this->createFixtureUser($this->companyB, 'other_acc_be10@test.com', 'accountant', 'all');

        // Custodies
        $this->custodyA1 = CashCustody::create([
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchA1->id,
            'custodian_name' => 'أمين عهدة أ1',
            'amount' => 500000,
            'used' => 0,
            'min_alert' => 100000,
            'status' => 'active',
        ]);

        $this->custodyA2 = CashCustody::create([
            'company_id' => $this->companyA->id,
            'branch_id' => $this->branchA2->id,
            'custodian_name' => 'أمين عهدة أ2',
            'amount' => 600000,
            'used' => 0,
            'min_alert' => 100000,
            'status' => 'active',
        ]);

        $this->custodyB1 = CashCustody::create([
            'company_id' => $this->companyB->id,
            'branch_id' => $this->branchB1->id,
            'custodian_name' => 'أمين عهدة ب1',
            'amount' => 700000,
            'used' => 0,
            'min_alert' => 100000,
            'status' => 'active',
        ]);
    }

    private function createFixtureUser(AsabCompany $company, string $email, string $role, string $scope, array $branchIds = [], array $brandIds = []): AsabUser
    {
        $u = AsabUser::create([
            'company_id' => $company->id,
            'name' => $role,
            'email' => $email,
            'password' => 'secret-password',
            'status' => 'active',
        ]);

        AsabUserRole::create([
            'user_id' => $u->id,
            'role_key' => $role,
            'scope' => $scope,
            'branch_ids' => $branchIds,
            'brand_ids' => $brandIds,
        ]);

        CompanyUser::create([
            'company_id' => $company->id,
            'user_id' => $u->id,
            'role_key' => $role,
            'status' => 'active',
        ]);

        return $u;
    }

    // ── 1. Prohibited Operations: Zero Mutations & 403 Forbidden ───────────────

    public function test_accountant_and_head_cannot_use_the_generic_transaction_writer(): void
    {
        $payloadDebit = ['txnType' => 'debit', 'amount' => 50000, 'description' => 'شراء مستلزمات'];
        $payloadCredit = ['txnType' => 'credit', 'amount' => 100000, 'source' => 'treasury'];

        // 1. Accountant attempt on company/me surface
        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custodyA1->id}/transactions", $payloadDebit)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'CUSTODY_MANUAL_WRITE_PROHIBITED');

        // Assert ZERO financial mutation
        $this->assertSame(0, $this->custodyA1->fresh()->used);
        $this->assertSame(500000, $this->custodyA1->fresh()->amount);
        $this->assertSame(0, CashTransaction::where('custody_id', $this->custodyA1->id)->count());

        // 2. Accountant attempt on accountant portal surface
        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/accountant/cash-custody/{$this->custodyA1->id}/transactions", $payloadDebit)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'CUSTODY_MANUAL_WRITE_PROHIBITED');

        $this->assertSame(0, $this->custodyA1->fresh()->used);
        $this->assertSame(0, CashTransaction::where('custody_id', $this->custodyA1->id)->count());

        // 3. Head attempt to disburse or replenish on company/me surface
        $this->actingAs($this->head, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custodyA1->id}/transactions", $payloadCredit)
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'CUSTODY_MANUAL_WRITE_PROHIBITED');

        $this->assertSame(500000, $this->custodyA1->fresh()->amount);
        $this->assertSame(0, CashTransaction::where('custody_id', $this->custodyA1->id)->count());
    }

    public function test_company_admin_is_not_a_funding_owner_and_cannot_mutate_custody(): void
    {
        $this->actingAs($this->companyAdmin, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custodyA1->id}/transactions", [
                'txnType' => 'debit', 'amount' => 10000, 'description' => 'unauthorized',
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'CUSTODY_MANUAL_WRITE_PROHIBITED');

        $this->assertSame(0, CashTransaction::where('custody_id', $this->custodyA1->id)->count());
        $this->assertSame(500000, $this->custodyA1->fresh()->amount);
        $this->assertSame(0, $this->custodyA1->fresh()->used);

        $pending = CashTransaction::create([
            'custody_id' => $this->custodyA1->id, 'txn_type' => 'debit', 'amount' => 10000,
            'description' => 'pending decision', 'status' => 'pending', 'txn_date' => now(),
            'created_by_id' => $this->accountant->id,
        ]);
        $this->actingAs($this->companyAdmin, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custodyA1->id}/transactions/{$pending->id}/approve")
            ->assertStatus(403);
        $this->actingAs($this->companyAdmin, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custodyA1->id}/transactions/{$pending->id}/reject", ['reason' => 'not authorized'])
            ->assertStatus(403);
        $this->actingAs($this->companyAdmin, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custodyA1->id}/settle", [])
            ->assertStatus(403);
        $this->assertSame('pending', $pending->fresh()->status);
        $this->assertSame(0, $this->custodyA1->fresh()->used);
        $this->assertSame(500000, $this->custodyA1->fresh()->amount);
    }

    public function test_actual_brand_owner_provisioning_is_sufficient_for_its_brand_custody(): void
    {
        $result = app(BrandOwnerProvisioningService::class)
            ->provision($this->brandA1, 'provisioned_owner_be10@test.com', 'Provisioned Owner');
        $owner = $result['user'];

        $this->assertDatabaseHas('asab_company_users', [
            'company_id' => $this->companyA->id,
            'user_id' => $owner->id,
            'role_key' => 'brand-owner',
            'status' => 'active',
        ]);

        $visibleCustodies = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/company/me/cash-custody')
            ->assertOk()
            ->json('data');
        $this->assertSame([$this->custodyA1->id], array_column($visibleCustodies, 'id'));

        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/company/me/cash-custody/{$this->custodyA2->id}/transactions")
            ->assertStatus(404);
        $this->actingAs($owner, 'sanctum')
            ->getJson("/api/v1/company/me/cash-custody/{$this->custodyB1->id}/transactions")
            ->assertStatus(404);

        $export = $this->actingAs($owner, 'sanctum')
            ->get('/api/v1/company/me/cash-custody/export?format=csv')
            ->assertOk();
        $exportContent = $export->baseResponse->getFile()->getContent();
        $this->assertStringContainsString($this->branchA1->name, $exportContent);
        $this->assertStringNotContainsString($this->branchA2->name, $exportContent);
        $this->assertStringNotContainsString($this->branchB1->name, $exportContent);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custodyA1->id}/transactions", [
                'txnType' => 'debit', 'amount' => 10000, 'description' => 'provisioned owner scoped debit',
            ])
            ->assertStatus(201);

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custodyA2->id}/transactions", [
                'txnType' => 'debit', 'amount' => 10000, 'description' => 'cross brand debit',
            ])
            ->assertStatus(404);
        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custodyB1->id}/transactions", [
                'txnType' => 'debit', 'amount' => 10000, 'description' => 'cross company debit',
            ])
            ->assertStatus(404);

        $this->assertSame(10000, $this->custodyA1->fresh()->used);
        $this->assertSame(0, $this->custodyA2->fresh()->used);
    }

    public function test_admin_http_brand_provisioning_creates_a_scoped_owner_membership(): void
    {
        \Illuminate\Support\Facades\Mail::fake();
        $platformAdmin = AsabUser::create([
            'name' => 'Platform Admin', 'email' => 'admin-brand-owner-be10@test.com',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $platformAdmin->id, 'role_key' => 'admin', 'scope' => 'all']);

        $created = $this->actingAs($platformAdmin, 'sanctum')
            ->postJson('/api/v1/admin/brands', [
                'name' => 'Provisioned HTTP Brand',
                'owner' => 'HTTP Owner',
                'ownerEmail' => 'http-owner-be10@test.com',
            ])
            ->assertCreated();

        $brand = AsabBrand::findOrFail($created->json('id'));
        $owner = AsabUser::findOrFail($brand->owner_user_id);
        $this->assertDatabaseHas('asab_company_users', [
            'company_id' => $brand->company_id,
            'user_id' => $owner->id,
            'role_key' => 'brand-owner',
            'status' => 'active',
        ]);

        $branch = Branch::factory()->create([
            'name' => 'HTTP Owner Branch',
            'asab_brand_id' => $brand->id,
            'asab_company_id' => $brand->company_id,
        ]);
        $custody = CashCustody::create([
            'company_id' => $brand->company_id,
            'branch_id' => $branch->id,
            'custodian_name' => 'HTTP Owner Custodian',
            'amount' => 500000,
            'used' => 0,
            'min_alert' => 100000,
            'status' => 'active',
        ]);

        $visibleCustodies = $this->actingAs($owner, 'sanctum')
            ->getJson('/api/v1/company/me/cash-custody')
            ->assertOk()
            ->json('data');
        $this->assertSame([$custody->id], array_column($visibleCustodies, 'id'));

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custodyA1->id}/settlement-request")
            ->assertStatus(404);

        $export = $this->actingAs($owner, 'sanctum')
            ->get('/api/v1/company/me/cash-custody/export?format=csv')
            ->assertOk();
        $this->assertStringContainsString($branch->name, $export->baseResponse->getFile()->getContent());

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$custody->id}/settlement-request")
            ->assertCreated()
            ->assertJsonPath('status', 'pending');

        $this->actingAs($owner, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$custody->id}/transactions", [
                'txnType' => 'credit', 'amount' => 100000, 'source' => 'treasury',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CUSTODY_FUNDING_REQUEST_REQUIRED');

        $this->assertSame(0, $custody->fresh()->used);
        $this->assertSame(1, SettlementRequest::where('custody_id', $custody->id)->count());
    }

    public function test_brand_role_with_all_scope_cannot_read_or_export_other_brands(): void
    {
        $wideRole = $this->createFixtureUser(
            $this->companyA,
            'wide_brand_owner_be10@test.com',
            'brand-owner',
            'all',
            [],
            [$this->brandA1->id],
        );

        $list = $this->actingAs($wideRole, 'sanctum')
            ->getJson('/api/v1/company/me/cash-custody')
            ->assertOk()
            ->json('data');

        $this->assertSame([$this->custodyA1->id], array_column($list, 'id'));

        $this->actingAs($wideRole, 'sanctum')
            ->getJson("/api/v1/company/me/cash-custody/{$this->custodyA2->id}/transactions")
            ->assertStatus(404);

        $export = $this->actingAs($wideRole, 'sanctum')
            ->get('/api/v1/company/me/cash-custody/export?format=csv')
            ->assertOk();

        $exportContent = $export->baseResponse->getFile()->getContent();
        $this->assertStringContainsString($this->branchA1->name, $exportContent);
        $this->assertStringNotContainsString($this->branchA2->name, $exportContent);
    }

    public function test_company_admin_cannot_create_a_funding_settlement_request(): void
    {
        $visibleCustodies = $this->actingAs($this->companyAdmin, 'sanctum')
            ->getJson('/api/v1/company/me/cash-custody')
            ->assertOk()
            ->json('data');
        $this->assertEqualsCanonicalizing(
            [$this->custodyA1->id, $this->custodyA2->id],
            array_column($visibleCustodies, 'id'),
        );

        $export = $this->actingAs($this->companyAdmin, 'sanctum')
            ->get('/api/v1/company/me/cash-custody/export?format=csv')
            ->assertOk();
        $exportContent = $export->baseResponse->getFile()->getContent();
        $this->assertStringContainsString($this->branchA1->name, $exportContent);
        $this->assertStringContainsString($this->branchA2->name, $exportContent);
        $this->assertStringNotContainsString($this->branchB1->name, $exportContent);

        $this->actingAs($this->companyAdmin, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custodyA1->id}/settlement-request")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'CUSTODY_MANUAL_WRITE_PROHIBITED');

        $this->actingAs($this->companyAdmin, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custodyA1->id}/transactions", [
                'txnType' => 'credit', 'amount' => 100000, 'source' => 'treasury',
            ])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'CUSTODY_MANUAL_WRITE_PROHIBITED');

        $this->assertSame(0, SettlementRequest::where('custody_id', $this->custodyA1->id)->count());
    }

    public function test_owner_and_brand_manager_cannot_credit_through_the_generic_cash_transaction_writer(): void
    {
        foreach ([$this->owner, $this->brandManager] as $actor) {
            $this->actingAs($actor, 'sanctum')
                ->postJson("/api/v1/company/me/cash-custody/{$this->custodyA1->id}/transactions", [
                    'txnType' => 'credit', 'amount' => 100000, 'source' => 'treasury',
                ])
                ->assertStatus(422)
                ->assertJsonPath('error.code', 'CUSTODY_FUNDING_REQUEST_REQUIRED');
        }

        $this->assertSame(500000, $this->custodyA1->fresh()->amount);
        $this->assertSame(0, $this->custodyA1->fresh()->used);
        $this->assertSame(0, CashTransaction::where('custody_id', $this->custodyA1->id)->count());

        $pendingCredit = CashTransaction::create([
            'custody_id' => $this->custodyA1->id, 'txn_type' => 'credit', 'amount' => 100000,
            'description' => 'legacy pending credit', 'status' => 'pending', 'txn_date' => now(),
            'created_by_id' => $this->accountant->id,
        ]);
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custodyA1->id}/transactions/{$pendingCredit->id}/approve")
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CUSTODY_FUNDING_REQUEST_REQUIRED');
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custodyA1->id}/transactions/{$pendingCredit->id}/reject", ['reason' => 'use funding request'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CUSTODY_FUNDING_REQUEST_REQUIRED');
        $this->assertSame('pending', $pendingCredit->fresh()->status);
        $this->assertSame(500000, $this->custodyA1->fresh()->amount);

        $appliedLegacyCredit = CashTransaction::create([
            'custody_id' => $this->custodyA1->id, 'txn_type' => 'credit', 'amount' => 50000,
            'description' => 'legacy approved credit', 'status' => 'approved', 'txn_date' => now(),
            'created_by_id' => $this->owner->id,
        ]);
        $this->custodyA1->update(['amount' => 550000]);
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custodyA1->id}/transactions/{$appliedLegacyCredit->id}/reject", ['reason' => 'use funding request'])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CUSTODY_FUNDING_REQUEST_REQUIRED');
        $this->assertSame('approved', $appliedLegacyCredit->fresh()->status);
        $this->assertSame(550000, $this->custodyA1->fresh()->amount);
    }

    public function test_brand_owner_with_an_explicit_brand_assignment_can_use_only_that_brand_surface(): void
    {
        $this->actingAs($this->brandOwner, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custodyA1->id}/transactions", [
                'txnType' => 'debit', 'amount' => 10000, 'description' => 'brand-scoped debit',
            ])
            ->assertStatus(201);

        $this->actingAs($this->brandOwner, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custodyA2->id}/transactions", [
                'txnType' => 'debit', 'amount' => 10000, 'description' => 'cross-brand debit',
            ])
            ->assertStatus(404);

        $this->assertSame(10000, $this->custodyA1->fresh()->used);
        $this->assertSame(0, $this->custodyA2->fresh()->used);
    }

    public function test_inactive_membership_and_revoked_brand_assignment_fail_closed_without_mutation(): void
    {
        CompanyUser::where('company_id', $this->companyA->id)
            ->where('user_id', $this->owner->id)
            ->update(['status' => 'inactive']);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custodyA1->id}/transactions", [
                'txnType' => 'debit', 'amount' => 10000,
            ])
            ->assertStatus(403);

        CompanyUser::where('company_id', $this->companyA->id)
            ->where('user_id', $this->owner->id)
            ->update(['status' => 'active']);
        AsabBrand::whereKey($this->brandA1->id)->update(['owner_user_id' => null]);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custodyA1->id}/transactions", [
                'txnType' => 'debit', 'amount' => 10000,
            ])
            // Once the persisted brand ownership link is revoked, the brand's
            // custody is outside this user's resource scope and must be hidden.
            ->assertStatus(404);

        AsabUserRole::where('user_id', $this->brandOwner->id)
            ->where('role_key', 'brand-owner')
            ->update(['brand_ids' => [$this->brandA2->id]]);
        $this->actingAs($this->brandOwner, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custodyA1->id}/transactions", [
                'txnType' => 'debit', 'amount' => 10000,
            ])
            ->assertStatus(404);

        $this->assertSame(0, $this->custodyA1->fresh()->used);
        $this->assertSame(0, CashTransaction::where('custody_id', $this->custodyA1->id)->count());
    }

    public function test_accountant_and_head_cannot_approve_reject_or_settle(): void
    {
        // Owner creates pending transaction and settlement request
        $pendingTxn = CashTransaction::create([
            'custody_id' => $this->custodyA1->id,
            'txn_type' => 'debit',
            'amount' => 40000,
            'description' => 'طلب صرف معلق',
            'status' => 'pending',
            'txn_date' => now(),
            'created_by_id' => $this->owner->id,
        ]);

        $settlementReq = SettlementRequest::create([
            'custody_id' => $this->custodyA1->id,
            'status' => 'pending',
            'requested_at' => now(),
        ]);

        // 1. Accountant cannot approve
        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custodyA1->id}/transactions/{$pendingTxn->id}/approve")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'CUSTODY_MANUAL_WRITE_PROHIBITED');

        $this->assertSame('pending', $pendingTxn->fresh()->status);
        $this->assertSame(0, $this->custodyA1->fresh()->used);

        // 2. Head cannot approve
        $this->actingAs($this->head, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custodyA1->id}/transactions/{$pendingTxn->id}/approve")
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'CUSTODY_MANUAL_WRITE_PROHIBITED');

        $this->assertSame('pending', $pendingTxn->fresh()->status);
        $this->assertSame(0, $this->custodyA1->fresh()->used);

        // 3. Accountant cannot reject
        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custodyA1->id}/transactions/{$pendingTxn->id}/reject", ['reason' => 'مرفوض'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'CUSTODY_MANUAL_WRITE_PROHIBITED');

        $this->assertSame('pending', $pendingTxn->fresh()->status);

        // 4. Head cannot reject
        $this->actingAs($this->head, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custodyA1->id}/transactions/{$pendingTxn->id}/reject", ['reason' => 'مرفوض من رئيس الحسابات'])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'CUSTODY_MANUAL_WRITE_PROHIBITED');

        $this->assertSame('pending', $pendingTxn->fresh()->status);

        // 5. Accountant and Head cannot settle
        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custodyA1->id}/settle", ['newDepositHalalas' => 100000])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'CUSTODY_MANUAL_WRITE_PROHIBITED');

        $this->actingAs($this->head, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custodyA1->id}/settle", ['newDepositHalalas' => 100000])
            ->assertStatus(403)
            ->assertJsonPath('error.code', 'CUSTODY_MANUAL_WRITE_PROHIBITED');

        // Zero financial mutation
        $this->assertSame(0, $this->custodyA1->fresh()->used);
        $this->assertSame(500000, $this->custodyA1->fresh()->amount);
        $this->assertSame('pending', $settlementReq->fresh()->status);
    }

    // ── 2. Authorized Operations: Owner and Brand Manager ──────────────────────

    public function test_authorized_owner_and_brand_manager_can_transact_and_settle(): void
    {
        // 1. Owner can disburse (debit)
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custodyA1->id}/transactions", [
                'txnType' => 'debit',
                'amount' => 150000,
                'description' => 'صرف معتمد من المالك',
            ])
            ->assertStatus(201);

        $this->assertSame(150000, $this->custodyA1->fresh()->used);

        // Direct top-ups are no longer accepted through the generic transaction writer.
        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custodyA1->id}/transactions", [
                'txnType' => 'credit',
                'amount' => 200000,
                'source' => 'treasury',
            ])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CUSTODY_FUNDING_REQUEST_REQUIRED');

        $this->assertSame(500000, $this->custodyA1->fresh()->amount);

        // 3. Brand Manager can approve pending transactions
        $pending = CashTransaction::create([
            'custody_id' => $this->custodyA1->id,
            'txn_type' => 'debit',
            'amount' => 50000,
            'description' => 'طلب صرف يحتاج اعتماد مدير البراند',
            'status' => 'pending',
            'txn_date' => now(),
            'created_by_id' => $this->owner->id,
        ]);

        $this->actingAs($this->brandManager, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custodyA1->id}/transactions/{$pending->id}/approve")
            ->assertOk();

        $this->assertSame('approved', $pending->fresh()->status);
        $this->assertSame(200000, $this->custodyA1->fresh()->used); // 150000 + 50000

        // 4. Owner can settle spent custody, but a settlement cannot smuggle in a top-up.
        SettlementRequest::create(['custody_id' => $this->custodyA1->id, 'status' => 'pending', 'requested_at' => now()]);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custodyA1->id}/settle", ['newDepositHalalas' => 300000])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'CUSTODY_FUNDING_REQUEST_REQUIRED');

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custodyA1->id}/settle")
            ->assertOk();

        // Closing settlement does not erase approved spending or reopen funds.
        $this->assertSame(200000, $this->custodyA1->fresh()->used);
        $this->assertSame(500000, $this->custodyA1->fresh()->amount);
        $this->assertSame(300000, $this->custodyA1->fresh()->amount - $this->custodyA1->fresh()->used);
        $this->assertSame(2, CashTransaction::where('custody_id', $this->custodyA1->id)->count());
        $this->assertSame(0, CashTransaction::where('custody_id', $this->custodyA1->id)
            ->where('description', 'تسوية العهدة — إغلاق المصروف')->count());
        $this->assertSame(0, SettlementRequest::where('custody_id', $this->custodyA1->id)->where('status', 'pending')->count());
    }

    // ── 3. Tenant & Brand Isolation: Fail-Closed with 404 (NOT 403) ─────────────

    public function test_cross_tenant_and_cross_brand_returns_404_not_403(): void
    {
        $payload = ['txnType' => 'debit', 'amount' => 20000, 'description' => 'محاولة اختراق'];

        // 1. Cross-Company: User from Company A accessing Company B custody
        // Must return 404 Not Found, NEVER 403
        $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/company/me/cash-custody/{$this->custodyB1->id}/transactions")
            ->assertStatus(404);

        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custodyB1->id}/transactions", $payload)
            ->assertStatus(404);

        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custodyB1->id}/settle", [])
            ->assertStatus(404);

        // Even Company A Owner gets 404 for Company B custody (tenant boundary is impenetrable)
        $this->actingAs($this->owner, 'sanctum')
            ->getJson("/api/v1/company/me/cash-custody/{$this->custodyB1->id}/transactions")
            ->assertStatus(404);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custodyB1->id}/transactions", $payload)
            ->assertStatus(404);

        $this->actingAs($this->owner, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custodyB1->id}/settle", [])
            ->assertStatus(404);

        // 2. Cross-Brand / Cross-Branch: Accountant assigned only to branchA1 accessing custodyA2 (branchA2)
        $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/company/me/cash-custody/{$this->custodyA2->id}/transactions")
            ->assertStatus(404);

        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custodyA2->id}/transactions", $payload)
            ->assertStatus(404);

        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/accountant/cash-custody/{$this->custodyA2->id}/transactions", $payload)
            ->assertStatus(404);

        $this->actingAs($this->accountant, 'sanctum')
            ->postJson("/api/v1/company/me/cash-custody/{$this->custodyA2->id}/settle", [])
            ->assertStatus(404);

        // Verify foreign custodies suffered ZERO mutations
        $this->assertSame(0, $this->custodyB1->fresh()->used);
        $this->assertSame(0, $this->custodyA2->fresh()->used);
    }

    // ── 4. Read Operations: Remain Fully Functional ────────────────────────────

    public function test_read_operations_remain_functional_for_accountant_and_head(): void
    {
        // 1. GET index on company/me
        $resMe = $this->actingAs($this->accountant, 'sanctum')
            ->getJson('/api/v1/company/me/cash-custody')
            ->assertOk()
            ->json();

        $this->assertArrayHasKey('meta', $resMe);
        $this->assertArrayHasKey('kpis', $resMe['meta']);
        $this->assertArrayHasKey('data', $resMe);

        // 2. GET index on accountant portal
        $this->actingAs($this->accountant, 'sanctum')
            ->getJson('/api/v1/accountant/cash-custody')
            ->assertOk();

        // 3. GET transactions ledger
        $this->actingAs($this->accountant, 'sanctum')
            ->getJson("/api/v1/company/me/cash-custody/{$this->custodyA1->id}/transactions")
            ->assertOk()
            ->assertJsonStructure(['custody', 'transactions']);

        // 4. GET export CSV
        $this->actingAs($this->accountant, 'sanctum')
            ->get("/api/v1/company/me/cash-custody/{$this->custodyA1->id}/transactions/export?format=csv")
            ->assertOk();
    }
}
