<?php

namespace Modules\Notification\Services;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Mail\Mailer;
use Modules\Notification\Mail\NotificationMail;
use Modules\Notification\Services\SmsProviders\SmsProviderInterface;
use Psr\Log\LoggerInterface;

/**
 * Single delivery point for one-time passwords. Every portal (brand owner,
 * brand manager, branch manager, supplier, cashier) reached for its own private
 * `sendOtpByEmail()`; three of them were `Log::info()` stubs, so the code was
 * written to the log and the caller still reported success.
 *
 * Two rules a plain `Mail::to()` call cannot enforce:
 *
 * 1. Never hand an OTP to the `log` mailer. LogTransport renders the message
 *    into storage/logs/laravel.log and never throws — a misconfigured
 *    MAIL_MAILER turns every OTP into a plaintext log entry AND reports sent.
 * 2. Report delivery honestly, so a caller can tell the client the code was not
 *    sent instead of leaving them waiting on a mail that never left the server.
 *
 * The `array` mailer is deliberately allowed: it is the test transport.
 *
 * @see \Modules\Admin\Services\CredentialMailer the same guard for passwords.
 */
class OtpDeliveryService
{
    private const UNDELIVERABLE_MAILERS = ['log'];

    public function __construct(
        private readonly Mailer $mailer,
        private readonly SmsProviderInterface $smsProvider,
        private readonly Repository $config,
        private readonly LoggerInterface $logger,
    ) {}

    /** Whether the configured mailer can carry an OTP off this machine. */
    public function deliverable(): bool
    {
        return ! in_array($this->config->get('mail.default'), self::UNDELIVERABLE_MAILERS, true);
    }

    /**
     * Best-effort OTP email. A mail-transport outage must not fail the
     * surrounding request (the user can always request another code), but it
     * must not be reported as delivered either.
     */
    public function sendEmail(string $email, string $otp, string $purpose = 'password_reset'): bool
    {
        if (! $this->deliverable()) {
            $this->logger->warning('OTP email suppressed: MAIL_MAILER is '.
                $this->config->get('mail.default').', which would write the code to the log. Configure a real mailer.');

            return false;
        }

        try {
            $this->mailer->to($email)->send(new NotificationMail(
                $this->subject($purpose),
                $this->body($otp, $purpose),
            ));

            return true;
        } catch (\Throwable $e) {
            // The code itself never reaches the log — only the transport error.
            $this->logger->warning('OTP email failed: '.$e->getMessage());

            return false;
        }
    }

    /** Best-effort OTP SMS; same honesty contract as sendEmail(). */
    public function sendSms(string $phone, string $otp, string $purpose = 'password_reset'): bool
    {
        try {
            $this->smsProvider->send($phone, $this->body($otp, $purpose));

            return true;
        } catch (\Throwable $e) {
            $this->logger->warning('OTP SMS failed: '.$e->getMessage());

            return false;
        }
    }

    private function subject(string $purpose): string
    {
        return match ($purpose) {
            'verification' => 'رمز التحقق — Verification Code',
            default => 'رمز إعادة تعيين كلمة المرور — Password Reset Code',
        };
    }

    private function body(string $otp, string $purpose): string
    {
        $line = match ($purpose) {
            'verification' => 'رمز التحقق الخاص بك / Your verification code is: '.$otp,
            default => 'رمز إعادة تعيين كلمة المرور الخاص بك / Your password reset code is: '.$otp,
        };

        return $line."\n".'الرمز صالح لمدة 10 دقائق / This code expires in 10 minutes.';
    }
}
