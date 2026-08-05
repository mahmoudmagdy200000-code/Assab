<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Enums\ShiftStatus;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\Shift;
use Tests\TestCase;

/**
 * Production 2026-08-05: saving a cashier raised a raw SQL dialog in the app —
 *   SQLSTATE[01000]: Warning: 1265 Data truncated for column 'status' at row 1
 *   (update `cashier_shifts` set `status` = cancelled …)
 *
 * The deactivation listener wrote 'cancelled' — a value present in neither the
 * `ShiftStatus` PHP enum ('canceled') nor the MySQL ENUM on the column — so no
 * cashier could ever be deactivated.
 */
class CashierDeactivationCancelsShiftsTest extends TestCase
{
    use RefreshDatabase;

    private ?Shift $slot = null;

    private function cashier(string $status = 'active'): Cashier
    {
        return Cashier::factory()->create(['status' => $status]);
    }

    /**
     * One shift slot per test: `shifts` is unique on (branch_id, start_time,
     * end_time), and `cashier_shifts` on (cashier_id, shift_id, shift_date) —
     * so rows differ by date or cashier, never by a second identical slot.
     */
    private function shift(Cashier $cashier, ShiftStatus $status, string $date): CashierShift
    {
        $this->slot ??= Shift::factory()->create();

        return CashierShift::factory()->create([
            'cashier_id' => $cashier->id,
            'shift_id' => $this->slot->id,
            'shift_date' => $date,
            'status' => $status,
        ]);
    }

    public function test_deactivating_a_cashier_cancels_their_future_pending_shifts(): void
    {
        $cashier = $this->cashier();
        $today = $this->shift($cashier, ShiftStatus::NOT_STARTED, today()->toDateString());
        $future = $this->shift($cashier, ShiftStatus::NOT_STARTED, today()->addDays(3)->toDateString());

        $cashier->update(['status' => 'deactivated']);

        $this->assertSame(ShiftStatus::CANCELED, $today->fresh()->status);
        $this->assertSame(ShiftStatus::CANCELED, $future->fresh()->status);
    }

    /** A running till and a closed past shift are financial records — untouched. */
    public function test_running_and_past_shifts_are_left_alone(): void
    {
        $cashier = $this->cashier();
        $running = $this->shift($cashier, ShiftStatus::IN_PROGRESS, today()->toDateString());
        $past = $this->shift($cashier, ShiftStatus::NOT_STARTED, today()->subDay()->toDateString());
        $done = $this->shift($cashier, ShiftStatus::COMPLETED, today()->addDay()->toDateString());

        $cashier->update(['status' => 'deactivated']);

        $this->assertSame(ShiftStatus::IN_PROGRESS, $running->fresh()->status);
        $this->assertSame(ShiftStatus::NOT_STARTED, $past->fresh()->status);
        $this->assertSame(ShiftStatus::COMPLETED, $done->fresh()->status);
    }

    public function test_another_cashiers_schedule_is_not_touched(): void
    {
        $cashier = $this->cashier();
        $colleague = $this->cashier();
        $mine = $this->shift($cashier, ShiftStatus::NOT_STARTED, today()->addDay()->toDateString());
        $theirs = $this->shift($colleague, ShiftStatus::NOT_STARTED, today()->addDay()->toDateString());

        $cashier->update(['status' => 'deactivated']);

        $this->assertSame(ShiftStatus::CANCELED, $mine->fresh()->status);
        $this->assertSame(ShiftStatus::NOT_STARTED, $theirs->fresh()->status);
    }

    /** A cancelled shift must drop off the app's upcoming list, not linger on it. */
    public function test_a_cancelled_shift_leaves_the_upcoming_scope(): void
    {
        $cashier = $this->cashier();
        $shift = $this->shift($cashier, ShiftStatus::NOT_STARTED, today()->addDay()->toDateString());

        $this->assertTrue(CashierShift::upcoming()->whereKey($shift->id)->exists());

        $cashier->update(['status' => 'deactivated']);

        $this->assertFalse(CashierShift::upcoming()->whereKey($shift->id)->exists());
    }

    /**
     * Editing a cashier without touching `status` must not cancel a schedule —
     * the side effects hang off a real status transition, nothing else.
     */
    public function test_an_unrelated_edit_does_not_cancel_anything(): void
    {
        $cashier = $this->cashier();
        $shift = $this->shift($cashier, ShiftStatus::NOT_STARTED, today()->addDay()->toDateString());

        $cashier->update(['name' => 'اسم جديد']);

        $this->assertSame(ShiftStatus::NOT_STARTED, $shift->fresh()->status);
    }

    /** Re-saving an already deactivated cashier is not a new transition. */
    public function test_deactivating_twice_does_not_re_cancel(): void
    {
        $cashier = $this->cashier('deactivated');
        $shift = $this->shift($cashier, ShiftStatus::NOT_STARTED, today()->addDay()->toDateString());

        $cashier->update(['status' => 'deactivated', 'name' => 'اسم آخر']);

        $this->assertSame(ShiftStatus::NOT_STARTED, $shift->fresh()->status);
    }
}
