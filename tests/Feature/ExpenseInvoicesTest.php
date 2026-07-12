<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Attachment;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\OperationSequence;
use Modules\Branch\Models\Branch;
use Tests\TestCase;

/**
 * T05.1–T05.3 — the expenses statement: the server-side `invoices[]` contract,
 * the 15% VAT split, per-invoice توثيق and the matched/mismatch/missing badge.
 */
class ExpenseInvoicesTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branch;

    private AsabUser $accountant;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Exp Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'براند', 'sub_status' => 'active', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['name' => 'فرع', 'asab_brand_id' => $brand->id, 'asab_company_id' => $this->company->id]);

        $this->accountant = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'محاسب', 'email' => 'acc@exp.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->accountant->id, 'role_key' => 'accountant', 'scope' => 'all']);
    }

    private function acc()
    {
        return $this->actingAs($this->accountant, 'sanctum');
    }

    /** @param  array<int, array<string,mixed>>  $invoices */
    private function statement(array $invoices, array $attrs = []): Operation
    {
        $op = Operation::create(array_merge([
            'public_id' => OperationSequence::next('EXP'),
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
            'module_key' => 'expenses',
            'payload' => ['invoices' => $invoices],
            'amount' => array_sum(array_column($invoices, 'amountHalalas')),
            'match' => 'exact',
            'origin' => 'mobile',
            'status' => Operation::STATUS_PENDING,
            'operation_date' => now(),
        ], $attrs));

        return $op;
    }

    private function invoice(array $overrides = []): array
    {
        return array_merge([
            'invNum' => 'INV-1', 'vendor' => 'مورد', 'desc' => 'بند',
            'date' => now()->toDateString(), 'amountHalalas' => 11500,
        ], $overrides);
    }

    private function attach(Operation $op, ?string $label = null): Attachment
    {
        return Attachment::create([
            'owner_type' => 'operation', 'owner_id' => $op->id, 'filename' => 'inv.jpg',
            'mime_type' => 'image/jpeg', 'size' => 1024, 'storage_key' => 'x/inv.jpg',
            'public_url' => 'https://cdn.test/inv.jpg', 'label' => $label, 'uploaded_at' => now(),
        ]);
    }

    // ── T05.1 VAT contract ───────────────────────────────────────────────────

    public function test_the_vat_split_is_exact_and_self_consistent(): void
    {
        $op = $this->statement([$this->invoice(['amountHalalas' => 11500])]);

        $invoice = $this->acc()->getJson("/api/v1/company/me/operations/{$op->id}")
            ->assertOk()->json('expenses.invoices.0');

        $this->assertSame(10000, $invoice['preTaxHalalas']);
        $this->assertSame(1500, $invoice['vat15Halalas']);
        $this->assertSame(11500, $invoice['inclTaxHalalas']);
        $this->assertSame($invoice['inclTaxHalalas'], $invoice['preTaxHalalas'] + $invoice['vat15Halalas']);
    }

    public function test_an_invoice_carrying_its_own_vat_line_is_not_re_derived(): void
    {
        // A zero-rated invoice must not sprout 15% VAT on the way through.
        $op = $this->statement([$this->invoice(['amountHalalas' => 20000, 'vatHalalas' => 0])]);

        $invoice = $this->acc()->getJson("/api/v1/company/me/operations/{$op->id}")
            ->assertOk()->json('expenses.invoices.0');

        $this->assertSame(0, $invoice['vat15Halalas']);
        $this->assertSame(20000, $invoice['preTaxHalalas']);
    }

    public function test_statement_totals_sum_every_invoice(): void
    {
        $op = $this->statement([
            $this->invoice(['amountHalalas' => 11500]),
            $this->invoice(['invNum' => 'INV-2', 'amountHalalas' => 23000]),
        ]);

        $totals = $this->acc()->getJson("/api/v1/company/me/operations/{$op->id}")
            ->assertOk()->json('expenses.totals');

        $this->assertSame(2, $totals['invoiceCount']);
        $this->assertSame(30000, $totals['preTaxHalalas']);
        $this->assertSame(4500, $totals['vat15Halalas']);
        $this->assertSame(34500, $totals['inclTaxHalalas']);
    }

    public function test_a_legacy_single_invoice_payload_still_renders(): void
    {
        // Pre-T05 statements smeared one invoice across the payload root.
        $op = $this->statement([], [
            'payload' => ['invNum' => 'OLD-1', 'vendor' => 'مورد قديم', 'verified' => true],
            'amount' => 11500,
        ]);

        $block = $this->acc()->getJson("/api/v1/company/me/operations/{$op->id}")->assertOk()->json('expenses');

        $this->assertCount(1, $block['invoices']);
        $this->assertSame('OLD-1', $block['invoices'][0]['invNum']);
        $this->assertSame(10000, $block['invoices'][0]['preTaxHalalas']);
        $this->assertTrue($block['allVerified']);
    }

    // ── T05.2 per-invoice توثيق ──────────────────────────────────────────────

    public function test_verifying_one_invoice_leaves_the_other_untouched(): void
    {
        $op = $this->statement([$this->invoice(), $this->invoice(['invNum' => 'INV-2'])]);

        $body = $this->acc()->postJson("/api/v1/company/me/expense-invoices/{$op->id}/verify", ['invoiceIndex' => 0])
            ->assertOk()->json();

        $this->assertTrue($body['verified']);
        $this->assertFalse($body['allVerified']);
        $this->assertSame(1, $body['verifiedCount']);

        $invoices = $this->acc()->getJson("/api/v1/company/me/operations/{$op->id}")->assertOk()->json('expenses.invoices');
        $this->assertTrue($invoices[0]['verified']);
        $this->assertFalse($invoices[1]['verified']);
    }

    public function test_verifying_every_invoice_raises_the_all_verified_badge(): void
    {
        $op = $this->statement([$this->invoice(), $this->invoice(['invNum' => 'INV-2'])]);

        $this->acc()->postJson("/api/v1/company/me/expense-invoices/{$op->id}/verify", ['invoiceIndex' => 0])->assertOk();
        $body = $this->acc()->postJson("/api/v1/company/me/expense-invoices/{$op->id}/verify", ['invoiceIndex' => 1])
            ->assertOk()->json();

        $this->assertTrue($body['allVerified']);
        $this->assertSame('✅ كل الفواتير موثّقة', $body['allVerifiedBadgeAr']);
    }

    public function test_unverify_reverses_a_single_invoice(): void
    {
        $op = $this->statement([$this->invoice(), $this->invoice(['invNum' => 'INV-2'])]);
        $this->acc()->postJson("/api/v1/company/me/expense-invoices/{$op->id}/verify", ['invoiceIndex' => 0])->assertOk();
        $this->acc()->postJson("/api/v1/company/me/expense-invoices/{$op->id}/verify", ['invoiceIndex' => 1])->assertOk();

        $this->acc()->deleteJson("/api/v1/company/me/expense-invoices/{$op->id}/verify", ['invoiceIndex' => 1])
            ->assertOk()
            ->assertJsonPath('verified', false)
            ->assertJsonPath('allVerified', false)
            ->assertJsonPath('verifiedCount', 1);
    }

    public function test_an_out_of_range_invoice_index_is_refused(): void
    {
        $op = $this->statement([$this->invoice()]);

        $this->acc()->postJson("/api/v1/company/me/expense-invoices/{$op->id}/verify", ['invoiceIndex' => 3])
            ->assertStatus(422)
            ->assertJsonPath('error.code', 'INVOICE_INDEX_OUT_OF_RANGE');
    }

    public function test_a_final_approved_statement_cannot_be_verified(): void
    {
        $op = $this->statement([$this->invoice()], ['status' => Operation::STATUS_FINAL]);

        $this->acc()->postJson("/api/v1/company/me/expense-invoices/{$op->id}/verify")
            ->assertStatus(409)
            ->assertJsonPath('error.code', 'OP_ALREADY_FINAL');
    }

    public function test_a_statement_of_another_company_is_invisible(): void
    {
        $other = AsabCompany::create(['name' => 'Other', 'plan' => 'Basic', 'status' => 'active']);
        $otherBranch = Branch::factory()->create(['name' => 'فرع آخر', 'asab_company_id' => $other->id]);
        $op = Operation::create([
            'public_id' => OperationSequence::next('EXP'), 'company_id' => $other->id, 'branch_id' => $otherBranch->id,
            'module_key' => 'expenses', 'payload' => ['invoices' => [$this->invoice()]], 'amount' => 11500,
            'match' => 'exact', 'origin' => 'mobile', 'status' => Operation::STATUS_PENDING, 'operation_date' => now(),
        ]);

        $this->acc()->postJson("/api/v1/company/me/expense-invoices/{$op->id}/verify")->assertStatus(404);
    }

    // ── T05.3 match status ───────────────────────────────────────────────────

    public function test_an_invoice_without_documents_is_missing(): void
    {
        $op = $this->statement([$this->invoice(), $this->invoice(['invNum' => 'INV-2'])]);

        $invoices = $this->acc()->getJson("/api/v1/company/me/operations/{$op->id}")->assertOk()->json('expenses.invoices');

        $this->assertSame('missing', $invoices[0]['matchStatus']);
        $this->assertSame('مفقودة', $invoices[0]['matchLabelAr']);
    }

    public function test_a_documented_invoice_is_matched_and_a_disagreeing_one_is_mismatch(): void
    {
        $op = $this->statement([
            $this->invoice(),
            $this->invoice(['invNum' => 'INV-2', 'documentAmountHalalas' => 31500]),
            $this->invoice(['invNum' => 'INV-3']),
        ]);
        $this->attach($op, 'invoice:0:receipt');
        $this->attach($op, 'invoice:1:receipt');

        $block = $this->acc()->getJson("/api/v1/company/me/operations/{$op->id}")->assertOk()->json('expenses');

        $this->assertSame('matched', $block['invoices'][0]['matchStatus']);
        $this->assertSame('mismatch', $block['invoices'][1]['matchStatus']);
        $this->assertSame('missing', $block['invoices'][2]['matchStatus']);
        $this->assertSame(20000, $block['invoices'][1]['deltaHalalas']);
        $this->assertSame('⚠ فرق: 200.00 ر.س عن الفاتورة الأصلية', $block['invoices'][1]['deltaNoteAr']);
        $this->assertSame(['matched' => 1, 'mismatch' => 1, 'missing' => 1], $block['matchSummary']);
    }

    public function test_the_review_modal_records_the_document_and_re_derives_the_badge(): void
    {
        $op = $this->statement([$this->invoice()]);
        $this->attach($op);   // single-invoice statement: unlabelled doc belongs to invoice 0

        $body = $this->acc()->patchJson("/api/v1/company/me/expense-invoices/{$op->id}/invoices/0", [
            'documentAmountHalalas' => 12000, 'documentVendor' => 'المورد الحقيقي',
        ])->assertOk()->json();

        $this->assertSame('mismatch', $body['invoice']['matchStatus']);
        $this->assertSame(500, $body['deltaHalalas']);
        $amountRow = collect($body['rows'])->firstWhere('field', 'amountHalalas');
        $this->assertFalse($amountRow['matches']);
        $this->assertSame(12000, $amountRow['document']);
        $this->assertSame(11500, $amountRow['entered']);
    }

    public function test_the_kpi_endpoint_splits_pending_invoices_by_match(): void
    {
        $op = $this->statement([
            $this->invoice(),
            $this->invoice(['invNum' => 'INV-2', 'documentAmountHalalas' => 999]),
            $this->invoice(['invNum' => 'INV-3']),
        ]);
        $this->attach($op, 'invoice:0:receipt');
        $this->attach($op, 'invoice:1:receipt');
        // An approved statement must not pollute the pending split.
        $this->statement([$this->invoice()], ['status' => Operation::STATUS_APPROVED]);

        $kpis = $this->acc()->getJson('/api/v1/company/me/expenses/kpis')->assertOk()->json();

        $this->assertSame(2, $kpis['statementCount']);
        $this->assertSame(4, $kpis['invoiceCount']);
        $this->assertSame(['total' => 3, 'matched' => 1, 'mismatch' => 1, 'missing' => 1], $kpis['pendingInvoices']);
        $this->assertSame(0, $kpis['verifiedInvoiceCount']);
    }

    // ── Attachments panel ────────────────────────────────────────────────────

    public function test_attachments_are_grouped_per_invoice(): void
    {
        $op = $this->statement([$this->invoice(), $this->invoice(['invNum' => 'INV-2'])]);
        $this->attach($op, 'invoice:1:stamp');
        $this->attach($op, null);   // statement-level

        $groups = $this->acc()->getJson("/api/v1/company/me/expense-invoices/{$op->id}/attachments")
            ->assertOk()->json('data');

        $byIndex = collect($groups)->keyBy(fn ($g) => $g['invoiceIndex'] ?? 'statement');
        $this->assertCount(0, $byIndex[0]['attachments']);
        $this->assertCount(1, $byIndex[1]['attachments']);
        $this->assertCount(1, $byIndex['statement']['attachments']);

        $this->acc()->getJson("/api/v1/company/me/expense-invoices/{$op->id}/attachments?invoiceIndex=1")
            ->assertOk()->assertJsonCount(1, 'data');
    }
}
