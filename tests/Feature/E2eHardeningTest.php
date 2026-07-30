<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\Operation;
use Modules\Admin\Services\PurchaseFeedbackBridgeService;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Expense\Models\Expense;
use Modules\Expense\Models\InvoiceDetail;
use Modules\Inventory\Enums\InventorySessionStatus;
use Modules\Inventory\Models\InventorySession;
use Modules\Inventory\Models\WasteDamageReport;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Models\Item;
use Modules\Purchase\Services\PurchaseOrderService;
use Modules\Supplier\Models\Supplier;
use Tests\TestCase;

/**
 * Regression net for the 2026-07-31 production E2E sweep: every case here was a
 * CONFIRMED live defect (see docs/tasks/MEETING-2026-07-30-FIXES.md).
 */
class E2eHardeningTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    private BranchManager $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $companyId = AsabCompany::create(['name' => 'Co', 'plan' => 'Basic', 'status' => 'active'])->id;
        $brand = AsabBrand::create([
            'company_id' => $companyId, 'name' => 'برجر بيت', 'abbr' => 'BB',
            'sub_status' => 'active', 'status' => 'active',
        ]);
        $this->branch = Branch::factory()->create([
            'asab_company_id' => $companyId,
            'asab_brand_id' => $brand->id,
        ]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
    }

    private function pendingSession(?string $branchId = null): InventorySession
    {
        return InventorySession::create([
            'session_number' => 'INV-TEST-'.uniqid(),
            'branch_id' => $branchId ?? $this->branch->id,
            'created_by' => $this->manager->id,
            'created_by_type' => 'branch_manager',
            'assigned_to_type' => 'personal',
            'inventory_date' => today(),
            'status' => InventorySessionStatus::PENDING,
            'submitted_at' => now(),
        ]);
    }

    public function test_foreign_branch_manager_cannot_approve_or_reject_a_session(): void
    {
        $session = $this->pendingSession();

        $foreignManager = BranchManager::factory()->create([
            'branch_id' => Branch::factory()->create()->id,
        ]);

        $this->actingAs($foreignManager, 'sanctum')
            ->postJson("/api/v1/inventory/daily-quick/sessions/{$session->id}/approve", ['sales' => []])
            ->assertStatus(404);

        $this->actingAs($foreignManager, 'sanctum')
            ->postJson("/api/v1/inventory/daily-quick/sessions/{$session->id}/reject", ['comment' => 'not mine'])
            ->assertStatus(404);

        $this->assertSame(InventorySessionStatus::PENDING, $session->fresh()->status);
    }

    public function test_cashier_cannot_approve_a_session_but_own_manager_can(): void
    {
        $session = $this->pendingSession();

        $cashier = Cashier::factory()->create(['branch_id' => $this->branch->id]);
        $this->actingAs($cashier, 'sanctum')
            ->postJson("/api/v1/inventory/daily-quick/sessions/{$session->id}/approve", ['sales' => []])
            ->assertStatus(403);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/v1/inventory/daily-quick/sessions/{$session->id}/approve", ['sales' => []])
            ->assertStatus(200);
    }

    public function test_bm_waste_damage_queue_survives_legacy_confirmation_rows(): void
    {
        // Rows written while the 2026-02-25 status split was live keep this
        // value — the missing enum case 500'd the whole BM work queue.
        WasteDamageReport::factory()->create([
            'branch_id' => $this->branch->id,
            'created_by' => $this->manager->id,
            'status' => 'pending_your_confirmation',
            'submitted_at' => now(),
        ]);

        $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/branch-manager/inventory/waste-damage-requests')
            ->assertStatus(200);

        $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/branch-manager/inventory/waste-damage-requests?status=pending')
            ->assertStatus(200);
    }

    public function test_rejected_purchase_order_is_resubmittable_via_submit(): void
    {
        $supplier = Supplier::create([
            'name' => 'مورد عصب', 'email' => 'supplier@hardening.test',
            'password' => 'irrelevant-password', 'is_active' => true,
        ]);
        $item = Item::create(['name' => 'بيتزا', 'unit' => 'kg', 'is_active' => true]);

        $this->actingAs($this->manager, 'sanctum');
        $order = app(PurchaseOrderService::class)->createOrder([
            'order_type' => OrderType::DIRECT_SUPPLIER->value,
            'branch_id' => $this->branch->id,
            'requested_by' => $this->manager->id,
            'supplier_id' => $supplier->id,
            'items' => [['item_id' => $item->id, 'quantity' => 3, 'unit' => 'kg', 'unit_price' => 50]],
        ]);

        $op = Operation::withoutGlobalScopes()
            ->where('source_module', 'purchase')->where('source_id', $order->id)->firstOrFail();
        $op->update(['status' => Operation::STATUS_REJECTED, 'reject_reason' => 'تناقض في المبالغ']);
        app(PurchaseFeedbackBridgeService::class)->syncFromOperation($op->fresh());

        $order->refresh();
        $this->assertSame(OrderStatus::REJECTED, $order->status);
        $this->assertNotNull($order->rejected_at, 'dashboard rejection must stamp rejected_at');

        // The E2E dead-end: submit() only accepted DRAFT, so the edit+resend
        // loop was unreachable from any HTTP route.
        $this->assertTrue($order->submit());
        $order->refresh();
        $this->assertSame(OrderStatus::PENDING, $order->status);
        $this->assertNull($order->rejection_reason);
        $this->assertNull($order->rejected_at);
    }

    public function test_non_tax_invoice_bridges_with_its_real_amount(): void
    {
        $expense = Expense::create([
            'branch_manager_id' => $this->manager->id,
            'expense_type' => 'single_invoice',
            'status' => 'pending',
            'submitted_at' => now(),
            'total_amount' => 690,
            'net_amount' => 600,
            'vat_amount' => 90,
            'payment_method' => 'cash',
        ]);
        InvoiceDetail::create([
            'expense_id' => $expense->id,
            'invoice_number' => 'NONTAX-1',
            'issue_date' => now()->toDateString(),
            'is_tax_invoice' => false,
            'payment_type' => 'full',
            'paid_amount' => 690,
        ]);

        $op = app(\Modules\Admin\Services\ExpenseBridgeService::class)->sync($expense);

        $this->assertNotNull($op);
        // Live defect: amountHalalas mapped only tax_total_amount, so every
        // non-tax invoice showed 0.00 SAR in the accountant's invoice table.
        $this->assertSame(69000, $op->payload['invoices'][0]['amountHalalas']);
        $this->assertSame(9000, $op->payload['invoices'][0]['vatHalalas']);
    }

    public function test_session_time_taken_is_positive(): void
    {
        $session = $this->pendingSession();
        $session->start_time = now()->subHours(2);
        $session->complete();

        $this->assertGreaterThan(0, $session->fresh()->time_taken, 'Carbon 3 signed diff must not persist negative durations');
    }
}
