<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Expense\Events\ExpenseSubmittedEvent;
use Modules\Expense\Models\Expense;
use Modules\Expense\Models\Supplier;
use Modules\Expense\Services\ExpenseApprovalService;
use Tests\Concerns\BuildsExpenseTenantFixtures;
use Tests\TestCase;

/**
 * PKG-2 F-04 (as directed): an expense may be CAPTURED as an explicit draft with
 * an unresolved origin, but it may NOT enter the financially-accountable cycle
 * (pending / approved) unless its immutable origin branch+brand+company is
 * complete and consistent. The guard fires at EVERY real writer path — the four
 * create services, their draft->pending updates, submit, resubmit, approve —
 * before any write, event, timeline or financial side effect, and never silently
 * downgrades an invalid submission to a draft. Legacy null-origin rows stay
 * fail-closed; ownership is never guessed from the manager's current branch.
 *
 * These are HTTP-level negative controls for all four creation endpoints and the
 * transition paths.
 */
class ExpenseCreationOriginGuardTest extends TestCase
{
    use BuildsExpenseTenantFixtures;
    use RefreshDatabase;

    private BranchManager $manager;   // sits on a branch with NO brand/company link

    private Supplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->buildExpenseTenants();

        $branch = Branch::factory()->create(['asab_company_id' => null, 'asab_brand_id' => null, 'asab_restaurant_id' => null]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $branch->id]);
        $this->supplier = Supplier::factory()->create();
    }

    private function asManager(): self
    {
        $this->actingAs($this->manager, 'sanctum');

        return $this;
    }

    private function assertNoExpensePersisted(): void
    {
        $this->assertSame(0, Expense::where('branch_manager_id', $this->manager->id)->count(), 'no expense row may be persisted on a blocked submission');
    }

    private function assertBlocked($response): void
    {
        $response->assertStatus(422);
        // Both guard messages (creation and stored-origin) share this token.
        $this->assertStringContainsString('unresolved', (string) $response->json('message'));
    }

    // ---- the four creation writers (is_draft = false) ----------------------

    public function test_quick_cash_submission_is_blocked_with_no_write_or_event(): void
    {
        Event::fake();

        $res = $this->asManager()->postJson('/api/branch-manager/expenses/quick-cash', [
            'expense_date' => now()->toDateString(),
            'expense_name' => 'Taxi',
            'total_amount' => 100,
            'payment_method' => 'cash',
            'is_draft' => false,
        ]);

        $this->assertBlocked($res);
        $this->assertNoExpensePersisted();
        Event::assertNotDispatched(ExpenseSubmittedEvent::class);
    }

    public function test_single_invoice_submission_is_blocked(): void
    {
        Event::fake();

        $res = $this->asManager()->postJson('/api/branch-manager/expenses/single-invoice', [
            'supplier_id' => $this->supplier->id,
            'invoice_number' => 'INV-1',
            'total_amount' => 600,
            'issue_date' => now()->toDateString(),
            'is_tax_invoice' => false,
            'payment_type' => 'full',
            'payment_method' => 'cash',
            'is_draft' => false,
        ]);

        $this->assertBlocked($res);
        $this->assertNoExpensePersisted();
        Event::assertNotDispatched(ExpenseSubmittedEvent::class);
    }

    public function test_pre_approval_submission_is_blocked(): void
    {
        Event::fake();

        $res = $this->asManager()->postJson('/api/branch-manager/expenses/pre-approval', [
            'purpose' => 'Annual maintenance contract',
            'estimated_amount' => 600,
            'payment_method' => 'cash',
            'priority' => 'high',
            'is_draft' => false,
        ]);

        $this->assertBlocked($res);
        $this->assertNoExpensePersisted();
        Event::assertNotDispatched(ExpenseSubmittedEvent::class);
    }

    public function test_grouped_invoice_submission_is_blocked(): void
    {
        Event::fake();

        $res = $this->asManager()->postJson('/api/branch-manager/expenses/grouped-invoice', [
            'payment_type' => 'full',
            'payment_method' => 'cash',
            'invoices' => [[
                'supplier_id' => $this->supplier->id,
                'invoice_number' => 'G-1',
                'issue_date' => now()->toDateString(),
                'is_tax_invoice' => false,
            ]],
            'is_draft' => false,
        ]);

        $this->assertBlocked($res);
        $this->assertNoExpensePersisted();
        Event::assertNotDispatched(ExpenseSubmittedEvent::class);
    }

    // ---- explicit draft is still allowed (no silent auto-draft, but a real draft persists) ----

    public function test_explicit_draft_at_unbranded_branch_is_allowed(): void
    {
        $res = $this->asManager()->postJson('/api/branch-manager/expenses/quick-cash', [
            'expense_date' => now()->toDateString(),
            'expense_name' => 'Taxi',
            'total_amount' => 100,
            'payment_method' => 'cash',
            'is_draft' => true,
        ]);

        $res->assertStatus(201);
        $this->assertSame(1, Expense::where('branch_manager_id', $this->manager->id)->where('status', 'draft')->count());
        $this->assertSame(0, Expense::where('branch_manager_id', $this->manager->id)->where('status', 'pending')->count());
    }

    // ---- transition paths: draft->pending update, submit, resubmit, approve ----

    public function test_draft_to_pending_update_is_blocked(): void
    {
        // First capture a valid draft.
        $this->asManager()->postJson('/api/branch-manager/expenses/quick-cash', [
            'expense_date' => now()->toDateString(), 'expense_name' => 'Taxi', 'total_amount' => 100,
            'payment_method' => 'cash', 'is_draft' => true,
        ])->assertStatus(201);

        $draft = Expense::where('branch_manager_id', $this->manager->id)->firstOrFail();

        Event::fake();
        $res = $this->asManager()->putJson("/api/branch-manager/expenses/quick-cash/{$draft->id}", [
            'is_draft' => false,
        ]);

        $this->assertBlocked($res);
        $this->assertSame('draft', $draft->fresh()->status, 'the draft must not flip to pending');
        Event::assertNotDispatched(ExpenseSubmittedEvent::class);
    }

    public function test_submit_endpoint_blocks_an_unresolved_origin_draft(): void
    {
        $this->asManager()->postJson('/api/branch-manager/expenses/quick-cash', [
            'expense_date' => now()->toDateString(), 'expense_name' => 'Taxi', 'total_amount' => 100,
            'payment_method' => 'cash', 'is_draft' => true,
        ])->assertStatus(201);

        $draft = Expense::where('branch_manager_id', $this->manager->id)->firstOrFail();

        Event::fake();
        $res = $this->asManager()->postJson("/api/branch-manager/expenses/{$draft->id}/submit");

        $this->assertContains($res->status(), [400, 422], 'submit of an unresolved-origin draft must be refused');
        $this->assertSame('draft', $draft->fresh()->status);
        Event::assertNotDispatched(ExpenseSubmittedEvent::class);
    }

    public function test_resubmit_endpoint_blocks_a_legacy_unresolved_rejected_row(): void
    {
        // Legacy-style row: created as a draft (null origin), then moved to
        // rejected directly (simulating pre-guard data). Resubmission must fail.
        $expense = Expense::create([
            'branch_manager_id' => $this->manager->id, 'expense_type' => 'quick_cash', 'status' => 'draft',
            'total_amount' => 50, 'net_amount' => 50, 'vat_amount' => 0, 'payment_method' => 'cash',
        ]);
        $this->assertNull($expense->origin_asab_brand_id);
        $expense->update(['status' => 'rejected']);

        Event::fake();
        $res = $this->asManager()->postJson("/api/branch-manager/expenses/{$expense->id}/resubmit");

        $this->assertContains($res->status(), [400, 422]);
        $this->assertSame('rejected', $expense->fresh()->status);
        Event::assertNotDispatched(ExpenseSubmittedEvent::class);
    }

    public function test_approve_service_blocks_a_legacy_unresolved_pending_row(): void
    {
        // A legacy pending null-origin row (pre-guard). The approval service must
        // refuse to commit it (defense in depth; over HTTP a brand owner cannot
        // even reach it, since brand scope keys on the NULL origin brand).
        $expense = Expense::create([
            'branch_manager_id' => $this->manager->id, 'expense_type' => 'quick_cash', 'status' => 'draft',
            'total_amount' => 50, 'net_amount' => 50, 'vat_amount' => 0, 'payment_method' => 'cash',
        ]);
        $expense->update(['status' => 'pending']);

        $this->expectException(\Modules\Expense\Exceptions\UnresolvedExpenseOriginException::class);
        app(ExpenseApprovalService::class)->approveExpense($expense->fresh(), 'owner-id', 'Owner');
    }
}
