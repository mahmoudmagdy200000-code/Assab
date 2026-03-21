<?php

namespace Modules\Purchase\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Collection;
use Modules\Purchase\Models\PurchaseOrder;

class PurchaseOrderCreated extends Notification
{
    use Queueable;

    /**
     * @var Collection<int, PurchaseOrder>
     */
    public Collection $orders;

    /**
     * @param  PurchaseOrder|Collection<int, PurchaseOrder>  $orders
     */
    public function __construct(PurchaseOrder|Collection $orders)
    {
        $this->orders = $orders instanceof Collection ? $orders : collect([$orders]);
    }

    /**
     * Get the notification's delivery channels.
     */
    public function via($notifiable): array
    {
        return ['database'];
    }

    /**
     * Get the mail representation of the notification.
     */
    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->line('The introduction to the notification.')
            ->action('Notification Action', 'https://laravel.com')
            ->line('Thank you for using our application!');
    }

    /**
     * Get the array representation of the notification.
     */
    public function toArray($notifiable): array
    {
        $first = $this->orders->first();

        return [
            'order_count' => $this->orders->count(),
            'order_id' => $first?->id,
            'order_number' => $this->orders->count() === 1 ? $first?->order_number : null,
            'order_numbers' => $this->orders->pluck('order_number')->values()->all(),
            'status' => $first?->status,
            'order_type' => $first?->order_type,
        ];
    }
}
