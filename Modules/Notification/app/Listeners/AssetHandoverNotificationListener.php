<?php

namespace Modules\Notification\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Modules\FixedAssets\Enums\HandoverStatus;
use Modules\FixedAssets\Events\HandoverReceiverSigned;
use Modules\FixedAssets\Events\HandoverSenderSigned;
use Modules\FixedAssets\Events\HandoverStarted;
use Modules\FixedAssets\Events\HandoverStatusChanged;
use Modules\Notification\Contracts\NotificationServiceInterface;
use Modules\Notification\Enums\NotificationType;

/**
 * Fixed-asset handover sessions → push.
 *
 * These events already broadcast over Pusher to drive the live signing screen.
 * Push covers the other half: the counterparty is usually NOT looking at the
 * app when a session opens or a signature lands on them.
 */
class AssetHandoverNotificationListener implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private readonly NotificationServiceInterface $notificationService
    ) {}

    public function handle(
        HandoverStarted|HandoverSenderSigned|HandoverReceiverSigned|HandoverStatusChanged $event
    ): void {
        $handover = $event->handover;
        $handover->loadMissing(['recipient', 'sender']);

        $payload = [
            'handover_id' => $handover->id,
            'session_code' => $handover->session_code,
            'branch_id' => $handover->branch_id,
        ];

        match (true) {
            // The session just opened — tell the receiving party to join it.
            $event instanceof HandoverStarted => $this->notify(
                $handover->recipient,
                NotificationType::ASSET_HANDOVER_STARTED,
                $payload
            ),

            // The sender signed; the receiver is now the blocking party.
            $event instanceof HandoverSenderSigned => $this->notify(
                $handover->recipient,
                NotificationType::ASSET_HANDOVER_SIGNATURE_REQUIRED,
                $payload
            ),

            // The receiver signed; the sender is waiting on that confirmation.
            $event instanceof HandoverReceiverSigned => $this->notify(
                $handover->sender,
                NotificationType::ASSET_HANDOVER_COMPLETED,
                $payload
            ),

            $event instanceof HandoverStatusChanged => $this->onStatusChanged($handover, $payload),
        };
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function onStatusChanged(object $handover, array $payload): void
    {
        // Only the terminal transition is worth a push; intermediate status
        // churn is what the Pusher channel is for.
        if ($handover->status !== HandoverStatus::COMPLETED) {
            return;
        }

        $this->notify($handover->sender, NotificationType::ASSET_HANDOVER_COMPLETED, $payload);
        $this->notify($handover->recipient, NotificationType::ASSET_HANDOVER_COMPLETED, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function notify(?object $recipient, NotificationType $type, array $payload): void
    {
        // recipient is a MorphTo and may point at a deleted or unsupported row.
        if ($recipient === null || ! method_exists($recipient, 'notifications')) {
            return;
        }

        $this->notificationService->send($recipient, $type, $payload);
    }
}
