<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftHandoverSettlement;
use Modules\Shift\Models\ShiftReportAggregate;
use Modules\Shift\Models\ShiftReportRevision;
use Tests\TestCase;

class ShiftHandoverSettlementHttpTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branchA;

    private Branch $branchB;

    private BranchManager $managerA;

    private BranchManager $managerB;

    private Cashier $cashier;

    private CashierShift $shift;

    private CashierShiftHandoverReceipt $receipt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Http Settlement Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'B', 'sub_status' => 'active', 'status' => 'active']);
        $this->branchA = Branch::factory()->create(['asab_brand_id' => $brand->id, 'asab_company_id' => $this->company->id]);
        $this->branchB = Branch::factory()->create(['asab_brand_id' => $brand->id, 'asab_company_id' => $this->company->id]);

        $this->managerA = BranchManager::factory()->create(['branch_id' => $this->branchA->id]);
        $this->managerB = BranchManager::factory()->create(['branch_id' => $this->branchB->id]);
        $this->cashier = Cashier::factory()->create(['branch_id' => $this->branchA->id]);

        $shiftDef = Shift::create([
            'branch_id' => $this->branchA->id,
            'name' => 'Morning',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);

        $this->shift = CashierShift::create([
            'cashier_id' => $this->cashier->id,
            'shift_id' => $shiftDef->id,
            'branch_id' => $this->branchA->id,
            'shift_date' => today(),
            'status' => 'completed',
        ]);

        $managerWorkday = BranchManagerShift::where('branch_manager_id', $this->managerA->id)->whereDate('shift_date', today())->first()
            ?? BranchManagerShift::create([
                'branch_manager_id' => $this->managerA->id,
                'branch_id' => $this->branchA->id,
                'shift_date' => today(),
                'status' => 'completed',
            ]);
        $managerWorkday->update(['status' => 'completed']);

        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $this->shift->id,
            'shift_id' => $this->shift->shift_id,
            'branch_id' => $this->branchA->id,
            'handover_type' => 'manager',
            'handover_to_type' => 'branch_manager',
            'handover_to_id' => $this->managerA->id,
            'handover_amount' => 100.00,
            'declared_amount' => 100.00,
            'status' => 'approved',
            'handover_date' => today(),
            'handover_time' => now(),
        ]);

        $aggregate = ShiftReportAggregate::create([
            'source_type' => 'cashier_shift',
            'source_id' => $this->shift->id,
            'current_revision_number' => 1,
        ]);
        $revision = ShiftReportRevision::create([
            'report_aggregate_id' => $aggregate->id,
            'revision_number' => 1,
            'created_by_type' => 'cashier',
            'created_by_id' => $this->cashier->id,
        ]);

        $this->receipt = CashierShiftHandoverReceipt::create([
            'cashier_shift_handover_id' => $handover->id,
            'receiving_cashier_shift_id' => null,
            'receiving_cashier_id' => null,
            'receiving_branch_manager_shift_id' => $managerWorkday->id,
            'receiving_branch_manager_id' => $this->managerA->id,
            'confirmed_by_branch_manager_id' => $this->managerA->id,
            'report_revision_id' => $revision->id,
            'confirmed_amount' => 100.00,
            'confirmed_at' => now(),
        ]);

        if ($this->name() !== 'test_audit_return_cannot_spend_manager_reserved_cash') {
            PersonalLedgerTransaction::create([
                'branch_manager_id' => $this->managerA->id,
                'transaction_type' => 'Total Sales',
                'amount' => '100.00',
                'is_cash_in' => true,
                'cashier_name' => $this->cashier->name,
                'related_shift_id' => $managerWorkday->id,
                'related_handover_id' => $handover->id,
                'receipt_id' => null,
                'transaction_date' => now(),
            ]);
        }

        // Default to authorized for the HTTP mechanics suite; specific test asserts unresolved behavior
        config(['shift.settlement_authority_approved' => true]);
    }

    public function test_public_http_settle_unresolved_authority_returns_403(): void
    {
        config(['shift.settlement_authority_approved' => false]);
        $response = $this->actingAs($this->managerA, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/handover-receipts/{$this->receipt->id}/settlements", [
                'settlement_type' => 'overpayment_return',
                'amount_halalas' => 2000,
                'reason' => '20 SAR returned to cashier',
                'expected_settlement_version' => 0,
            ]);

        $response->assertStatus(403);
        $response->assertJsonFragment([
            'code' => 'SETTLEMENT_AUTHORITY_UNRESOLVED',
        ]);
    }

    public function test_public_http_settle_success_201(): void
    {
        $idempotencyKey = (string) Str::uuid();

        $response = $this->actingAs($this->managerA, 'sanctum')
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson("/api/v1/branch-manager/handover-receipts/{$this->receipt->id}/settlements", [
                'settlement_type' => 'overpayment_return',
                'amount_halalas' => 2000,
                'reason' => '20 SAR returned to cashier',
                'expected_settlement_version' => 0,
            ]);

        $response->assertStatus(201);
        $response->assertJson([
            'success' => true,
            'message' => 'Settlement recorded successfully',
        ]);
        $this->assertEquals(-2000, $response->json('data.signed_delta_halalas'));
        $this->assertEquals(2000, $response->json('data.amount_halalas'));
        $this->assertEquals('20.00', $response->json('data.amount_sar'));

        // Dual counterpart rows created in DB
        $settlementId = $response->json('data.id');
        $this->assertEquals(1, CashierCustodyTransaction::where('settlement_id', $settlementId)->count());
        $this->assertEquals(1, PersonalLedgerTransaction::where('settlement_id', $settlementId)->count());

        // Receipt confirmed amount is 100% untouched
        $this->assertEquals('100.00', $this->receipt->fresh()->confirmed_amount);
    }

    public function test_identical_retry_replays_exact_201_response(): void
    {
        $idempotencyKey = (string) Str::uuid();
        $payload = [
            'settlement_type' => 'overpayment_return',
            'amount_halalas' => 1500,
            'reason' => 'Identical retry test',
            'expected_settlement_version' => 0,
        ];

        $first = $this->actingAs($this->managerA, 'sanctum')
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson("/api/v1/branch-manager/handover-receipts/{$this->receipt->id}/settlements", $payload);

        $first->assertStatus(201);
        $firstId = $first->json('data.id');

        $second = $this->actingAs($this->managerA, 'sanctum')
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson("/api/v1/branch-manager/handover-receipts/{$this->receipt->id}/settlements", $payload);

        $second->assertStatus(201);
        $this->assertEquals($firstId, $second->json('data.id'));

        // Zero duplicate DB writes
        $this->assertEquals(1, ShiftHandoverSettlement::where('original_receipt_id', $this->receipt->id)->count());
        $this->assertEquals(1, CashierCustodyTransaction::where('settlement_id', $firstId)->count());
        $this->assertEquals(1, PersonalLedgerTransaction::where('settlement_id', $firstId)->count());
    }

    public function test_missing_idempotency_key_is_rejected(): void
    {
        $response = $this->actingAs($this->managerA, 'sanctum')
            ->postJson("/api/v1/branch-manager/handover-receipts/{$this->receipt->id}/settlements", [
                'settlement_type' => 'overpayment_return',
                'amount_halalas' => 1000,
                'reason' => 'Missing key test',
                'expected_settlement_version' => 0,
            ]);

        $response->assertStatus(422);
    }

    public function test_changed_payload_with_same_key_throws_mismatch(): void
    {
        $idempotencyKey = (string) Str::uuid();

        $first = $this->actingAs($this->managerA, 'sanctum')
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson("/api/v1/branch-manager/handover-receipts/{$this->receipt->id}/settlements", [
                'settlement_type' => 'overpayment_return',
                'amount_halalas' => 1000,
                'reason' => 'Initial payload',
                'expected_settlement_version' => 0,
            ]);

        $first->assertStatus(201);

        $second = $this->actingAs($this->managerA, 'sanctum')
            ->withHeader('Idempotency-Key', $idempotencyKey)
            ->postJson("/api/v1/branch-manager/handover-receipts/{$this->receipt->id}/settlements", [
                'settlement_type' => 'underpayment_supplement', // changed!
                'amount_halalas' => 2000, // changed!
                'reason' => 'Modified payload',
                'expected_settlement_version' => 0,
            ]);

        $this->assertTrue(in_array($second->status(), [409, 422]));
    }

    public function test_stale_expected_settlement_version_returns_409(): void
    {
        // First settlement advances version to 1
        $this->actingAs($this->managerA, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/handover-receipts/{$this->receipt->id}/settlements", [
                'settlement_type' => 'overpayment_return',
                'amount_halalas' => 1000,
                'reason' => 'First advance',
                'expected_settlement_version' => 0,
            ])
            ->assertStatus(201);

        // Second request with stale version 0
        $response = $this->actingAs($this->managerA, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/handover-receipts/{$this->receipt->id}/settlements", [
                'settlement_type' => 'overpayment_return',
                'amount_halalas' => 500,
                'reason' => 'Stale version test',
                'expected_settlement_version' => 0, // Stale!
            ]);

        $response->assertStatus(409);
        $this->assertEquals('STALE_SETTLEMENT_VERSION', $response->json('code'));
    }

    public function test_unauthorized_manager_from_different_branch_returns_403(): void
    {
        $response = $this->actingAs($this->managerB, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/handover-receipts/{$this->receipt->id}/settlements", [
                'settlement_type' => 'overpayment_return',
                'amount_halalas' => 1000,
                'reason' => 'Different branch attempt',
                'expected_settlement_version' => 0,
            ]);

        $response->assertStatus(403);
    }

    public function test_forbidden_client_override_fields_rejected_with_422(): void
    {
        $response = $this->actingAs($this->managerA, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/handover-receipts/{$this->receipt->id}/settlements", [
                'settlement_type' => 'overpayment_return',
                'amount_halalas' => 1000,
                'reason' => 'Inject override',
                'expected_settlement_version' => 0,
                'signed_delta' => 9999, // FORBIDDEN!
            ]);

        $response->assertStatus(422);
        $this->assertEquals('FORBIDDEN_REQUEST_FIELD', $response->json('code'));
    }

    public function test_f05_foreign_branch_cannot_read_receipt_or_settlement_amounts(): void
    {
        $response = $this->actingAs($this->managerB, 'sanctum')
            ->getJson("/api/v1/branch-manager/handover-receipts/{$this->receipt->id}/settlements");
        $response->assertStatus(403);
        $this->assertArrayNotHasKey('data', $response->json());
        $this->assertStringNotContainsString($this->receipt->id, $response->getContent());
    }

    public function test_f13_invalid_or_overflowing_money_is_rejected_without_writes(): void
    {
        foreach ([1000000000000, PHP_INT_MAX, 0, -1, '1.5', []] as $amount) {
            $this->actingAs($this->managerA, 'sanctum')->withHeader('Idempotency-Key', (string) Str::uuid())
                ->postJson("/api/v1/branch-manager/handover-receipts/{$this->receipt->id}/settlements", [
                    'settlement_type' => 'underpayment_supplement', 'amount_halalas' => $amount,
                    'expected_settlement_version' => 0, 'reason' => 'Invalid money',
                ])->assertStatus(422);
        }
        $this->assertSame(0, ShiftHandoverSettlement::count());
        $this->assertSame(0, CashierCustodyTransaction::whereNotNull('settlement_id')->count());
        $this->assertSame(0, PersonalLedgerTransaction::whereNotNull('settlement_id')->count());
    }

    public function test_index_settlements_returns_separate_additive_summary(): void
    {
        // Execute one settlement
        $this->actingAs($this->managerA, 'sanctum')
            ->withHeader('Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/v1/branch-manager/handover-receipts/{$this->receipt->id}/settlements", [
                'settlement_type' => 'overpayment_return',
                'amount_halalas' => 2000,
                'reason' => '20 SAR return',
                'expected_settlement_version' => 0,
            ])
            ->assertStatus(201);

        // Call GET settlements endpoint
        $response = $this->actingAs($this->managerA, 'sanctum')
            ->getJson("/api/v1/branch-manager/handover-receipts/{$this->receipt->id}/settlements");

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'data' => [
                'original_receipt_id' => $this->receipt->id,
                'original_amount' => '100.00',
                'original_amount_halalas' => 10000,
                'settlement_version' => 1,
                'total_delta_halalas' => -2000,
                'effective_amount_halalas' => 8000,
                'effective_amount' => '80.00',
            ],
        ]);
        $this->assertCount(1, $response->json('data.settlements'));
    }
}
