<?php

namespace Tests\Feature;

use Carbon\Carbon;
use Modules\Shift\Models\BranchShiftRosterAssignment;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Enums\ShiftStatus;

final class IndependentRosterAuditTest extends \Tests\Feature\ShiftRecurringRosterRenewalTest
{
    public function test_audit_empty_roster_contract_is_json_object(): void
    {
        $this->actingAs($this->manager, 'sanctum');
        $response = $this->getJson('/api/v1/branch-manager/cashiers/dated-roster?start_date=2026-11-15&end_date=2026-11-21')->assertOk();
        $this->assertIsObject(json_decode($response->getContent())->data->roster, 'Flutter requires Map; empty backend roster must serialize as object.');
    }

    public function test_audit_expired_roster_does_not_revive_historical_cashier(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-11 10:00:00'));
        try {
            BranchShiftRosterAssignment::create(['branch_id'=>$this->branch->id,'shift_id'=>$this->template1->id,'cashier_id'=>$this->cashierA->id,'effective_from'=>'2026-01-01','effective_to'=>'2026-11-12']);
            CashierShift::factory()->create(['cashier_id'=>$this->substituteCashier->id,'shift_id'=>$this->template1->id,'shift_date'=>'2026-11-10','status'=>ShiftStatus::COMPLETED]);
            $this->artisan('shifts:renew-week')->assertSuccessful();
            $this->assertSame(0, CashierShift::where('shift_id',$this->template1->id)->whereDate('shift_date','>=','2026-11-15')->count(), 'Expired roster must not revive historical substitute.');
        } finally { Carbon::setTestNow(); }
    }

    public function test_audit_deactivated_roster_cashier_is_not_renewed(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-11 10:00:00'));
        try {
            BranchShiftRosterAssignment::create(['branch_id'=>$this->branch->id,'shift_id'=>$this->template1->id,'cashier_id'=>$this->cashierA->id,'effective_from'=>'2026-01-01']);
            $this->cashierA->update(['status'=>'deactivated']);
            $this->artisan('shifts:renew-week')->assertSuccessful();
            $this->assertSame(0, CashierShift::where('cashier_id', $this->cashierA->id)->count(), 'Deactivated cashier must not receive new shifts.');
        } finally { Carbon::setTestNow(); }
    }

    public function test_audit_future_different_day_does_not_block_empty_slot(): void
    {
        $this->actingAs($this->manager, 'sanctum');
        $this->cashierA->update(['status'=>'active']);
        $this->cashierB->update(['status'=>'active']);
        $this->postJson('/api/v1/branch-manager/cashiers/assign-shifts',['cashier_id'=>$this->cashierA->id,'shift_ids'=>[$this->template1->id],'shift_date'=>'2026-11-18'])->assertOk();
        $this->postJson('/api/v1/branch-manager/cashiers/assign-shifts',['cashier_id'=>$this->cashierB->id,'shift_ids'=>[$this->template1->id],'shift_date'=>'2026-11-15'])->assertOk();
    }
}
