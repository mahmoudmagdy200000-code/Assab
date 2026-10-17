<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Custody\Models\CustodyHandoverRequest;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Tests\TestCase;

/**
 * PKG-1 / BRC-01 — the legacy cashier custody handover (POST custody/handover and
 * POST custody/handover-requests/{id}/accept) is retired with 409 and zero writes.
 * Manager/owner paths and reject stay reachable.
 */
class LegacyCustodyHandoverRetirementTest extends TestCase
{
    use RefreshDatabase;

    private const TABLES = [
        'cashier_custody_transactions',
        'custody_handover_requests',
        'personal_ledger_transactions',
        'custody_transactions',
        'cash_sales_transfer_requests',
    ];

    /** Both registered aliases of every custody route (verified with `artisan route:list`). */
    private const PREFIXES = ['/api', '/api/v1'];

    private Branch $branch;

    private BranchManager $manager;

    private Cashier $cashier;

    private Cashier $peer;

    private BrandOwner $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::factory()->create();
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);
        $this->owner = BrandOwner::create([
            'name' => 'Owner One', 'email' => 'owner@example.com', 'phone' => '0500000000',
            'password' => 'secret-password', 'is_active' => true, 'is_first_login' => false, 'status' => 'active',
        ]);
        $this->peer = Cashier::factory()->create(['branch_id' => $this->branch->id, 'created_by' => $this->manager->id]);

        // Sufficient balance, so any rejection below cannot be an insufficient-funds failure.
        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier->id,
            'transaction_type' => 'Cash In',
            'amount' => 500,
            'is_cash_in' => true,
            'transaction_date' => now(),
        ]);
    }

    private function counts(): array
    {
        $out = [];
        foreach (self::TABLES as $t) {
            $out[$t] = DB::table($t)->count();
        }

        return $out;
    }

    public function test_cashier_handover_is_retired_for_every_recipient_type_and_alias_with_zero_writes(): void
    {
        $before = $this->counts();

        foreach (self::PREFIXES as $prefix) {
            foreach ([
                ['recipientId' => $this->peer->id, 'recipientType' => 'Cashier'],
                ['recipientId' => $this->manager->id, 'recipientType' => 'Branch Manager',
                    'handoverMethod' => 'cash_handover', 'handoverDate' => today()->toDateString()],
                ['recipientId' => $this->owner->id, 'recipientType' => 'Brand Owner',
                    'handoverMethod' => 'cash_handover', 'handoverDate' => today()->toDateString()],
                ['recipientId' => $this->manager->id, 'recipientType' => 'Custody'],
            ] as $payload) {
                $this->actingAs($this->cashier, 'sanctum')
                    ->postJson("$prefix/custody/handover", $payload + ['handoverAmount' => 100])
                    ->assertStatus(409)
                    ->assertJsonPath('success', false)
                    ->assertJsonPath('errors.code', 'LEGACY_CUSTODY_HANDOVER_RETIRED');
            }

            // Retirement precedes validation: an invalid body is still a 409.
            $this->actingAs($this->cashier, 'sanctum')->postJson("$prefix/custody/handover", [])->assertStatus(409);
        }

        $this->assertSame($before, $this->counts());
        $this->assertSame(500.0, (float) CashierCustodyTransaction::where('cashier_id', $this->cashier->id)->sum('amount'));
    }

    public function test_cashier_accept_is_retired_on_every_alias_and_pending_request_is_untouched(): void
    {
        $req = CustodyHandoverRequest::create([
            'from_cashier_id' => $this->peer->id,
            'to_cashier_id' => $this->cashier->id,
            'amount' => 50,
            'status' => 'pending',
        ]);
        $before = $this->counts();

        foreach (self::PREFIXES as $prefix) {
            $this->actingAs($this->cashier, 'sanctum')
                ->postJson("$prefix/custody/handover-requests/{$req->id}/accept")
                ->assertStatus(409)
                ->assertJsonPath('errors.code', 'LEGACY_CUSTODY_HANDOVER_RETIRED');
        }

        $this->assertSame('pending', $req->fresh()->status);
        $this->assertNull($req->fresh()->responded_at);
        $this->assertSame($before, $this->counts());
    }

    public function test_non_cashier_cannot_accept_and_gets_403_not_409(): void
    {
        $req = CustodyHandoverRequest::create([
            'from_cashier_id' => $this->peer->id, 'to_cashier_id' => $this->cashier->id,
            'amount' => 50, 'status' => 'pending',
        ]);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/custody/handover-requests/{$req->id}/accept")
            ->assertStatus(403);
    }

    public function test_reject_path_is_not_disabled(): void
    {
        $req = CustodyHandoverRequest::create([
            'from_cashier_id' => $this->peer->id, 'to_cashier_id' => $this->cashier->id,
            'amount' => 50, 'status' => 'pending',
        ]);

        $res = $this->actingAs($this->cashier, 'sanctum')
            ->postJson("/api/custody/handover-requests/{$req->id}/reject");

        $this->assertNotSame(409, $res->status());
        $this->assertSame('rejected', $req->fresh()->status);
    }

    private function seedManagerBalance(float $amount): void
    {
        PersonalLedgerTransaction::create([
            'branch_manager_id' => $this->manager->id,
            'transaction_type' => 'Seed',
            'amount' => $amount,
            'is_cash_in' => true,
            'transaction_date' => now(),
        ]);
    }

    public function test_manager_handover_paths_still_work_on_every_alias(): void
    {
        $this->seedManagerBalance(1000);

        foreach (self::PREFIXES as $prefix) {
            $cashierRecipient = $this->actingAs($this->manager, 'sanctum')->postJson("$prefix/custody/handover", [
                'recipientId' => $this->peer->id, 'recipientType' => 'Cashier', 'handoverAmount' => 10,
            ]);
            $this->assertNotSame(409, $cashierRecipient->status());
            $this->assertNotSame('LEGACY_CUSTODY_HANDOVER_RETIRED', $cashierRecipient->json('errors.code'));

            $before = PersonalLedgerTransaction::count();
            $toOwner = $this->actingAs($this->manager, 'sanctum')->postJson("$prefix/custody/handover", [
                'recipientId' => $this->owner->id, 'recipientType' => 'Brand Owner', 'handoverAmount' => 10,
                'handoverMethod' => 'cash_handover', 'handoverDate' => today()->toDateString(),
            ]);
            $toOwner->assertOk();
            $this->assertSame($before + 1, PersonalLedgerTransaction::count());
        }
    }

    /**
     * NG-09 — custody/transfer is a branch-manager operation. The cashier below holds a SUFFICIENT
     * cashier-custody balance, so refusal cannot be explained by insufficient funds: it must be the role.
     */
    public function test_ng09_non_branch_manager_actors_get_403_before_validation_with_zero_writes(): void
    {
        $before = $this->counts();

        foreach (self::PREFIXES as $prefix) {
            foreach ([$this->cashier, $this->owner] as $actor) {
                foreach ([['transferAmount' => 100], ['isFullTransfer' => true], []] as $body) {
                    $res = $this->actingAs($actor, 'sanctum')->postJson("$prefix/custody/transfer", $body);
                    $res->assertStatus(403)->assertJsonPath('success', false);
                    $this->assertStringNotContainsString('SQLSTATE', $res->getContent());
                    $this->assertStringNotContainsString('INVALID_AMOUNT', $res->getContent());
                }
            }
        }

        $this->assertSame($before, $this->counts());
        $this->assertSame(500.0, (float) CashierCustodyTransaction::where('cashier_id', $this->cashier->id)->sum('amount'));
    }

    public function test_ng09_unauthenticated_transfer_is_401(): void
    {
        $this->postJson('/api/custody/transfer', ['transferAmount' => 10])->assertStatus(401);
    }

    /**
     * The manager transfer stays reachable on both aliases with its documented error contract
     * (400 + errors.code INVALID_AMOUNT + errors.maxAmount). BRC v3.4 F-04 (FUND-01/02) changed WHICH cash it
     * releases: an arbitrary ledger balance that is not fully-approved sales cash (here a bare seed row with no
     * approved source day) is no longer transferable. The approved/available/reserved/idempotent paths are
     * pinned in CustodyTransferFundingGateTest.
     */
    public function test_ng09_branch_manager_transfer_regression(): void
    {
        $this->seedManagerBalance(300);
        $before = $this->counts();

        foreach (self::PREFIXES as $prefix) {
            $this->actingAs($this->manager, 'sanctum')
                ->postJson("$prefix/custody/transfer", ['transferAmount' => 100])
                ->assertStatus(400)
                ->assertJsonPath('success', false)
                ->assertJsonPath('errors.code', 'INVALID_AMOUNT');
        }
        $this->actingAs($this->manager, 'sanctum')->postJson('/api/custody/transfer', ['isFullTransfer' => true])
            ->assertStatus(400)->assertJsonPath('errors.code', 'INVALID_AMOUNT');

        $this->assertSame($before, $this->counts(), 'a refused transfer writes nothing');
        $this->assertSame(300.0, $this->ledgerBalance());
    }

    private function ledgerBalance(): float
    {
        return (float) PersonalLedgerTransaction::where('branch_manager_id', $this->manager->id)->where('is_cash_in', true)->sum('amount')
            - (float) PersonalLedgerTransaction::where('branch_manager_id', $this->manager->id)->where('is_cash_in', false)->sum('amount');
    }
}
