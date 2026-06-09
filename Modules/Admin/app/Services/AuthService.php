<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AsabUser;

class AuthService
{
    /** Minimum seconds between password-reset (re)sends per email. */
    private const RESEND_COOLDOWN = 60;

    /** Lifetime of the interim two-factor login token. */
    private const TWO_FACTOR_TOKEN_TTL = 300;

    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly TwoFactorService $twoFactor,
    ) {}

    /**
     * Password (and optional 2FA) login (FE completion request §3.1).
     * When 2FA is enabled and no code is supplied, returns
     * { requires2fa:true, twoFactorToken } — no access token yet.
     *
     * @return array spec-exact { accessToken, refreshToken, expiresIn, user } | { requires2fa, twoFactorToken }
     */
    public function login(string $email, string $password, ?string $code = null, ?string $twoFactorToken = null): array
    {
        // Step-up path: client returns with the interim token + a code.
        if ($twoFactorToken) {
            return $this->completeTwoFactorLogin($twoFactorToken, $code);
        }

        $user = AsabUser::where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            throw new AsabException('INVALID_CREDENTIALS', 'Invalid email or password', 'بيانات الدخول غير صحيحة', 401);
        }

        if ($user->status !== 'active') {
            throw new AsabException('USER_INACTIVE', 'User account is not active', 'الحساب غير مُفعّل', 403);
        }

        if ($user->twoFactorEnabled()) {
            if ($code === null || $code === '') {
                $token = Str::random(48);
                Cache::put('2fa-login:'.$token, $user->id, self::TWO_FACTOR_TOKEN_TTL);
                if ($user->two_factor_method === 'sms') {
                    $this->twoFactor->sendSmsCode($user);
                }

                return ['requires2fa' => true, 'twoFactorToken' => $token];
            }
            if (! $this->twoFactor->verify($user, $code)) {
                throw new AsabException('TWO_FACTOR_INVALID_CODE', 'Invalid verification code', 'رمز التحقق غير صحيح', 422);
            }
        }

        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return $this->issueTokens($user);
    }

    /** Resolve the interim token + verify the code, then issue tokens. */
    private function completeTwoFactorLogin(string $twoFactorToken, ?string $code): array
    {
        $userId = Cache::get('2fa-login:'.$twoFactorToken);
        if (! $userId) {
            throw new AsabException('TWO_FACTOR_TOKEN_INVALID', 'Two-factor session expired', 'انتهت جلسة المصادقة الثنائية', 401);
        }
        $user = AsabUser::find($userId);
        if (! $user) {
            throw new AsabException('TWO_FACTOR_TOKEN_INVALID', 'Two-factor session invalid', 'جلسة المصادقة الثنائية غير صالحة', 401);
        }
        if ($code === null || ! $this->twoFactor->verify($user, $code)) {
            throw new AsabException('TWO_FACTOR_INVALID_CODE', 'Invalid verification code', 'رمز التحقق غير صحيح', 422);
        }

        Cache::forget('2fa-login:'.$twoFactorToken);
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return $this->issueTokens($user);
    }

    /**
     * Rotate a refresh token: revoke it and issue a fresh access+refresh pair.
     */
    public function refresh(string $refreshToken): array
    {
        $token = PersonalAccessToken::findToken($refreshToken);

        if (! $token || $token->name !== 'refresh') {
            throw new AsabException('INVALID_TOKEN', 'Invalid refresh token', 'رمز التحديث غير صالح', 401);
        }

        $user = $token->tokenable;
        if (! $user instanceof AsabUser) {
            throw new AsabException('INVALID_TOKEN', 'Invalid refresh token', 'رمز التحديث غير صالح', 401);
        }

        $token->delete(); // rotate

        $issued = $this->issueTokens($user);

        return [
            'accessToken' => $issued['accessToken'],
            'refreshToken' => $issued['refreshToken'],
            'expiresIn' => $issued['expiresIn'],
        ];
    }

    public function userPayload(AsabUser $user, bool $withPermissions = false): array
    {
        $user->loadMissing('roleAssignments');

        $payload = [
            'id' => $user->id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar' => $user->avatar,
            'companyId' => $user->company_id,
            'defaultPage' => $user->default_page,
            'roles' => $user->roleAssignments->map(fn ($r) => [
                'key' => $r->role_key,
                'scope' => $r->scope,
                'brandIds' => $r->brand_ids ?? [],
                'restaurantIds' => $r->restaurant_ids ?? [],
                'branchIds' => $r->branch_ids ?? [],
                'moduleKeys' => $r->module_keys ?? [],
            ])->values()->all(),
        ];

        if ($withPermissions) {
            $payload['permissions'] = $this->permissions->forUser($user);
        }

        return $payload;
    }

    public function changePassword(AsabUser $user, string $current, string $new): void
    {
        if (! Hash::check($current, $user->password)) {
            throw new AsabException('INVALID_PASSWORD', 'Current password is incorrect', 'كلمة المرور الحالية غير صحيحة', 422);
        }

        $user->forceFill(['password' => $new])->save();
        $user->tokens()->delete(); // revoke all other sessions
    }

    /**
     * Always succeeds (no user enumeration). Stores a 1-hour reset token.
     * In production this also emails https://app.asab.sa/reset?token=...
     */
    public function forgotPassword(string $email): void
    {
        $user = AsabUser::where('email', $email)->first();
        if (! $user) {
            return;
        }

        $token = Str::random(64);
        DB::table('password_reset_tokens')->updateOrInsert(
            ['email' => $email],
            ['token' => $token, 'created_at' => now()],
        );
        // Mail dispatch is environment-dependent; the token row is the contract here.
    }

    /**
     * Resend the reset token, rate-limited per email (MISSING_Dashboard §8.1).
     * Always returns ok:true for unknown emails (no enumeration); throws
     * RATE_LIMITED (429) while a cooldown is active.
     */
    public function resendForgotPassword(string $email): array
    {
        $key = 'pwd-reset-resend:'.sha1(Str::lower($email));

        if (RateLimiter::tooManyAttempts($key, 1)) {
            throw new AsabException(
                'RATE_LIMITED',
                'Please wait before requesting another reset',
                'يرجى الانتظار قبل إعادة الإرسال',
                429,
                ['nextResendAvailableAt' => now()->addSeconds(RateLimiter::availableIn($key))->toIso8601String()],
            );
        }

        RateLimiter::hit($key, self::RESEND_COOLDOWN);
        $this->forgotPassword($email); // no-ops on unknown email

        return ['ok' => true, 'nextResendAvailableAt' => now()->addSeconds(self::RESEND_COOLDOWN)->toIso8601String()];
    }

    public function resetPassword(string $token, string $newPassword): void
    {
        $row = DB::table('password_reset_tokens')->where('token', $token)->first();

        if (! $row || now()->diffInMinutes($row->created_at) > 60) {
            throw new AsabException('INVALID_TOKEN', 'Reset token is invalid or expired', 'رمز إعادة التعيين غير صالح أو منتهي', 422);
        }

        $user = AsabUser::where('email', $row->email)->first();
        if (! $user) {
            throw new AsabException('INVALID_TOKEN', 'Reset token is invalid', 'رمز إعادة التعيين غير صالح', 422);
        }

        $user->forceFill(['password' => $newPassword])->save();
        $user->tokens()->delete();
        DB::table('password_reset_tokens')->where('email', $row->email)->delete();
    }

    /** @return array list of active sessions for the user */
    public function sessions(AsabUser $user): array
    {
        return $user->tokens()->orderByDesc('created_at')->get()->map(fn ($t) => [
            'id' => (string) $t->id,
            'name' => $t->name,
            'lastUsedAt' => optional($t->last_used_at)->toIso8601String(),
            'createdAt' => optional($t->created_at)->toIso8601String(),
        ])->all();
    }

    public function revokeSession(AsabUser $user, string $id): void
    {
        $user->tokens()->where('id', $id)->delete();
    }

    public function issueTokens(AsabUser $user): array
    {
        return [
            'accessToken' => $user->createToken('access')->plainTextToken,
            'refreshToken' => $user->createToken('refresh')->plainTextToken,
            'expiresIn' => 900,
            'user' => $this->userPayload($user),
        ];
    }
}
