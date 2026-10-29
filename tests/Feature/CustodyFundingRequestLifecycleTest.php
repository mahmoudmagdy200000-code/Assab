<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\CompanyUser;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BrandOwner\Models\BrandManager;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CustodyRequest;
use Modules\Custody\Models\CustodyRequestTimeline;
use Modules\Custody\Models\CustodyTransaction;
use Tests\Concerns\BuildsExpenseTenantFixtures;
use Tests\Concerns\LinksMobileBrandScope;
use Tests\TestCase;

/**
 * BRC v3.4 F-05 — the branch-custody funding request (`custody_requests`): FUND-04, FUND-05, FUND-06.
 *
 *  FUND-04  the brand owner or the brand manager decides (approves / rejects); neither the branch manager's
 *           transfer permission nor the accountant/head day-approval cycle makes anyone the decider. (AC-58)
 *  FUND-05  a rejected request is resubmitted AS THE SAME REQUEST with its history kept; "Approve Anyways"
 *           on a rejected request does not exist. (AC-57)
 *  FUND-06  approval alone never raises the custody balance; the increase is posted once, when the manager
 *           confirms receipt. (AC-59)
 *
 * ASSUMPTION (flagged, not tested as policy): the repository's `custody_requests` flow IS the v3.4 "funding request".
 * Out of scope here: tenant scoping of owner decisions/lists (see the F-09 note) and the `reuseRequestId` shortcut,
 * which creates a NEW request and is therefore not the FUND-05 resubmission.
 */
class CustodyFundingRequestLifecycleTest extends TestCase
{
    use BuildsExpenseTenantFixtures;
    use LinksMobileBrandScope;
    use RefreshDatabase;

    private Branch $branch;

    private BranchManager $manager;

    private BrandOwner $owner;

    private ?AsabUser $accountantActor = null;

    protected function setUp(): void
    {
        parent::setUp();

        // SQLITE_FK_DIVERGENCE: migration 2026_05_17_000002 drops the branch_managers foreign keys on
        // custody_requests.approved_by / rejected_by on every driver EXCEPT sqlite (it cannot drop a column FK), because
        // owners and brand managers — not branch managers — decide these requests. Production (MySQL) therefore has no
        // such FK; the SQLite test schema still has it. Without this no owner decision could be exercised here.
        // (`foreign_keys = OFF` is a no-op inside RefreshDatabase's transaction; deferring the check until a commit that
        // never happens — the test transaction is rolled back — is the equivalent that works.)
        DB::statement('PRAGMA defer_foreign_keys = ON');

        $this->branch = Branch::factory()->create();
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
        $this->owner = BrandOwner::create([
            'name' => 'Owner', 'email' => 'owner@fund-req.test', 'phone' => '0500000010',
            'password' => 'secret-password', 'is_active' => true, 'is_first_login' => false, 'status' => 'active',
        ]);
        // F-09: brand-level actors reach only the branches of their own brands (server-resolved, fail-closed) —
        // an owner with no brand link decides nothing, so the fixture links the owner to this branch's brand.
        $this->brandOfBranch = $this->linkBrandOwner($this->owner, $this->branch);
    }

    private \Modules\Admin\Models\AsabBrand $brandOfBranch;

    private ?BrandManager $brandManagerActor = null;

    private function brandManager(): BrandManager
    {
        if ($this->brandManagerActor === null) {
            $this->brandManagerActor = BrandManager::create([
                'name' => 'Brand Manager', 'email' => 'bm@fund-req.test', 'phone' => '0500000011',
                'password' => 'secret-password', 'is_active' => true, 'is_first_login' => false, 'status' => 'active',
            ]);
            // F-09 / D-2: an explicit delegation to this branch's brand.
            $this->assignBrandManager($this->brandManagerActor, $this->brandOfBranch->company, [$this->brandOfBranch->id]);
        }

        return $this->brandManagerActor;
    }

    /** A manager-raised funding request, created through the real endpoint. */
    private function raise(float $amount = 800): string
    {
        $res = $this->actingAs($this->manager, 'sanctum')->postJson('/api/custody/request-cashin', [
            'requestedAmount' => $amount, 'purpose' => 'branch float', 'preferredReceiptMethod' => 'cash_handover',
        ])->assertStatus(201);

        return $res->json('data.requestId');
    }

    private function approve(string $id, $as = null)
    {
        return $this->actingAs($as ?? $this->owner, 'sanctum')->postJson("/api/custody/requests/$id/approve");
    }

    private function reject(string $id, string $reason = 'amount not justified', $as = null)
    {
        return $this->actingAs($as ?? $this->owner, 'sanctum')->postJson("/api/custody/requests/$id/reject", ['reason' => $reason]);
    }

    private function receive(string $id, $as = null)
    {
        return $this->actingAs($as ?? $this->manager, 'sanctum')->postJson("/api/custody/requests/$id/confirm-receipt");
    }

    private function resubmit(string $id, $as = null)
    {
        return $this->actingAs($as ?? $this->manager, 'sanctum')->postJson("/api/custody/requests/$id/resubmit");
    }

    private function accountant(): AsabUser
    {
        if ($this->accountantActor === null) {
            $this->accountantActor = AsabUser::create([
                'company_id' => $this->branch->asab_company_id,
                'name' => 'Custody Accountant',
                'email' => 'custody-accountant@fund-req.test',
                'password' => 'secret-password',
                'status' => 'active',
            ]);
            CompanyUser::create([
                'company_id' => $this->branch->asab_company_id,
                'user_id' => $this->accountantActor->id,
                'role_key' => 'accountant',
                'status' => 'active',
            ]);
            AsabUserRole::create([
                'user_id' => $this->accountantActor->id,
                'role_key' => 'accountant',
                'scope' => 'branch',
                'branch_ids' => [$this->branch->id],
            ]);
        }

        return $this->accountantActor;
    }

    private function execute(string $id)
    {
        return $this->executeAs($id, $this->accountant());
    }

    private function executeAs(string $id, AsabUser $actor)
    {
        return $this->actingAs($actor, 'sanctum')->post('/api/custody/requests/'.$id.'/execute', [
            'transferReference' => 'TRX-'.substr($id, 0, 8),
            'proof' => UploadedFile::fake()->create('transfer-proof.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json']);
    }

    private function custodyBalance(): float
    {
        return (float) $this->actingAs($this->manager, 'sanctum')->getJson('/api/branch-manager/ledger/branch-custody-balance')
            ->assertSuccessful()->json('data.currentBalance');
    }

    private function postedFor(string $id): int
    {
        return CustodyTransaction::where('related_custody_request_id', $id)->count();
    }

    // ── FUND-06 / AC-59 ─────────────────────────────────────────────────────

    public function test_ac59_approval_alone_never_raises_the_custody_balance(): void
    {
        $id = $this->raise(800);

        $this->approve($id)->assertOk()->assertJsonPath('data.status', 'approved');

        $this->assertSame(0, $this->postedFor($id), 'approval posts no cash-in');
        $this->assertSame(0.0, $this->custodyBalance());
    }

    public function test_owner_created_request_is_mapped_to_an_explicit_in_scope_branch_and_manager(): void
    {
        $response = $this->actingAs($this->owner, 'sanctum')->postJson('/api/custody/request-cashin', [
            'branchId' => $this->branch->id,
            'requestedAmount' => 300,
            'purpose' => 'branch replenishment',
            'preferredReceiptMethod' => 'bank_transfer',
        ])->assertStatus(201);

        $this->assertDatabaseHas('custody_requests', [
            'id' => $response->json('data.requestId'),
            'branch_id' => $this->branch->id,
            'branch_manager_id' => $this->manager->id,
            'created_by_brand_owner_id' => $this->owner->id,
        ]);
    }

    public function test_owner_request_without_an_explicit_branch_is_rejected_and_not_guessed(): void
    {
        $this->actingAs($this->owner, 'sanctum')->postJson('/api/custody/request-cashin', [
            'requestedAmount' => 300,
            'purpose' => 'branch replenishment',
            'preferredReceiptMethod' => 'bank_transfer',
        ])->assertStatus(422)->assertJsonValidationErrors('branchId');

        $this->assertSame(0, CustodyRequest::where('created_by_brand_owner_id', $this->owner->id)->count());
    }

    public function test_ac59_the_increase_is_posted_once_at_receipt_confirmation_and_never_again(): void
    {
        $id = $this->raise(800);
        $this->approve($id)->assertOk();

        $this->receive($id)->assertStatus(422);
        $this->assertSame(0, $this->postedFor($id));
        $this->execute($id)->assertOk()->assertJsonPath('data.financiallyPosted', false);
        $this->assertDatabaseHas('custody_requests', [
            'id' => $id,
            'executed_by_user_id' => $this->accountant()->id,
            'transfer_reference' => 'TRX-'.substr($id, 0, 8),
        ]);
        $this->assertNotNull(CustodyRequest::findOrFail($id)->execution_proof_sha256);
        $this->assertSame(0, $this->postedFor($id), 'accountant send must not post cash');
        $this->execute($id)->assertStatus(422);

        $this->receive($id)->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertSame(1, $this->postedFor($id));
        $this->assertSame(800.0, $this->custodyBalance());

        // A retry, a double tap or a second device must not credit again.
        $this->receive($id)->assertStatus(422);
        $this->assertSame(1, $this->postedFor($id));
        $this->assertSame(800.0, $this->custodyBalance());
    }

    public function test_accountant_execution_is_denied_for_company_admin_and_foreign_branch_scope(): void
    {
        $id = $this->raise(250);
        $this->approve($id)->assertOk();

        $companyAdmin = AsabUser::create([
            'company_id' => $this->branch->asab_company_id,
            'name' => 'Company Admin', 'email' => 'custody-admin@fund-req.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        CompanyUser::create([
            'company_id' => $this->branch->asab_company_id,
            'user_id' => $companyAdmin->id, 'role_key' => 'company-admin', 'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $companyAdmin->id, 'role_key' => 'accountant', 'scope' => 'branch',
            'branch_ids' => [$this->branch->id],
        ]);
        $this->executeAs($id, $companyAdmin)->assertNotFound();

        $otherBrand = AsabBrand::create([
            'company_id' => $this->branch->asab_company_id,
            'name' => 'Other Brand', 'abbr' => 'OB', 'status' => 'active',
        ]);
        $otherBrandBranch = Branch::factory()->create([
            'asab_company_id' => $this->branch->asab_company_id,
            'asab_brand_id' => $otherBrand->id,
        ]);
        $otherBrandAccountant = AsabUser::create([
            'company_id' => $this->branch->asab_company_id,
            'name' => 'Other Brand Accountant', 'email' => 'other-brand-accountant@fund-req.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        CompanyUser::create([
            'company_id' => $this->branch->asab_company_id,
            'user_id' => $otherBrandAccountant->id, 'role_key' => 'accountant', 'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $otherBrandAccountant->id, 'role_key' => 'accountant', 'scope' => 'brand',
            'brand_ids' => [$otherBrand->id], 'branch_ids' => [$otherBrandBranch->id],
        ]);
        $this->executeAs($id, $otherBrandAccountant)->assertNotFound();

        $foreignCompany = AsabCompany::create(['name' => 'Foreign Co', 'plan' => 'Professional', 'status' => 'active']);
        $foreignBrand = AsabBrand::create(['company_id' => $foreignCompany->id, 'name' => 'Foreign Brand', 'abbr' => 'FB', 'status' => 'active']);
        $foreignBranch = Branch::factory()->create([
            'asab_company_id' => $foreignCompany->id,
            'asab_brand_id' => $foreignBrand->id,
        ]);
        $foreignAccountant = AsabUser::create([
            'company_id' => $foreignCompany->id,
            'name' => 'Foreign Accountant', 'email' => 'foreign-accountant@fund-req.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        CompanyUser::create([
            'company_id' => $foreignCompany->id,
            'user_id' => $foreignAccountant->id, 'role_key' => 'accountant', 'status' => 'active',
        ]);
        AsabUserRole::create([
            'user_id' => $foreignAccountant->id, 'role_key' => 'accountant', 'scope' => 'branch',
            'branch_ids' => [$foreignBranch->id],
        ]);
        $this->executeAs($id, $foreignAccountant)->assertNotFound();

        $this->assertNull(CustodyRequest::findOrFail($id)->executed_at);
        $this->assertSame(0, $this->postedFor($id));
    }

    public function test_failed_execution_save_rolls_back_evidence_and_removes_the_stored_proof(): void
    {
        Storage::fake('local');
        $id = $this->raise(250);
        $this->approve($id)->assertOk();
        DB::unprepared("CREATE TRIGGER fail_custody_execution BEFORE UPDATE ON custody_requests BEGIN SELECT RAISE(ABORT, 'forced execution persistence failure'); END");

        $this->execute($id)->assertStatus(500);

        $this->assertNull(CustodyRequest::findOrFail($id)->executed_at);
        $this->assertNull(CustodyRequest::findOrFail($id)->execution_proof_path);
        $this->assertSame([], Storage::disk('local')->allFiles('custody-requests/'.$id));
        $this->assertSame(0, $this->postedFor($id));
    }

    public function test_ac59_a_request_that_was_never_approved_or_was_rejected_cannot_be_received(): void
    {
        $pending = $this->raise(300);
        $this->receive($pending)->assertStatus(422);

        $rejected = $this->raise(400);
        $this->reject($rejected)->assertOk();
        $this->receive($rejected)->assertStatus(422);

        $this->assertSame(0, CustodyTransaction::count());
        $this->assertSame(0.0, $this->custodyBalance());
    }

    // ── FUND-04 / AC-58 ─────────────────────────────────────────────────────

    public function test_ac58_the_brand_owner_decides_and_the_brand_manager_decides(): void
    {
        $a = $this->raise(100);
        $b = $this->raise(200);
        $c = $this->raise(300);
        $d = $this->raise(400);

        $this->approve($a)->assertOk();
        $this->reject($b)->assertOk();
        $this->approve($c, $this->brandManager())->assertOk();
        $this->reject($d, 'not needed', $this->brandManager())->assertOk();

        $this->assertSame('Approved', CustodyRequest::find($a)->status);
        $this->assertSame('Rejected', CustodyRequest::find($b)->status);
        $this->assertSame('Approved', CustodyRequest::find($c)->status);
        $this->assertSame('Rejected', CustodyRequest::find($d)->status);
    }

    public function test_ac58_nobody_else_decides_not_the_branch_manager_not_a_cashier_not_the_day_approval_cycle(): void
    {
        $id = $this->raise(500);
        $cashier = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);
        $company = AsabCompany::create(['name' => 'Fund Co', 'plan' => 'Professional', 'status' => 'active']);
        $accountant = AsabUser::create(['company_id' => $company->id, 'name' => 'Acc', 'email' => 'acc@fund-req.test', 'password' => 'secret-password', 'status' => 'active']);
        AsabUserRole::create(['user_id' => $accountant->id, 'role_key' => 'accountant', 'scope' => 'all']);
        $head = AsabUser::create(['company_id' => $company->id, 'name' => 'Head', 'email' => 'head@fund-req.test', 'password' => 'secret-password', 'status' => 'active']);
        AsabUserRole::create(['user_id' => $head->id, 'role_key' => 'head', 'scope' => 'all']);

        foreach ([$this->manager, $cashier, $accountant, $head] as $actor) {
            $this->approve($id, $actor)->assertStatus(403);
            $this->reject($id, 'no authority', $actor)->assertStatus(403);
        }

        $this->assertSame('Pending', CustodyRequest::find($id)->status);
        $this->assertSame(0, CustodyRequestTimeline::where('custody_request_id', $id)->whereIn('stage', ['Approve Case', 'Reject Case'])->count());
    }

    // ── FUND-05 / AC-57 ─────────────────────────────────────────────────────

    public function test_ac57_rejection_keeps_the_record_and_approve_anyways_does_not_exist(): void
    {
        $id = $this->raise(900);
        $this->reject($id, 'missing quotation')->assertOk();

        // The record, its reason and the rejection event are kept.
        $row = CustodyRequest::find($id);
        $this->assertSame('Rejected', $row->status);
        $this->assertSame('missing quotation', $row->rejection_reason);
        $this->assertSame(1, CustodyRequestTimeline::where('custody_request_id', $id)->where('stage', 'Reject Case')->count());

        // "Approve Anyways": a rejected request cannot be approved or received directly.
        $this->approve($id)->assertStatus(400);
        $this->approve($id, $this->brandManager())->assertStatus(400);
        $this->receive($id)->assertStatus(422);

        $this->assertSame('Rejected', CustodyRequest::find($id)->status);
        $this->assertSame(0, $this->postedFor($id));
    }

    public function test_ac57_a_rejected_request_is_resubmitted_as_the_same_request_with_its_history_kept(): void
    {
        $id = $this->raise(900);
        $this->reject($id, 'missing quotation')->assertOk();

        $this->resubmit($id)->assertOk()->assertJsonPath('data.requestId', $id)->assertJsonPath('data.status', 'pending');

        $this->assertSame(1, CustodyRequest::count(), 'the SAME request, not a new one');
        $this->assertSame('Pending', CustodyRequest::find($id)->status);
        $this->assertSame(0, $this->postedFor($id), 'resubmission moves no money');

        // Submit -> Reject -> Submit, in order, and the first rejection's reason + decider survive the resubmission.
        $stages = CustodyRequestTimeline::where('custody_request_id', $id)->orderBy('action_date')->orderBy('created_at')->get();
        $this->assertSame(['Submit Case', 'Reject Case', 'Submit Case'], $stages->pluck('stage')->all());
        $this->assertStringContainsString('missing quotation', (string) $stages[1]->notes);
        $this->assertStringContainsString('missing quotation', (string) $stages[2]->notes);
        $this->assertStringContainsString($this->owner->id, (string) $stages[2]->notes);

        // The new review cycle completes normally and funds the branch exactly once.
        $this->approve($id)->assertOk();
        $this->assertSame(0.0, $this->custodyBalance());
        $this->execute($id)->assertOk();
        $this->receive($id)->assertOk();
        $this->assertSame(900.0, $this->custodyBalance());
        $this->assertSame(1, $this->postedFor($id));
    }

    public function test_ac57_a_second_rejection_keeps_both_reasons_and_resubmission_can_repeat(): void
    {
        $id = $this->raise(250);
        $this->reject($id, 'first reason')->assertOk();
        $this->resubmit($id)->assertOk();
        $this->reject($id, 'second reason')->assertOk();
        $this->resubmit($id)->assertOk();

        $notes = CustodyRequestTimeline::where('custody_request_id', $id)->pluck('notes')->filter()->implode(' | ');
        $this->assertStringContainsString('first reason', $notes);
        $this->assertStringContainsString('second reason', $notes);
        $this->assertSame(5, CustodyRequestTimeline::where('custody_request_id', $id)->count());
        $this->assertSame('Pending', CustodyRequest::find($id)->status);
    }

    public function test_ac57_resubmission_is_only_for_a_rejected_request_by_its_own_manager_and_is_not_repeatable(): void
    {
        $id = $this->raise(700);

        // Pending / approved / completed requests are not resubmittable.
        $this->resubmit($id)->assertStatus(400);
        $this->approve($id)->assertOk();
        $this->resubmit($id)->assertStatus(400);
        $this->execute($id)->assertOk();
        $this->receive($id)->assertOk();
        $this->resubmit($id)->assertStatus(400);

        $rejected = $this->raise(710);
        $this->reject($rejected)->assertOk();

        // Another branch's manager, the owner and a cashier cannot resubmit it.
        $stranger = BranchManager::factory()->create(['branch_id' => Branch::factory()->create()->id]);
        $cashier = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);
        $this->resubmit($rejected, $stranger)->assertStatus(403);
        $this->resubmit($rejected, $this->owner)->assertStatus(403);
        $this->resubmit($rejected, $cashier)->assertStatus(403);
        $this->assertSame('Rejected', CustodyRequest::find($rejected)->status);

        // A repeated / retried resubmission is refused and adds no second event.
        $this->resubmit($rejected)->assertOk();
        $before = CustodyRequestTimeline::where('custody_request_id', $rejected)->count();
        $this->resubmit($rejected)->assertStatus(400);
        $this->assertSame($before, CustodyRequestTimeline::where('custody_request_id', $rejected)->count());
    }

    public function test_ac57_an_owner_created_transfer_is_not_a_manager_request_and_cannot_be_resubmitted_by_the_manager(): void
    {
        $transfer = CustodyRequest::create([
            'branch_manager_id' => $this->manager->id, 'branch_id' => $this->branch->id,
            'created_by_brand_owner_id' => $this->owner->id, 'requested_amount' => 1000, 'purpose' => 'op',
            'preferred_receipt_method' => 'Cash Handover', 'status' => 'Rejected',
        ]);

        $this->resubmit($transfer->id)->assertStatus(400);
        $this->assertSame('Rejected', $transfer->fresh()->status);
    }

    // ── tenant isolation on the request resource (read side) ───────────────

    public function test_a_manager_of_another_branch_cannot_read_this_branchs_funding_request(): void
    {
        $id = $this->raise(640);
        $stranger = BranchManager::factory()->create(['branch_id' => Branch::factory()->create()->id]);
        $cashier = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);

        // 404, not 403: the existence of another branch's request is not disclosed.
        $this->actingAs($stranger, 'sanctum')->getJson("/api/custody/requests/$id")->assertStatus(404);
        $this->actingAs($cashier, 'sanctum')->getJson("/api/custody/requests/$id")->assertStatus(404);

        // The raising manager and the brand actors who decide it keep their access.
        $this->actingAs($this->manager, 'sanctum')->getJson("/api/custody/requests/$id")
            ->assertOk()->assertJsonPath('data.details.requestedAmount', 640);
        $this->actingAs($this->owner, 'sanctum')->getJson("/api/custody/requests/$id")->assertOk();
        $this->actingAs($this->brandManager(), 'sanctum')->getJson("/api/custody/requests/$id")->assertOk();
    }
}
