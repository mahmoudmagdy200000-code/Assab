<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabIdentityMap;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Illuminate\Testing\TestResponse;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\Custody\Models\CustodyRequest;
use Modules\Custody\Models\CustodyRequestTimeline;
use Modules\Custody\Models\CustodyTransaction;
use Tests\Concerns\BuildsExpenseTenantFixtures;
use Tests\TestCase;

/**
 * BRC v3.4 F-09 — tenant isolation of the funding-request (`custody_requests`) flow for the brand-level actors.
 *
 * AS-IS (REVIEWER_EXECUTED probe on Candidate dabae358): any BrandOwner — and every BrandManager, which extends it —
 * could list, read, approve and reject ANY request of ANY company, even with no dashboard brand link at all, and
 * could address an Owner Payment Form transfer to ANY branch manager (the recipient picker listed every active branch
 * manager of every tenant). Required: the actor's authority is the set of branches of the brands the SERVER resolves for
 * them (MobileBranchScopeService, the one assignment source the repository has — the same one EXP-06 uses for expenses),
 * fail-closed: an owner / brand manager with no resolvable brand reaches nothing. Out-of-scope = the same answer as an
 * unknown id, so existence is not disclosed. Nothing the client sends is authorization.
 *
 *   Company A ── Brand A1 ── Branch A1 ── bmA1      ownerA1 (A1), managerA1 (A1, brand manager)
 *            └── Brand A2 ── Branch A2 ── bmA2      ownerA12 (A1 + A2)
 *   Company B ── Brand B1 ── Branch B1 ── bmB1      ownerB1 (B1)          ownerUnlinked / managerUnassigned: no brand
 */
class CustodyRequestTenantScopeTest extends TestCase
{
    use BuildsExpenseTenantFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // SQLITE_FK_DIVERGENCE (see CustodyFundingRequestLifecycleTest): approved_by/rejected_by keep a branch_managers
        // FK on SQLite only; owners decide these requests, so defer the check (the test transaction never commits).
        DB::statement('PRAGMA defer_foreign_keys = ON');
        $this->buildExpenseTenants();
    }

    private function request(BranchManager $manager, array $over = []): CustodyRequest
    {
        return CustodyRequest::create(array_merge([
            'branch_manager_id' => $manager->id, 'branch_id' => $manager->branch_id,
            'requested_amount' => 500, 'purpose' => 'branch float', 'preferred_receipt_method' => 'Cash Handover',
            'status' => 'Pending',
        ], $over));
    }

    /** @return array<string, string> a fingerprint of every table a decision could write to */
    private function snapshot(): array
    {
        $out = [];
        foreach (['custody_requests', (new CustodyRequestTimeline)->getTable(), (new CustodyTransaction)->getTable()] as $table) {
            $rows = DB::table($table)->get()->map(fn ($r) => (array) $r)->sortBy('id')->values()->toJson();
            $out[$table] = md5($rows).':'.DB::table($table)->count();
        }

        return $out;
    }

    private function idsListedFor(object $actor): array
    {
        return collect($this->actingAs($actor, 'sanctum')->getJson('/api/custody/requests')->assertOk()->json('data.requests'))
            ->pluck('id')->sort()->values()->all();
    }

    private function sorted(CustodyRequest ...$requests): array
    {
        return collect($requests)->pluck('id')->sort()->values()->all();
    }

    // ───────────────────────────── list ─────────────────────────────

    public function test_each_owner_lists_only_the_requests_of_the_branches_of_their_own_brands(): void
    {
        $a1 = $this->request($this->bmA1);
        $a2 = $this->request($this->bmA2);
        $b1 = $this->request($this->bmB1);

        $this->assertSame($this->sorted($a1), $this->idsListedFor($this->ownerA1));
        $this->assertSame($this->sorted($a1, $a2), $this->idsListedFor($this->ownerA12));
        $this->assertSame($this->sorted($b1), $this->idsListedFor($this->ownerB1));
    }

    public function test_a_brand_manager_lists_only_its_delegated_brands(): void
    {
        $a1 = $this->request($this->bmA1);
        $this->request($this->bmA2);
        $this->request($this->bmB1);

        $this->assertSame($this->sorted($a1), $this->idsListedFor($this->managerA1));
    }

    public function test_an_owner_or_brand_manager_with_no_brand_link_lists_nothing_fail_closed(): void
    {
        $this->request($this->bmA1);
        $this->request($this->bmB1);

        $this->assertSame([], $this->idsListedFor($this->ownerUnlinked));
        $this->assertSame([], $this->idsListedFor($this->managerUnassigned));
    }

    public function test_an_owner_still_lists_the_transfer_they_created_themselves(): void
    {
        $own = $this->request($this->bmA1, ['created_by_brand_owner_id' => $this->ownerA1->id]);
        $this->request($this->bmB1, ['created_by_brand_owner_id' => $this->ownerB1->id]);

        $this->assertSame($this->sorted($own), $this->idsListedFor($this->ownerA1));
    }

    public function test_a_request_without_a_branch_is_scoped_through_its_managers_branch(): void
    {
        $legacy = $this->request($this->bmA1, ['branch_id' => null]);
        $other = $this->request($this->bmB1, ['branch_id' => null]);

        $this->assertSame($this->sorted($legacy), $this->idsListedFor($this->ownerA1));
        $this->assertSame($this->sorted($other), $this->idsListedFor($this->ownerB1));
    }

    // ───────────────────────────── read ─────────────────────────────

    public function test_out_of_scope_detail_is_indistinguishable_from_an_unknown_id(): void
    {
        $b1 = $this->request($this->bmB1);
        $a2 = $this->request($this->bmA2);
        $this->actingAs($this->ownerA1, 'sanctum');

        $this->getJson("/api/custody/requests/{$this->request($this->bmA1)->id}")->assertOk();

        $unknown = $this->getJson('/api/custody/requests/'.Str::uuid());
        foreach ([$b1, $a2] as $outOfScope) {
            $r = $this->getJson("/api/custody/requests/{$outOfScope->id}");
            $r->assertNotFound();
            $this->assertSame($unknown->status(), $r->status());
            $this->assertSame($unknown->json('message'), $r->json('message'));
        }
    }

    public function test_unlinked_actors_cannot_read_any_request(): void
    {
        $a1 = $this->request($this->bmA1);

        $this->actingAs($this->ownerUnlinked, 'sanctum')->getJson("/api/custody/requests/{$a1->id}")->assertNotFound();
        $this->actingAs($this->managerUnassigned, 'sanctum')->getJson("/api/custody/requests/{$a1->id}")->assertNotFound();
    }

    // ───────────────────────────── decide ─────────────────────────────

    private function assertSameAsUnknownId(TestResponse $actual, string $verb): void
    {
        $unknown = $this->actingAs($this->ownerUnlinked, 'sanctum')->postJson('/api/custody/requests/'.Str::uuid()."/$verb", ['reason' => 'no reason given']);
        $this->assertSame($unknown->status(), $actual->status());
        $strip = fn ($m) => preg_replace('/[0-9a-f]{8}-[0-9a-f-]{27}/', '<id>', (string) $m);
        $this->assertSame($strip($unknown->json('message')), $strip($actual->json('message')));
    }

    public function test_an_owner_of_another_company_cannot_approve_or_reject_and_nothing_is_written(): void
    {
        $a1 = $this->request($this->bmA1);
        $before = $this->snapshot();

        $approve = $this->actingAs($this->ownerB1, 'sanctum')->postJson("/api/custody/requests/{$a1->id}/approve");
        $reject = $this->actingAs($this->ownerB1, 'sanctum')->postJson("/api/custody/requests/{$a1->id}/reject", ['reason' => 'no reason given']);

        $this->assertSameAsUnknownId($approve, 'approve');
        $this->assertSameAsUnknownId($reject, 'reject');
        $this->assertSame($before, $this->snapshot(), 'an out-of-scope decision wrote to the database');
        $this->assertSame('Pending', $a1->fresh()->status);
    }

    public function test_an_owner_of_a_sibling_brand_in_the_same_company_cannot_decide(): void
    {
        $a2 = $this->request($this->bmA2);
        $before = $this->snapshot();

        $this->assertSameAsUnknownId($this->actingAs($this->ownerA1, 'sanctum')->postJson("/api/custody/requests/{$a2->id}/approve"), 'approve');
        $this->assertSame($before, $this->snapshot());
    }

    public function test_unlinked_owner_and_unassigned_brand_manager_decide_nothing(): void
    {
        $a1 = $this->request($this->bmA1);
        $before = $this->snapshot();

        foreach ([$this->ownerUnlinked, $this->managerUnassigned] as $actor) {
            $this->assertSameAsUnknownId($this->actingAs($actor, 'sanctum')->postJson("/api/custody/requests/{$a1->id}/approve"), 'approve');
            $this->assertSameAsUnknownId($this->actingAs($actor, 'sanctum')->postJson("/api/custody/requests/{$a1->id}/reject", ['reason' => 'no reason given']), 'reject');
        }
        $this->assertSame($before, $this->snapshot());
    }

    public function test_a_brand_manager_decides_only_inside_its_delegated_brands(): void
    {
        $a1 = $this->request($this->bmA1);
        $a2 = $this->request($this->bmA2);
        $b1 = $this->request($this->bmB1);

        $this->actingAs($this->managerA1, 'sanctum')->postJson("/api/custody/requests/{$a2->id}/approve")->assertStatus(400);
        $this->actingAs($this->managerA1, 'sanctum')->postJson("/api/custody/requests/{$b1->id}/reject", ['reason' => 'no reason given'])->assertStatus(400);
        $this->assertSame('Pending', $a2->fresh()->status);
        $this->assertSame('Pending', $b1->fresh()->status);

        $this->actingAs($this->managerA1, 'sanctum')->postJson("/api/custody/requests/{$a1->id}/approve")->assertOk();
        $this->assertSame('Approved', $a1->fresh()->status);
    }

    public function test_owners_decide_their_own_brands_requests_as_before(): void
    {
        $a1 = $this->request($this->bmA1);
        $a2 = $this->request($this->bmA2);
        $b1 = $this->request($this->bmB1);

        $this->actingAs($this->ownerA1, 'sanctum')->postJson("/api/custody/requests/{$a1->id}/approve")->assertOk()->assertJsonPath('data.status', 'approved');
        $this->actingAs($this->ownerA12, 'sanctum')->postJson("/api/custody/requests/{$a2->id}/reject", ['reason' => 'amount not justified'])->assertOk()->assertJsonPath('data.status', 'rejected');
        $this->actingAs($this->ownerB1, 'sanctum')->postJson("/api/custody/requests/{$b1->id}/approve")->assertOk();
    }

    public function test_the_branch_managers_own_flow_is_unchanged(): void
    {
        $this->actingAs($this->bmA1, 'sanctum')->postJson('/api/custody/request-cashin', [
            'requestedAmount' => 300, 'purpose' => 'branch float', 'preferredReceiptMethod' => 'cash_handover',
        ])->assertStatus(201);

        $own = $this->request($this->bmA1, ['created_by_brand_owner_id' => $this->ownerA1->id]);
        $this->actingAs($this->bmA1, 'sanctum')->postJson("/api/custody/requests/{$own->id}/confirm-receipt")->assertOk();
        $this->assertCount(2, $this->actingAs($this->bmA1, 'sanctum')->getJson('/api/custody/requests')->json('data.requests'));
    }

    // ─────────────────── owner payment form + recipient picker ───────────────────

    private function payment(object $owner, BranchManager $recipient, float $amount = 250): TestResponse
    {
        return $this->actingAs($owner, 'sanctum')->postJson('/api/brand-owner/payment-form', [
            'recipientEmployeeId' => $recipient->id, 'amount' => $amount, 'preferredReceiptMethod' => 'cash_handover',
            'handoverDate' => now()->toDateString(), 'note' => 'owner transfer',
        ]);
    }

    public function test_an_owner_cannot_address_a_transfer_to_a_manager_outside_their_brands_and_nothing_is_written(): void
    {
        $before = $this->snapshot();

        $this->payment($this->ownerA1, $this->bmB1)->assertStatus(422)->assertJsonValidationErrors('recipientEmployeeId');
        $this->payment($this->ownerA1, $this->bmA2)->assertStatus(422)->assertJsonValidationErrors('recipientEmployeeId');
        $this->payment($this->ownerUnlinked, $this->bmA1)->assertStatus(422)->assertJsonValidationErrors('recipientEmployeeId');

        $this->assertSame($before, $this->snapshot(), 'a refused cross-tenant transfer wrote to the database');
    }

    public function test_an_owner_transfers_to_a_manager_of_their_own_brands_as_before(): void
    {
        $this->payment($this->ownerA1, $this->bmA1)->assertStatus(201)->assertJsonPath('data.status', 'pending');
        $this->payment($this->ownerA12, $this->bmA2)->assertStatus(201);

        $this->assertSame(2, CustodyRequest::whereNotNull('created_by_brand_owner_id')->count());
    }

    public function test_the_recipient_picker_lists_only_managers_of_the_owners_brands(): void
    {
        $ids = fn (object $actor) => collect($this->actingAs($actor, 'sanctum')->getJson('/api/custody/recipients')->assertOk()->json('data.recipients'))
            ->pluck('id')->sort()->values()->all();

        $this->assertSame([$this->bmA1->id], $ids($this->ownerA1));
        $this->assertSame(collect([$this->bmA1->id, $this->bmA2->id])->sort()->values()->all(), $ids($this->ownerA12));
        $this->assertSame([$this->bmB1->id], $ids($this->ownerB1));
        $this->assertSame([], $ids($this->ownerUnlinked));
    }

    /**
     * G7-P2 / MEM-01: creator identity is historical attribution, not permanent
     * branch authority. Revoking a brand must stop reads and decisions immediately.
     */
    public function test_creator_owned_request_is_inaccessible_after_live_brand_revocation_on_every_owner_path(): void
    {
        $transfer = $this->payment($this->ownerA1, $this->bmA1)->assertStatus(201);
        $transferId = $transfer->json('data.requestId');
        $managerRequest = $this->request($this->bmA1);

        $this->actingAs($this->ownerA1, 'sanctum')
            ->getJson("/api/custody/requests/{$transferId}")->assertOk();
        $this->assertCount(1, $this->getJson('/api/brand-owner/payment-logs')->assertOk()->json('data.data'));
        $this->assertCount(1, $this->getJson('/api/custody/request-cashin/history')->assertOk()->json('data.previousRequests'));

        $dashboardId = AsabIdentityMap::where('entity_type', AsabIdentityMap::ENTITY_BRAND_OWNER)
            ->where('legacy_id', $this->ownerA1->id)->value('dashboard_id');
        $this->assertNotNull($dashboardId);
        AsabUserRole::where('user_id', $dashboardId)->where('role_key', 'brand-owner')->delete();

        $before = $this->snapshot();
        $this->assertSame([], $this->idsListedFor($this->ownerA1));
        $this->actingAs($this->ownerA1, 'sanctum')
            ->getJson("/api/custody/requests/{$transferId}")->assertNotFound();
        $this->getJson("/api/custody/requests/{$managerRequest->id}")->assertNotFound();
        $this->getJson("/api/brand-owner/payment-logs/{$transferId}")->assertNotFound();
        $this->assertSame([], $this->getJson('/api/brand-owner/payment-logs')->assertOk()->json('data.data'));
        $this->assertSame([], $this->getJson('/api/custody/request-cashin/history')->assertOk()->json('data.previousRequests'));

        $this->postJson("/api/custody/requests/{$managerRequest->id}/approve")->assertStatus(400);
        $this->postJson("/api/custody/requests/{$managerRequest->id}/reject", ['reason' => 'not authorized'])->assertStatus(400);
        $this->assertSame('Pending', $managerRequest->fresh()->status);
        $this->assertSame($before, $this->snapshot(), 'Revoked brand actor mutated custody financial/history tables');
    }

    public function test_creator_of_two_brands_keeps_only_current_brand_after_one_brand_is_removed(): void
    {
        $a1 = $this->payment($this->ownerA12, $this->bmA1)->assertStatus(201)->json('data.requestId');
        $a2 = $this->payment($this->ownerA12, $this->bmA2)->assertStatus(201)->json('data.requestId');
        $dashboardId = AsabIdentityMap::where('entity_type', AsabIdentityMap::ENTITY_BRAND_OWNER)
            ->where('legacy_id', $this->ownerA12->id)->value('dashboard_id');
        $this->assertNotNull($dashboardId);
        $role = AsabUserRole::where('user_id', $dashboardId)->where('role_key', 'brand-owner')->firstOrFail();
        $role->update(['brand_ids' => [$this->brandA2->id]]);

        $this->assertSame([$a2], $this->idsListedFor($this->ownerA12));
        $this->actingAs($this->ownerA12, 'sanctum')->getJson("/api/custody/requests/{$a1}")->assertNotFound();
        $this->getJson("/api/custody/requests/{$a2}")->assertOk();
        $this->getJson("/api/brand-owner/payment-logs/{$a1}")->assertNotFound();
        $this->getJson("/api/brand-owner/payment-logs/{$a2}")->assertOk();
        $this->assertSame([$a2], collect($this->getJson('/api/brand-owner/payment-logs')->assertOk()->json('data.data'))
            ->pluck('id')->all());
        $this->assertSame([$a2], collect($this->getJson('/api/custody/request-cashin/history')->assertOk()->json('data.previousRequests'))
            ->pluck('id')->all());
    }

    public function test_disabled_dashboard_owner_cannot_keep_mobile_brand_scope_through_old_token(): void
    {
        $transfer = $this->payment($this->ownerA1, $this->bmA1)->assertStatus(201)->json('data.requestId');
        $dashboardId = AsabIdentityMap::where('entity_type', AsabIdentityMap::ENTITY_BRAND_OWNER)
            ->where('legacy_id', $this->ownerA1->id)->value('dashboard_id');
        $this->assertNotNull($dashboardId);
        AsabUser::withoutGlobalScope('tenant')->findOrFail($dashboardId)->update(['status' => 'disabled']);

        $this->assertSame([], $this->idsListedFor($this->ownerA1));
        $this->actingAs($this->ownerA1, 'sanctum')->getJson("/api/custody/requests/{$transfer}")->assertNotFound();
        $this->assertSame([], $this->getJson('/api/brand-owner/payment-logs')->assertOk()->json('data.data'));
        $this->assertSame([], $this->getJson('/api/custody/request-cashin/history')->assertOk()->json('data.previousRequests'));
    }

    public function test_the_payment_log_stays_creator_only_and_unchanged(): void
    {
        $this->payment($this->ownerA1, $this->bmA1)->assertStatus(201);

        $this->assertCount(1, $this->actingAs($this->ownerA1, 'sanctum')->getJson('/api/brand-owner/payment-logs')->assertOk()->json('data.data'));
        $this->assertCount(0, $this->actingAs($this->ownerB1, 'sanctum')->getJson('/api/brand-owner/payment-logs')->assertOk()->json('data.data'));
    }
}
