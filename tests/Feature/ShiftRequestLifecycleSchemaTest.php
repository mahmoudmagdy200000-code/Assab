<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Modules\Shift\Models\BranchManagerCashTransfer;
use Modules\Shift\Models\CashierShiftHandover;
use Tests\TestCase;

class ShiftRequestLifecycleSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_transfer_request_tables_have_lifecycle_columns(): void
    {
        $expectedColumns = [
            'superseded_at',
            'supersedes_id',
            'cancelled_at',
            'cancelled_by_type',
            'cancelled_by_id',
            'cancellation_reason',
            'replacement_request_id',
        ];

        foreach (['cashier_shift_handovers', 'branch_manager_cash_transfers'] as $table) {
            foreach ($expectedColumns as $col) {
                $this->assertTrue(
                    Schema::hasColumn($table, $col),
                    "Table {$table} is missing expected column {$col}."
                );
            }
        }
    }

    public function test_models_have_cancellation_and_supersession_casts_and_relations(): void
    {
        $handover = new CashierShiftHandover;
        $this->assertTrue($handover->hasCast('superseded_at', 'datetime'));
        $this->assertTrue($handover->hasCast('cancelled_at', 'datetime'));
        $this->assertTrue(method_exists($handover, 'supersedes'));
        $this->assertTrue(method_exists($handover, 'replacementRequest'));
        $this->assertTrue(method_exists($handover, 'isCancelled'));
        $this->assertTrue(method_exists($handover, 'isSuperseded'));

        $transfer = new BranchManagerCashTransfer;
        $this->assertTrue($transfer->hasCast('superseded_at', 'datetime'));
        $this->assertTrue($transfer->hasCast('cancelled_at', 'datetime'));
        $this->assertTrue(method_exists($transfer, 'supersedes'));
        $this->assertTrue(method_exists($transfer, 'replacementRequest'));
        $this->assertTrue(method_exists($transfer, 'isCancelled'));
        $this->assertTrue(method_exists($transfer, 'isSuperseded'));
    }
}
