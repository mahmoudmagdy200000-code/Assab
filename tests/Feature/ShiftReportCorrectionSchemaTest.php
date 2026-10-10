<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use LogicException;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\ShiftReportAggregate;
use Modules\Shift\Models\ShiftReportCorrection;
use Modules\Shift\Models\ShiftReportRevision;
use Tests\TestCase;

class ShiftReportCorrectionSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_shift_report_corrections_table_has_expected_columns(): void
    {
        $this->assertTrue(Schema::hasTable('shift_report_corrections'));

        $expectedColumns = [
            'id',
            'report_aggregate_id',
            'previous_revision_id',
            'new_revision_id',
            'previous_revision_number',
            'new_revision_number',
            'field_name',
            'field_type',
            'old_value',
            'new_value',
            'old_halalas',
            'new_halalas',
            'reason',
            'actor_type',
            'actor_id',
            'company_id',
            'branch_id',
            'operation_id',
            'created_at',
        ];

        foreach ($expectedColumns as $col) {
            $this->assertTrue(
                Schema::hasColumn('shift_report_corrections', $col),
                "Missing column: {$col} on shift_report_corrections"
            );
        }
    }

    public function test_shift_report_cash_counts_has_evidence_revision_id_column(): void
    {
        $this->assertTrue(Schema::hasTable('shift_report_cash_counts'));
        $this->assertTrue(
            Schema::hasColumn('shift_report_cash_counts', 'evidence_revision_id'),
            'Missing column evidence_revision_id on shift_report_cash_counts'
        );
    }

    public function test_shift_report_correction_model_is_immutable(): void
    {
        $shift = CashierShift::factory()->create();
        $aggregate = ShiftReportAggregate::create([
            'source_type' => 'cashier_shift',
            'source_id' => $shift->id,
            'current_revision_number' => 1,
        ]);

        $rev1 = ShiftReportRevision::create([
            'report_aggregate_id' => $aggregate->id,
            'revision_number' => 1,
            'created_by_type' => 'cashier',
            'created_by_id' => $shift->cashier_id,
        ]);

        $rev2 = ShiftReportRevision::create([
            'report_aggregate_id' => $aggregate->id,
            'revision_number' => 2,
            'created_by_type' => 'cashier',
            'created_by_id' => $shift->cashier_id,
        ]);

        $correction = ShiftReportCorrection::create([
            'report_aggregate_id' => $aggregate->id,
            'previous_revision_id' => $rev1->id,
            'new_revision_id' => $rev2->id,
            'previous_revision_number' => 1,
            'new_revision_number' => 2,
            'field_name' => 'card_payments',
            'field_type' => 'monetary',
            'old_value' => '20.00',
            'new_value' => '25.00',
            'old_halalas' => 2000,
            'new_halalas' => 2500,
            'reason' => 'Receipt audit adjustment',
            'actor_type' => 'cashier',
            'actor_id' => $shift->cashier_id,
            'company_id' => $shift->company_id,
            'branch_id' => $shift->branch_id,
            'operation_id' => (string) Str::uuid(),
        ]);

        $this->expectException(LogicException::class);
        $correction->update(['reason' => 'tampered']);
    }

    public function test_shift_report_correction_model_cannot_be_deleted(): void
    {
        $shift = CashierShift::factory()->create();
        $aggregate = ShiftReportAggregate::create([
            'source_type' => 'cashier_shift',
            'source_id' => $shift->id,
            'current_revision_number' => 1,
        ]);

        $rev1 = ShiftReportRevision::create([
            'report_aggregate_id' => $aggregate->id,
            'revision_number' => 1,
            'created_by_type' => 'cashier',
            'created_by_id' => $shift->cashier_id,
        ]);

        $rev2 = ShiftReportRevision::create([
            'report_aggregate_id' => $aggregate->id,
            'revision_number' => 2,
            'created_by_type' => 'cashier',
            'created_by_id' => $shift->cashier_id,
        ]);

        $correction = ShiftReportCorrection::create([
            'report_aggregate_id' => $aggregate->id,
            'previous_revision_id' => $rev1->id,
            'new_revision_id' => $rev2->id,
            'previous_revision_number' => 1,
            'new_revision_number' => 2,
            'field_name' => 'card_payments',
            'field_type' => 'monetary',
            'old_value' => '20.00',
            'new_value' => '25.00',
            'old_halalas' => 2000,
            'new_halalas' => 2500,
            'reason' => 'Receipt audit adjustment',
            'actor_type' => 'cashier',
            'actor_id' => $shift->cashier_id,
            'company_id' => $shift->company_id,
            'branch_id' => $shift->branch_id,
            'operation_id' => (string) Str::uuid(),
        ]);

        $this->expectException(LogicException::class);
        $correction->delete();
    }
}
