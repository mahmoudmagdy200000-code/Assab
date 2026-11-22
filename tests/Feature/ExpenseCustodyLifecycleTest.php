<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\CompanyUser;
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
        $revision = (int) (Expense::query()->whereKey($id)->value('review_revision') ?? 1);

        return $this->actor($actor ?? $this->ownerA1)->postJson("/api/brand-owner/expenses/{$id}/approve", ['review_revision' => $revision]);
    }

    private function reject(Expense|string $expense, ?object $actor = null): TestResponse
    {
        $id = $expense instanceof Expense ? $expense->id : $expense;
        $revision = (int) (Expense::query()->whereKey($id)->value('review_revision') ?? 1);

        return $this->actor($actor ?? $this->ownerA1)->postJson("/api/brand-owner/expenses/{$id}/reject", [
            'reason' => self::REASON,
            'review_revision' => $revision,
        ]);
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

    public function test_cash_wire_alias_uses_branch_custody_and_an_insufficient_draft_remains_saveable(): void
    {
        $before = $this->ledgerSnapshot();
        $draft = $this->actor($this->bmA1)->postJson('/api/branch-manager/expenses/quick-cash', [
            'expense_date' => now()->toDateString(), 'expense_name' => 'مسودة نقدية بلا رصيد',
            'total_amount' => 30, 'payment_method' => 'cash', 'is_draft' => true,
            'items' => [['title' => 'بند', 'amount' => 30]],
        ])->assertCreated();
        $id = (string) $draft->json('data.id');
        $this->assertSame('draft', Expense::findOrFail($id)->status);
        $this->assertSame($before, $this->ledgerSnapshot(), 'saving a cash draft has no financial effect');

        $this->actor($this->bmA1)->postJson("/api/branch-manager/expenses/{$id}/submit")->assertStatus(400);
        $this->assertSame('draft', Expense::findOrFail($id)->status);
        $this->assertSame($before, $this->ledgerSnapshot(), 'insufficient cash submission is atomic');

        $this->fund($this->bmA1, 100);
        $submitted = $this->submittedId($this->submitQuickCash($this->bmA1, 30, ['payment_method' => 'cash']));
        $this->assertSame(['30.00'], $this->outs($submitted));
        $this->assertEqualsWithDelta(70.0, $this->custody($this->bmA1), 0.001);
        $this->approve($submitted)->assertOk();
        $this->assertEqualsWithDelta(70.0, $this->custody($this->bmA1), 0.001, 'approval does not charge cash a second time');
    }

    public function test_branch_custody_balance_and_attribution_survive_manager_replacement(): void
    {
        $formerManager = $this->bmA1;
        $this->fund($formerManager, 100);
        $formerManager->update(['is_active' => false, 'status' => 'inactive']);
        $currentManager = BranchManager::factory()->create([
            'branch_id' => $this->branchA1->id, 'is_active' => true, 'status' => 'active',
        ]);

        $balance = $this->actor($currentManager)->getJson('/api/branch-manager/ledger/branch-custody-balance')->assertOk();
        $this->assertSame('100.00', number_format((float) $balance->json('data.currentBalance'), 2, '.', ''));
        $this->assertSame(1, CustodyTransaction::where('branch_manager_id', $formerManager->id)->count(), 'historical funding keeps its original actor');

        $expenseId = $this->submittedId($this->submitQuickCash($currentManager, 25, ['payment_method' => 'cash']));
        $this->assertSame($currentManager->id, CustodyTransaction::where('related_expense_id', $expenseId)->value('branch_manager_id'));
        $this->assertEqualsWithDelta(75.0, $this->custody($currentManager), 0.001);
        $this->assertSame(1, CustodyTransaction::where('branch_manager_id', $formerManager->id)->count(), 'no historical row was rewritten or duplicated');
    }

    public function test_replaying_expense_creation_with_the_same_idempotency_key_returns_one_expense_and_one_charge(): void
    {
        $this->fund($this->bmA1, 500);
        $payload = [
            'expense_date' => now()->toDateString(), 'expense_name' => 'شراء مستلزمات عاجلة',
            'total_amount' => 125, 'payment_method' => 'custody', 'is_draft' => false,
            'items' => [['title' => 'مستلزمات', 'amount' => 125]],
        ];
        $headers = ['Idempotency-Key' => (string) \Illuminate\Support\Str::uuid()];

        $first = $this->actor($this->bmA1)->postJson('/api/branch-manager/expenses/quick-cash', $payload, $headers)->assertCreated();
        $replay = $this->actor($this->bmA1)->postJson('/api/branch-manager/expenses/quick-cash', $payload, $headers)->assertCreated();

        $expenseId = (string) $first->json('data.id');
        $this->assertSame($expenseId, (string) $replay->json('data.id'));
        $this->assertSame(1, Expense::whereKey($expenseId)->count());
        $this->assertSame(['125.00'], $this->outs($expenseId));
        $this->assertEqualsWithDelta(375.0, $this->custody($this->bmA1), 0.001);
    }

    public function test_partial_single_invoice_charges_only_the_amount_paid_now(): void
    {
        $this->fund($this->bmA1, 150);

        // Invoice gross is 690; only 100 SAR was paid from custody now.
        $id = $this->submittedId($this->postSingleInvoice($this->bmA1, 600, [
            'is_tax_invoice' => true,
            'tax_invoice_details' => ['supplier_name' => 'Phase 5 VAT supplier', 'net_amount' => 600, 'vat_amount' => 90, 'total_amount' => 690],
            'payment_type' => 'partial', 'payment_method' => 'cash', 'paid_amount' => 100, 'due_date' => now()->addDays(30)->toDateString(),
        ]));

        $this->assertSame(['100.00'], $this->outs($id));
        $this->assertEqualsWithDelta(50.0, $this->custody($this->bmA1), 0.001);
        $expense = Expense::with('invoiceDetails')->findOrFail($id);
        $this->assertSame('690.00', (string) $expense->total_amount);
        $this->assertSame('600.00', (string) $expense->net_amount);
        $this->assertSame('90.00', (string) $expense->vat_amount);
        $this->assertSame('100.00', (string) $expense->invoiceDetails->first()->paid_amount);
        $this->assertSame('90.00', (string) $expense->invoiceDetails->first()->tax_vat_amount);
        $this->assertSame('690.00', (string) $expense->invoiceDetails->first()->tax_total_amount);
        $this->assertSame('590.00', number_format((float) $expense->total_amount - (float) $expense->invoiceDetails->first()->paid_amount, 2, '.', ''), 'supplier remainder is gross total less actual payment');
        $dashboardOperation = Operation::where('source_module', 'expense')->where('source_id', $id)->firstOrFail();
        $this->assertSame(10000, $dashboardOperation->payload['custodyChargeHalalas']);
    }

    public function test_deferred_and_supplier_credit_invoices_do_not_debit_branch_custody(): void
    {
        $this->fund($this->bmA1, 200);
        $supplier = $this->supplier();

        $deferredId = $this->submittedId($this->postSingleInvoice($this->bmA1, 600, [
            'supplier_id' => $supplier->id,
            'payment_type' => 'deferred', 'payment_method' => 'custody',
            'due_date' => now()->addDays(30)->toDateString(),
        ]));
        $deferred = Expense::with('invoiceDetails')->findOrFail($deferredId);
        $this->assertSame('0.00', (string) $deferred->invoiceDetails->first()->paid_amount);
        $this->assertSame([], $this->outs($deferredId));
        $this->assertEqualsWithDelta(200.0, $this->custody($this->bmA1), 0.001);

        $supplierCreditId = $this->submittedId($this->postSingleInvoice($this->bmA1, 600, [
            'supplier_id' => $supplier->id,
            'payment_type' => 'deferred', 'payment_method' => 'supplier', 'payment_supplier_id' => $supplier->id,
            'due_date' => now()->addDays(30)->toDateString(),
        ]));
        $supplierCredit = Expense::with('invoiceDetails')->findOrFail($supplierCreditId);
        $this->assertSame('0.00', (string) $supplierCredit->invoiceDetails->first()->paid_amount);
        $this->assertSame(0, CustodyTransaction::where('related_expense_id', $deferredId)->count());
        $this->assertSame(0, CustodyTransaction::where('related_expense_id', $supplierCreditId)->count());
        $this->assertSame([], $this->outs($supplierCreditId));
        $this->assertEqualsWithDelta(200.0, $this->custody($this->bmA1), 0.001);
    }

    public function test_grouped_partial_payment_is_allocated_proportionally_and_debits_once(): void
    {
        $this->fund($this->bmA1, 150);
        $supplier = $this->supplier();
        $invoices = [];
        foreach ([300, 300] as $index => $amount) {
            $invoices[] = [
                'supplier_id' => $supplier->id,
                'invoice_number' => 'GP-'.$index.'-'.uniqid(),
                'issue_date' => now()->toDateString(),
                'is_tax_invoice' => false,
                'items' => [$this->purchaseLine((float) $amount)],
            ];
        }

        $id = $this->submittedId($this->actor($this->bmA1)->postJson('/api/branch-manager/expenses/grouped-invoice', [
            'payment_type' => 'partial', 'payment_method' => 'cash', 'paid_amount' => 100,
            'is_draft' => false, 'invoices' => $invoices,
        ]));

        $expense = Expense::with(['groupedInvoice', 'invoiceDetails'])->findOrFail($id);
        $this->assertSame('100.00', (string) $expense->groupedInvoice->paid_amount);
        $this->assertSame(['50.00', '50.00'], $expense->invoiceDetails->pluck('paid_amount')->map(fn ($v) => (string) $v)->all());
        $this->assertSame(['100.00'], $this->outs($id));
        $this->assertEqualsWithDelta(50.0, $this->custody($this->bmA1), 0.001);
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

    public function test_pre_approval_submission_does_not_charge_custody(): void
    {
        $this->fund($this->bmA1, 1000);
        $this->salesCash($this->bmA1, 5000);

        // Pre-approval request for 600 does NOT touch custody (Rule EXP-10)
        $id = $this->submittedId($this->postPreApproval($this->bmA1, 600));
        $this->assertEqualsWithDelta(1000.0, $this->custody($this->bmA1), 0.001);
        $this->assertCount(0, $this->movements($id));

        // Even with insufficient balance for an actual expense, a pre-approval request is accepted
        $second = $this->submittedId($this->postPreApproval($this->bmA1, 1500));
        $this->assertEqualsWithDelta(1000.0, $this->custody($this->bmA1), 0.001);
        $this->assertCount(0, $this->movements($second));

        // Approving the pre-approval request is still not an actual payment (EXP-10).
        $this->approve($id)->assertOk();
        $this->assertEqualsWithDelta(1000.0, $this->custody($this->bmA1), 0.001);
        $this->assertCount(0, $this->movements($id));
    }

    public function test_pre_approval_reject_and_resubmit_never_move_custody(): void
    {
        $this->fund($this->bmA1, 1000);
        $before = $this->ledgerSnapshot();
        $id = $this->submittedId($this->postPreApproval($this->bmA1, 600));

        $this->assertEqualsWithDelta(1000.0, $this->custody($this->bmA1), 0.001);
        $this->reject($id)->assertOk();
        $this->assertEqualsWithDelta(1000.0, $this->custody($this->bmA1), 0.001);
        $this->actor($this->bmA1)->postJson("/api/branch-manager/expenses/{$id}/resubmit")->assertOk();
        $this->assertEqualsWithDelta(1000.0, $this->custody($this->bmA1), 0.001);
        $this->actor($this->ownerA1)->postJson("/api/brand-owner/expenses/{$id}/approve", ['review_revision' => 2])->assertOk();

        $this->assertSame($before, $this->ledgerSnapshot(), 'pre-approval lifecycle has no custody or personal cash effect');
        $this->assertCount(0, $this->movements($id));
    }

    public function test_stale_or_unversioned_decisions_cannot_decide_a_resubmitted_review_cycle(): void
    {
        $this->fund($this->bmA1, 500);
        $id = $this->submittedId($this->submitQuickCash($this->bmA1, 30));
        $first = $this->actor($this->ownerA1)->getJson("/api/brand-owner/expenses/{$id}")->assertOk();
        $this->assertSame(1, (int) $first->json('data.review_revision'));

        $this->reject($id)->assertOk();
        $this->actor($this->bmA1)->postJson("/api/branch-manager/expenses/{$id}/resubmit")->assertOk();
        $current = $this->actor($this->ownerA1)->getJson("/api/brand-owner/expenses/{$id}")->assertOk();
        $this->assertSame(2, (int) $current->json('data.review_revision'));
        $before = $this->ledgerSnapshot();

        $this->actor($this->ownerA1)->postJson("/api/brand-owner/expenses/{$id}/approve", ['review_revision' => 1])
            ->assertConflict()->assertJsonPath('code', 'STALE_EXPENSE_REVIEW_REVISION')
            ->assertJsonPath('message', 'The expense changed. Reopen it and review the current version before making a decision.');
        $this->actor($this->ownerA1)->postJson("/api/brand-owner/expenses/{$id}/reject", ['reason' => self::REASON], ['Accept-Language' => 'ar'])
            ->assertConflict()->assertJsonPath('code', 'EXPENSE_REVIEW_REVISION_REQUIRED')
            ->assertJsonPath('message', 'حدّث التطبيق ثم افتح المصروف من جديد وراجعه قبل اتخاذ القرار.');

        $this->assertSame('pending', Expense::findOrFail($id)->status);
        $this->assertSame($before, $this->ledgerSnapshot(), 'stale decisions have no state or ledger effect');
        $this->actor($this->ownerA1)->postJson("/api/brand-owner/expenses/{$id}/approve", ['review_revision' => 2])->assertOk();
        $this->assertSame('approved', Expense::findOrFail($id)->status);
        $this->assertEqualsWithDelta(470.0, $this->custody($this->bmA1), 0.001);
    }

    public function test_pre_approval_draft_flipped_to_pending_does_not_charge_custody(): void
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
        // Flipping pre-approval draft to pending does NOT charge custody (Rule EXP-10)
        $this->assertEqualsWithDelta(700.0, $this->custody($this->bmA1), 0.001);
        $this->assertCount(0, $this->movements($id));
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
        $original = $this->submittedId($this->postSingleInvoice($this->bmA1, 600));

        $copyId = $this->submittedId($this->actor($this->bmA1)->postJson("/api/branch-manager/expenses/single-invoice/{$original}/duplicate"));

        $this->assertNotSame($original, $copyId);
        $this->assertSame(['690.00'], $this->outs($original));
        $this->assertSame(['690.00'], $this->outs($copyId));
        $this->assertEqualsWithDelta(620.0, $this->custody($this->bmA1), 0.001);
    }

    public function test_duplicating_a_pre_approval_request_does_not_charge_custody(): void
    {
        $this->fund($this->bmA1, 2000);
        $original = $this->submittedId($this->postPreApproval($this->bmA1, 600));

        $copyId = $this->submittedId($this->actor($this->bmA1)->postJson("/api/branch-manager/expenses/pre-approval/{$original}/duplicate"));

        $this->assertNotSame($original, $copyId);
        $this->assertCount(0, $this->movements($original));
        $this->assertCount(0, $this->movements($copyId));
        $this->assertEqualsWithDelta(2000.0, $this->custody($this->bmA1), 0.001);
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

    public function test_cash_and_custody_paid_expenses_touch_only_branch_custody(): void
    {
        $this->fund($this->bmA1, 1000);
        $personalBefore = PersonalLedgerTransaction::count();

        $cashExpense = $this->submittedId($this->submitQuickCash($this->bmA1, 200, ['payment_method' => 'cash']));
        $this->assertSame(['200.00'], $this->outs($cashExpense));
        $this->assertEqualsWithDelta(800.0, $this->custody($this->bmA1), 0.001);

        $supplierExpense = $this->submittedId($this->submitQuickCash($this->bmA1, 100, ['payment_method' => 'supplier']));
        $this->assertCount(0, $this->movements($supplierExpense));
        $this->assertEqualsWithDelta(800.0, $this->custody($this->bmA1), 0.001);

        $this->assertSame($personalBefore, PersonalLedgerTransaction::count(), 'cash never spills into the sales-cash/personal ledger');
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

    public function test_rejected_expense_detail_exposes_a_rejection_record_separate_from_cancellation(): void
    {
        $this->fund($this->bmA1, 1000);
        $id = $this->submittedId($this->submitQuickCash($this->bmA1, 200));
        $this->reject($id)->assertOk();

        $response = $this->actor($this->bmA1)
            ->getJson("/api/branch-manager/expenses/{$id}")
            ->assertOk()
            ->assertJsonPath('data.status.value', 'rejected')
            ->assertJsonPath('data.rejection.reason', self::REASON)
            ->assertJsonPath('data.cancellation.deprecated_alias_of', 'rejection')
            ->assertJsonPath('data.cancellation.cancellation_reason', self::REASON);

        $this->assertNotEmpty($response->json('data.rejection.rejected_at'));
        $this->assertSame($this->ownerA1->id, $response->json('data.rejection.rejected_by.id'));
        $this->assertSame('brand_owner', $response->json('data.rejection.rejected_by.type'));
        $this->assertSame(2, $this->movements($id)->count());
    }

    public function test_deleting_an_unsubmitted_draft_has_no_custody_effect_and_submitted_expenses_cannot_be_deleted(): void
    {
        $this->fund($this->bmA1, 1000);
        $draftId = $this->submittedId($this->submitQuickCash($this->bmA1, 200, ['is_draft' => true]));
        $before = $this->ledgerSnapshot();

        $this->actor($this->bmA1)->deleteJson("/api/branch-manager/expenses/{$draftId}")->assertOk();
        $this->assertSame($before, $this->ledgerSnapshot());
        $this->assertSoftDeleted('expenses', ['id' => $draftId]);

        $submittedId = $this->submittedId($this->submitQuickCash($this->bmA1, 100));
        $beforeSubmittedDelete = $this->ledgerSnapshot();
        $this->actor($this->bmA1)->deleteJson("/api/branch-manager/expenses/{$submittedId}")->assertStatus(400);
        $this->assertSame($beforeSubmittedDelete, $this->ledgerSnapshot());
        $this->assertSame('pending', Expense::findOrFail($submittedId)->status);
    }

    public function test_rejecting_an_expense_that_was_never_deducted_posts_no_reversal(): void
    {
        $this->fund($this->bmA1, 1000);
        $id = $this->submittedId($this->submitQuickCash($this->bmA1, 200, ['payment_method' => 'supplier']));
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

        // Correct the same rejected request through its real API, keeping the rejected state
        // until the explicit resubmit action posts the next custody charge.
        $this->actor($this->bmA1)->putJson("/api/branch-manager/expenses/quick-cash/{$id}", [
            'expense_name' => 'مصروف تشغيل مصحح', 'total_amount' => 150, 'net_amount' => 150,
            'vat_amount' => 0, 'items' => [['title' => 'بند مصحح', 'amount' => 150]],
            'is_draft' => false,
        ])->assertOk();
        $this->assertSame('rejected', Expense::find($id)->status, 'editing does not clear rejection or post custody');
        $this->assertEqualsWithDelta(1000.0, $this->custody($this->bmA1), 0.001);

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
        $this->actor($this->bmA1)->putJson("/api/branch-manager/expenses/quick-cash/{$id}", [
            'total_amount' => 400, 'net_amount' => 400, 'vat_amount' => 0,
            'items' => [['title' => 'بند مصحح', 'amount' => 400]], 'is_draft' => false,
        ])->assertOk();
        $before = $this->ledgerSnapshot();

        $this->actor($this->bmA1)->postJson("/api/branch-manager/expenses/{$id}/resubmit")->assertStatus(400);

        $this->assertSame('rejected', Expense::find($id)->status, 'the expense stays rejected');
        $this->assertSame($before, $this->ledgerSnapshot());
    }

    public function test_phase4_bank_funded_branch_expense_acceptance_journey(): void
    {
        // SQLite keeps an obsolete branch-manager FK on custody_requests.approved_by;
        // production migrations drop it. Defer the check inside the rolled-back test transaction.
        DB::statement('PRAGMA defer_foreign_keys = ON');
        Storage::fake('public');
        $this->assertEqualsWithDelta(0.0, $this->custody($this->bmA1), 0.001, 'isolated branch starts at zero');
        $initialPersonalLedgerCount = PersonalLedgerTransaction::count();

        $funding = $this->actor($this->bmA1)->postJson('/api/custody/request-cashin', [
            'requestedAmount' => 100,
            'purpose' => 'Phase 4 isolated acceptance journey',
            'preferredReceiptMethod' => 'bank_transfer',
        ])->assertStatus(201);
        $fundingId = (string) $funding->json('data.requestId');
        $this->assertEqualsWithDelta(0.0, $this->custody($this->bmA1), 0.001, 'request creation does not post cash');

        $approval = $this->actor($this->ownerA1)->postJson("/api/custody/requests/{$fundingId}/approve");
        $this->assertSame(200, $approval->getStatusCode(), (string) $approval->getContent());
        $this->assertEqualsWithDelta(0.0, $this->custody($this->bmA1), 0.001, 'owner approval does not post cash');

        $accountant = AsabUser::create([
            'company_id' => $this->companyA->id, 'name' => 'Acceptance Accountant',
            'email' => 'phase4-accountant@tenant.test', 'password' => 'secret-password', 'status' => 'active',
        ]);
        CompanyUser::create(['company_id' => $this->companyA->id, 'user_id' => $accountant->id, 'role_key' => 'accountant', 'status' => 'active']);
        AsabUserRole::create([
            'user_id' => $accountant->id, 'role_key' => 'accountant', 'scope' => 'branch', 'branch_ids' => [$this->branchA1->id],
        ]);

        $this->actingAs($accountant, 'sanctum')->post("/api/custody/requests/{$fundingId}/execute", [
            'transferReference' => 'TEST-BANK-TRANSFER-100',
            'proof' => UploadedFile::fake()->create('bank-transfer-proof.pdf', 20, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertEqualsWithDelta(0.0, $this->custody($this->bmA1), 0.001, 'accountant execution records evidence, not branch receipt');

        $this->actor($this->bmA1)->postJson("/api/custody/requests/{$fundingId}/confirm-receipt")
            ->assertOk();
        $this->assertDatabaseHas('custody_requests', [
            'id' => $fundingId, 'preferred_receipt_method' => 'Bank Transfer',
            'transfer_reference' => 'TEST-BANK-TRANSFER-100',
        ]);
        $this->assertEqualsWithDelta(100.0, $this->custody($this->bmA1), 0.001, 'confirmed receipt posts once');
        $this->actor($this->bmA1)->postJson("/api/custody/requests/{$fundingId}/confirm-receipt")
            ->assertStatus(422);
        $this->assertEqualsWithDelta(100.0, $this->custody($this->bmA1), 0.001, 'duplicate receipt confirmation is a no-op');

        $draft = $this->actor($this->bmA1)->postJson('/api/branch-manager/expenses/quick-cash', [
            'expense_date' => now()->toDateString(), 'expense_name' => 'Phase 4 expense',
            'total_amount' => 30, 'payment_method' => 'custody', 'is_draft' => true,
            'items' => [['title' => 'Phase 4 item', 'amount' => 30]],
        ])->assertCreated();
        $expenseId = (string) $draft->json('data.id');
        $this->actor($this->bmA1)->post("/api/branch-manager/expenses/{$expenseId}/attachments", [
            'attachments' => [UploadedFile::fake()->create('expense-receipt.pdf', 15, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertEqualsWithDelta(100.0, $this->custody($this->bmA1), 0.001, 'draft has no financial movement');

        $this->actor($this->bmA1)->postJson("/api/branch-manager/expenses/{$expenseId}/submit")
            ->assertOk();
        $this->assertEqualsWithDelta(70.0, $this->custody($this->bmA1), 0.001, 'initial submission deducts 30');
        $operation = Operation::withoutGlobalScopes()->where('source_id', $expenseId)->firstOrFail();
        $this->assertSame(3000, (int) $operation->payload['custodyChargeHalalas'], 'dashboard mirror shows 3,000 halalas');
        $this->actingAs($accountant, 'sanctum')->getJson("/api/v1/operations/{$operation->id}")->assertOk();

        $this->reject($expenseId)->assertOk();
        $this->assertEqualsWithDelta(100.0, $this->custody($this->bmA1), 0.001, 'rejection reverses the 30 deduction once');
        $this->assertSame(0, (int) $operation->fresh()->payload['custodyChargeHalalas'], 'dashboard mirror shows no posted charge after reversal');

        $this->actor($this->bmA1)->putJson("/api/branch-manager/expenses/quick-cash/{$expenseId}", [
            'expense_name' => 'Phase 4 corrected expense', 'total_amount' => 25,
            'net_amount' => 25, 'vat_amount' => 0, 'is_draft' => false,
            'items' => [['title' => 'Corrected Phase 4 item', 'amount' => 25]],
        ])->assertOk();
        $this->assertSame('rejected', Expense::findOrFail($expenseId)->status, 'editing keeps the prior rejection until explicit resubmission');
        $this->actor($this->bmA1)->post("/api/branch-manager/expenses/{$expenseId}/attachments", [
            'attachments' => [UploadedFile::fake()->create('corrected-expense-receipt.pdf', 15, 'application/pdf')],
        ], ['Accept' => 'application/json'])->assertOk();
        $this->assertSame(2, Expense::findOrFail($expenseId)->attachments()->count(), 'corrected evidence is attached to the same rejected request');
        $this->assertEqualsWithDelta(100.0, $this->custody($this->bmA1), 0.001, 'editing rejected data does not create a movement');

        $this->actor($this->bmA1)->postJson("/api/branch-manager/expenses/{$expenseId}/resubmit")
            ->assertOk();
        $this->assertEqualsWithDelta(75.0, $this->custody($this->bmA1), 0.001, 'resubmission deducts corrected 25 once');
        $this->assertSame(2500, (int) $operation->fresh()->payload['custodyChargeHalalas'], 'dashboard mirror shows 2,500 halalas');
        $this->approve($expenseId)->assertOk();
        $this->assertEqualsWithDelta(75.0, $this->custody($this->bmA1), 0.001, 'approval does not deduct again');
        $this->assertSame('final-approved', $operation->fresh()->status);
        $this->assertSame(2500, (int) $operation->fresh()->amount, 'dashboard operation amount stays in halalas');
        $this->assertSame(2500, (int) $operation->fresh()->payload['custodyChargeHalalas']);

        $balanceResponse = $this->actor($this->bmA1)->getJson('/api/branch-manager/ledger/branch-custody-balance')->assertOk();
        $this->assertSame('75.00', number_format((float) $balanceResponse->json('data.currentBalance'), 2, '.', ''));
        $this->assertSame(7500, (int) round((float) $balanceResponse->json('data.currentBalance') * 100), '75 SAR equals 7,500 halalas');
        $this->assertSame($this->branchA1->id, $balanceResponse->json('data.branch.id'));
        $this->assertCount(3, $this->movements($expenseId), 'initial charge, one reversal, and corrected charge are all auditable');
        $this->assertSame(['25.00', '30.00'], $this->outs($expenseId));
        $this->assertSame(['30.00'], $this->ins($expenseId), 'external cash recovery does not cause a second app credit');
        $this->assertSame($initialPersonalLedgerCount, PersonalLedgerTransaction::count(), 'sales cash and cashier drawer ledger are unchanged');

        $export = $this->actor($this->bmA1)->postJson('/api/branch-manager/ledger/export-pdf', ['view' => 'detailed'])->assertOk();
        $this->assertNotEmpty($export->json('data.file_url'));
        $this->assertTrue(Storage::disk('public')->exists($export->json('data.file_path')), 'export artifact is written in isolated test storage');
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

    public function test_branch_custody_statement_includes_the_branch_when_balance_is_zero(): void
    {
        $res = $this->actor($this->bmA1)->getJson('/api/branch-manager/ledger/branch-custody-balance')->assertOk();

        $this->assertSame($this->branchA1->id, $res->json('data.branch.id'));
        $this->assertSame($this->branchA1->name, $res->json('data.branch.name'));
        $this->assertEqualsWithDelta(0.0, (float) $res->json('data.currentBalance'), 0.001);
        $this->assertSame([], $res->json('data.transactions'));
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

    public function test_legacy_in_flight_custody_expense_without_submission_charge_is_not_approved(): void
    {
        $this->fund($this->bmA1, 1000);
        $this->salesCash($this->bmA1, 400);
        $legacy = $this->pendingExpense($this->bmA1, ['payment_method' => 'custody', 'total_amount' => 250.00]);

        $before = $this->ledgerSnapshot();
        $this->approve($legacy)->assertStatus(400);

        $this->assertEqualsWithDelta(1000.0, $this->custody($this->bmA1), 0.001, 'approval cannot create a missing submission movement');
        $this->assertCount(0, $this->movements($legacy->id));
        $this->assertEqualsWithDelta(400.0, $this->personal($this->bmA1), 0.001);
        $this->assertSame(0, PersonalLedgerTransaction::where('related_expense_id', $legacy->id)->count());
        $this->assertSame($before, $this->ledgerSnapshot());
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
