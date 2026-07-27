<?php

namespace Modules\Notification\Console\Commands;

use Illuminate\Console\Command;
use Modules\Notification\Services\DeviceTokenService;

/**
 * Deletes registration tokens no device has used for a long time.
 *
 * Dead tokens are also pruned reactively when FCM rejects them, but a device
 * that is simply never opened again never produces a rejection — it just sits
 * in the table making every fan-out for that user slower.
 */
class PruneDeviceTokensCommand extends Command
{
    protected $signature = 'notification:prune-device-tokens
                            {--days= : Override the configured staleness window}';

    protected $description = 'Delete FCM device tokens that have gone unused';

    public function handle(DeviceTokenService $deviceTokenService): int
    {
        $days = (int) ($this->option('days') ?: config('notification.device_tokens.stale_after_days', 180));

        if ($days < 1) {
            $this->error('The staleness window must be at least 1 day.');

            return self::FAILURE;
        }

        $deleted = $deviceTokenService->pruneStale($days);

        $this->info("Pruned {$deleted} device token(s) unused for {$days}+ days.");

        return self::SUCCESS;
    }
}
