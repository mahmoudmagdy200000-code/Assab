<?php

namespace Tests\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Modules\Shift\Models\CashierShiftHandover;
use Modules\Shift\Models\ShiftTransferAttempt;
use Modules\Shift\Services\ShiftTransferAttemptService;

/**
 * S111-FINAL-03 (Business Rules v3.2): rejected cash is physically returned to the sender, and the sender confirms
 * that return, BEFORE the same request may be corrected. Fixtures that exercise the correction path must therefore
 * record the explicit presentation -> rejection -> return -> sender-confirmation chain. This helper does so through the
 * production services; it never writes evidence rows directly.
 */
trait ConfirmsPhysicalReturn
{
    /** Present the request's cash as a tracked attempt (sender action). */
    protected function presentAttempt(CashierShiftHandover $handover, Model $sender, int $presentedHalalas): ShiftTransferAttempt
    {
        return app(ShiftTransferAttemptService::class)->present('handover', $handover->id, $sender, $presentedHalalas, 'present-'.Str::uuid());
    }

    /** Recipient returns every retained halala of the rejected attempt and the sender confirms the return. */
    protected function returnAndConfirm(ShiftTransferAttempt $attempt, Model $recipient, Model $sender): void
    {
        $service = app(ShiftTransferAttemptService::class);
        $retained = $service->retained($attempt->fresh());
        if ($retained > 0) {
            $return = $service->initiate($attempt->id, $recipient, $retained, 'return-'.Str::uuid(), 'Rejected cash returned', null);
            $service->confirmReturn($return->id, $sender);
        }
        $this->assertSame(0, $service->retained($attempt->fresh()));
    }

    /**
     * HTTP-level chain for feature tests: sender presents the pending request, recipient rejects that attempt with the
     * physically counted amount, recipient returns the retained cash and the sender confirms it. Returns the attempt.
     */
    protected function httpRejectAndReturn(CashierShiftHandover $handover, Model $sender, Model $recipient, string $shiftId, string $physicalSar, string $reason = 'Counted differently'): ShiftTransferAttempt
    {
        $attempt = $this->presentAttempt($handover->fresh(), $sender, (int) round(((float) $handover->fresh()->handover_amount) * 100));
        $this->actingAs($recipient, 'sanctum')->postJson("/api/v1/cashier/shifts/{$shiftId}/handover/reject", [
            'rejection_reason' => $reason, 'confirmed_amount' => $physicalSar,
            'correction_reason' => 'actual_shortage', 'transfer_attempt_id' => $attempt->id,
        ])->assertOk();
        $this->returnAndConfirm($attempt, $recipient, $sender);

        return $attempt;
    }
}
