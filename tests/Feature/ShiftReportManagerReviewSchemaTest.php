<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ShiftReportManagerReviewSchemaTest extends TestCase
{
    use RefreshDatabase;

    public function test_shift_report_manager_reviews_table_and_aggregate_column_exist(): void
    {
        $this->assertTrue(Schema::hasTable('shift_report_manager_reviews'));
        $this->assertTrue(Schema::hasColumns('shift_report_manager_reviews', [
            'id',
            'company_id',
            'branch_id',
            'cashier_shift_id',
            'report_revision_id',
            'report_revision_number',
            'status',
            'variance_halalas',
            'has_shortage',
            'reviewer_type',
            'reviewer_id',
            'reviewed_at',
            'reason',
        ]));

        $this->assertTrue(Schema::hasColumn('shift_report_aggregates', 'required_manager_review_evidence_revision_id'));
    }
}
