<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\Shift;
use Tests\TestCase;

/**
 * Reviewer probe (NOT for commit, isolated SQLite only — no staging, no real money):
 * Does POST branch-manager/shifts/{shift}/start-handover (the route the Flutter app uses)
 * accept a next_cashier_id that is (a) in another branch, (b) deactivated, (c) the sender?
 * Contrast with GET .../available-recipients which excludes all three.
 */
class ReviewerProbeStartHandoverEligibilityTest extends TestCase
{
    use RefreshDatabase;

    private function completedShiftFor(Cashier $cashier, Shift $template): CashierShift
    {
        $shift = CashierShift::create([
            'cashier_id' => $cashier->id, 'shift_id' => $template->id,
            'shift_date' => today()->toDateString(), 'status' => ShiftStatus::NOT_STARTED->value,
        ]);
        $shift->update(['status' => ShiftStatus::IN_PROGRESS, 'actual_start_time' => now()]);
        $this->actingAs($cashier, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shift->id}/end", [
            'total_sales' => '100.00', 'cash_collected' => '100.00', 'counted_cash' => '100.00',
        ]);

        return $shift->fresh();
    }

    /** @return array{0:int,1:?string} */
    private function attempt(Cashier $sender, CashierShift $shift, string $recipientId): array
    {
        $res = $this->actingAs($sender, 'sanctum')->postJson("/api/v1/branch-manager/shifts/{$shift->id}/start-handover", [
            'handover_to_type' => 'cashier', 'next_cashier_id' => $recipientId, 'handover_amount' => 100,
        ]);
        $row = CashierShiftHandover::where('cashier_shift_id', $shift->id)->where('handover_to_id', $recipientId)->first();
        $info = $row
            ? sprintf('HANDOVER_ROW_PERSISTED to_type=%s to_id=recipient amount=%s status=%s', $row->handover_to_type, $row->handover_amount, $row->status)
            : 'no_row';

        return [$res->status(), $info.' | '.substr((string) $res->json('message'), 0, 80)];
    }

    public function test_start_handover_recipient_eligibility(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        BranchManager::factory()->create(['branch_id' => $branchA->id, 'is_active' => true, 'status' => 'active']);
        $creatorB = BranchManager::factory()->create(['branch_id' => $branchB->id, 'is_active' => true, 'status' => 'active']);
        $creatorA = BranchManager::query()->where('branch_id', $branchA->id)->value('id');

        $templateA = Shift::factory()->create(['branch_id' => $branchA->id, 'is_active' => true]);

        $otherBranchCashier = Cashier::factory()->create(['branch_id' => $branchB->id, 'created_by' => $creatorB->id]);
        $deactivatedSameBranch = Cashier::factory()->create(['branch_id' => $branchA->id, 'created_by' => $creatorA, 'status' => 'deactivated']);

        $cases = [
            'other_branch_cashier' => $otherBranchCashier,
            'deactivated_same_branch' => $deactivatedSameBranch,
            'self' => null,
        ];

        foreach ($cases as $label => $recipient) {
            $sender = Cashier::factory()->create(['branch_id' => $branchA->id, 'created_by' => $creatorA]);
            $shift = $this->completedShiftFor($sender, $templateA);
            fwrite(STDERR, "\n[$label] source shift status=".($shift->status->value ?? $shift->status));
            [$code, $info] = $this->attempt($sender, $shift, $recipient?->id ?? $sender->id);
            fwrite(STDERR, " | start-handover HTTP $code | $info\n");

            $avail = $this->actingAs($sender, 'sanctum')->getJson("/api/v1/branch-manager/shifts/{$shift->id}/available-recipients");
            $ids = collect($avail->json('data.recipients') ?? $avail->json('data') ?? [])->pluck('id')->map(fn ($v) => (string) $v)->all();
            fwrite(STDERR, "   available-recipients HTTP ".$avail->status().' includes recipient? '.(in_array((string) ($recipient?->id ?? $sender->id), $ids, true) ? 'YES' : 'NO')."\n");
        }

        $this->assertTrue(true);
    }
}
