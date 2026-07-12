<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Admin\Models\ApprovalStep;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabSupplier;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\SupplierItem;
use Modules\Admin\Services\OperationSequence;
use Modules\Branch\Models\Branch;
use Modules\Purchase\Enums\OrderItemStatus;
use Modules\Purchase\Enums\OrderStatus;
use Modules\Purchase\Enums\OrderType;
use Modules\Purchase\Enums\ReturnRequiredAction;
use Modules\Purchase\Enums\ReturnStatus;
use Modules\Purchase\Models\PurchaseOrder;
use Modules\Purchase\Models\PurchaseOrderItem;
use Modules\Purchase\Models\ReturnOrder;
use Tests\TestCase;

/**
 * T06 — Purchases (Accountant 3-way match). Covers the flow (§5) and the
 * match-engine edge cases E1–E14: the two live legs (ordered↔received quantity,
 * ordered↔invoice unit price) and the reserved third, per the «ثنائية + سعر،
 * مُنمذَجة لثلاثية» decision.
 */
class PurchasesAccountantTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private AsabBrand $brand;

    private Branch $branchA;

    private Branch $branchB;

    private AsabUser $accountant;

    private AsabSupplier $supplier;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Purch Co', 'plan' => 'Professional', 'status' => 'active']);
        $this->brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'براند', 'sub_status' => 'active', 'status' => 'active']);
        $this->branchA = Branch::factory()->create(['name' => 'فرع أ', 'asab_brand_id' => $this->brand->id, 'asab_company_id' => $this->company->id]);
        $this->branchB = Branch::factory()->create(['name' => 'فرع ب', 'asab_brand_id' => $this->brand->id, 'asab_company_id' => $this->company->id]);

        $this->accountant = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'محاسب', 'email' => 'acc@purch.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $this->accountant->id, 'role_key' => 'accountant', 'scope' => 'all']);

        $this->supplier = AsabSupplier::create([
            'company_id' => $this->company->id, 'name' => 'مورد الخضار', 'category' => 'خضروات',
            'rating' => 45, 'status' => 'active',
        ]);
    }

    private function acc()
    {
        return $this->actingAs($this->accountant, 'sanctum');
    }

    /**
     * A procurement-shaped purchases op. Items: [{itemId, qty, unitPriceHalalas}].
     */
    private function purchaseOp(array $items, array $attrs = []): Operation
    {
        return Operation::create(array_merge([
            'public_id' => OperationSequence::next('PUR'),
            'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id,
            'module_key' => 'purchases',
            'amount' => array_sum(array_map(fn ($i) => (int) round(($i['qty'] ?? 0) * ($i['unitPriceHalalas'] ?? 0)), $items)),
            'match' => 'review',
            'origin' => 'procurement',
            'status' => Operation::STATUS_PENDING,
            'submitted_at' => now(),
            'operation_date' => now(),
            'payload' => array_merge([
                'supplierId' => $this->supplier->id,
                'items' => $items,
                'deliveryDate' => now()->addDay()->toDateString(),
                'urgency' => 'normal',
                'origin' => 'procurement',
            ], $attrs['payload'] ?? []),
        ], array_diff_key($attrs, ['payload' => null])));
    }

    private function line(string $itemId, float $qty, int $unitPrice, string $item = 'طماطم'): array
    {
        return ['itemId' => $itemId, 'item' => $item, 'unit' => 'كجم', 'qty' => $qty, 'unitPriceHalalas' => $unitPrice];
    }

    // ── Detail / match engine ────────────────────────────────────────────────

    public function test_detail_returns_the_three_way_match_table(): void
    {
        $op = $this->purchaseOp([$this->line('itm-1', 10, 500)]);

        $body = $this->acc()->getJson("/api/v1/company/me/operations/{$op->id}")->assertOk()->json();

        $this->assertSame('مورد الخضار', $body['purchases']['supplierName']);
        $this->assertCount(1, $body['purchases']['purchaseItems']);
        $this->assertSame(5000, $body['purchases']['purchaseItems'][0]['totalHalalas']);
        $this->assertSame('procurement', $body['purchases']['orderSource']['key']);
    }

    /** E1 — a line with no received qty is `pending`, NOT a shortfall. */
    public function test_e1_unreceived_line_is_pending_not_a_shortfall(): void
    {
        $op = $this->purchaseOp([$this->line('itm-1', 10, 500)]);

        $line = $this->acc()->getJson("/api/v1/company/me/operations/{$op->id}")
            ->assertOk()->json('purchases.purchaseItems.0');

        $this->assertNull($line['rcvQty']);
        $this->assertNull($line['diffQty']);
        $this->assertSame('pending', $line['lineMatch']['key']);
        $this->assertFalse($this->acc()->getJson("/api/v1/company/me/operations/{$op->id}")->json('purchases.summary.isMatched'));
    }

    /** Received == ordered and price untouched → matched, op.match = exact. */
    public function test_matched_line_marks_the_operation_exact(): void
    {
        $op = $this->purchaseOp([$this->line('itm-1', 10, 500)]);

        $this->acc()->patchJson("/api/v1/company/me/operations/{$op->id}/purchase-lines/itm-1", ['rcvQty' => 10])
            ->assertOk()->assertJsonPath('match', 'exact');

        $line = $this->acc()->getJson("/api/v1/company/me/operations/{$op->id}")->json('purchases.purchaseItems.0');
        $this->assertSame('matched', $line['lineMatch']['key']);
        $this->assertSame(0.0, (float) $line['diffQty']);
    }

    /** E3 — received > ordered (over-delivery) → diff, positive diffQty. */
    public function test_e3_over_delivery_is_a_positive_diff(): void
    {
        $op = $this->purchaseOp([$this->line('itm-1', 10, 500)]);

        $this->acc()->patchJson("/api/v1/company/me/operations/{$op->id}/purchase-lines/itm-1", ['rcvQty' => 12])
            ->assertOk()->assertJsonPath('match', 'diff');

        $this->assertSame(2.0, (float) $this->acc()->getJson("/api/v1/company/me/operations/{$op->id}")
            ->json('purchases.purchaseItems.0.diffQty'));
    }

    /** E4 — partial receipt → diff, negative diffQty. */
    public function test_e4_partial_receipt_is_a_negative_diff(): void
    {
        $op = $this->purchaseOp([$this->line('itm-1', 10, 500)]);

        $this->acc()->patchJson("/api/v1/company/me/operations/{$op->id}/purchase-lines/itm-1", ['rcvQty' => 7])
            ->assertOk()->assertJsonPath('match', 'diff');

        $this->assertSame(-3.0, (float) $this->acc()->getJson("/api/v1/company/me/operations/{$op->id}")
            ->json('purchases.purchaseItems.0.diffQty'));
    }

    /** E5 — decimal quantities compare with tolerance, not === . */
    public function test_e5_decimal_quantities_match_within_tolerance(): void
    {
        $op = $this->purchaseOp([$this->line('itm-1', 2.5, 800)]);

        $this->acc()->patchJson("/api/v1/company/me/operations/{$op->id}/purchase-lines/itm-1", ['rcvQty' => 2.5])
            ->assertOk()->assertJsonPath('match', 'exact');
    }

    /** E6 — the decision: quantity matches but the invoice price diverges → diff. */
    public function test_e6_price_divergence_flips_match_even_when_quantity_agrees(): void
    {
        $op = $this->purchaseOp([$this->line('itm-1', 10, 500)]);
        // Receive exactly what was ordered — quantity leg is clean.
        $this->acc()->patchJson("/api/v1/company/me/operations/{$op->id}/purchase-lines/itm-1", ['rcvQty' => 10])
            ->assertOk()->assertJsonPath('match', 'exact');

        // Now the supplier invoice shows a higher unit price.
        $this->acc()->patchJson("/api/v1/company/me/operations/{$op->id}/purchase-lines/itm-1", ['unitPriceHalalas' => 550])
            ->assertOk()->assertJsonPath('match', 'diff');

        $line = $this->acc()->getJson("/api/v1/company/me/operations/{$op->id}")->json('purchases.purchaseItems.0');
        $this->assertFalse($line['priceMatched']);
        $this->assertTrue($line['qtyMatched']);
    }

    /** E9 — the badge is bidirectional: fixing the line returns it to exact. */
    public function test_e9_match_recomputes_both_directions(): void
    {
        $op = $this->purchaseOp([$this->line('itm-1', 10, 500)]);
        $this->acc()->patchJson("/api/v1/company/me/operations/{$op->id}/purchase-lines/itm-1", ['rcvQty' => 7])
            ->assertJsonPath('match', 'diff');
        $this->assertNotNull($op->fresh()->diff_note);

        $this->acc()->patchJson("/api/v1/company/me/operations/{$op->id}/purchase-lines/itm-1", ['rcvQty' => 10])
            ->assertOk()->assertJsonPath('match', 'exact');
        $this->assertNull($op->fresh()->diff_note);
    }

    /** E11 — the operation amount always equals the sum of its line totals. */
    public function test_e11_amount_stays_consistent_with_line_totals(): void
    {
        $op = $this->purchaseOp([$this->line('itm-1', 10, 500), $this->line('itm-2', 4, 250, 'خيار')]);

        $this->acc()->patchJson("/api/v1/company/me/operations/{$op->id}/purchase-lines/itm-1", ['unitPriceHalalas' => 600]);

        // 10×600 + 4×250 = 7000.
        $this->assertSame(7000, $op->fresh()->amount);
    }

    /** E8 — a locked (final-approved) op refuses line edits and documentation. */
    public function test_e8_locked_operation_refuses_edits(): void
    {
        $op = $this->purchaseOp([$this->line('itm-1', 10, 500)], ['status' => Operation::STATUS_FINAL]);

        $this->acc()->patchJson("/api/v1/company/me/operations/{$op->id}/purchase-lines/itm-1", ['rcvQty' => 9])
            ->assertStatus(409)->assertJsonPath('error.code', 'OP_ALREADY_FINAL');

        $this->acc()->postJson("/api/v1/company/me/operations/{$op->id}/document")
            ->assertStatus(409)->assertJsonPath('error.code', 'OP_ALREADY_FINAL');
    }

    public function test_line_edit_unknown_row_is_404(): void
    {
        $op = $this->purchaseOp([$this->line('itm-1', 10, 500)]);

        $this->acc()->patchJson("/api/v1/company/me/operations/{$op->id}/purchase-lines/ghost", ['rcvQty' => 1])
            ->assertStatus(404);
    }

    public function test_line_edit_on_a_non_purchase_op_is_404(): void
    {
        $sales = Operation::create([
            'public_id' => OperationSequence::next('OPS'), 'company_id' => $this->company->id,
            'branch_id' => $this->branchA->id, 'module_key' => 'sales', 'amount' => 1000, 'match' => 'exact',
            'origin' => 'mobile', 'status' => Operation::STATUS_PENDING, 'operation_date' => now(),
        ]);

        $this->acc()->patchJson("/api/v1/company/me/operations/{$sales->id}/purchase-lines/itm-1", ['rcvQty' => 1])
            ->assertStatus(404);
    }

    // ── E7 legacy received-qty bridge (tenant-guarded) ───────────────────────

    public function test_e7_legacy_bridge_hydrates_received_qty_in_scope(): void
    {
        $order = PurchaseOrder::factory()->create([
            'order_number' => 'PO-'.strtoupper(Str::random(6)), 'order_type' => OrderType::DIRECT_SUPPLIER,
            'status' => OrderStatus::PENDING, 'branch_id' => $this->branchA->id, 'submitted_at' => now(),
        ]);
        PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $order->id, 'item_id' => 'itm-1', 'status' => OrderItemStatus::PENDING,
            'quantity_ordered' => 10, 'quantity_received' => 8,
        ]);

        $op = $this->purchaseOp([$this->line('itm-1', 10, 500)], [
            'source_module' => 'purchase', 'source_id' => $order->id,
        ]);

        $line = $this->acc()->getJson("/api/v1/company/me/operations/{$op->id}")->json('purchases.purchaseItems.0');
        $this->assertSame(8.0, (float) $line['rcvQty']);
        $this->assertSame(-2.0, (float) $line['diffQty']);
        $this->assertSame('diff', $line['lineMatch']['key']);
    }

    public function test_e7_legacy_bridge_will_not_cross_company_boundaries(): void
    {
        $foreignCompany = AsabCompany::create(['name' => 'Other Co', 'plan' => 'Basic', 'status' => 'active']);
        $foreignBranch = Branch::factory()->create(['asab_company_id' => $foreignCompany->id]);
        $order = PurchaseOrder::factory()->create([
            'order_number' => 'PO-'.strtoupper(Str::random(6)), 'order_type' => OrderType::DIRECT_SUPPLIER,
            'status' => OrderStatus::PENDING, 'branch_id' => $foreignBranch->id, 'submitted_at' => now(),
        ]);
        PurchaseOrderItem::factory()->create([
            'purchase_order_id' => $order->id, 'item_id' => 'itm-1', 'quantity_ordered' => 10, 'quantity_received' => 8,
        ]);

        // The ASAB op is in our tenant but its (spoofed) legacy link points at a
        // foreign branch's order — the received qty must NOT hydrate.
        $op = $this->purchaseOp([$this->line('itm-1', 10, 500)], [
            'source_module' => 'purchase', 'source_id' => $order->id,
        ]);

        $line = $this->acc()->getJson("/api/v1/company/me/operations/{$op->id}")->json('purchases.purchaseItems.0');
        $this->assertNull($line['rcvQty']);
        $this->assertSame('pending', $line['lineMatch']['key']);
    }

    // ── توثيق (document) ─────────────────────────────────────────────────────

    public function test_document_stamps_the_order_and_writes_an_audit_step(): void
    {
        $op = $this->purchaseOp([$this->line('itm-1', 10, 500)]);

        $this->acc()->postJson("/api/v1/company/me/operations/{$op->id}/document", ['note' => 'طوبقت'])
            ->assertOk()->assertJsonPath('status', Operation::STATUS_PENDING);

        $this->assertTrue($this->acc()->getJson("/api/v1/company/me/operations/{$op->id}")->json('purchases.isDocumented'));
        $this->assertSame(1, ApprovalStep::where('operation_id', $op->id)->where('stage_id', 'review')->count());
    }

    public function test_document_is_idempotent(): void
    {
        $op = $this->purchaseOp([$this->line('itm-1', 10, 500)]);

        $this->acc()->postJson("/api/v1/company/me/operations/{$op->id}/document")->assertOk();
        $first = $op->fresh()->payload['documentation']['documentedAt'];

        $this->acc()->postJson("/api/v1/company/me/operations/{$op->id}/document")->assertOk();
        // Status unchanged; still documented (timestamp refreshed, never duplicated flag).
        $this->assertTrue($op->fresh()->payload['documentation']['documentedAt'] >= $first);
        $this->assertSame(Operation::STATUS_PENDING, $op->fresh()->status);
    }

    // ── List rows / KPI / filters ────────────────────────────────────────────

    public function test_purchases_rows_carry_supplier_and_diff_flags(): void
    {
        $op = $this->purchaseOp([$this->line('itm-1', 10, 500)], ['match' => 'diff', 'diff_note' => 'فرق']);

        $row = collect($this->acc()->getJson('/api/v1/company/me/operations?moduleKey=purchases')->assertOk()->json('data'))
            ->firstWhere('id', $op->id);

        $this->assertSame('مورد الخضار', $row['purchaseRow']['supplierName']);
        $this->assertSame(1, $row['purchaseRow']['itemCount']);
        $this->assertNotNull($row['purchaseRow']['receiveDate']);
    }

    public function test_supplier_and_source_filters_narrow_the_list(): void
    {
        $mine = $this->purchaseOp([$this->line('itm-1', 10, 500)]);
        $branchReq = $this->purchaseOp([$this->line('itm-2', 2, 300)], [
            'payload' => ['item' => 'ملح', 'qty' => 2, 'unit' => 'كجم', 'kind' => 'branch_request'],
        ]);

        $bySupplier = $this->acc()->getJson('/api/v1/company/me/operations?moduleKey=purchases&supplierId='.$this->supplier->id)
            ->assertOk()->json('data');
        $this->assertContains($mine->id, array_column($bySupplier, 'id'));

        $byBranch = $this->acc()->getJson('/api/v1/company/me/operations?moduleKey=purchases&source=branch')
            ->assertOk()->json('data');
        $ids = array_column($byBranch, 'id');
        $this->assertContains($branchReq->id, $ids);
        $this->assertNotContains($mine->id, $ids);
    }

    public function test_purchases_kpi_block_counts_today_and_discrepancies(): void
    {
        $this->purchaseOp([$this->line('itm-1', 10, 500)], ['status' => Operation::STATUS_APPROVED, 'amount' => 5000]);
        $this->purchaseOp([$this->line('itm-2', 4, 250)], ['match' => 'diff']);
        $this->purchaseOp([$this->line('itm-3', 1, 100)], ['operation_date' => now()->subDays(3), 'amount' => 100]);

        $kpi = $this->acc()->getJson('/api/v1/company/me/operations?moduleKey=purchases')->assertOk()->json('meta.summary.purchases');

        // «مشتريات اليوم» is every order dated today (5000 approved + 1000 diff);
        // the 3-days-old op is excluded. `approvedToday` narrows to approved.
        $this->assertSame(6000, $kpi['todayTotalHalalas']);
        $this->assertSame(1, $kpi['approvedToday']);
        $this->assertSame(1, $kpi['qtyDiscrepancies']);
    }

    public function test_a_foreign_companys_purchase_is_never_listed(): void
    {
        $foreign = AsabCompany::create(['name' => 'Rival', 'plan' => 'Basic', 'status' => 'active']);
        $foreignBranch = Branch::factory()->create(['asab_company_id' => $foreign->id]);
        Operation::create([
            'public_id' => 'PUR-9999', 'company_id' => $foreign->id, 'branch_id' => $foreignBranch->id,
            'module_key' => 'purchases', 'amount' => 9999, 'match' => 'exact', 'origin' => 'procurement',
            'status' => Operation::STATUS_PENDING, 'operation_date' => now(),
        ]);

        $ids = array_column($this->acc()->getJson('/api/v1/company/me/operations?moduleKey=purchases')->json('data'), 'publicId');
        $this->assertNotContains('PUR-9999', $ids);
    }

    // ── Returns ──────────────────────────────────────────────────────────────

    public function test_returns_list_shows_arabic_status_and_scopes_by_branch(): void
    {
        $mineOrder = PurchaseOrder::factory()->create([
            'order_number' => 'PO-'.strtoupper(Str::random(6)), 'order_type' => OrderType::DIRECT_SUPPLIER,
            'status' => OrderStatus::PENDING, 'branch_id' => $this->branchA->id, 'submitted_at' => now(),
        ]);
        ReturnOrder::create([
            'return_number' => 'RET-1', 'purchase_order_id' => $mineOrder->id, 'branch_id' => $this->branchA->id,
            'created_by' => (string) Str::uuid(), 'return_date' => now(),
            'status' => ReturnStatus::PENDING, 'required_action' => ReturnRequiredAction::CASH_REFUND,
            'total_return_amount' => 150.00, 'refund_amount' => 150.00,
        ]);
        $foreignCompany = AsabCompany::create(['name' => 'Rival2', 'plan' => 'Basic', 'status' => 'active']);
        $foreignBranch = Branch::factory()->create(['asab_company_id' => $foreignCompany->id]);
        $foreignOrder = PurchaseOrder::factory()->create([
            'order_number' => 'PO-'.strtoupper(Str::random(6)), 'order_type' => OrderType::DIRECT_SUPPLIER,
            'status' => OrderStatus::PENDING, 'branch_id' => $foreignBranch->id, 'submitted_at' => now(),
        ]);
        ReturnOrder::create([
            'return_number' => 'RET-FOREIGN', 'purchase_order_id' => $foreignOrder->id, 'branch_id' => $foreignBranch->id,
            'created_by' => (string) Str::uuid(), 'return_date' => now(), 'status' => ReturnStatus::PENDING,
            'required_action' => ReturnRequiredAction::CASH_REFUND, 'total_return_amount' => 9.00,
        ]);

        $rows = $this->acc()->getJson('/api/v1/company/me/purchases/returns')->assertOk()->json('data');
        $numbers = array_column($rows, 'returnNumber');

        $this->assertContains('RET-1', $numbers);
        $this->assertNotContains('RET-FOREIGN', $numbers);
        $mine = collect($rows)->firstWhere('returnNumber', 'RET-1');
        $this->assertSame('قيد المراجعة', $mine['statusLabelAr']);
        $this->assertSame(15000, $mine['totalReturnAmountHalalas']);
    }

    // ── Suppliers cards ──────────────────────────────────────────────────────

    public function test_supplier_cards_carry_items_and_monthly_order_counts(): void
    {
        SupplierItem::create(['company_id' => $this->company->id, 'supplier_id' => $this->supplier->id, 'name' => 'طماطم', 'unit' => 'كجم', 'status' => 'active']);
        SupplierItem::create(['company_id' => $this->company->id, 'supplier_id' => $this->supplier->id, 'name' => 'خيار', 'unit' => 'كجم', 'status' => 'active']);
        $this->purchaseOp([$this->line('itm-1', 10, 500)]);

        $card = collect($this->acc()->getJson('/api/v1/company/me/suppliers')->assertOk()->json('data'))
            ->firstWhere('id', $this->supplier->id);

        $this->assertSame(2, $card['itemsCount']);
        $this->assertSame(1, $card['monthlyOrderCount']);
    }

    // ── Zero-trust ───────────────────────────────────────────────────────────

    public function test_a_branch_role_cannot_document_edit_or_read_returns(): void
    {
        $branchUser = AsabUser::create([
            'company_id' => $this->company->id, 'name' => 'مدير فرع', 'email' => 'brm@purch.test',
            'password' => 'secret-password', 'status' => 'active',
        ]);
        AsabUserRole::create(['user_id' => $branchUser->id, 'role_key' => 'branch', 'scope' => 'all']);
        $op = $this->purchaseOp([$this->line('itm-1', 10, 500)]);

        $as = $this->actingAs($branchUser, 'sanctum');
        $as->postJson("/api/v1/operations/{$op->id}/document")->assertStatus(403);
        $as->patchJson("/api/v1/operations/{$op->id}/purchase-lines/itm-1", ['rcvQty' => 1])->assertStatus(403);
        $as->getJson('/api/v1/purchases/returns')->assertStatus(403);
    }

    public function test_unauthenticated_is_rejected(): void
    {
        $op = $this->purchaseOp([$this->line('itm-1', 10, 500)]);

        $this->postJson("/api/v1/operations/{$op->id}/document")->assertStatus(401);
    }
}
