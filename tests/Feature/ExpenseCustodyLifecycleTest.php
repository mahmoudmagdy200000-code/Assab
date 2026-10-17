<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Modules\Admin\Models\AsabSupplier;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Custody\Models\CustodyTransaction;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Custody\Services\CustodyBalanceService;
use Modules\Expense\Events\ExpenseRejectedEvent;
use Modules\Expense\Events\ExpenseSubmittedEvent;
use Modules\Expense\Models\Category;
use Modules\Expense\Models\Expense;
use Modules\Expense\Models\ExpenseTimeline;
use Modules\Expense\Models\Supplier;
use Tests\Concerns\BuildsExpenseTenantFixtures;
use Tests\TestCase;

/**
 * BRC v3.4 F-01 — EXP-01…EXP-08 / CASH-17 / AC-27…AC-31 / AC-39.
 *
 * An expense paid from custody is
 *   - deducted ONCE, when it is SUBMITTED for review (EXP-02), from the BRANCH expense
 *     custody only — never from the manager's sales-cash (personal) ledger (EXP-01, CASH-17);
 *   - blocked at submission when the custody does not cover it (EXP-05), however much sales
 *     cash the manager holds;
 *   - confirmed (not deducted again) by approval (EXP-02);
 *   - reversed exactly once by a rejection, the reversal movement and the rejected expense being
 *     kept (EXP-03/04);
 *   - re-deducted once, at the corrected amount, when the rejected request is resubmitted
 *     (EXP-08).
 *
 * Everything runs through the real routes/middleware as the authenticated actor. Money columns
 * of `custody_transactions` are decimal SAR (2 dp); balances are compared with assertEqualsWithDelta
 * on the service result, rows with exact decimal strings.
 */
class ExpenseCustodyLifecycleTest extends TestCase
{
    use BuildsExpenseTenantFixtures;
    use RefreshDatabase;

    private const REASON = 'الفاتورة غير واضحة ويجب إعادة التصوير';

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildExpenseTenants();
    }

    // ── helpers ──────────────────────────────────────────────────────────────

    private function actor(object $actor): static
    {
        return $this->actingAs($actor, 'sanctum');
    }

    /** A recorded top-up of the branch expense custody (the funding that precedes any submission). */
    private function fund(BranchManager $bm, float $amount): CustodyTransaction
    {
        return CustodyTransaction::create([
            'branch_manager_id' => $bm->id, 'branch_id' => $bm->branch_id, 'type' => 'Cash Transfer',
            'amount' => $amount, 'is_cash_in' => true, 'transaction_date' => now(),
        ]);
    }

    /** Sales cash the manager physically holds (the personal ledger) — must never fund an expense. */
    private function salesCash(BranchManager $bm, float $amount): PersonalLedgerTransaction
    {
        return PersonalLedgerTransaction::create([
            'branch_manager_id' => $bm->id, 'transaction_type' => 'Total Sales', 'amount' => $amount,
            'is_cash_in' => true, 'transaction_date' => now(),
        ]);
    }

    private function custody(BranchManager $bm): float
    {
        return app(CustodyBalanceService::class)->getCustodyBalance($bm->id, $bm->branch_id);
    }

    private function personal(BranchManager $bm): float
    {
        return app(CustodyBalanceService::class)->getPersonalLedgerBalance($bm->id);
    }

    /** POST /quick-cash as the manager (quick cash is capped at 500 by the route validator). */
    private function submitQuickCash(BranchManager $bm, float $amount, array $over = []): TestResponse
    {
        return $this->actor($bm)->postJson('/api/branch-manager/expenses/quick-cash', array_merge([
            'expense_date' => now()->toDateString(), 'expense_name' => 'مصروف تشغيل',
            'total_amount' => $amount, 'payment_method' => 'custody', 'is_draft' => false,
            'items' => [['title' => 'بند', 'amount' => $amount]],
        ], $over));
    }

    /** @return Collection<int, CustodyTransaction> every custody movement linked to the expense, oldest first */
    private function movements(string $expenseId): Collection
    {
        return CustodyTransaction::withoutGlobalScopes()->where('related_expense_id', $expenseId)
            ->orderBy('created_at')->orderBy('id')->get();
    }

    /** @return string[] the sorted amounts of the expense's cash-out (deduction) movements */
    private function outs(string $expenseId): array
    {
        return $this->movements($expenseId)->filter(fn ($r) => ! $r->is_cash_in)->map(fn ($r) => (string) $r->amount)->sort()->values()->all();
    }

    /** @return string[] the sorted amounts of the expense's cash-in (reversal) movements */
    private function ins(string $expenseId): array
    {
        return $this->movements($expenseId)->filter(fn ($r) => $r->is_cash_in)->map(fn ($r) => (string) $r->amount)->sort()->values()->all();
    }

    /** md5 over both ledgers so "zero writes" is exact. */
    private function ledgerSnapshot(): array
    {
        $out = [];
        foreach (['custody_transactions', 'personal_ledger_transactions'] as $table) {
            $rows = DB::table($table)->get()->map(fn ($r) => (array) $r)->sortBy('id')->values()->toJson();
            $out[$table] = md5($rows).':'.DB::table($table)->count();
        }

        return $out;
    }

    private function approve(Expense|string $expense, ?object $actor = null): TestResponse
    {
        $id = $expense instanceof Expense ? $expense->id : $expense;

        return $this->actor($actor ?? $this->ownerA1)->postJson("/api/brand-owner/expenses/{$id}/approve");
    }

    private function reject(Expense|string $expense, ?object $actor = null): TestResponse
    {
        $id = $expense instanceof Expense ? $expense->id : $expense;

        return $this->actor($actor ?? $this->ownerA1)->postJson("/api/brand-owner/expenses/{$id}/reject", ['reason' => self::REASON]);
    }

    private function submittedId(TestResponse $response): string
    {
        // quick-cash / single-invoice answer 201, grouped-invoice / pre-approval 200 (existing contracts).
        $this->assertContains($response->getStatusCode(), [200, 201], (string) $response->getContent());

        return (string) $response->json('data.id');
    }

    /** A supplier visible to branch A1's brand (company-wide row), as the single/grouped writers require. */
    private function supplier(): Supplier
    {
        $legacy = Supplier::factory()->create(['is_active' => true]);
        AsabSupplier::create([
            'company_id' => $this->companyA->id, 'brand_id' => null, 'name' => $legacy->name, 'status' => 'active',
        ])->forceFill(['legacy_supplier_id' => $legacy->id])->save();

        return $legacy;
    }

    private function purchaseLine(float $unitPrice): array
    {
        $category = Category::factory()->purchase()->create(['is_active' => true]);

        return ['category_id' => $category->id, 'name' => 'مواد', 'quantity' => 1, 'unit_price' => $unitPrice];
    }

    private function postSingleInvoice(BranchManager $bm, float $net, array $over = []): TestResponse
    {
        return $this->actor($bm)->postJson('/api/branch-manager/expenses/single-invoice', array_merge([
            'supplier_id' => $this->supplier()->id, 'invoice_number' => 'INV-'.uniqid(), 'total_amount' => round($net * 1.15, 2),
            'issue_date' => now()->toDateString(), 'is_tax_invoice' => false, 'payment_type' => 'full',
            'payment_method' => 'custody', 'is_draft' => false, 'items' => [$this->purchaseLine($net)],
        ], $over));
    }

    private function postGroupedInvoice(BranchManager $bm, float $amount, array $over = []): TestResponse
    {
        return $this->actor($bm)->postJson('/api/branch-manager/expenses/grouped-invoice', array_merge([
            'payment_type' => 'full', 'payment_method' => 'custody', 'is_draft' => false,
            'invoices' => [[
                'supplier_id' => $this->supplier()->id, 'invoice_number' => 'G-'.uniqid(), 'issue_date' => now()->toDateString(),
                'is_tax_invoice' => false, 'items' => [$this->purchaseLine($amount)],
            ]],
        ], $over));
    }

    private function postPreApproval(BranchManager $bm, float $amount, array $over = []): TestResponse
    {
        return $this->actor($bm)->postJson('/api/branch-manager/expenses/pre-approval', array_merge([
            'purpose' => 'عقد صيانة سنوي', 'estimated_amount' => $amount, 'payment_method' => 'custody',
            'priority' => 'high', 'is_draft' => false,
        ], $over));
    }

    // ── every submission writer charges once and refuses when short (EXP-02 / EXP-05) ──

    public function test_single_invoice_submission_charges_the_custody_once_and_refuses_when_short(): void
    {
        $this->fund($this->bmA1, 1000);
        $this->salesCash($this->bmA1, 5000);

        $id = $this->submittedId($this->postSingleInvoice($this->bmA1, 600));   // 600 + 15% VAT = 690
        $this->assertEqualsWithDelta(310.0, $this->custody($this->bmA1), 0.001);
        $this->assertSame(['690.00'], $this->outs($id));

        $before = $this->ledgerSnapshot();
        $expenses = Expense::count();
        $this->postSingleInvoice($this->bmA1, 600)->assertStatus(400)->assertJsonPath('message', 'Insufficient custody balance');
        $this->assertSame($before, $this->ledgerSnapshot(), 'sales cash 5,000 does not cover the shortfall');
        $this->assertSame($expenses, Expense::count(), 'the refused submission persisted nothing');
    }

    public function test_grouped_invoice_submission_charges_the_custody_once_and_refuses_when_short(): void
    {
        $this->fund($this->bmA1, 1000);
        $this->salesCash($this->bmA1, 5000);

        $id = $this->submittedId($this->postGroupedInvoice($this->bmA1, 600));
        $this->assertEqualsWithDelta(400.0, $this->custody($this->bmA1), 0.001);
        $this->assertSame(['600.00'], $this->outs($id));

        $before = $this->ledgerSnapshot();
        $expenses = Expense::count();
        $this->postGroupedInvoice($this->bmA1, 600)->assertStatus(400)->assertJsonPath('message', 'Insufficient custody balance');
        $this->assertSame($before, $this->ledgerSnapshot());
        $this->assertSame($expenses, Expense::count());
    }

    public function test_pre_approval_submission_charges_the_custody_once_and_refuses_when_short(): void
    {
        $this->fund($this->bmA1, 1000);
        $this->salesCash($this->bmA1, 5000);

        $id = $this->submittedId($this->postPreApproval($this->bmA1, 600));
        $this->assertEqualsWithDelta(400.0, $this->custody($this->bmA1), 0.001);
        $this->assertSame(['600.00'], $this->outs($id));

        $before = $this->ledgerSnapshot();
        $expenses = Expense::count();
        $this->postPreApproval($this->bmA1, 600)->assertStatus(400)->assertJsonPath('message', 'Insufficient custody balance');
        $this->assertSame($before, $this->ledgerSnapshot());
        $this->assertSame($expenses, Expense::count());
    }

    public function test_pre_approval_draft_flipped_to_pending_through_update_charges_once_or_is_refused(): void
    {
        Storage::fake('public');
        $flip = fn (string $id): TestResponse => $this->actor($this->bmA1)->post(
            "/api/branch-manager/expenses/pre-approval/{$id}",
            ['_method' => 'PUT', 'is_draft' => 0, 'attachments' => [UploadedFile::fake()->create('quote.pdf', 10, 'application/pdf')]],
            ['Accept' => 'application/json']
        );

        $this->fund($this->bmA1, 700);
        $id = $this->submittedId($this->postPreApproval($this->bmA1, 600, ['is_draft' => true]));
        $this->assertCount(0, $this->movements($id));

        $flip($id)->assertOk();
        $this->assertEqualsWithDelta(100.0, $this->custody($this->bmA1), 0.001);
        $this->assertSame(['600.00'], $this->outs($id));

        // A second draft cannot be flipped once the custody no longer covers it, and stays a draft.
        $second = $this->submittedId($this->postPreApproval($this->bmA1, 600, ['is_draft' => true]));
        $before = $this->ledgerSnapshot();
        $flip($second)->assertStatus(400)->assertJsonPath('message', 'Insufficient custody balance');
        $this->assertSame('draft', Expense::find($second)->status);
        $this->assertSame($before, $this->ledgerSnapshot());
    }

    public function test_grouped_invoice_draft_flipped_to_pending_through_update_charges_once_or_is_refused(): void
    {
        $this->fund($this->bmA1, 700);
        $first = $this->submittedId($this->postGroupedInvoice($this->bmA1, 600, ['is_draft' => true]));
        $second = $this->submittedId($this->postGroupedInvoice($this->bmA1, 600, ['is_draft' => true]));

        $this->actor($this->bmA1)->putJson("/api/branch-manager/expenses/grouped-invoice/{$first}", ['is_draft' => false])->assertOk();
        $this->assertSame(['600.00'], $this->outs($first));
        $this->assertEqualsWithDelta(100.0, $this->custody($this->bmA1), 0.001);

        $before = $this->ledgerSnapshot();
        $this->actor($this->bmA1)->putJson("/api/branch-manager/expenses/grouped-invoice/{$second}", ['is_draft' => false])
            ->assertStatus(400)->assertJsonPath('message', 'Insufficient custody balance');
        $this->assertSame('draft', Expense::find($second)->status, 'the failed flip rolled back with the refusal');
        $this->assertSame($before, $this->ledgerSnapshot());
    }

    public function test_single_invoice_draft_flipped_to_pending_through_update_charges_once(): void
    {
        $this->fund($this->bmA1, 1000);
        $id = $this->submittedId($this->postSingleInvoice($this->bmA1, 600, ['is_draft' => true]));
        $this->assertCount(0, $this->movements($id));

        $this->actor($this->bmA1)->putJson("/api/branch-manager/expenses/single-invoice/{$id}", ['is_draft' => false])->assertOk();

        $this->assertSame(['690.00'], $this->outs($id));
        $this->assertEqualsWithDelta(310.0, $this->custody($this->bmA1), 0.001);
    }

    public function test_duplicating_an_expense_is_a_new_request_with_its_own_single_charge(): void
    {
        $this->fund($this->bmA1, 2000);
        $original = $this->submittedId($this->postPreApproval($this->bmA1, 600));

        $copyId = $this->submittedId($this->actor($this->bmA1)->postJson("/api/branch-manager/expenses/pre-approval/{$original}/duplicate"));

        $this->assertNotSame($original, $copyId);
        $this->assertSame(['600.00'], $this->outs($original));
        $this->assertSame(['600.00'], $this->outs($copyId));
        $this->assertEqualsWithDelta(800.0, $this->custody($this->bmA1), 0.001);
    }

    // ── AC-27 / EXP-02: deduct once at SUBMISSION ────────────────────────────

    public function test_ac27_submission_deducts_once_from_branch_custody_and_approval_does_not_deduct_again(): void
    {
        $this->fund($this->bmA1, 1000);
        $this->salesCash($this->bmA1, 400);

        $id = $this->submittedId($this->submitQuickCash($this->bmA1, 200));

        $this->assertEqualsWithDelta(800.0, $this->custody($this->bmA1), 0.001, 'balance is 800 at SUBMISSION, before any approval');
        $rows = $this->movements($id);
        $this->assertCount(1, $rows);
        $this->assertSame('Expenses Deduction', $rows[0]->type);
        $this->assertFalse($rows[0]->is_cash_in);
        $this->assertSame('200.00', (string) $rows[0]->amount);
        $this->assertSame($this->branchA1->id, $rows[0]->branch_id);

        $this->approve($id)->assertOk();

        $this->assertEqualsWithDelta(800.0, $this->custody($this->bmA1), 0.001, 'approval confirms the deduction, it does not repeat it');
        $this->assertCount(1, $this->movements($id), 'exactly one movement for the expense after approval');
        $this->assertEqualsWithDelta(400.0, $this->personal($this->bmA1), 0.001, 'sales cash (personal ledger) is never touched');
        $this->assertSame(0, PersonalLedgerTransaction::where('related_expense_id', $id)->count());
    }

    public function test_ac27_draft_does_not_deduct_and_its_submission_deducts_exactly_once(): void
    {
        $this->fund($this->bmA1, 1000);

        $id = $this->submittedId($this->submitQuickCash($this->bmA1, 200, ['is_draft' => true]));
        $this->assertEqualsWithDelta(1000.0, $this->custody($this->bmA1), 0.001, 'a draft is not a submission');
        $this->assertCount(0, $this->movements($id));

        $this->actor($this->bmA1)->postJson("/api/branch-manager/expenses/{$id}/submit")->assertOk();
        $this->assertEqualsWithDelta(800.0, $this->custody($this->bmA1), 0.001);

        // A client retry of the same submit must not deduct again.
        $this->actor($this->bmA1)->postJson("/api/branch-manager/expenses/{$id}/submit")->assertOk();
        $this->assertEqualsWithDelta(800.0, $this->custody($this->bmA1), 0.001, 'a retried submit is not a second deduction');
        $this->assertCount(1, $this->movements($id));
    }

    public function test_ac27_draft_flipped_to_pending_through_update_deducts_once(): void
    {
        $this->fund($this->bmA1, 1000);
        $id = $this->submittedId($this->submitQuickCash($this->bmA1, 200, ['is_draft' => true]));

        $this->actor($this->bmA1)->putJson("/api/branch-manager/expenses/quick-cash/{$id}", ['is_draft' => false])->assertOk();
        $this->assertEqualsWithDelta(800.0, $this->custody($this->bmA1), 0.001);
        $this->assertCount(1, $this->movements($id));

        // The expense is now pending: it can no longer be edited, so no second flip can re-deduct.
        $this->actor($this->bmA1)->putJson("/api/branch-manager/expenses/quick-cash/{$id}", ['is_draft' => false])->assertStatus(400);
        $this->assertCount(1, $this->movements($id));
    }

    public function test_only_custody_paid_expenses_touch_custody(): void
    {
        $this->fund($this->bmA1, 1000);
        $before = $this->ledgerSnapshot();

        $this->submittedId($this->submitQuickCash($this->bmA1, 200, ['payment_method' => 'cash']));
        $this->submittedId($this->submitQuickCash($this->bmA1, 100, ['payment_method' => 'supplier']));

        $this->assertSame($before, $this->ledgerSnapshot(), 'cash / supplier expenses never post a custody movement');
    }

    // ── AC-28 / EXP-03/04: rejection reverses once, evidence is kept ─────────

    public function test_ac28_rejection_returns_the_amount_once_and_keeps_both_movements(): void
    {
        $this->fund($this->bmA1, 1000);
        $this->salesCash($this->bmA1, 400);
        $id = $this->submittedId($this->submitQuickCash($this->bmA1, 200));
        $this->assertEqualsWithDelta(800.0, $this->custody($this->bmA1), 0.001);

        $this->reject($id)->assertOk();

        $this->assertEqualsWithDelta(1000.0, $this->custody($this->bmA1), 0.001, 'the balance returns to 1,000 AT REJECTION');
        $rows = $this->movements($id);
        $this->assertCount(2, $rows, 'the original deduction AND the reversal are both kept');
        $this->assertSame(['200.00'], $this->outs($id));
        $this->assertSame(['200.00'], $this->ins($id), 'one reversal, same amount');
        $this->assertSame(['Expenses Deduction', 'Expenses Deduction'], $rows->pluck('type')->all());
        $this->assertSame('rejected', Expense::find($id)->status);
        $this->assertSame(self::REASON, Expense::find($id)->rejection_reason);
        $this->assertEqualsWithDelta(400.0, $this->personal($this->bmA1), 0.001, 'sales cash untouched by the reversal');

        // EXP-04: the manager recovers the cash outside the app — nothing in the app may add it again,
        // and a replayed / second rejection is a no-op.
        $this->reject($id)->assertStatus(400);
        $this->assertCount(2, $this->movements($id));
        $this->assertEqualsWithDelta(1000.0, $this->custody($this->bmA1), 0.001, 'the balance never rises above 1,000');
    }

    public function test_rejecting_an_expense_that_was_never_deducted_posts_no_reversal(): void
    {
        $this->fund($this->bmA1, 1000);
        $id = $this->submittedId($this->submitQuickCash($this->bmA1, 200, ['payment_method' => 'cash']));
        $before = $this->ledgerSnapshot();

        $this->reject($id)->assertOk();

        $this->assertSame($before, $this->ledgerSnapshot());
    }

    public function test_a_rejection_failure_rolls_back_the_status_and_the_reversal_together(): void
    {
        $this->fund($this->bmA1, 1000);
        $id = $this->submittedId($this->submitQuickCash($this->bmA1, 200));
        $before = $this->ledgerSnapshot();

        // The audit row (timeline) is written in the same unit of work: if it fails nothing may persist.
        ExpenseTimeline::creating(function () {
            throw new \RuntimeException('injected audit failure');
        });
        $this->reject($id)->assertStatus(400);
        ExpenseTimeline::flushEventListeners();

        $this->assertSame('pending', Expense::find($id)->status, 'the status flip rolled back with the reversal');
        $this->assertSame($before, $this->ledgerSnapshot(), 'no reversal movement survived the failure');
    }

    // ── AC-29 / EXP-05: insufficient custody blocks the submission ───────────

    public function test_ac29_insufficient_custody_blocks_submission_and_never_spills_to_sales_cash(): void
    {
        $this->fund($this->bmA1, 300);
        $this->salesCash($this->bmA1, 400); // would cover the 200 shortfall under the legacy spill rule
        $before = $this->ledgerSnapshot();

        $this->submitQuickCash($this->bmA1, 500)->assertStatus(400)->assertJsonPath('message', 'Insufficient custody balance');

        $this->assertSame($before, $this->ledgerSnapshot(), 'no custody and no personal-ledger movement');
        $this->assertSame(0, Expense::where('branch_manager_id', $this->bmA1->id)->count(), 'the blocked expense is not persisted');
        $this->assertEqualsWithDelta(300.0, $this->custody($this->bmA1), 0.001);
        $this->assertEqualsWithDelta(400.0, $this->personal($this->bmA1), 0.001);

        // A recorded top-up makes the same submission valid, and it then consumes only the custody.
        $this->fund($this->bmA1, 200);
        $id = $this->submittedId($this->submitQuickCash($this->bmA1, 500));
        $this->assertEqualsWithDelta(0.0, $this->custody($this->bmA1), 0.001);
        $this->assertEqualsWithDelta(400.0, $this->personal($this->bmA1), 0.001);
        $this->assertCount(1, $this->movements($id));
    }

    public function test_ac29_the_exact_balance_is_allowed_and_one_halala_short_is_not(): void
    {
        $this->fund($this->bmA1, 500);
        $id = $this->submittedId($this->submitQuickCash($this->bmA1, 500));
        $this->assertEqualsWithDelta(0.0, $this->custody($this->bmA1), 0.001);
        $this->assertCount(1, $this->movements($id));

        $this->fund($this->bmA1, 499.99);
        $before = $this->ledgerSnapshot();
        $this->submitQuickCash($this->bmA1, 500)->assertStatus(400);
        $this->assertSame($before, $this->ledgerSnapshot());
    }

    public function test_ac29_submitting_a_draft_with_insufficient_custody_is_refused_and_leaves_it_a_draft(): void
    {
        $this->fund($this->bmA1, 300);
        $this->salesCash($this->bmA1, 900);
        $id = $this->submittedId($this->submitQuickCash($this->bmA1, 200, ['is_draft' => true]));
        // The custody is spent elsewhere (handed over) between saving the draft and submitting it.
        CustodyTransaction::create([
            'branch_manager_id' => $this->bmA1->id, 'branch_id' => $this->branchA1->id, 'type' => 'Cash Handover',
            'amount' => 250, 'is_cash_in' => false, 'transaction_date' => now(),
        ]);
        $before = $this->ledgerSnapshot();

        $this->actor($this->bmA1)->postJson("/api/branch-manager/expenses/{$id}/submit")->assertStatus(400);

        $this->assertSame('draft', Expense::find($id)->status);
        $this->assertSame($before, $this->ledgerSnapshot());
    }

    public function test_ac29_a_failure_after_the_deduction_rolls_the_deduction_back(): void
    {
        $this->fund($this->bmA1, 1000);
        $before = $this->ledgerSnapshot();

        Event::listen(ExpenseSubmittedEvent::class, function () {
            throw new \RuntimeException('injected bridge failure');
        });
        $this->submitQuickCash($this->bmA1, 200)->assertStatus(500);

        $this->assertSame($before, $this->ledgerSnapshot(), 'the deduction and the expense succeed together or not at all');
        $this->assertSame(0, Expense::count());
    }

    // ── AC-30 / EXP-08: corrected resubmission ───────────────────────────────

    public function test_ac30_resubmission_deducts_the_corrected_amount_once_and_preserves_the_previous_cycle(): void
    {
        $this->fund($this->bmA1, 1000);
        $id = $this->submittedId($this->submitQuickCash($this->bmA1, 200));
        $this->reject($id)->assertOk();
        $this->assertEqualsWithDelta(1000.0, $this->custody($this->bmA1), 0.001);

        // The corrected amount (the repository exposes no endpoint that edits a rejected expense,
        // so the correction is applied on the record itself, exactly what a corrected request carries).
        Expense::whereKey($id)->update(['total_amount' => 150, 'net_amount' => 150]);

        $this->actor($this->bmA1)->postJson("/api/branch-manager/expenses/{$id}/resubmit")->assertOk();

        $this->assertEqualsWithDelta(850.0, $this->custody($this->bmA1), 0.001, 'exactly one deduction of the corrected 150');
        $this->assertSame(['150.00', '200.00'], $this->outs($id), 'deduction 200 (kept) and the corrected deduction 150');
        $this->assertSame(['200.00'], $this->ins($id), 'exactly one reversal, of the first cycle');
        $this->assertSame(1, Expense::where('id', $id)->count(), 'same request, not a new expense');

        $this->actor($this->bmA1)->postJson("/api/branch-manager/expenses/{$id}/resubmit")->assertStatus(400);
        $this->assertCount(3, $this->movements($id), 'a retried resubmit does not deduct again');

        $this->approve($id)->assertOk();
        $this->assertEqualsWithDelta(850.0, $this->custody($this->bmA1), 0.001, 'approval after resubmission does not deduct again');
        $this->assertCount(3, $this->movements($id));

        $actions = ExpenseTimeline::where('expense_id', $id)->orderBy('created_at')->orderBy('id')->pluck('action')->all();
        foreach (['reject', 'resubmit', 'approve'] as $action) {
            $this->assertContains($action, $actions, "the {$action} audit row of the cycle is preserved");
        }
    }

    public function test_ac30_resubmission_is_blocked_when_the_corrected_amount_exceeds_the_custody(): void
    {
        $this->fund($this->bmA1, 300);
        $this->salesCash($this->bmA1, 900);
        $id = $this->submittedId($this->submitQuickCash($this->bmA1, 200));
        $this->reject($id)->assertOk();
        Expense::whereKey($id)->update(['total_amount' => 400, 'net_amount' => 400]);
        $before = $this->ledgerSnapshot();

        $this->actor($this->bmA1)->postJson("/api/branch-manager/expenses/{$id}/resubmit")->assertStatus(400);

        $this->assertSame('rejected', Expense::find($id)->status, 'the expense stays rejected');
        $this->assertSame($before, $this->ledgerSnapshot());
    }

    // ── EXP-01 / CASH-17: isolation of the custody that may fund the expense ─

    public function test_custody_of_another_branch_or_company_never_funds_the_expense(): void
    {
        $this->fund($this->bmA2, 1000); // same company, other branch
        $this->fund($this->bmB1, 1000); // other company
        $before = $this->ledgerSnapshot();

        $this->submitQuickCash($this->bmA1, 100)->assertStatus(400);

        $this->assertSame($before, $this->ledgerSnapshot(), 'no movement anywhere, in particular none on the other tenants');
        $this->assertEqualsWithDelta(1000.0, $this->custody($this->bmA2), 0.001);
        $this->assertEqualsWithDelta(1000.0, $this->custody($this->bmB1), 0.001);
    }

    public function test_custody_the_manager_built_at_another_branch_does_not_fund_this_branch(): void
    {
        // Custody belongs to a BRANCH: money recorded against this manager at branch A2 is not branch A1's.
        CustodyTransaction::create([
            'branch_manager_id' => $this->bmA1->id, 'branch_id' => $this->branchA2->id, 'type' => 'Cash Transfer',
            'amount' => 1000, 'is_cash_in' => true, 'transaction_date' => now(),
        ]);
        $before = $this->ledgerSnapshot();

        $this->submitQuickCash($this->bmA1, 100)->assertStatus(400);

        $this->assertSame($before, $this->ledgerSnapshot());
    }

    public function test_the_custody_balance_endpoint_reports_the_branch_custody_without_sales_cash(): void
    {
        $this->fund($this->bmA1, 300);
        $this->salesCash($this->bmA1, 400);

        $res = $this->actor($this->bmA1)->getJson('/api/branch-manager/expenses/custody-balance')->assertOk();

        $this->assertEqualsWithDelta(300.0, (float) $res->json('data.balance'), 0.001, 'spendable balance = branch custody only');
        $this->assertEqualsWithDelta(300.0, (float) $res->json('data.branch_custody_balance'), 0.001);
    }

    // ── authorization / tenant isolation of the decisions that move custody ──

    public function test_an_out_of_scope_actor_cannot_trigger_the_reversal(): void
    {
        $this->fund($this->bmA1, 1000);
        $id = $this->submittedId($this->submitQuickCash($this->bmA1, 200));
        $before = $this->ledgerSnapshot();

        $this->reject($id, $this->ownerB1)->assertStatus(404);
        $this->approve($id, $this->ownerB1)->assertStatus(404);
        $this->actor($this->bmA2)->postJson("/api/branch-manager/expenses/{$id}/resubmit")->assertStatus(403);

        $this->assertSame($before, $this->ledgerSnapshot());
        $this->assertSame('pending', Expense::find($id)->status);
    }

    public function test_another_manager_cannot_submit_a_draft_they_do_not_own(): void
    {
        $this->fund($this->bmA1, 1000);
        $this->fund($this->bmA2, 1000);
        $id = $this->submittedId($this->submitQuickCash($this->bmA1, 200, ['is_draft' => true]));
        $before = $this->ledgerSnapshot();

        $this->actor($this->bmA2)->postJson("/api/branch-manager/expenses/{$id}/submit")->assertStatus(403);

        $this->assertSame($before, $this->ledgerSnapshot());
        $this->assertSame('draft', Expense::find($id)->status);
    }

    // ── EXP-07 (sequential): one decision, one effect ────────────────────────

    public function test_ac31_conflicting_and_duplicate_decisions_execute_one_effect(): void
    {
        $this->fund($this->bmA1, 1000);
        $id = $this->submittedId($this->submitQuickCash($this->bmA1, 200));

        $this->approve($id)->assertOk();
        $afterApprove = $this->ledgerSnapshot();

        $this->approve($id)->assertStatus(400);      // duplicate
        $this->reject($id)->assertStatus(400);       // conflicting
        $this->reject($id)->assertStatus(400);

        $this->assertSame($afterApprove, $this->ledgerSnapshot(), 'a refused decision writes nothing');
        $this->assertCount(1, $this->movements($id));
        $this->assertSame('approved', Expense::find($id)->status);
    }

    public function test_a_rejection_first_then_an_approval_leaves_exactly_one_reversal(): void
    {
        $this->fund($this->bmA1, 1000);
        $id = $this->submittedId($this->submitQuickCash($this->bmA1, 200));

        $this->reject($id)->assertOk();
        $this->approve($id)->assertStatus(400);

        $this->assertCount(2, $this->movements($id));
        $this->assertEqualsWithDelta(1000.0, $this->custody($this->bmA1), 0.001);
    }

    public function test_the_rejection_event_still_fires_once_after_the_reversal(): void
    {
        $this->fund($this->bmA1, 1000);
        $id = $this->submittedId($this->submitQuickCash($this->bmA1, 200));

        $seen = [];
        Event::listen(ExpenseRejectedEvent::class, function () use (&$seen, $id) {
            $seen[] = $this->movements($id)->count();
        });
        $this->reject($id)->assertOk();

        $this->assertSame([2], $seen, 'notifications / the dashboard mirror run once, after the reversal is persisted');
    }

    // ── cut-over safety: an expense submitted BEFORE this change (never reserved) ─

    public function test_legacy_in_flight_custody_expense_is_deducted_once_when_approved_if_covered(): void
    {
        $this->fund($this->bmA1, 1000);
        $this->salesCash($this->bmA1, 400);
        $legacy = $this->pendingExpense($this->bmA1, ['payment_method' => 'custody', 'total_amount' => 250.00]);

        $this->approve($legacy)->assertOk();

        $this->assertEqualsWithDelta(750.0, $this->custody($this->bmA1), 0.001, 'a never-reserved pending expense is deducted once, at approval');
        $this->assertCount(1, $this->movements($legacy->id));
        $this->assertEqualsWithDelta(400.0, $this->personal($this->bmA1), 0.001);
        $this->assertSame(0, PersonalLedgerTransaction::where('related_expense_id', $legacy->id)->count());

        $this->approve($legacy)->assertStatus(400);
        $this->assertCount(1, $this->movements($legacy->id));
    }

    public function test_legacy_in_flight_custody_expense_is_not_approved_when_the_custody_does_not_cover_it(): void
    {
        $this->fund($this->bmA1, 100);
        $this->salesCash($this->bmA1, 900);
        $legacy = $this->pendingExpense($this->bmA1, ['payment_method' => 'custody', 'total_amount' => 250.00]);
        $before = $this->ledgerSnapshot();

        $this->approve($legacy)->assertStatus(400);

        $this->assertSame('pending', Expense::find($legacy->id)->status, 'nothing is approved that custody cannot back');
        $this->assertSame($before, $this->ledgerSnapshot(), 'and nothing spills into the sales-cash ledger');
    }

    public function test_legacy_in_flight_custody_expense_rejection_posts_no_reversal(): void
    {
        $this->fund($this->bmA1, 1000);
        $legacy = $this->pendingExpense($this->bmA1, ['payment_method' => 'custody', 'total_amount' => 250.00]);
        $before = $this->ledgerSnapshot();

        $this->reject($legacy)->assertOk();

        $this->assertSame($before, $this->ledgerSnapshot(), 'there was no deduction, so there is nothing to reverse');
    }
}
