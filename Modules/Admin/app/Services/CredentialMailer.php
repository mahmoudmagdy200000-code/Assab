<?php

namespace Modules\Admin\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Notifications\Notification;
use Psr\Log\LoggerInterface;

/**
 * Single delivery point for mail that carries a credential (one-time passwords,
 * temporary passwords). Two rules the plain notify() call could not enforce:
 *
 * 1. Never hand a credential to the `log` mailer. LogTransport writes the fully
 *    rendered message to storage/logs/laravel.log and never throws, so a
 *    misconfigured MAIL_MAILER silently turned every one-time password into a
 *    plaintext log entry AND reported success.
 * 2. Report delivery honestly. Callers surface `emailSent` to the dashboard,
 *    which promises the user an email; a flag that is true whenever nothing
 *    threw is worse than no flag at all.
 *
 * The `array` mailer is deliberately allowed: it is the test transport, and
 * Notification::fake() intercepts before any transport is reached.
 */
class CredentialMailer
{
    private const UNDELIVERABLE_MAILERS = ['log'];

    public function __construct(
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    /** Whether the configured mailer can carry a credential off this machine. */
    public function deliverable(): bool
    {
        return ! in_array($this->config->get('mail.default'), self::UNDELIVERABLE_MAILERS, true);
    }

    /**
     * Best-effort send: a mail-transport outage must not fail the surrounding
     * request (an admin can always re-issue the password), but it must not be
     * reported as delivered either.
     *
     * @param  \Illuminate\Notifications\Notifiable  $notifiable
     */
    public function send($notifiable, Notification $notification): bool
    {
        if (! $this->deliverable()) {
            $this->logger->warning('Credential email suppressed: MAIL_MAILER is '.
                $this->config->get('mail.default').', which would write the password to the log. Configure a real mailer.');

            return false;
        }

        try {
            $notifiable->notify($notification);

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('Credential email failed: '.$e->getMessage());

            return false;
        }
    }
}
