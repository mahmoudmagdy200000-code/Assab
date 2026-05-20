<?php

namespace Modules\Cashier\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Modules\Shift\Models\CashierShift;

class ShiftAssignedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private CashierShift $shift
    ) {}

    public function via($notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail($notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('New Shift Assigned - Assab')
            ->greeting('Hello '.$notifiable->name.'!')
            ->line('A new shift has been assigned to you.')
            ->line('**Shift:** '.$this->shift->shift->name)
            ->line('**Date:** '.$this->shift->shift_date->format('d M Y'))
            ->line('**Time:** '.$this->shift->shift->start_time.' - '.$this->shift->shift->end_time)
            ->line('Please make sure to be on time.')
            ->action('View Shift Details', url('/cashier/shifts/'.$this->shift->id))
            ->salutation('Best regards, Assab Team');
    }

    public function toArray($notifiable): array
    {
        return [
            'type' => 'shift_assigned',
            'shift_id' => $this->shift->id,
            'shift_name' => $this->shift->shift->name,
            'shift_date' => $this->shift->shift_date->format('Y-m-d'),
            'start_time' => $this->shift->shift->start_time,
            'end_time' => $this->shift->shift->end_time,
            'message' => 'New shift assigned: '.$this->shift->shift->name.' on '.$this->shift->shift_date->format('d M Y'),
        ];
    }
}
