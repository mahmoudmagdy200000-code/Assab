<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CashCustody;
use Modules\Admin\Models\CashTransaction;
use Modules\Admin\Models\SettlementRequest;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * T09 §7 ACC-8 / §8 HEAD-4 — cash custody: derived status + KPIs, the txn
 * lifecycle (pending/approve/reject/overdraw), replenish, settle, monthly
 * ledger, head access, and tenant isolation.
 */
class T09CashCustodyTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branchA;

    private Branch $branchB;

    private AsabUser $accountant;

    private AsabUser $head;

    private AsabUser $branchUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Custody Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'براند', 'sub_status' => 'active', 'status' => 'active']);
        $this->branchA = Branch::factory()->create(['name' => 'فرع أ', 'asab_brand_id' => $brand->id, 'asab_company_id' => $this->company->id]);
        $this->branchB = Branch::factory()->create(['name' => 'فرع ب', 'asab_brand_id' => $brand->id, 'asab_company_id' => $this->company->id]);

        $this->accountant = $this->user('acc@custody.test', 'accountant', 'all');
        $this->head = $this->user('head@custody.test', 'head', 'all');
        $this->branchUser = $this->user('brm@custody.test', 'branch', 'branch', [$this->branchA->id]);
    }

    private function user(string $email, string $role, string $scope, array $branchIds = []): AsabUser
    {
        $u = AsabUser::create(['company_id' => $this->company->id, 'name' => $role, 'email' => $email, 'password' => 'secret-password', 'status' => 'active']);
        AsabUserRole::create(['user_id' => $u->id, 'role_key' => $role, 'scope' => $scope, 'branch_ids' => $branchIds]);

        return $u;
    }

    private function acc()
    {
        return $this->actingAs($this->accountant, 'sanctum');
    }

    private function custody(Branch $branch, int $amount, int $used = 0, int $minAlert = 500000): CashCustody
    {
        return CashCustody::create([
            'company_id' => $this->company->id, 'branch_id' => $branch->id,
            'custodian_name' => 'أمين', 'amount' => $amount, 'used' => $used,
            'min_alert' => $minAlert, 'status' => 'active',
        ]);
    }

    // ── ACC-8.1 index KPIs + status derivation ─────────────────────────────────

    public function test_index_kpis_and_status_derivation(): void
    {
        $normal = $this->custody($this->branchA, 1000000);      // remaining 10,000 SAR → normal
        $low = $this->custody($this->branchA, 300000);          // remaining 3,000 SAR (< min_alert) → low
        $critical = $this->custody($this->branchB, 30000);      // remaining 300 SAR (< 500) → critical

        SettlementRequest::create(['custody_id' => $normal->id, 'status' => 'pending', 'requested_at' => now()]);
        CashTransaction::create(['custody_id' => $normal->id, 'txn_type' => 'debit', 'amount' => 100, 'description' => 'x', 'txn_date' => now(), 'status' => 'pending']);

        $body = $this->acc()->getJson('/api/v1/company/me/cash-custody')->assertOk()->json();
        $this->assertSame(3, $body['meta']['kpis']['activeCustodies']);
        $this->assertSame(1, $body['meta']['kpis']['nearDepletion']);
        $this->assertSame(2, $body['meta']['kpis']['pendingRequests']); // 1 settlement + 1 pending txn

        $byId = collect($body['data'])->keyBy('id');
        $this->assertSame('normal', $byId[$normal->id]['status']);
        $this->assertSame('low', $byId[$low->id]['status']);
        $this->assertSame('critical', $byId[$critical->id]['status']);
        $this->assertSame('حرج', $byId[$critical->id]['statusLabelAr']);
    }

    // ── ACC-8.2 addTransaction: replenish / disburse / overdraw / recompute ──────

    public function test_add_transaction_applies_and_guards_overdraw(): void
    {
        $c = $this->custody($this->branchA, 600000); // remaining 6,000 SAR → normal

        // Debit disbursement raises used and flips status to low (remaining 4,500 < 5,000).
        $this->acc()->postJson("/api/v1/company/me/cash-custody/{$c->id}/transactions", [
            'txnType' => 'debit', 'amount' => 150000, 'description' => 'صرف',
        ])->assertStatus(201);
        $this->assertSame(150000, $c->fresh()->used);
        $this->assertSame('low', $c->fresh()->status);

        // Credit replenish raises amount.
        $this->acc()->postJson("/api/v1/company/me/cash-custody/{$c->id}/transactions", [
            'txnType' => 'credit', 'amount' => 100000, 'source' => 'treasury',
        ])->assertStatus(201);
        $this->assertSame(700000, $c->fresh()->amount);

        // Overdraw → 422.
        $this->acc()->postJson("/api/v1/company/me/cash-custody/{$c->id}/transactions", [
            'txnType' => 'debit', 'amount' => 9999999, 'description' => 'كبير',
        ])->assertStatus(422)->assertJsonPath('error.code', 'CUSTODY_OVERDRAWN');
    }

    // ── ACC-8.2 pending → approve/reject lifecycle ──────────────────────────────

    public function test_pending_txn_lifecycle(): void
    {
        $c = $this->custody($this->branchA, 1000000);

        // Pending debit applies nothing.
        $pendingId = $this->acc()->postJson("/api/v1/company/me/cash-custody/{$c->id}/transactions", [
            'txnType' => 'debit', 'amount' => 100000, 'description' => 'طلب صرف', 'status' => 'pending',
        ])->assertStatus(201)->json('id');
        $this->assertSame(0, $c->fresh()->used);

        // Approve applies once; re-approve is a safe no-op.
        $this->acc()->postJson("/api/v1/company/me/cash-custody/{$c->id}/transactions/{$pendingId}/approve")->assertOk();
        $this->assertSame(100000, $c->fresh()->used);
        $this->acc()->postJson("/api/v1/company/me/cash-custody/{$c->id}/transactions/{$pendingId}/approve")->assertOk();
        $this->assertSame(100000, $c->fresh()->used);

        // Rejecting a pending txn applies nothing.
        $pending2 = $this->acc()->postJson("/api/v1/company/me/cash-custody/{$c->id}/transactions", [
            'txnType' => 'debit', 'amount' => 50000, 'description' => 'طلب', 'status' => 'pending',
        ])->assertStatus(201)->json('id');
        $this->acc()->postJson("/api/v1/company/me/cash-custody/{$c->id}/transactions/{$pending2}/reject", ['reason' => 'غير مبرر'])->assertOk();
        $this->assertSame(100000, $c->fresh()->used);

        // Rejecting the APPROVED txn reverses its effect and persists the reason.
        $this->acc()->postJson("/api/v1/company/me/cash-custody/{$c->id}/transactions/{$pendingId}/reject", ['reason' => 'خطأ'])->assertOk();
        $this->assertSame(0, $c->fresh()->used);
        $this->assertSame('خطأ', CashTransaction::find($pendingId)->reason);
    }

    // ── ACC-8 settle ────────────────────────────────────────────────────────────

    public function test_settle_posts_ledger_txns_and_drains_requests(): void
    {
        $c = $this->custody($this->branchA, 1000000, used: 200000);
        SettlementRequest::create(['custody_id' => $c->id, 'status' => 'pending', 'requested_at' => now()]);

        $this->acc()->postJson("/api/v1/company/me/cash-custody/{$c->id}/settle", ['newDepositHalalas' => 300000])
            ->assertOk();

        $c->refresh();
        $this->assertSame(0, $c->used);
        $this->assertSame(1300000, $c->amount); // 1,000,000 + 300,000 deposit
        // A settlement debit (200,000) + a deposit credit (300,000) recorded.
        $this->assertSame(1, CashTransaction::where('custody_id', $c->id)->where('description', 'تسوية العهدة — إغلاق المصروف')->count());
        $this->assertSame(1, CashTransaction::where('custody_id', $c->id)->where('description', 'تسوية العهدة — إيداع جديد')->count());
        // Pending request drained.
        $this->assertSame(0, SettlementRequest::where('custody_id', $c->id)->where('status', 'pending')->count());
    }

    // ── HEAD-4 head access on the company surface ───────────────────────────────

    public function test_head_can_manage_custody_and_branch_is_denied(): void
    {
        $c = $this->custody($this->branchA, 1000000);

        $head = fn () => $this->actingAs($this->head, 'sanctum');
        $head()->getJson('/api/v1/company/me/cash-custody')->assertOk();
        $head()->postJson("/api/v1/company/me/cash-custody/{$c->id}/transactions", [
            'txnType' => 'credit', 'amount' => 500000, 'source' => 'treasury',
        ])->assertStatus(201);
        $this->assertSame(1500000, $c->fresh()->amount);
        $head()->postJson("/api/v1/company/me/cash-custody/{$c->id}/settle", [])->assertOk();

        // Branch role cannot reach custody.
        $this->actingAs($this->branchUser, 'sanctum')->getJson('/api/v1/company/me/cash-custody')->assertStatus(403);
    }

    // ── HEAD-4 monthly ledger ────────────────────────────────────────────────────

    public function test_monthly_ledger_running_balance_and_type_labels(): void
    {
        $c = $this->custody($this->branchA, 500000);
        // Approved credit (وارد) + debit (صادر) this month.
        $this->acc()->postJson("/api/v1/company/me/cash-custody/{$c->id}/transactions", ['txnType' => 'credit', 'amount' => 200000, 'source' => 'treasury'])->assertStatus(201);
        $this->acc()->postJson("/api/v1/company/me/cash-custody/{$c->id}/transactions", ['txnType' => 'debit', 'amount' => 100000, 'description' => 'صرف'])->assertStatus(201);

        $body = $this->acc()->getJson("/api/v1/company/me/cash-custody/{$c->id}/transactions?month=".now()->format('Y-m'))
            ->assertOk()->json();

        // Running balance reconciles to remaining (700,000 − 100,000 = 600,000).
        $this->assertSame(600000, $body['custody']['currentBalanceHalalas']);
        $this->assertSame(600000, $body['transactions'][0]['runningBalanceHalalas']); // newest first = the debit
        // Type labels.
        $credit = collect($body['transactions'])->firstWhere('txnType', 'credit');
        $this->assertSame('مدين - وارد', $credit['typeLabelAr']);

        $this->acc()->get("/api/v1/company/me/cash-custody/{$c->id}/transactions/export?format=csv")->assertOk();
    }

    // ── Tenant isolation ─────────────────────────────────────────────────────────

    public function test_tenant_isolation_on_custody(): void
    {
        $otherCo = AsabCompany::create(['name' => 'Other', 'plan' => 'Professional', 'status' => 'active']);
        $otherBrand = AsabBrand::create(['company_id' => $otherCo->id, 'name' => 'ب2', 'sub_status' => 'active', 'status' => 'active']);
        $otherBranch = Branch::factory()->create(['asab_brand_id' => $otherBrand->id, 'asab_company_id' => $otherCo->id]);
        $foreign = CashCustody::create(['company_id' => $otherCo->id, 'branch_id' => $otherBranch->id, 'custodian_name' => 'x', 'amount' => 100000, 'used' => 0, 'min_alert' => 500000, 'status' => 'active']);

        $this->acc()->getJson("/api/v1/company/me/cash-custody/{$foreign->id}/transactions")->assertStatus(404);
        $this->acc()->postJson("/api/v1/company/me/cash-custody/{$foreign->id}/settle", [])->assertStatus(404);
        $this->acc()->postJson("/api/v1/company/me/cash-custody/{$foreign->id}/transactions", ['txnType' => 'credit', 'amount' => 1000])->assertStatus(404);
    }
}
