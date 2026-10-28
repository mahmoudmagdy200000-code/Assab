<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Branch\Models\Branch;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Tests\TestCase;

/**
 * Reviewer probe (NOT for commit, isolated SQLite):
 * The Flutter cashier "Start" button calls POST branch-manager/shifts/start-by-manager/{id}.
 * Can a CASHIER use it to start (a) another cashier's shift, (b) a future-dated own shift?
 * Contrast with POST cashier/shifts/{shift}/start.
 */
class ReviewerProbeStartByManagerAsCashierTest extends TestCase
{
    use RefreshDatabase;

    private function pending(Cashier $c, Shift $t, string $date): CashierShift
    {
        return CashierShift::create(['cashier_id' => $c->id, 'shift_id' => $t->id, 'shift_date' => $date, 'status' => ShiftStatus::NOT_STARTED->value]);
    }

    public function test_cashier_using_manager_start_endpoint(): void
    {
        $branch = Branch::factory()->create();
        $mgr = BranchManager::factory()->create(['branch_id' => $branch->id, 'is_active' => true, 'status' => 'active']);
        $tpl = Shift::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $actor = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $mgr->id]);
        $victim = Cashier::factory()->create(['branch_id' => $branch->id, 'created_by' => $mgr->id]);

        // (a) another cashier's shift today
        $other = $this->pending($victim, $tpl, today()->toDateString());
        $r = $this->actingAs($actor, 'sanctum')->postJson("/api/v1/branch-manager/shifts/start-by-manager/{$other->id}");
        fwrite(STDERR, "\n[a other-cashier shift via start-by-manager] HTTP ".$r->status().' | status now='.($other->fresh()->status->value ?? $other->fresh()->status));
        $tpl2 = Shift::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $other2 = $this->pending($victim, $tpl2, today()->toDateString());
        $r2 = $this->actingAs($actor, 'sanctum')->postJson("/api/v1/cashier/shifts/{$other2->id}/start");
        fwrite(STDERR, "\n[a' other-cashier shift via cashier/start] HTTP ".$r2->status().' | status now='.($other2->fresh()->status->value ?? $other2->fresh()->status));

        // (b) own future-dated shift
        $future = $this->pending($actor, $tpl, today()->addDays(3)->toDateString());
        $r3 = $this->actingAs($actor, 'sanctum')->postJson("/api/v1/branch-manager/shifts/start-by-manager/{$future->id}");
        fwrite(STDERR, "\n[b own future shift via start-by-manager] HTTP ".$r3->status().' | status now='.($future->fresh()->status->value ?? $future->fresh()->status));
        $tpl3 = Shift::factory()->create(['branch_id' => $branch->id, 'is_active' => true]);
        $future2 = $this->pending($actor, $tpl3, today()->addDays(4)->toDateString());
        $r4 = $this->actingAs($actor, 'sanctum')->postJson("/api/v1/cashier/shifts/{$future2->id}/start");
        fwrite(STDERR, "\n[b' own future shift via cashier/start] HTTP ".$r4->status().' | status now='.($future2->fresh()->status->value ?? $future2->fresh()->status)."\n");

        $this->assertTrue(true);
    }
}
