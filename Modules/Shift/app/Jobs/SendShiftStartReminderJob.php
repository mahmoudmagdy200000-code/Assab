<?php

namespace Modules\Shift\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Services\ShiftNotificationService;

class SendShiftStartReminderJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 60;
    public $tries = 3;

    public function __construct(
        public CashierShift $shift
    ) {}

    public function handle(ShiftNotificationService $notificationService): void
    {
        // Send notification 15 minutes before shift starts
        $notificationService->notifyShiftStart($this->shift);
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Failed to send shift start reminder', [
            'shift_id' => $this->shift->id,
            'error' => $exception->getMessage(),
        ]);
    }
}
