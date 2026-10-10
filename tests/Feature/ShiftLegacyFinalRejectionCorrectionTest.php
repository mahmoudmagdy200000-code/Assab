<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabCompany;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Custody\Models\CashierCustodyTransaction;
use Modules\Shift\Enums\HandoverStatus;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\CashierShiftHandoverReceipt;
use Modules\Shift\Models\Shift;
use Modules\Shift\Models\ShiftHandoverStatus;
use Modules\Shift\Models\ShiftReportCashCount;
use Modules\Shift\Models\ShiftReportRevisionSnapshot;
use Modules\Shift\Services\ShiftReportRevisionService;
use Tests\TestCase;

class ShiftLegacyFinalRejectionCorrectionTest extends TestCase
{
    use RefreshDatabase;

    public function test_completed_legacy_final_rejection_can_reopen_count_and_confirm_once(): void
    {
        $company = AsabCompany::create(['name' => 'Legacy Co', 'plan' => 'Professional', 'status' => 'active']);
        $brand = AsabBrand::create(['company_id' => $company->id, 'name' => 'Legacy B', 'sub_status' => 'active', 'status' => 'active']);
        $branch = Branch::factory()->create(['asab_brand_id' => $brand->id, 'asab_company_id' => $company->id]);
        $manager = BranchManager::factory()->create(['branch_id' => $branch->id, 'is_active' => true, 'status' => 'active']);
        $sender = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $recipient = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $manager->id]);
        $template = Shift::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $source = CashierShift::factory()->completed()->create([
            'cashier_id' => $sender->id, 'shift_id' => $template->id, 'shift_date' => today(),
            'next_cashier_id' => $recipient->id, 'total_sales' => '100.00', 'cash_collected' => '100.00',
            'card_payments' => '0.00', 'closing_balance' => '120.00', 'handover_notes' => 'Old report notes',
        ]);
        $receiving = CashierShift::factory()->create([
            'cashier_id' => $recipient->id, 'shift_id' => $template->id, 'shift_date' => today(),
            'status' => ShiftStatus::IN_PROGRESS, 'actual_start_time' => now(),
        ]);
        $oldRevision = app(ShiftReportRevisionService::class)->recordCashierRevision($source, 'cashier', $sender->id, 0);
        $handover = CashierShiftHandover::create([
            'cashier_shift_id' => $source->id, 'handover_to_id' => $recipient->id, 'handover_to_type' => 'cashier',
            'handover_amount' => '120.00', 'variance_amount' => '0.00', 'handover_notes' => 'Old request notes',
            'handover_date' => today()->toDateString(), 'handover_time' => now(), 'status' => 'rejected_final',
            'rejection_reason' => 'Wrong amount', 'rejection_count' => 2, 'report_revision_id' => $oldRevision->id,
        ]);
        ShiftHandoverStatus::create([
            'cashier_shift_id' => $source->id, 'status' => HandoverStatus::REJECTED,
            'manager_approval_status' => 'rejected_final', 'rejection_count' => 2,
            'rejection_reason' => 'Wrong amount', 'reviewed_by_id' => $recipient->id,
            'reviewed_by_type' => Cashier::class, 'reviewed_at' => now(),
        ]);
        $sales = CashierCustodyTransaction::create([
            'cashier_id' => $sender->id, 'transaction_type' => 'Total Sales', 'amount' => '100.00',
            'is_cash_in' => true, 'related_shift_id' => $source->id, 'transaction_date' => now(),
        ]);
        $this->assertSame(0, ShiftReportCashCount::count());

        $this->actingAs($sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$source->id}/handover/edit", [
            'handover_amount' => '100.00', 'correction_reason' => 'input_error', 'handover_notes' => 'Corrected request',
        ])->assertOk();
        $this->assertSame(ShiftStatus::IN_PROGRESS, $source->fresh()->status);
        $this->assertSame('pending', $handover->fresh()->status);
        $oldSnapshot = ShiftReportRevisionSnapshot::where('report_revision_id', $oldRevision->id)->firstOrFail();
        $this->assertSame('completed', $oldSnapshot->snapshot_data['status']);
        $this->assertSame('120.00', $oldSnapshot->snapshot_data['closing_balance']);
        $this->assertSame('Old report notes', $oldSnapshot->snapshot_data['handover_notes']);
        $this->assertSame('Old request notes', $oldSnapshot->snapshot_data['handover']['handover_notes']);
        $this->assertSame('rejected_final', $oldSnapshot->snapshot_data['handover']['status']);
        $this->assertSame('Wrong amount', $oldSnapshot->snapshot_data['handover']['rejection_reason']);
        $oldSnapshotData = $oldSnapshot->snapshot_data;
        $this->assertSame(0, ShiftReportCashCount::count(), 'Editing cannot invent physical count evidence.');
        $this->actingAs($recipient, 'sanctum')->postJson("/api/v1/cashier/shifts/{$source->id}/handover/accept", [
            'confirmed_amount' => '100.00', 'receiving_shift_id' => $receiving->id,
        ])->assertStatus(409)->assertJsonPath('code', 'REPORT_COUNT_REQUIRED');

        $this->actingAs($sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$source->id}/end", [
            'total_sales' => '100.00', 'cash_collected' => '100.00', 'counted_cash' => '100.00',
        ])->assertOk();
        $current = app(ShiftReportRevisionService::class)->currentCashierRevision($source->fresh());
        $this->assertNotSame($oldRevision->id, $current->id);
        $this->assertDatabaseHas('shift_report_cash_counts', [
            'report_revision_id' => $current->id, 'counted_revision_id' => $current->id,
            'counted_halalas' => 10000, 'expected_halalas' => 10000, 'variance_halalas' => 0,
        ]);
        $this->assertSame($current->id, $handover->fresh()->report_revision_id);
        $this->assertNotNull($sales->fresh());
        $this->assertSame(1, CashierCustodyTransaction::where('related_shift_id', $source->id)->where('transaction_type', 'Total Sales')->count());

        $payload = ['confirmed_amount' => '100.00', 'receiving_shift_id' => $receiving->id];
        $this->actingAs($sender, 'sanctum')->postJson("/api/v1/cashier/shifts/{$source->id}/handover/accept", $payload)->assertForbidden();
        $this->actingAs($recipient, 'sanctum')->postJson("/api/v1/cashier/shifts/{$source->id}/handover/accept", $payload)->assertOk();
        $this->assertSame(ShiftStatus::COMPLETED, $source->fresh()->status);
        $this->assertSame(1, CashierShiftHandoverReceipt::count());
        $this->assertSame('100.00', (string) $receiving->fresh()->opening_balance);
        $posted = CashierCustodyTransaction::orderBy('id')->get()->map->getAttributes()->all();
        $this->actingAs($recipient, 'sanctum')->postJson("/api/v1/cashier/shifts/{$source->id}/handover/accept", $payload)->assertStatus(409);
        $this->assertSame(1, CashierShiftHandoverReceipt::count());
        $this->assertSame($posted, CashierCustodyTransaction::orderBy('id')->get()->map->getAttributes()->all());
        $this->assertSame($oldSnapshotData, $oldSnapshot->fresh()->snapshot_data);
    }
}
