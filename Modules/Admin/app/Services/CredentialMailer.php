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
    private const DELIVERABLE_TRANSPORTS = ['smtp', 'mailgun', 'ses', 'ses-v2', 'postmark', 'resend', 'sendmail', 'failover', 'roundrobin'];

    public function __construct(
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    /** Whether the configured mailer can carry a credential off this machine. */
    public function deliverable(): bool
    {
        $mailer = $this->config->get('mail.default');
        $mailers = $this->config->get('mail.mailers', []);

        if (! is_string($mailer) || ! is_array($mailers)) {
            return false;
        }

        return $this->containsUnsafeTransport($mailer, $mailers, []);
    }

    private function containsUnsafeTransport(string $mailer, array $mailers, array $visited): bool
    {
        if (in_array($mailer, $visited, true)) {
            return false;
        }

        $visited[] = $mailer;
        $config = $mailers[$mailer] ?? null;
        if (! is_array($config)) {
            return false;
        }

        $transport = $config['transport'] ?? null;
        $testArrayTransport = $transport === 'array' && $this->config->get('app.env') === 'testing';
        if (! $testArrayTransport && ! in_array($transport, self::DELIVERABLE_TRANSPORTS, true)) {
            return false;
        }

        $fallbacks = $config['mailers'] ?? [];
        if (! is_array($fallbacks)
            || (in_array($transport, ['failover', 'roundrobin'], true) && $fallbacks === [])) {
            return false;
        }

        foreach ($fallbacks as $fallback) {
            if (! $this->containsUnsafeTransport((string) $fallback, $mailers, $visited)) {
                return false;
            }
        }

        return true;
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
            $this->logger->warning('Credential email suppressed: configured mailer is not eligible for credential delivery.');

            return false;
        }

        try {
            $notifiable->notify($notification);

            return true;
        } catch (\Throwable $e) {
            // Mail transport exceptions may include rendered message bodies,
            // SMTP transcripts, addresses, or credentials. Keep logs content-free.
            $this->logger->warning('Credential email delivery failed.');

            return false;
        }
    }
}
