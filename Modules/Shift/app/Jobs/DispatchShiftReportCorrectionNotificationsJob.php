<?php

namespace Modules\Shift\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Modules\Shift\Services\ShiftReportCorrectionNotificationService;

class DispatchShiftReportCorrectionNotificationsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public ?string $revisionId = null) {}

    public function handle(ShiftReportCorrectionNotificationService $service): void
    {
        $service->dispatchPending($this->revisionId);
    }
}
