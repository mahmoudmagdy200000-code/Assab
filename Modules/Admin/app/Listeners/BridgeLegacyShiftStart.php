<?php

namespace Modules\Admin\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\Admin\Services\LegacyShiftMirror;
use Modules\Shift\Events\ShiftStartedEvent;

/**
 * MOB-1.6 (live half): a cashier starting their shift in the mobile app opens
 * the matching `asab_shifts` row, so the accountant's «مباشر» board shows the
 * shift while it runs instead of only after it closes.
 *
 * Best-effort: a mirroring failure must never break the cashier's start.
 */
class BridgeLegacyShiftStart
{
    public function __construct(private readonly LegacyShiftMirror $mirror) {}

    public function handle(ShiftStartedEvent $event): void
    {
        try {
            $this->mirror->open($event->shift);
        } catch (\Throwable $e) {
            Log::warning('Live shift mirror failed: '.$e->getMessage(), ['legacyShiftId' => $event->shift->id]);
        }
    }
}
