<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Models\BranchManagerCashTransfer;
use Modules\Shift\Models\BranchManagerShift;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftHandoverSettlement;
use Modules\Shift\Models\ShiftReportAggregate;
use Modules\Shift\Models\ShiftReportRevision;
use Modules\Shift\Services\HandoverSettlementService;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;
use Tests\TestCase;

class ShiftHandoverSettlementServiceTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branch;

    private BranchManager $manager;

    private Cashier $cashierA;

    private Cashier $cashierB;

    private CashierShift $shiftA;

    private CashierShift $shiftB;

    private BranchManagerShift $managerShift;

    private HandoverSettlementService $service;

    protected function setUp(): void
    {
        parent::setUp();

        // Technical direction tests simulate an approved policy configuration.
        // Production activation remains blocked pending the business decision.
        config(['shift.settlement_authority_approved' => true]);

        $this->company = AsabCompany::create(['name' => 'Settlement Svc Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'B', 'sub_status' => 'active', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['asab_brand_id' => $brand->id, 'asab_company_id' => $this->company->id]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashierA = Cashier::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashierB = Cashier::factory()->create(['branch_id' => $this->branch->id]);

        $shiftDef = Shift::create([
            'branch_id' => $this->branch->id,
            'name' => 'General',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);

        $this->shiftA = CashierShift::create([
            'cashier_id' => $this->cashierA->id,
            'shift_id' => $shiftDef->id,
            'branch_id' => $this->branch->id,
            'shift_date' => today(),
            'status' => 'completed',
        ]);

        $this->shiftB = CashierShift::create([
            'cashier_id' => $this->cashierB->id,
            'shift_id' => $shiftDef->id,
            'branch_id' => $this->branch->id,
            'shift_date' => today(),
            'status' => 'completed',
        ]);

        $this->managerShift = BranchManagerShift::where('branch_manager_id', $this->manager->id)->whereDate('shift_date', today())->first()
            ?? BranchManagerShift::create([
                'branch_manager_id' => $this->manager->id,
                'branch_id' => $this->branch->id,
                'shift_date' => today(),
                'status' => 'completed',
            ]);
        $this->managerShift->update(['status' => 'completed']);

        PersonalLedgerTransaction::create([
            'branch_manager_id' => $this->manager->id, 'transaction_type' => 'Total Sales',
            'amount' => '100.00', 'is_cash_in' => true, 'related_shift_id' => $this->managerShift->id, 'transaction_date' => now(),
        ]);
        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashierA->id, 'transaction_type' => 'Total Sales',
            'amount' => '100.00', 'is_cash_in' => true, 'related_shift_id' => $this->shiftA->id, 'transaction_date' => now(),
        ]);

        $this->service = app(HandoverSettlementService::class);
    }

    public function test_f12_each_partial_write_failure_rolls_back_and_can_retry(): void
    {
        $receipt = $this->createCashierToManagerReceipt(100.00);
        $version = 0;
        foreach ([ShiftHandoverSettlement::class, CashierCustodyTransaction::class, \Modules\Shift\Models\CashierShiftHistory::class] as $model) {
            $stage = $model;
            $operation = (string) \Illuminate\Support\Str::uuid();
            $armed = true;
            \Illuminate\Support\Facades\Event::listen('eloquent.created: '.$model, function () use (&$armed, &$stage, $model) {
                if ($armed && $stage === $model) {
                    throw new \RuntimeException('Injected partial settlement write');
                }
            });
            try {
                $this->service->settle($receipt, $this->manager, 'overpayment_return', 100, $version, 'Rollback test', $operation);
                $this->fail('Injected failure must escape and rollback.');
            } catch (\RuntimeException $exception) {
                $this->assertSame('Injected partial settlement write', $exception->getMessage());
            }
            $armed = false;
            $this->assertDatabaseMissing('shift_handover_settlements', ['operation_id' => $operation]);
            $this->assertSame('100.00', (string) $receipt->fresh()->confirmed_amount);
            $created = $this->service->settle($receipt, $this->manager, 'overpayment_return', 100, $version++, 'Rollback test', $operation);
            $this->assertSame(1, CashierCustodyTransaction::where('settlement_id', $created->id)->count());
            $this->assertSame(1, PersonalLedgerTransaction::where('settlement_id', $created->id)->count());
        }
    }

    public function test_f08_direct_service_cannot_bypass_unresolved_authority(): void
    {
        $receipt = $this->createCashierToManagerReceipt();
        config(['shift.settlement_authority_approved' => false]);
        $this->expectException(\Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException::class);
        $this->expectExceptionMessage('SETTLEMENT_AUTHORITY_UNRESOLVED');
        $this->service->settle($receipt, $this->manager, 'underpayment_supplement', 1, 0, 'Test authority', 'f08');
    }

    public function test_review_physical_shortage_limits_spendable_cash(): void
    {
        $receipt = $this->createCashierToManagerReceipt(0);
        $revision = ShiftReportRevision::findOrFail($receipt->report_revision_id);
        \Illuminate\Support\Facades\DB::transaction(function () use ($revision) {
            app(\Modules\Shift\Services\ShiftCashCountService::class)->record($this->shiftA, $revision, 10000, 0, 0, 9000);
        });
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('INSUFFICIENT_CASHIER_CUSTODY_CASH');
        $this->service->settle($receipt, $this->manager, 'underpayment_supplement', 9500, 0, 'Must respect physical cash', 'review-physical');
    }

    public function test_review_cashier_request_cannot_reserve_cash_spent_by_settlement(): void
    {
        $receipt = $this->createCashierToManagerReceipt(0);
        $this->service->settle($receipt, $this->manager, 'underpayment_supplement', 8000, 0, 'Earlier cash expenditure', 'review-spent');
        $report = CashierShift::create(['cashier_id' => $this->cashierA->id, 'shift_id' => $this->shiftA->shift_id,
            'shift_date' => today()->subDay(), 'status' => 'completed', 'total_sales' => '0.00']);
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('INSUFFICIENT_CASHIER_CUSTODY_CASH');
        app(\Modules\Shift\Services\HandoverService::class)->recordHandover($report, [
            'handover_to_type' => 'branch_manager', 'handover_to_id' => $this->manager->id, 'handover_amount' => '100.00',
        ], $this->cashierA);
    }

    public function test_review_carried_count_adjusts_cash_once_and_recount_fails_closed(): void
    {
        $receipt = $this->createCashierToManagerReceipt(0);
        \Illuminate\Support\Facades\DB::transaction(function () use ($receipt) {
            $revision = ShiftReportRevision::findOrFail($receipt->report_revision_id);
            app(\Modules\Shift\Services\ShiftCashCountService::class)->record($this->shiftA, $revision, 10000, 0, 0, 9000);
            $next = app(\Modules\Shift\Services\ShiftReportRevisionService::class)->recordCashierRevision($this->shiftA, 'cashier', $this->cashierA->id, 1);
            app(\Modules\Shift\Services\ShiftCashCountService::class)->carryForward($revision, $next);
            $this->assertSame(9000, app(\Modules\Shift\Services\ShiftCashAvailabilityService::class)->availableCashierMinor($this->cashierA->id));
        });
        ShiftReportAggregate::where('source_id', $this->shiftA->id)->update(['fresh_count_required' => true]);
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('PHYSICAL_RECOUNT_REQUIRED');
        $this->service->settle($receipt, $this->manager, 'underpayment_supplement', 1, 0, 'Cannot use invalid count', 'review-recount');
    }

    public function test_f06_cashier_identity_must_match_historical_receiving_shift_and_request(): void
    {
        $receipt = $this->createCashierToCashierReceipt();
        \Illuminate\Support\Facades\DB::table('cashier_shift_handover_receipts')->where('id', $receipt->id)
            ->update(['receiving_cashier_id' => $this->cashierA->id]);
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('UNRESOLVED_RECEIPT_PROVENANCE');
        $this->service->resolveProvenance($receipt->fresh());
    }

    public function test_f06_cashier_receipt_without_historical_destination_shift_fails_closed(): void
    {
        $receipt = $this->createCashierToCashierReceipt();
        \Illuminate\Support\Facades\DB::table('cashier_shift_handover_receipts')->where('id', $receipt->id)
            ->update(['receiving_cashier_shift_id' => null]);
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('UNRESOLVED_RECEIPT_PROVENANCE');
        $this->service->resolveProvenance($receipt->fresh());
    }

    public function test_f06_reassigned_manager_keeps_proven_historical_workday_identity(): void
    {
        $receipt = $this->createCashierToManagerReceipt();
        $other = Branch::factory()->create(['asab_company_id' => $this->company->id]);
        $this->manager->update(['branch_id' => $other->id]);
        $provenance = $this->service->resolveProvenance($receipt);
        $this->assertSame($this->branch->id, $provenance['branch_id']);
        $this->assertSame($this->manager->id, $provenance['recipient_id']);
    }

    public function test_f07_liability_credit_is_not_cash_for_cashier_return(): void
    {
        $receipt = $this->createCashierToCashierReceipt();
        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashierB->id, 'transaction_type' => 'Variance',
            'amount' => '100.00', 'is_cash_in' => true, 'category' => 'cash_variance', 'transaction_date' => now(),
        ]);
        try {
            $this->service->settle($receipt, $this->manager, 'overpayment_return', 1000, 0, 'No cash', 'f07');
            $this->fail('Liability credit was treated as spendable cash.');
        } catch (ConflictHttpException $exception) {
            $this->assertSame('INSUFFICIENT_CASHIER_CUSTODY_CASH', $exception->getMessage());
        }
        $this->assertSame(0, ShiftHandoverSettlement::count());
    }

    public function test_f10_changed_reason_version_or_type_cannot_replay_service_operation(): void
    {
        $receipt = $this->createCashierToManagerReceipt();
        $first = $this->service->settle($receipt, $this->manager, 'overpayment_return', 100, 0, 'Original', 'f10');
        foreach ([['overpayment_return', 0, 'Changed'], ['overpayment_return', 1, 'Original'], ['underpayment_supplement', 0, 'Original']] as [$type, $version, $reason]) {
            try {
                $this->service->settle($receipt, $this->manager, $type, 100, $version, $reason, 'f10');
                $this->fail('Different canonical payload replayed.');
            } catch (ConflictHttpException $exception) {
                $this->assertSame('IDEMPOTENCY_PAYLOAD_MISMATCH', $exception->getMessage());
            }
        }
        $retry = $this->service->settle($receipt, $this->manager, 'overpayment_return', 100, 0, 'Original', 'f10');
        $this->assertSame($first->id, $retry->id);
        $this->assertSame(1, ShiftHandoverSettlement::count());
        $this->assertSame(1, PersonalLedgerTransaction::where('settlement_id', $first->id)->count());
    }

    public function test_f13_valid_decimal_boundary_is_persisted_without_float_conversion(): void
    {
        $receipt = $this->createCashierToManagerReceipt(0.00);
        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashierA->id, 'transaction_type' => 'Total Sales',
            'amount' => '9999999999.99', 'is_cash_in' => true,
            'related_shift_id' => $this->shiftA->id, 'transaction_date' => now(),
        ]);
        $settlement = $this->service->settle($receipt, $this->manager, 'underpayment_supplement', 999999999999, 0, 'Boundary', 'f13');
        $this->assertSame(999999999999, $settlement->signed_delta_halalas);
        $this->assertSame('9999999999.99', (string) PersonalLedgerTransaction::where('settlement_id', $settlement->id)->sole()->amount);
        $this->assertSame('9999999999.99', (string) CashierCustodyTransaction::where('settlement_id', $settlement->id)->sole()->amount);
        $this->assertSame('0.00', (string) $receipt->fresh()->confirmed_amount);
    }

    private function createCashierToManagerReceipt(float $amount = 100.00): CashierShiftHandoverReceipt
    {
        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $this->shiftA->id,
            'shift_id' => $this->shiftA->shift_id,
            'branch_id' => $this->branch->id,
            'handover_type' => 'manager',
            'handover_to_type' => 'branch_manager',
            'handover_to_id' => $this->manager->id,
            'handover_amount' => $amount,
            'declared_amount' => $amount,
            'status' => 'approved',
            'handover_date' => today(),
            'handover_time' => now(),
        ]);

        $aggregate = ShiftReportAggregate::create([
            'source_type' => 'cashier_shift',
            'source_id' => $this->shiftA->id,
            'current_revision_number' => 1,
        ]);
        $revision = ShiftReportRevision::create([
            'report_aggregate_id' => $aggregate->id,
            'revision_number' => 1,
            'created_by_type' => 'cashier',
            'created_by_id' => $this->cashierA->id,
        ]);

        return CashierShiftHandoverReceipt::create([
            'cashier_shift_handover_id' => $handover->id,
            'receiving_cashier_shift_id' => null,
            'receiving_cashier_id' => null,
            'receiving_branch_manager_shift_id' => $this->managerShift->id,
            'receiving_branch_manager_id' => $this->manager->id,
            'confirmed_by_branch_manager_id' => $this->manager->id,
            'report_revision_id' => $revision->id,
            'confirmed_amount' => $amount,
            'confirmed_at' => now(),
        ]);
    }

    private function createCashierToCashierReceipt(float $amount = 80.00): CashierShiftHandoverReceipt
    {
        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $this->shiftA->id,
            'shift_id' => $this->shiftA->shift_id,
            'branch_id' => $this->branch->id,
            'handover_type' => 'cashier',
            'handover_to_type' => 'cashier',
            'handover_to_id' => $this->cashierB->id,
            'handover_amount' => $amount,
            'declared_amount' => $amount,
            'status' => 'approved',
            'handover_date' => today(),
            'handover_time' => now(),
        ]);

        $aggregate = ShiftReportAggregate::create([
            'source_type' => 'cashier_shift',
            'source_id' => $this->shiftA->id,
            'current_revision_number' => 1,
        ]);
        $revision = ShiftReportRevision::create([
            'report_aggregate_id' => $aggregate->id,
            'revision_number' => 1,
            'created_by_type' => 'cashier',
            'created_by_id' => $this->cashierA->id,
        ]);

        return CashierShiftHandoverReceipt::create([
            'cashier_shift_handover_id' => $handover->id,
            'receiving_cashier_shift_id' => $this->shiftB->id,
            'receiving_cashier_id' => $this->cashierB->id,
            'confirmed_by_id' => $this->cashierB->id,
            'report_revision_id' => $revision->id,
            'confirmed_amount' => $amount,
            'confirmed_at' => now(),
        ]);
    }

    private function createManagerToCashierReceipt(float $amount = 50.00): CashierShiftHandoverReceipt
    {
        $aggregate = ShiftReportAggregate::firstOrCreate([
            'source_type' => 'branch_manager_shift',
            'source_id' => $this->managerShift->id,
        ], [
            'current_revision_number' => 1,
        ]);
        $revision = ShiftReportRevision::create([
            'report_aggregate_id' => $aggregate->id,
            'revision_number' => 1,
            'created_by_type' => 'branch_manager',
            'created_by_id' => $this->manager->id,
        ]);

        $transfer = BranchManagerCashTransfer::create([
            'branch_manager_shift_id' => $this->managerShift->id,
            'destination_cashier_id' => $this->cashierA->id,
            'destination_cashier_shift_id' => $this->shiftA->id,
            'report_revision_id' => $revision->id,
            'created_by_id' => $this->manager->id,
            'requested_amount' => $amount,
            'status' => 'confirmed',
        ]);

        return CashierShiftHandoverReceipt::create([
            'branch_manager_cash_transfer_id' => $transfer->id,
            'receiving_cashier_shift_id' => $this->shiftA->id,
            'receiving_cashier_id' => $this->cashierA->id,
            'confirmed_by_id' => $this->cashierA->id,
            'report_revision_id' => $revision->id,
            'confirmed_amount' => $amount,
            'confirmed_at' => now(),
        ]);
    }

    public function test_cashier_to_manager_overpayment_return(): void
    {
        $receipt = $this->createCashierToManagerReceipt(100.00);

        $settlement = $this->service->settle(
            $receipt,
            $this->manager,
            'overpayment_return',
            2000, // 20.00 SAR
            0,
            'Counted 20 SAR excess returned to Cashier A',
            'op-cm-overpay'
        );

        $this->assertEquals(-2000, $settlement->signed_delta_halalas);
        $this->assertEquals('overpayment_return', $settlement->settlement_type);

        // Original receipt is 100% immutable
        $this->assertEquals('100.00', $receipt->fresh()->confirmed_amount);

        // Exactly 2 counterpart rows
        $custodyRow = CashierCustodyTransaction::where('settlement_id', $settlement->id)->first();
        $ledgerRow = PersonalLedgerTransaction::where('settlement_id', $settlement->id)->first();

        $this->assertNotNull($custodyRow);
        $this->assertNotNull($ledgerRow);

        // Sender (Cashier A) gets cash in
        $this->assertEquals($this->cashierA->id, $custodyRow->cashier_id);
        $this->assertTrue((bool) $custodyRow->is_cash_in);
        $this->assertEquals('20.00', (string) $custodyRow->amount);
        $this->assertEquals('handover_settlement', $custodyRow->category);

        // Recipient (Manager) gets cash out
        $this->assertEquals($this->manager->id, $ledgerRow->branch_manager_id);
        $this->assertFalse((bool) $ledgerRow->is_cash_in);
        $this->assertEquals('20.00', (string) $ledgerRow->amount);
        $this->assertEquals('handover_settlement', $ledgerRow->category);
    }

    public function test_cashier_to_manager_underpayment_supplement(): void
    {
        $receipt = $this->createCashierToManagerReceipt(100.00);

        $settlement = $this->service->settle(
            $receipt,
            $this->manager,
            'underpayment_supplement',
            1500, // 15.00 SAR
            0,
            'Shortfall 15 SAR paid by Cashier A',
            'op-cm-underpay'
        );

        $this->assertEquals(1500, $settlement->signed_delta_halalas);

        $custodyRow = CashierCustodyTransaction::where('settlement_id', $settlement->id)->first();
        $ledgerRow = PersonalLedgerTransaction::where('settlement_id', $settlement->id)->first();

        // Sender (Cashier A) pays cash out
        $this->assertFalse((bool) $custodyRow->is_cash_in);
        $this->assertEquals('15.00', (string) $custodyRow->amount);

        // Recipient (Manager) receives cash in
        $this->assertTrue((bool) $ledgerRow->is_cash_in);
        $this->assertEquals('15.00', (string) $ledgerRow->amount);
    }

    public function test_cashier_to_cashier_dual_custody_movements(): void
    {
        $receipt = $this->createCashierToCashierReceipt(80.00);
        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashierB->id, 'transaction_type' => 'Handover Received',
            'amount' => '80.00', 'is_cash_in' => true, 'receipt_id' => $receipt->id, 'transaction_date' => now(),
        ]);

        $settlement = $this->service->settle(
            $receipt,
            $this->manager,
            'overpayment_return',
            1000,
            0,
            'Cashier B returns 10 SAR overpayment to Cashier A',
            'op-cc-settle'
        );

        $custodyRows = CashierCustodyTransaction::where('settlement_id', $settlement->id)->get();
        $this->assertCount(2, $custodyRows);

        $senderRow = $custodyRows->firstWhere('cashier_id', $this->cashierA->id);
        $recipientRow = $custodyRows->firstWhere('cashier_id', $this->cashierB->id);

        $this->assertTrue((bool) $senderRow->is_cash_in);
        $this->assertFalse((bool) $recipientRow->is_cash_in);
        $this->assertEquals('10.00', (string) $senderRow->amount);
        $this->assertEquals('10.00', (string) $recipientRow->amount);
    }

    public function test_manager_to_cashier_settlement(): void
    {
        $receipt = $this->createManagerToCashierReceipt(50.00);

        $settlement = $this->service->settle(
            $receipt,
            $this->manager,
            'overpayment_return',
            500, // 5.00 SAR returned to Manager
            0,
            'Cashier returns 5 SAR to manager',
            'op-mc-settle'
        );

        $ledgerRow = PersonalLedgerTransaction::where('settlement_id', $settlement->id)->first();
        $custodyRow = CashierCustodyTransaction::where('settlement_id', $settlement->id)->first();

        // Sender (Manager) received 5 SAR return
        $this->assertTrue((bool) $ledgerRow->is_cash_in);
        // Recipient (Cashier) paid 5 SAR return
        $this->assertFalse((bool) $custodyRow->is_cash_in);
    }

    public function test_same_operation_id_is_idempotent_replay(): void
    {
        $receipt = $this->createCashierToManagerReceipt(100.00);

        $first = $this->service->settle(
            $receipt,
            $this->manager,
            'overpayment_return',
            1000,
            0,
            'Identical retry test',
            'op-idemp-1'
        );

        $second = $this->service->settle(
            $receipt,
            $this->manager,
            'overpayment_return',
            1000,
            0,
            'Identical retry test',
            'op-idemp-1'
        );

        $this->assertEquals($first->id, $second->id);
        $this->assertEquals(1, ShiftHandoverSettlement::where('operation_id', 'op-idemp-1')->count());
        $this->assertEquals(1, CashierCustodyTransaction::where('settlement_id', $first->id)->count());
        $this->assertEquals(1, PersonalLedgerTransaction::where('settlement_id', $first->id)->count());
    }

    public function test_same_operation_id_different_payload_throws_mismatch(): void
    {
        $receipt = $this->createCashierToManagerReceipt(100.00);

        $this->service->settle(
            $receipt,
            $this->manager,
            'overpayment_return',
            1000,
            0,
            'First payload',
            'op-mismatch-1'
        );

        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('IDEMPOTENCY_PAYLOAD_MISMATCH');

        $this->service->settle(
            $receipt,
            $this->manager,
            'underpayment_supplement', // changed type
            2000, // changed amount
            0,
            'Different payload',
            'op-mismatch-1'
        );
    }

    public function test_stale_expected_settlement_version_throws_conflict(): void
    {
        $receipt = $this->createCashierToManagerReceipt(100.00);

        // First settlement advances version to 1
        $this->service->settle(
            $receipt,
            $this->manager,
            'overpayment_return',
            1000,
            0,
            'First advance',
            'op-v1'
        );

        // Next settlement with expected version 0 must fail with 409
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('STALE_SETTLEMENT_VERSION');

        $this->service->settle(
            $receipt,
            $this->manager,
            'overpayment_return',
            500,
            0, // Stale! Expected is 1
            'Second attempt with stale version',
            'op-v2'
        );
    }

    public function test_sequential_legitimate_settlements_succeed(): void
    {
        $receipt = $this->createCashierToManagerReceipt(100.00);

        $s1 = $this->service->settle(
            $receipt,
            $this->manager,
            'overpayment_return',
            1000,
            0,
            'First 10 SAR return',
            'op-seq-1'
        );

        $s2 = $this->service->settle(
            $receipt,
            $this->manager,
            'overpayment_return',
            500,
            1, // Correct sequential version
            'Second 5 SAR return',
            'op-seq-2'
        );

        $this->assertNotEquals($s1->id, $s2->id);
        $this->assertEquals(2, ShiftHandoverSettlement::where('original_receipt_id', $receipt->id)->count());
    }

    public function test_overpayment_return_cannot_exceed_effective_confirmed_amount(): void
    {
        $receipt = $this->createCashierToManagerReceipt(100.00); // 10,000 halalas

        // First return: 80.00 SAR (8,000 halalas) -> 2,000 halalas remaining
        $this->service->settle(
            $receipt,
            $this->manager,
            'overpayment_return',
            8000,
            0,
            '80 SAR return',
            'op-cap-1'
        );

        // Second return of 30.00 SAR (3,000 halalas) exceeds remaining 2,000 halalas
        $this->expectException(ConflictHttpException::class);
        $this->expectExceptionMessage('OVERPAYMENT_RETURN_EXCEEDS_CONFIRMED_AMOUNT');

        $this->service->settle(
            $receipt,
            $this->manager,
            'overpayment_return',
            3000,
            1,
            'Exceeding remaining return',
            'op-cap-2'
        );
    }

    public function test_negative_or_zero_amount_throws_validation_exception(): void
    {
        $receipt = $this->createCashierToManagerReceipt(100.00);

        $this->expectException(ValidationException::class);

        $this->service->settle(
            $receipt,
            $this->manager,
            'overpayment_return',
            0,
            0,
            'Zero test',
            'op-zero'
        );
    }
}
