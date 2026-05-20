<?php

namespace Modules\Purchase\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Modules\Purchase\Events\OrderStatusChanged;

class SendOrderNotification implements ShouldQueue
{
    use InteractsWithQueue;

    public $afterCommit = true;

    public $tries = 4;

    public function backoff(): array
    {
        $jitter = random_int(1, 4);

        return [5 + $jitter, 20 + $jitter, 60 + $jitter, 120 + $jitter];
    }

    public function handle(OrderStatusChanged $event): void
    {
        $order = $event->order;
        $newStatus = $event->newStatus;

        Log::info("Order {$order->order_number} status changed to {$newStatus->value}");

        // Send notifications based on order type and channels
        $channels = $order->notification_channels ?? [];

        foreach ($channels as $channel) {
            // Dispatch notification based on channel
            // This would integrate with your notification system
            match ($channel) {
                'email' => $this->sendEmail($order, $newStatus),
                'whatsapp' => $this->sendWhatsApp($order, $newStatus),
                'app' => $this->sendInAppNotification($order, $newStatus),
                'sms' => $this->sendSMS($order, $newStatus),
                default => null,
            };
        }
    }

    private function sendEmail($order, $status): void
    {
        // Implement email notification
        Log::info("Sending email notification for order {$order->order_number}");
    }

    private function sendWhatsApp($order, $status): void
    {
        // Implement WhatsApp notification
        Log::info("Sending WhatsApp notification for order {$order->order_number}");
    }

    private function sendInAppNotification($order, $status): void
    {
        // Implement in-app notification
        Log::info("Sending in-app notification for order {$order->order_number}");
    }

    private function sendSMS($order, $status): void
    {
        // Implement SMS notification
        Log::info("Sending SMS notification for order {$order->order_number}");
    }
}
