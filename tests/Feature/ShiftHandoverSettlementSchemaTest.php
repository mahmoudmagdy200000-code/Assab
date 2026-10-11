<?php

namespace Tests\Feature;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Custody\Models\PersonalLedgerTransaction;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftHandoverSettlement;
use Modules\Shift\Models\ShiftReportAggregate;
use Modules\Shift\Models\ShiftReportRevision;
use Modules\Shift\Models\ShiftTransferAttempt;
use RuntimeException;
use Tests\TestCase;

class ShiftHandoverSettlementSchemaTest extends TestCase
{
    use RefreshDatabase;

    private AsabCompany $company;

    private Branch $branch;

    private BranchManager $manager;

    private Cashier $cashier;

    private CashierShift $cashierShift;

    private CashierShiftHandoverReceipt $receipt;

    private ShiftTransferAttempt $attempt;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = AsabCompany::create(['name' => 'Settlement Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $this->company->id, 'name' => 'B', 'sub_status' => 'active', 'status' => 'active']);
        $this->branch = Branch::factory()->create(['asab_brand_id' => $brand->id, 'asab_company_id' => $this->company->id]);
        $this->manager = BranchManager::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier = Cashier::factory()->create(['branch_id' => $this->branch->id]);

        $shift = Shift::create([
            'branch_id' => $this->branch->id,
            'name' => 'Day Shift',
            'start_time' => '08:00:00',
            'end_time' => '16:00:00',
        ]);

        $this->cashierShift = CashierShift::create([
            'cashier_id' => $this->cashier->id,
            'shift_id' => $shift->id,
            'branch_id' => $this->branch->id,
            'shift_date' => today(),
            'status' => 'completed',
        ]);

        $aggregate = ShiftReportAggregate::create([
            'source_type' => 'cashier_shift',
            'source_id' => $this->cashierShift->id,
            'current_revision_number' => 1,
        ]);

        $revision = ShiftReportRevision::create([
            'report_aggregate_id' => $aggregate->id,
            'revision_number' => 1,
            'created_by_type' => 'cashier',
            'created_by_id' => $this->cashier->id,
        ]);

        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $this->cashierShift->id,
            'shift_id' => $shift->id,
            'branch_id' => $this->branch->id,
            'handover_type' => 'manager',
            'handover_to_type' => 'branch_manager',
            'handover_to_id' => $this->manager->id,
            'handover_amount' => 100.00,
            'declared_amount' => 100.00,
            'status' => 'approved',
            'handover_date' => today(),
            'handover_time' => now(),
        ]);

        $this->attempt = ShiftTransferAttempt::create([
            'request_type' => 'handover',
            'request_id' => $handover->id,
            'sequence' => 1,
            'report_revision_id' => $revision->id,
            'sender_type' => 'cashier',
            'sender_id' => $this->cashier->id,
            'recipient_type' => 'branch_manager',
            'recipient_id' => $this->manager->id,
            'source_branch_id' => $this->branch->id,
            'source_company_id' => $this->company->id,
            'presented_halalas' => 10000,
            'presented_at' => now(),
            'idempotency_key' => (string) Str::uuid(),
            'payload_hash' => hash('sha256', 'attempt-1'),
        ]);

        $this->receipt = CashierShiftHandoverReceipt::create([
            'cashier_shift_handover_id' => $handover->id,
            'receiving_cashier_shift_id' => null,
            'receiving_cashier_id' => null,
            'receiving_branch_manager_id' => $this->manager->id,
            'confirmed_by_branch_manager_id' => $this->manager->id,
            'report_revision_id' => $revision->id,
            'transfer_attempt_id' => $this->attempt->id,
            'confirmed_amount' => 100.00,
            'confirmed_at' => now(),
        ]);
    }

    public function test_migration_creates_settlement_table_with_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('shift_handover_settlements'));

        $expectedColumns = [
            'id',
            'original_receipt_id',
            'original_attempt_id',
            'original_request_type',
            'original_request_id',
            'settlement_type',
            'signed_delta_halalas',
            'reason',
            'operation_id',
            'payload_hash',
            'sender_type',
            'sender_id',
            'recipient_type',
            'recipient_id',
            'actor_type',
            'actor_id',
            'company_id',
            'branch_id',
            'created_at',
        ];

        foreach ($expectedColumns as $col) {
            $this->assertTrue(Schema::hasColumn('shift_handover_settlements', $col), "Missing column: {$col}");
        }
    }

    public function test_migration_adds_settlement_id_to_custody_and_personal_ledger(): void
    {
        $this->assertTrue(Schema::hasColumn('cashier_custody_transactions', 'settlement_id'));
        $this->assertTrue(Schema::hasColumn('personal_ledger_transactions', 'settlement_id'));
    }

    public function test_settlement_model_is_immutable_on_update_and_delete(): void
    {
        $settlement = ShiftHandoverSettlement::create([
            'original_receipt_id' => $this->receipt->id,
            'original_attempt_id' => $this->attempt->id,
            'original_request_type' => 'handover',
            'original_request_id' => $this->attempt->request_id,
            'settlement_type' => 'overpayment_return',
            'signed_delta_halalas' => -2000,
            'reason' => 'Overpayment counted and returned',
            'operation_id' => 'op-immutable-1',
            'payload_hash' => hash('sha256', 'payload-1'),
            'sender_type' => 'cashier',
            'sender_id' => $this->cashier->id,
            'recipient_type' => 'branch_manager',
            'recipient_id' => $this->manager->id,
            'actor_type' => 'branch_manager',
            'actor_id' => $this->manager->id,
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
        ]);

        $this->expectException(LogicException::class);
        $settlement->update(['reason' => 'Changed reason']);
    }

    public function test_settlement_model_refuses_deletion(): void
    {
        $settlement = ShiftHandoverSettlement::create([
            'original_receipt_id' => $this->receipt->id,
            'original_attempt_id' => $this->attempt->id,
            'original_request_type' => 'handover',
            'original_request_id' => $this->attempt->request_id,
            'settlement_type' => 'overpayment_return',
            'signed_delta_halalas' => -2000,
            'reason' => 'Overpayment counted and returned',
            'operation_id' => 'op-immutable-2',
            'payload_hash' => hash('sha256', 'payload-2'),
            'sender_type' => 'cashier',
            'sender_id' => $this->cashier->id,
            'recipient_type' => 'branch_manager',
            'recipient_id' => $this->manager->id,
            'actor_type' => 'branch_manager',
            'actor_id' => $this->manager->id,
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
        ]);

        $this->expectException(LogicException::class);
        $settlement->delete();
    }

    public function test_unique_constraint_on_receipt_and_operation_id(): void
    {
        ShiftHandoverSettlement::create([
            'original_receipt_id' => $this->receipt->id,
            'original_attempt_id' => $this->attempt->id,
            'original_request_type' => 'handover',
            'original_request_id' => $this->attempt->request_id,
            'settlement_type' => 'overpayment_return',
            'signed_delta_halalas' => -1000,
            'reason' => 'First attempt',
            'operation_id' => 'op-unique-test',
            'payload_hash' => hash('sha256', 'payload-1'),
            'sender_type' => 'cashier',
            'sender_id' => $this->cashier->id,
            'recipient_type' => 'branch_manager',
            'recipient_id' => $this->manager->id,
            'actor_type' => 'branch_manager',
            'actor_id' => $this->manager->id,
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
        ]);

        $this->expectException(QueryException::class);
        ShiftHandoverSettlement::create([
            'original_receipt_id' => $this->receipt->id,
            'original_attempt_id' => $this->attempt->id,
            'original_request_type' => 'handover',
            'original_request_id' => $this->attempt->request_id,
            'settlement_type' => 'overpayment_return',
            'signed_delta_halalas' => -1000,
            'reason' => 'Duplicate attempt with same operation_id',
            'operation_id' => 'op-unique-test',
            'payload_hash' => hash('sha256', 'payload-2'),
            'sender_type' => 'cashier',
            'sender_id' => $this->cashier->id,
            'recipient_type' => 'branch_manager',
            'recipient_id' => $this->manager->id,
            'actor_type' => 'branch_manager',
            'actor_id' => $this->manager->id,
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
        ]);
    }

    public function test_polymorphic_parties_with_same_id_do_not_collide(): void
    {
        $sharedId = (string) Str::uuid();

        $settlement = ShiftHandoverSettlement::create([
            'original_receipt_id' => $this->receipt->id,
            'original_attempt_id' => $this->attempt->id,
            'original_request_type' => 'handover',
            'original_request_id' => $this->attempt->request_id,
            'settlement_type' => 'overpayment_return',
            'signed_delta_halalas' => -1000,
            'reason' => 'Shared ID test',
            'operation_id' => 'op-shared-id',
            'payload_hash' => hash('sha256', 'payload-3'),
            'sender_type' => 'cashier',
            'sender_id' => $sharedId,
            'recipient_type' => 'branch_manager',
            'recipient_id' => $sharedId,
            'actor_type' => 'branch_manager',
            'actor_id' => $sharedId,
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
        ]);

        $this->assertEquals('cashier', $settlement->sender_type);
        $this->assertEquals('branch_manager', $settlement->recipient_type);
        $this->assertEquals($sharedId, $settlement->sender_id);
        $this->assertEquals($sharedId, $settlement->recipient_id);
    }

    public function test_custody_settlement_unique_constraint(): void
    {
        $settlement = ShiftHandoverSettlement::create([
            'original_receipt_id' => $this->receipt->id,
            'original_attempt_id' => $this->attempt->id,
            'original_request_type' => 'handover',
            'original_request_id' => $this->attempt->request_id,
            'settlement_type' => 'overpayment_return',
            'signed_delta_halalas' => -1000,
            'reason' => 'Custody unique test',
            'operation_id' => 'op-custody-unique',
            'payload_hash' => hash('sha256', 'payload-4'),
            'sender_type' => 'cashier',
            'sender_id' => $this->cashier->id,
            'recipient_type' => 'branch_manager',
            'recipient_id' => $this->manager->id,
            'actor_type' => 'branch_manager',
            'actor_id' => $this->manager->id,
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
        ]);

        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier->id,
            'settlement_id' => $settlement->id,
            'transaction_type' => 'settlement_overpayment_return',
            'amount' => 10.00,
            'is_cash_in' => true,
            'counterpart_name' => 'Manager Return',
            'transaction_date' => now(),
            'category' => 'handover_settlement',
        ]);

        $this->expectException(QueryException::class);
        CashierCustodyTransaction::create([
            'cashier_id' => $this->cashier->id,
            'settlement_id' => $settlement->id,
            'transaction_type' => 'settlement_overpayment_return',
            'amount' => 10.00,
            'is_cash_in' => true,
            'counterpart_name' => 'Duplicate Custody',
            'transaction_date' => now(),
            'category' => 'handover_settlement',
        ]);
    }

    public function test_personal_ledger_settlement_unique_constraint(): void
    {
        $settlement = ShiftHandoverSettlement::create([
            'original_receipt_id' => $this->receipt->id,
            'original_attempt_id' => $this->attempt->id,
            'original_request_type' => 'handover',
            'original_request_id' => $this->attempt->request_id,
            'settlement_type' => 'overpayment_return',
            'signed_delta_halalas' => -1000,
            'reason' => 'Ledger unique test',
            'operation_id' => 'op-ledger-unique',
            'payload_hash' => hash('sha256', 'payload-5'),
            'sender_type' => 'cashier',
            'sender_id' => $this->cashier->id,
            'recipient_type' => 'branch_manager',
            'recipient_id' => $this->manager->id,
            'actor_type' => 'branch_manager',
            'actor_id' => $this->manager->id,
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
        ]);

        PersonalLedgerTransaction::create([
            'branch_manager_id' => $this->manager->id,
            'settlement_id' => $settlement->id,
            'transaction_type' => 'settlement_overpayment_return',
            'amount' => 10.00,
            'is_cash_in' => false,
            'cashier_name' => 'Cashier Return',
            'transaction_date' => now(),
            'category' => 'handover_settlement',
        ]);

        $this->expectException(QueryException::class);
        PersonalLedgerTransaction::create([
            'branch_manager_id' => $this->manager->id,
            'settlement_id' => $settlement->id,
            'transaction_type' => 'settlement_overpayment_return',
            'amount' => 10.00,
            'is_cash_in' => false,
            'cashier_name' => 'Duplicate Ledger',
            'transaction_date' => now(),
            'category' => 'handover_settlement',
        ]);
    }

    public function test_down_migration_refuses_when_settlement_data_exists(): void
    {
        ShiftHandoverSettlement::create([
            'original_receipt_id' => $this->receipt->id,
            'original_attempt_id' => $this->attempt->id,
            'original_request_type' => 'handover',
            'original_request_id' => $this->attempt->request_id,
            'settlement_type' => 'overpayment_return',
            'signed_delta_halalas' => -1000,
            'reason' => 'Down refusal test',
            'operation_id' => 'op-down-refusal',
            'payload_hash' => hash('sha256', 'payload-6'),
            'sender_type' => 'cashier',
            'sender_id' => $this->cashier->id,
            'recipient_type' => 'branch_manager',
            'recipient_id' => $this->manager->id,
            'actor_type' => 'branch_manager',
            'actor_id' => $this->manager->id,
            'company_id' => $this->company->id,
            'branch_id' => $this->branch->id,
        ]);

        $migration = require base_path('Modules/Shift/database/migrations/2026_10_11_000001_create_shift_handover_settlements.php');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Shift handover settlements');
        $migration->down();
    }
}
