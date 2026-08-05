<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Expense\Models\Expense;
use Modules\Expense\Models\InvoiceDetail;
use Tests\TestCase;

/**
 * Meeting 2026-08-04 «Something went wrong with the response … type 'Null' is
 * not a subtype of type 'String' in type cast» on «Past Request» (single
 * invoices): the list emitted null for string fields the app casts with
 * `as String`, and a row whose invoice-detail record was missing threw a 500 for
 * the whole page. Same class of bug as the null-numerics crash on the supplier
 * resources.
 */
class ExpensePreviousListNullSafetyTest extends TestCase
{
    use RefreshDatabase;

    private BranchManager $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = BranchManager::factory()->create(['branch_id' => Branch::factory()->create()->id]);
    }

    private function expense(array $overrides = []): Expense
    {
        return Expense::create(array_merge([
            'branch_manager_id' => $this->manager->id,
            'expense_type' => 'single_invoice',
            'status' => 'draft',
            'total_amount' => 100,
            'net_amount' => 100,
            'vat_amount' => 0,
        ], $overrides));
    }

    /** Every string field is a string, even on a barely-filled draft. */
    public function test_the_previous_invoices_list_never_emits_a_null_string(): void
    {
        $expense = $this->expense();
        InvoiceDetail::create([
            'expense_id' => $expense->id,
            'invoice_number' => 'INV-1',
            // tax_id / payment_type / due_date deliberately absent — all
            // nullable columns the app casts as String.
            'issue_date' => now(),
            'is_tax_invoice' => false,
            'paid_amount' => 0,
        ]);

        $row = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/branch-manager/expenses/single-invoice/previous')
            ->assertSuccessful()
            ->json('data.0');

        foreach (['payment_method', 'created_at', 'submitted_at'] as $key) {
            $this->assertIsString($row[$key], "{$key} must be a string, not null");
        }
        foreach (['invoice_number', 'issue_date', 'tax_id', 'payment_type'] as $key) {
            $this->assertIsString($row['data'][$key], "data.{$key} must be a string, not null");
        }
    }

    /** A row with no invoice-detail record must not 500 the whole list. */
    public function test_an_expense_without_its_invoice_detail_does_not_break_the_list(): void
    {
        $this->expense();

        $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/branch-manager/expenses/single-invoice/previous')
            ->assertSuccessful()
            ->assertJsonPath('data.0.data.invoice_number', '');
    }

    public function test_the_pre_approval_previous_list_is_null_safe_too(): void
    {
        $this->expense(['expense_type' => 'pre_approval']);

        $row = $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/branch-manager/expenses/pre-approval/previous')
            ->assertSuccessful()
            ->json('data.0');

        $this->assertIsString($row['data']['purpose']);
        $this->assertIsString($row['data']['priority']['value']);
    }
}
