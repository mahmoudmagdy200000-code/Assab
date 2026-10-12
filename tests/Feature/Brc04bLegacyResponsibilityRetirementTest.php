<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Employee;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Listeners\CreateCustodyLedgerEntriesForVariance;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Events\VarianceRecorded;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Modules\Shift\Models\ShiftLiabilityPosting;
use Modules\Shift\Models\ShiftVarianceDetail;
use Modules\Shift\Services\ShiftCashCountService;
use Modules\Shift\Services\ShiftReportRevisionService;
use Tests\TestCase;

/**
 * PKG-1 / BRC-04b — the legacy responsibility approval (cashier + manager) and the legacy ledger writer
 * (VarianceRecorded -> CreateCustodyLedgerEntriesForVariance) can no longer create, rewrite or delete financial
 * rows. Zero writes are proven by row counts (including soft-deleted rows), row ids, balances and history —
 * never by HTTP status alone. The S1-10 flow (allocate -> manager approve -> single posting; employee respond)
 * keeps working.
 */
class Brc04bLegacyResponsibilityRetirementTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branch;

    private BranchManager $manager;

    private Cashier $cashierA;

    private Cashier $cashierB;

    private Employee $employee;

    private CashierShift $shift;

    protected function setUp(): void
    {
        parent::setUp();
        $this->company = AsabCompany::create(['name' => 'BRC04b Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'Brand', 'sub_status' => 'active', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['asab_brand_id' => $brand->id, 'asab_company_id' => $this->company->id]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active', 'is_active' => true]);
        $this->cashierA = Cashier::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        $this->cashierB = Cashier::factory()->create(['branch_id' => $this->branch->id, 'status' => 'active']);
        $this->employee = Employee::create(['company_id' => $this->company->id, 'branch_id' => $this->branch->id,
            'emp_number' => '3001', 'name' => 'Employee', 'role' => 'كاشير', 'monthly_salary' => 500000, 'status' => 'active']);
        $template = Shift::create(['branch_id' => $this->branch->id, 'name' => 'Morning', 'start_time' => '08:00:00', 'end_time' => '16:00:00']);
        $this->shift = CashierShift::create(['cashier_id' => $this->cashierA->id, 'shift_id' => $template->id,
            'shift_date' => today(), 'status' => 'completed']);
    }

    /** counted = $countedHalalas against 10 000 gross: -10 000 = 100.00 SAR shortage, +2 000 = 20.00 SAR surplus. */
    private function recordCount(int $countedHalalas): void
    {
        DB::transaction(function () use ($countedHalalas) {
            $rev = app(ShiftReportRevisionService::class)->recordCashierRevision($this->shift, 'cashier', $this->cashierA->id, 0);
            app(ShiftCashCountService::class)->record($this->shift, $rev, 10000, 0, 0, $countedHalalas);
        });
    }

    private function legacyDetail(Cashier $c, string $type, float $amount, string $status = 'pending'): ShiftVarianceDetail
    {
        return ShiftVarianceDetail::create([
            'cashier_shift_id' => $this->shift->id, 'variance_amount' => $amount, 'variance_type' => $type,
            'responsibility_type' => 'self_and_others', 'responsible_cashier_id' => $c->id,
            'assigned_amount' => $amount, 'responsibility_status' => $status,
        ]);
    }

    /** Everything a financial/evidence write could touch, including soft-deleted rows and balances. */
    private function snapshot(): array
    {
        $cash = CashierCustodyTransaction::withTrashed()->orderBy('id')->get(['id', 'cashier_id', 'transaction_type', 'amount', 'is_cash_in', 'deleted_at']);
        $personal = PersonalLedgerTransaction::withTrashed()->orderBy('id')->get(['id', 'transaction_type', 'amount', 'is_cash_in', 'deleted_at']);
        $balance = fn (Cashier $c) => round(
            (float) CashierCustodyTransaction::where('cashier_id', $c->id)->where('is_cash_in', true)->sum('amount')
            - (float) CashierCustodyTransaction::where('cashier_id', $c->id)->where('is_cash_in', false)->sum('amount'), 2);

        return [
            'cashier_ledger' => $cash->toArray(),
            'personal_ledger' => $personal->toArray(),
            'balance_a' => $balance($this->cashierA),
            'balance_b' => $balance($this->cashierB),
            'details' => ShiftVarianceDetail::orderBy('id')->get(['id', 'responsibility_status', 'reviewed_by_id', 'reviewed_at', 'rejection_reason'])->toArray(),
            'review_evidence' => DB::table('shift_variance_review_evidence')->count(),
            'history' => DB::table('cashier_shift_history')->count(),
            'allocations' => DB::table('shift_liability_allocations')->count(),
            'postings' => DB::table('shift_liability_postings')->count(),
            'movements' => DB::table('asab_employee_movements')->count(),
        ];
    }

    private function idem(): array
    {
        return ['Idempotency-Key' => (string) Str::uuid()];
    }

    // ── legacy approve is retired ────────────────────────────────────────────

    public function test_cashier_legacy_approve_is_409_with_zero_writes_on_both_prefixes_and_when_repeated(): void
    {
        $this->recordCount(0);
        $this->legacyDetail($this->cashierA, 'short', 100);
        $before = $this->snapshot();

        foreach (['/api', '/api/v1'] as $prefix) {
            foreach ([1, 2] as $attempt) {
                $this->actingAs($this->cashierA, 'sanctum')
                    ->postJson("$prefix/cashier/my-shifts/{$this->shift->id}/responsibility/approve")
                    ->assertStatus(409)
                    ->assertJsonPath('success', false)
                    ->assertJsonPath('code', 'LEGACY_RESPONSIBILITY_APPROVAL_RETIRED');
            }
        }

        $this->assertSame($before, $this->snapshot());
        $this->assertSame('pending', ShiftVarianceDetail::sole()->responsibility_status);
        $this->assertSame(0.0, $before['balance_a']);
    }

    public function test_manager_legacy_approve_cannot_bypass_s1_10_or_create_a_posting(): void
    {
        $this->recordCount(0);
        $this->legacyDetail($this->cashierA, 'short', 100);
        $before = $this->snapshot();

        foreach (['/api', '/api/v1'] as $prefix) {
            foreach ([1, 2] as $attempt) {
                $this->actingAs($this->manager, 'sanctum')
                    ->postJson("$prefix/branch-manager/shifts/{$this->shift->id}/responsibility/approve")
                    ->assertStatus(409)
                    ->assertJsonPath('code', 'LEGACY_RESPONSIBILITY_APPROVAL_RETIRED');
            }
        }

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, ShiftLiabilityPosting::count());
        $this->assertSame(0, ShiftLiabilityAllocation::count());
    }

    public function test_legacy_reject_stays_available_and_never_writes_to_ledgers(): void
    {
        $this->recordCount(0);
        $this->legacyDetail($this->cashierA, 'short', 100);
        $before = $this->snapshot();

        $this->actingAs($this->cashierA, 'sanctum')
            ->postJson("/api/v1/cashier/my-shifts/{$this->shift->id}/responsibility/reject", ['reason' => 'Disputed'])
            ->assertOk();

        $after = $this->snapshot();
        $this->assertSame($before['cashier_ledger'], $after['cashier_ledger']);
        $this->assertSame($before['personal_ledger'], $after['personal_ledger']);
        $this->assertSame($before['balance_a'], $after['balance_a']);
        $this->assertSame('rejected', ShiftVarianceDetail::sole()->responsibility_status);
    }

    // ── legacy ledger writer cannot create / rewrite / delete ────────────────

    public function test_direct_legacy_event_dispatch_writes_nothing_and_preserves_existing_history(): void
    {
        $this->recordCount(0);
        // Rows recorded before this correction (historical evidence) — must survive untouched.
        $oldCash = CashierCustodyTransaction::create(['cashier_id' => $this->cashierA->id, 'transaction_type' => 'Variance',
            'amount' => 100, 'is_cash_in' => false, 'related_shift_id' => $this->shift->id, 'transaction_date' => now()]);
        $oldPersonal = PersonalLedgerTransaction::create(['branch_manager_id' => $this->manager->id, 'transaction_type' => 'Variance from Cashier',
            'amount' => 100, 'is_cash_in' => true, 'related_shift_id' => $this->shift->id, 'transaction_date' => now()]);
        $this->legacyDetail($this->cashierA, 'short', 100, 'approved');
        $this->legacyDetail($this->cashierB, 'short', 25, 'approved');
        $before = $this->snapshot();

        $event = fn () => new VarianceRecorded($this->shift->fresh(['varianceDetails', 'handover']));
        event($event());
        app(CreateCustodyLedgerEntriesForVariance::class)->handle($event());
        app(CreateCustodyLedgerEntriesForVariance::class)->handle($event());

        $this->assertSame($before, $this->snapshot());
        $this->assertNull($oldCash->fresh()->deleted_at);
        $this->assertNull($oldPersonal->fresh()->deleted_at);
        $this->assertSame($oldCash->id, CashierCustodyTransaction::where('related_shift_id', $this->shift->id)->sole()->id);
    }

    public function test_no_s1_10_allocation_means_no_financial_posting(): void
    {
        $this->recordCount(0);
        $this->legacyDetail($this->cashierA, 'short', 100, 'approved');
        $before = $this->snapshot();

        event(new VarianceRecorded($this->shift->fresh(['varianceDetails', 'handover'])));

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0, ShiftLiabilityAllocation::count());
        $this->assertSame(0, CashierCustodyTransaction::withTrashed()->count());
    }

    public function test_legacy_surplus_is_never_credited_to_a_cashier(): void
    {
        $this->recordCount(12000); // +20.00 SAR surplus
        $this->legacyDetail($this->cashierA, 'over', 20, 'approved');
        $before = $this->snapshot();

        event(new VarianceRecorded($this->shift->fresh(['varianceDetails', 'handover'])));
        $this->actingAs($this->cashierA, 'sanctum')
            ->postJson("/api/v1/cashier/my-shifts/{$this->shift->id}/responsibility/approve")->assertStatus(409);

        $this->assertSame($before, $this->snapshot());
        $this->assertSame(0.0, $this->snapshot()['balance_a']);
    }

    public function test_s1_10_has_no_shortage_liability_for_a_surplus(): void
    {
        $this->recordCount(12000);
        $before = $this->snapshot();

        $res = $this->actingAs($this->cashierA, 'sanctum')->withHeaders($this->idem())
            ->postJson("/api/v1/branch-manager/shifts/{$this->shift->id}/liability/allocate", [
                'shares' => [['type' => 'cashier', 'id' => $this->cashierA->id, 'amount' => 2000]],
                'expected_version' => 0, 'expected_revision' => 1, 'cashier_confirmed' => true,
            ]);

        $res->assertStatus(409)->assertJsonPath('code', 'NO_SHORTAGE_LIABILITY');
        $this->assertSame($before['cashier_ledger'], $this->snapshot()['cashier_ledger']);
        $this->assertSame(0.0, $this->snapshot()['balance_a']);
        $this->assertSame(0, ShiftLiabilityPosting::count());
    }

    // ── modern S1-10 path is unaffected ──────────────────────────────────────

    public function test_approved_s1_10_allocation_posts_exactly_once_and_legacy_paths_cannot_add_or_rewrite(): void
    {
        $this->recordCount(0); // 100.00 SAR shortage
        $this->actingAs($this->cashierA, 'sanctum')->withHeaders($this->idem())
            ->postJson("/api/v1/branch-manager/shifts/{$this->shift->id}/liability/allocate", [
                'shares' => [['type' => 'cashier', 'id' => $this->cashierA->id, 'amount' => 10000]],
                'expected_version' => 0, 'expected_revision' => 1, 'cashier_confirmed' => true,
            ])->assertCreated();
        $this->actingAs($this->manager, 'sanctum')->withHeaders($this->idem())
            ->postJson("/api/v1/branch-manager/shifts/{$this->shift->id}/liability/approve", [
                'version' => 1, 'expected_revision' => 1,
            ])->assertOk();

        $posted = $this->snapshot();
        $this->assertSame(-100.0, $posted['balance_a'], 'one 100.00 cash-out');
        $this->assertSame(1, CashierCustodyTransaction::where('transaction_type', 'Variance')->count());
        $this->assertSame(1, $posted['postings']);
        $ids = array_column($posted['cashier_ledger'], 'id');

        // Repeats: manager approve again, legacy approve (both actors), legacy event — nothing changes.
        $this->actingAs($this->manager, 'sanctum')->withHeaders($this->idem())
            ->postJson("/api/v1/branch-manager/shifts/{$this->shift->id}/liability/approve", ['version' => 1, 'expected_revision' => 1])
            ->assertStatus(409);
        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/branch-manager/shifts/{$this->shift->id}/responsibility/approve")->assertStatus(409);
        $this->legacyDetail($this->cashierA, 'short', 100, 'approved');
        $afterDetail = $this->snapshot();
        event(new VarianceRecorded($this->shift->fresh(['varianceDetails', 'handover'])));

        $final = $this->snapshot();
        $this->assertSame($afterDetail, $final);
        $this->assertSame($ids, array_column($final['cashier_ledger'], 'id'), 'financial history preserved, ids unchanged');
        $this->assertSame(1, CashierCustodyTransaction::where('transaction_type', 'Variance')->count());
    }

    /** DIFF-04: employee accept / objection is recorded by S1-10 respond, with no ledger effect. */
    public function test_liability_respond_still_records_acceptance_and_objection_without_financial_effect(): void
    {
        $this->recordCount(0);
        $user = AsabUser::create(['company_id' => $this->company->id, 'name' => 'Linked', 'email' => Str::uuid().'@example.test',
            'password' => 'secret', 'status' => 'active']);
        $this->employee->update(['asab_user_id' => $user->id]);
        $this->actingAs($this->manager, 'sanctum')->withHeaders($this->idem())
            ->postJson("/api/v1/branch-manager/shifts/{$this->shift->id}/liability/allocate", [
                'shares' => [
                    ['type' => 'employee', 'id' => $this->employee->id, 'amount' => 6000],
                    ['type' => 'cashier', 'id' => $this->cashierB->id, 'amount' => 4000],
                ],
                'expected_version' => 0, 'expected_revision' => 1, 'reason' => 'split',
            ])->assertCreated();
        $before = $this->snapshot();
        $uri = "/api/v1/shift-reports/{$this->shift->id}/liability/respond";

        $this->actingAs($user, 'sanctum')->withHeaders($this->idem())
            ->postJson($uri, ['version' => 1, 'response' => 'objected', 'reason' => 'I disagree'])->assertOk();
        $this->actingAs($this->cashierB, 'sanctum')->withHeaders($this->idem())
            ->postJson($uri, ['version' => 1, 'response' => 'accepted'])->assertOk();

        $this->assertDatabaseHas('shift_liability_shares', ['responsible_id' => $this->employee->id,
            'employee_response_status' => 'objected', 'employee_response_reason' => 'I disagree']);
        $this->assertDatabaseHas('shift_liability_shares', ['responsible_id' => $this->cashierB->id, 'employee_response_status' => 'accepted']);
        $after = $this->snapshot();
        $this->assertSame($before['cashier_ledger'], $after['cashier_ledger']);
        $this->assertSame($before['personal_ledger'], $after['personal_ledger']);
        $this->assertSame($before['postings'], $after['postings']);
        $this->assertSame(0, $after['postings']);
    }
}
