<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Notifications\TwoFactorCodeNotification;
use PragmaRX\Google2FA\Google2FA;

/**
 * Two-factor authentication (FE completion request §3.1). TOTP secrets and
 * backup codes are encrypted at rest (AsabUser casts); backup codes are also
 * hashed. SMS delivers the current TOTP value over the notification channel.
 */
class TwoFactorService
{
    private Google2FA $totp;

    public function __construct()
    {
        $this->totp = new Google2FA;
    }

    /** Begin enrolment: stash an (unconfirmed) secret, return provisioning data. */
    public function setup(AsabUser $user, string $method): array
    {
        if ($user->twoFactorEnabled()) {
            throw new AsabException('TWO_FACTOR_ALREADY_ENABLED', '2FA is already enabled', 'المصادقة الثنائية مفعلة بالفعل', 409);
        }

        $secret = $this->totp->generateSecretKey();
        $user->forceFill([
            'two_factor_method' => $method,
            'two_factor_secret' => $secret,
            'two_factor_confirmed_at' => null,
            'two_factor_backup_codes' => null,
        ])->save();

        if ($method === 'sms') {
            $this->sendSmsCode($user, $secret);

            return ['sentTo' => $this->maskPhone($user->phone)];
        }

        return [
            'secret' => $secret,
            'qrCodeUrl' => $this->totp->getQRCodeUrl(config('app.name', 'ASAB'), $user->email, $secret),
        ];
    }

    /** Confirm enrolment with a valid code; returns one-time backup codes. */
    public function confirm(AsabUser $user, string $code): array
    {
        if (! $user->two_factor_secret) {
            throw new AsabException('TWO_FACTOR_NOT_SETUP', 'Start 2FA setup first', 'ابدأ إعداد المصادقة الثنائية أولاً', 409);
        }
        if (! $this->totp->verifyKey($user->two_factor_secret, $code)) {
            throw new AsabException('TWO_FACTOR_INVALID_CODE', 'Invalid verification code', 'رمز التحقق غير صحيح', 422);
        }

        $plain = $this->generateBackupCodes();
        $user->forceFill([
            'two_factor_confirmed_at' => now(),
            'two_factor_backup_codes' => array_map(fn ($c) => Hash::make($c), $plain),
        ])->save();

        return ['backupCodes' => $plain];
    }

    public function disable(AsabUser $user, string $code): void
    {
        if (! $user->twoFactorEnabled()) {
            throw new AsabException('TWO_FACTOR_NOT_ENABLED', '2FA is not enabled', 'المصادقة الثنائية غير مفعلة', 409);
        }
        if (! $this->verify($user, $code)) {
            throw new AsabException('TWO_FACTOR_INVALID_CODE', 'Invalid verification code', 'رمز التحقق غير صحيح', 422);
        }

        $user->forceFill([
            'two_factor_method' => null,
            'two_factor_secret' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_backup_codes' => null,
        ])->save();
    }

    public function status(AsabUser $user): array
    {
        return [
            'enabled' => $user->twoFactorEnabled(),
            'method' => $user->two_factor_method,
            'backupCodesRemaining' => count($user->two_factor_backup_codes ?? []),
        ];
    }

    /** Verify a login challenge: TOTP first, then consume a single-use backup code. */
    public function verify(AsabUser $user, string $code): bool
    {
        if ($user->two_factor_secret && $this->totp->verifyKey($user->two_factor_secret, $code)) {
            return true;
        }

        return $this->consumeBackupCode($user, $code);
    }

    /** Deliver the current TOTP value over SMS (notification channel). */
    public function sendSmsCode(AsabUser $user, ?string $secret = null): void
    {
        $secret = $secret ?: $user->two_factor_secret;
        if (! $secret) {
            return;
        }
        try {
            $user->notify(new TwoFactorCodeNotification($this->totp->getCurrentOtp($secret)));
        } catch (\Throwable $e) {
            Log::warning('2FA SMS code delivery failed: '.$e->getMessage());
        }
    }

    private function consumeBackupCode(AsabUser $user, string $code): bool
    {
        $codes = $user->two_factor_backup_codes ?? [];
        foreach ($codes as $i => $hashed) {
            if (Hash::check($code, $hashed)) {
                unset($codes[$i]);
                $user->forceFill(['two_factor_backup_codes' => array_values($codes)])->save();

                return true;
            }
        }

        return false;
    }

    /** @return string[] */
    private function generateBackupCodes(int $count = 8): array
    {
        return collect(range(1, $count))
            ->map(fn () => strtoupper(Str::random(4).'-'.Str::random(4)))
            ->all();
    }

    private function maskPhone(?string $phone): string
    {
        if (! $phone) {
            return '***';
        }

        return Str::mask($phone, '*', 0, max(0, strlen($phone) - 3));
    }
}
