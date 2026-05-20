<?php

namespace Modules\Notification\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Queue\SerializesModels;

class NotificationMail extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(
        public string $title,
        public string $message,
        public array $data = []
    ) {}

    public function build(): self
    {
        return $this->subject($this->title)
            ->view('notification::emails.notification')
            ->with([
                'title' => $this->title,
                'message' => $this->message,
                'data' => $this->data,
            ]);
    }
}
