<?php

namespace Modules\Admin\Console\Commands;

use Illuminate\Console\Command;
use Modules\Admin\Models\AsabSubscription;
use Modules\Admin\Services\NotificationService;
use Modules\Admin\Services\RealtimeBroadcaster;

/**
 * Daily check that flags subscriptions nearing expiry, recomputes days_left,
 * broadcasts `subscription.expiring` (BACKEND_API_SPEC.md §8) and notifies the
 * platform admins of each affected company.
 */
class CheckExpiringSubscriptions extends Command
{
    protected $signature = 'asab:subscriptions-expiry {--days=7 : Threshold in days}';

    protected $description = 'Broadcast subscription.expiring for subscriptions within the threshold';

    public function handle(RealtimeBroadcaster $rt, NotificationService $notifications): int
    {
        $threshold = (int) $this->option('days');
        $now = now();
        $count = 0;

        AsabSubscription::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '>=', $now)
            ->where('expires_at', '<=', $now->copy()->addDays($threshold))
            ->whereIn('status', ['active', 'warning'])
            ->chunkById(100, function ($subs) use ($rt, $notifications, $now, &$count) {
                foreach ($subs as $sub) {
                    $daysLeft = max(0, (int) $now->diffInDays($sub->expires_at, false));
                    $sub->update(['days_left' => $daysLeft, 'status' => $daysLeft <= 3 ? 'danger' : 'warning']);

                    $rt->subscriptionExpiring($sub, $daysLeft);

                    if ($sub->company_id) {
                        $notifications->pushToRole(
                            $sub->company_id, 'admin', 'subscription.expiring',
                            'اشتراك على وشك الانتهاء', "يتبقى {$daysLeft} يوم — الخطة: {$sub->plan}",
                            null, ['type' => 'subscription', 'id' => $sub->id],
                        );
                    }
                    $count++;
                }
            });

        $this->info("ASAB: {$count} expiring subscription(s) processed.");

        return self::SUCCESS;
    }
}
