<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AdminSession;
use Modules\Admin\Models\AsabCompany;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Notifications\PasswordResetLinkNotification;

class AuthService
{
    /** Minimum seconds between password-reset (re)sends per email. */
    private const RESEND_COOLDOWN = 60;

    /** Lifetime of the interim two-factor login token. */
    private const TWO_FACTOR_TOKEN_TTL = 300;

    public function __construct(
        private readonly PermissionResolver $permissions,
        private readonly TwoFactorService $twoFactor,
        private readonly CredentialSyncService $credentials,
        private readonly CredentialMailer $mailer,
    ) {}

    /**
     * Determine whether the new Admin Session Family issuance lifecycle is enabled.
     * Defaults to false (Compatibility Mode).
     */
    public function isSessionFamiliesEnabled(): bool
    {
        return (bool) config('admin.session_families_enabled', false);
    }

    /**
     * Compute a security version fingerprint for a user to detect changed credentials.
     */
    public function computeCredentialVersion(AsabUser $user): string
    {
        return hash('sha256', implode('|', [
            $user->id,
            $user->password,
            $user->status,
            $user->company_id ?? '',
            $user->two_factor_secret ?? '',
            $user->two_factor_confirmed_at?->timestamp ?? '',
            $user->updated_at?->timestamp ?? '',
        ]));
    }

    /**
     * Password (and optional 2FA) login (FE completion request §3.1).
     * When 2FA is enabled and no code is supplied, returns
     * { requires2fa:true, twoFactorToken } — no access token yet.
     *
     * @return array spec-exact { accessToken, refreshToken, expiresIn, user, ... } | { requires2fa, twoFactorToken }
     */
    public function login(
        string $email,
        string $password,
        ?string $code = null,
        ?string $twoFactorToken = null,
        bool $rememberMe = false,
        ?string $deviceName = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        if (! $this->isSessionFamiliesEnabled()) {
            return $this->legacyLogin($email, $password, $code, $twoFactorToken);
        }

        return $this->familyLogin($email, $password, $code, $twoFactorToken, $rememberMe, $deviceName, $ipAddress, $userAgent);
    }

    /**
     * Legacy Compatibility Mode login flow.
     */
    private function legacyLogin(string $email, string $password, ?string $code = null, ?string $twoFactorToken = null): array
    {
        // Step-up path: client returns with the interim token + a code.
        if ($twoFactorToken) {
            return $this->legacyCompleteTwoFactorLogin($twoFactorToken, $code);
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

    /**
     * Legacy Compatibility Mode 2FA completion.
     */
    private function legacyCompleteTwoFactorLogin(string $twoFactorToken, ?string $code): array
    {
        // Guard against cross-mode replay: reject if this token is a family challenge or already consumed
        if (Cache::has('2fa-consumed:'.$twoFactorToken) || Cache::has('2fa-challenge:'.$twoFactorToken)) {
            Cache::forget('2fa-login:'.$twoFactorToken);
            Cache::forget('2fa-challenge:'.$twoFactorToken);
            Cache::put('2fa-consumed:'.$twoFactorToken, true, self::TWO_FACTOR_TOKEN_TTL);
            throw new AsabException('TWO_FACTOR_TOKEN_INVALID', 'Two-factor session expired', 'انتهت جلسة المصادقة الثنائية', 401);
        }

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
        Cache::put('2fa-consumed:'.$twoFactorToken, true, self::TWO_FACTOR_TOKEN_TTL);
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return $this->issueTokens($user);
    }

    /**
     * Slice 03 Admin Session Family login with admission locking & concurrency protection.
     */
    private function familyLogin(
        string $email,
        string $password,
        ?string $code = null,
        ?string $twoFactorToken = null,
        bool $rememberMe = false,
        ?string $deviceName = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        if ($twoFactorToken) {
            return $this->completeTwoFactorFamilyLogin($twoFactorToken, $code, $deviceName, $ipAddress, $userAgent);
        }

        $preliminaryUser = AsabUser::where('email', $email)->first();
        if (! $preliminaryUser || ! Hash::check($password, $preliminaryUser->password)) {
            throw new AsabException('INVALID_CREDENTIALS', 'Invalid email or password', 'بيانات الدخول غير صحيحة', 401);
        }

        if ($preliminaryUser->status !== 'active') {
            throw new AsabException('USER_INACTIVE', 'User account is not active', 'الحساب غير مُفعّل', 403);
        }

        if ($preliminaryUser->company_id !== null) {
            $company = AsabCompany::where('id', $preliminaryUser->company_id)->first();
            if (! $company || $company->status === 'suspended') {
                throw new AsabException('USER_INACTIVE', 'Company is not active', 'الشركة غير نشطة', 403);
            }
        }

        if ($preliminaryUser->twoFactorEnabled()) {
            if ($code === null || $code === '') {
                $token = Str::random(48);
                $ttl = self::TWO_FACTOR_TOKEN_TTL;
                $challengeData = [
                    'user_id' => $preliminaryUser->id,
                    'remember_me' => $rememberMe,
                    'credential_version' => $this->computeCredentialVersion($preliminaryUser),
                    'created_at' => now()->timestamp,
                    'expires_at' => now()->addSeconds($ttl)->timestamp,
                    'state' => 'pending',
                ];

                Cache::put('2fa-challenge:'.$token, $challengeData, $ttl);

                if ($preliminaryUser->two_factor_method === 'sms') {
                    $this->twoFactor->sendSmsCode($preliminaryUser);
                }

                return ['requires2fa' => true, 'twoFactorToken' => $token];
            }

            // Direct Password + Code path with 2FA enabled
            return DB::transaction(function () use ($preliminaryUser, $password, $code, $rememberMe, $deviceName, $ipAddress, $userAgent) {
                $user = AsabUser::where('id', $preliminaryUser->id)->lockForUpdate()->first();
                if (! $user || ! Hash::check($password, $user->password)) {
                    throw new AsabException('INVALID_CREDENTIALS', 'Invalid email or password', 'بيانات الدخول غير صحيحة', 401);
                }
                if ($user->status !== 'active') {
                    throw new AsabException('USER_INACTIVE', 'User account is not active', 'الحساب غير مُفعّل', 403);
                }
                if ($user->company_id !== null) {
                    $company = AsabCompany::where('id', $user->company_id)->first();
                    if (! $company || $company->status === 'suspended') {
                        throw new AsabException('USER_INACTIVE', 'Company is not active', 'الشركة غير نشطة', 403);
                    }
                }
                if (! $this->twoFactor->verify($user, $code)) {
                    throw new AsabException('TWO_FACTOR_INVALID_CODE', 'Invalid verification code', 'رمز التحقق غير صحيح', 422);
                }

                $user->forceFill(['last_login_at' => now()])->saveQuietly();

                return $this->issueSessionFamily($user, $rememberMe, $deviceName, $ipAddress, $userAgent);
            });
        }

        // Standard Password path without 2FA
        return DB::transaction(function () use ($preliminaryUser, $password, $code, $rememberMe, $deviceName, $ipAddress, $userAgent) {
            $user = AsabUser::where('id', $preliminaryUser->id)->lockForUpdate()->first();
            if (! $user || ! Hash::check($password, $user->password)) {
                throw new AsabException('INVALID_CREDENTIALS', 'Invalid email or password', 'بيانات الدخول غير صحيحة', 401);
            }
            if ($user->status !== 'active') {
                throw new AsabException('USER_INACTIVE', 'User account is not active', 'الحساب غير مُفعّل', 403);
            }
            if ($user->company_id !== null) {
                $company = AsabCompany::where('id', $user->company_id)->first();
                if (! $company || $company->status === 'suspended') {
                    throw new AsabException('USER_INACTIVE', 'Company is not active', 'الشركة غير نشطة', 403);
                }
            }

            // P1 Audit Fix: Re-evaluate 2FA requirement under the user lock
            if ($user->twoFactorEnabled()) {
                if ($code === null || $code === '') {
                    $token = Str::random(48);
                    $ttl = self::TWO_FACTOR_TOKEN_TTL;
                    $challengeData = [
                        'user_id' => $user->id,
                        'remember_me' => $rememberMe,
                        'credential_version' => $this->computeCredentialVersion($user),
                        'created_at' => now()->timestamp,
                        'expires_at' => now()->addSeconds($ttl)->timestamp,
                        'state' => 'pending',
                    ];

                    Cache::put('2fa-challenge:'.$token, $challengeData, $ttl);

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

            return $this->issueSessionFamily($user, $rememberMe, $deviceName, $ipAddress, $userAgent);
        });
    }

    /**
     * Slice 03 Secure 2FA Challenge Completion with atomic store primitive and User row lock.
     */
    private function completeTwoFactorFamilyLogin(
        string $twoFactorToken,
        ?string $code,
        ?string $deviceName = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
    ): array {
        if ($code === null || $code === '') {
            throw new AsabException('TWO_FACTOR_INVALID_CODE', 'Invalid verification code', 'رمز التحقق غير صحيح', 422);
        }

        // P2 Audit Fix: Atomic conditional reservation in shared store.
        // Cache::add() succeeds if and only if key did NOT previously exist.
        // NOTE: This atomic reservation guarantee relies on a shared cache store that supports atomic conditional writes.
        // It has been empirically proven on Laravel Database Cache with MariaDB (via INSERT IGNORE on unique key),
        // and does NOT apply to in-memory Array Cache across independent PHP processes.
        if (! Cache::add('2fa-consumed:'.$twoFactorToken, true, self::TWO_FACTOR_TOKEN_TTL)) {
            throw new AsabException('TWO_FACTOR_TOKEN_INVALID', 'Two-factor session already consumed or concurrent attempt', 'جلسة المصادقة الثنائية غير صالحة', 401);
        }

        // Secondary mutual exclusion lock around challenge retrieval
        $consumeLock = Cache::lock('2fa-consume-lock:'.$twoFactorToken, 30);
        if (! $consumeLock->get()) {
            throw new AsabException('TWO_FACTOR_TOKEN_INVALID', 'Two-factor session already consumed or concurrent attempt', 'جلسة المصادقة الثنائية غير صالحة', 401);
        }

        try {
            // Retrieve and clear challenge from store (Cache::add above provides the atomic mutual exclusivity).
            $challenge = Cache::pull('2fa-challenge:'.$twoFactorToken);
            Cache::forget('2fa-login:'.$twoFactorToken);

            if (! is_array($challenge) || ($challenge['state'] ?? '') !== 'pending') {
                throw new AsabException('TWO_FACTOR_TOKEN_INVALID', 'Two-factor session expired or invalid', 'انتهت جلسة المصادقة الثنائية', 401);
            }

            if (now()->timestamp >= ($challenge['expires_at'] ?? 0)) {
                throw new AsabException('TWO_FACTOR_TOKEN_INVALID', 'Two-factor session expired', 'انتهت جلسة المصادقة الثنائية', 401);
            }

            $userId = $challenge['user_id'];
            $rememberMe = (bool) ($challenge['remember_me'] ?? false);
            $expectedVersion = $challenge['credential_version'] ?? null;
        } finally {
            if ($consumeLock->isOwnedByCurrentProcess()) {
                $consumeLock->release();
            }
        }

        return DB::transaction(function () use ($userId, $code, $rememberMe, $expectedVersion, $deviceName, $ipAddress, $userAgent) {
            $user = AsabUser::where('id', $userId)->lockForUpdate()->first();
            if (! $user) {
                throw new AsabException('TWO_FACTOR_TOKEN_INVALID', 'User not found', 'المستخدم غير موجود', 401);
            }

            if ($user->status !== 'active') {
                throw new AsabException('USER_INACTIVE', 'User account is not active', 'الحساب غير مُفعّل', 403);
            }

            if ($user->company_id !== null) {
                $company = AsabCompany::where('id', $user->company_id)->first();
                if (! $company || $company->status === 'suspended') {
                    throw new AsabException('USER_INACTIVE', 'Company is not active', 'الشركة غير نشطة', 403);
                }
            }

            if ($expectedVersion !== null) {
                $currentVersion = $this->computeCredentialVersion($user);
                if (! hash_equals($expectedVersion, $currentVersion)) {
                    throw new AsabException('TWO_FACTOR_TOKEN_INVALID', 'Credentials or security state have changed', 'تم تغيير بيانات الاعتماد أو حالة الأمان', 401);
                }
            }

            if (! $this->twoFactor->verify($user, $code)) {
                throw new AsabException('TWO_FACTOR_INVALID_CODE', 'Invalid verification code', 'رمز التحقق غير صحيح', 422);
            }

            $user->forceFill(['last_login_at' => now()])->saveQuietly();

            return $this->issueSessionFamily($user, $rememberMe, $deviceName, $ipAddress, $userAgent);
        });
    }

    /**
     * Slice 03 Admin Session Family issuance using existing Sanctum architecture.
     */
    public function issueSessionFamily(
        AsabUser $user,
        bool $rememberMe = false,
        ?string $deviceName = null,
        ?string $ipAddress = null,
        ?string $userAgent = null,
        ?\DateTimeInterface $overrideExpiresAt = null,
    ): array {
        return DB::transaction(function () use ($user, $rememberMe, $deviceName, $ipAddress, $userAgent, $overrideExpiresAt) {
            $now = now();
            $maxTtlHours = $rememberMe ? (7 * 24) : 24;
            $maxPolicyExpiresAt = $now->copy()->addHours($maxTtlHours);

            if ($overrideExpiresAt !== null) {
                $requestedExpiresAt = \Carbon\Carbon::instance($overrideExpiresAt);
                // Strict policy invariant: override may ONLY shorten approved lifetime, never extend beyond policy maximum
                if ($requestedExpiresAt->gt($maxPolicyExpiresAt)) {
                    throw new AsabException(
                        'EXPIRATION_OVERRIDE_EXCEEDED',
                        'Session expiration cannot exceed policy maximum of ' . ($rememberMe ? '7 days' : '24 hours'),
                        'لا يمكن تمديد مدة الجلسة عن الحد الأقصى للسياسة',
                        422
                    );
                }
                $sessionExpiresAt = $requestedExpiresAt;
            } else {
                $sessionExpiresAt = $maxPolicyExpiresAt;
            }

            $remainingSeconds = (int) max(0, $now->diffInSeconds($sessionExpiresAt, false));
            if ($remainingSeconds <= 0) {
                throw new AsabException('SESSION_EXPIRED', 'Session has expired', 'انتهت الجلسة', 401);
            }

            $expiresIn = (int) min(900, $remainingSeconds);
            $accessExpiresAt = $now->copy()->addSeconds($expiresIn);
            $refreshExpiresAt = $sessionExpiresAt->copy();

            $sessionId = (string) Str::uuid();

            // 1. Create AdminSession
            $session = new AdminSession();
            $session->id = $sessionId;
            $session->user_id = $user->id;
            $session->company_id = $user->company_id;
            $session->device_name = $deviceName;
            $session->ip_address = $ipAddress;
            $session->user_agent = $userAgent;
            $session->is_remember_me = $rememberMe;
            $session->generation = 0;
            $session->last_active_at = $now;
            $session->expires_at = $sessionExpiresAt;
            $session->revoked_at = null;
            $session->save();

            // 2. Issue Access Token with exact ability ['admin:access']
            $accessNewToken = $user->createToken('access', ['admin:access'], $accessExpiresAt);
            $accessNewToken->accessToken->forceFill(['session_id' => $sessionId])->save();

            // 3. Issue Refresh Token with exact ability ['admin:refresh']
            $refreshNewToken = $user->createToken('refresh', ['admin:refresh'], $refreshExpiresAt);
            $refreshNewToken->accessToken->forceFill(['session_id' => $sessionId])->save();

            // 4. Update AdminSession pointers and legacy alias once
            $session->forceFill([
                'current_access_token_id' => $accessNewToken->accessToken->id,
                'current_refresh_token_id' => $refreshNewToken->accessToken->id,
                'legacy_access_token_id' => $accessNewToken->accessToken->id,
            ])->save();

            return [
                'accessToken' => $accessNewToken->plainTextToken,
                'refreshToken' => $refreshNewToken->plainTextToken,
                'expiresIn' => $expiresIn,
                'sessionId' => $sessionId,
                'expiresAt' => $accessExpiresAt->toIso8601String(),
                'sessionExpiresAt' => $sessionExpiresAt->toIso8601String(),
                'user' => $this->userPayload($user),
            ];
        });
    }

    /**
     * Slice 04 Atomic Admin Session Family refresh with User -> Session -> Token locking.
     */
    public function refresh(string $refreshToken, ?\Closure $testHook = null): array
    {
        $candidateToken = PersonalAccessToken::findToken($refreshToken);

        if (! $candidateToken || $candidateToken->name !== 'refresh') {
            throw new AsabException('INVALID_TOKEN', 'Invalid refresh token', 'رمز التحديث غير صالح', 401);
        }

        $candidateUser = $candidateToken->tokenable;
        if (! $candidateUser instanceof AsabUser) {
            throw new AsabException('INVALID_TOKEN', 'Invalid refresh token', 'رمز التحديث غير صالح', 401);
        }

        // Case A: Genuinely familyless legacy Admin refresh in Compatibility Mode
        if ($candidateToken->session_id === null) {
            return DB::transaction(function () use ($candidateToken, $candidateUser, $testHook) {
                // Match revocation and credential mutation: User -> Token.
                $user = AsabUser::where('id', $candidateUser->id)->lockForUpdate()->first();
                if (! $user) {
                    throw new AsabException('INVALID_TOKEN', 'User not found', 'المستخدم غير موجود', 401);
                }

                if ($user->status !== 'active') {
                    throw new AsabException('USER_INACTIVE', 'User account is not active', 'الحساب غير مُفعّل', 403);
                }

                // Ordinary company read preserves the current R1 suspension limits.
                if ($user->company_id !== null) {
                    $company = AsabCompany::where('id', $user->company_id)->first();
                    if (! $company || $company->status === 'suspended') {
                        throw new AsabException('USER_INACTIVE', 'Company is not active', 'الشركة غير نشطة', 403);
                    }
                }

                $token = $user->tokens()->where('id', $candidateToken->id)->lockForUpdate()->first();
                if (! $token || (int) $token->id !== (int) $candidateToken->id
                    || (string) $token->tokenable_id !== (string) $user->id
                    || $token->tokenable_type !== $user->getMorphClass()
                    || ! hash_equals($candidateToken->token, $token->token)
                    || $token->name !== 'refresh' || $token->session_id !== null) {
                    throw new AsabException('INVALID_TOKEN', 'Refresh token already consumed or changed', 'رمز التحديث مستهلك أو غير صالح', 401);
                }

                if ($token->abilities !== ['*'] && ! in_array('admin:refresh', $token->abilities, true)) {
                    throw new AsabException('INVALID_TOKEN', 'Invalid refresh token abilities', 'صلاحيات الرمز غير صحيحة', 401);
                }

                if ($token->expires_at && now()->greaterThanOrEqualTo($token->expires_at)) {
                    throw new AsabException('SESSION_EXPIRED', 'Refresh token expired', 'انتهت صلاحية رمز التحديث', 401);
                }

                $token->delete();
                $this->invokeTestHook($testHook, 'after_delete_old_tokens');
                $issued = $this->issueTokens($user);
                $this->invokeTestHook($testHook, 'before_commit');

                return [
                    'accessToken' => $issued['accessToken'],
                    'refreshToken' => $issued['refreshToken'],
                    'expiresIn' => $issued['expiresIn'],
                    'user' => $this->userPayload($user),
                ];
            });
        }

        // Case B: Family-linked token (strictly follows family rules regardless of feature flag state)
        return DB::transaction(function () use ($candidateToken, $candidateUser, $testHook) {
            // Lock 1: User
            $user = AsabUser::where('id', $candidateUser->id)->lockForUpdate()->first();
            if (! $user) {
                throw new AsabException('INVALID_TOKEN', 'User not found', 'المستخدم غير موجود', 401);
            }

            if ($user->status !== 'active') {
                throw new AsabException('USER_INACTIVE', 'User account is not active', 'الحساب غير مُفعّل', 403);
            }

            if ($user->company_id !== null) {
                $company = AsabCompany::where('id', $user->company_id)->first();
                if (! $company || $company->status === 'suspended') {
                    throw new AsabException('USER_INACTIVE', 'Company is not active', 'الشركة غير نشطة', 403);
                }
            }

            // Lock 2: Session
            $session = AdminSession::where('id', $candidateToken->session_id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (! $session) {
                throw new AsabException('INVALID_TOKEN', 'Session family not found', 'جلسة الإدارة غير موجودة', 401);
            }

            if ($session->revoked_at !== null) {
                throw new AsabException('SESSION_EXPIRED', 'Session has been revoked', 'تم إلغاء الجلسة', 401);
            }

            $now = now();
            if ($now->greaterThanOrEqualTo($session->expires_at)) {
                throw new AsabException('SESSION_EXPIRED', 'Session has expired', 'انتهت الجلسة', 401);
            }

            // Stale / already rotated check: is the presented token still the current refresh token?
            if ((int) $candidateToken->id !== (int) $session->current_refresh_token_id) {
                throw new AsabException('INVALID_TOKEN', 'Refresh token already rotated or consumed', 'رمز التحديث مستهلك أو غير صالح', 401);
            }

            // Lock 3: Tokens in deterministic ascending ID order
            $tokenIds = array_values(array_filter([$session->current_access_token_id, $session->current_refresh_token_id]));
            sort($tokenIds);

            $lockedTokens = PersonalAccessToken::whereIn('id', $tokenIds)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /** @var PersonalAccessToken|null $currentRefreshToken */
            $currentRefreshToken = $lockedTokens->get($session->current_refresh_token_id);
            if (! $currentRefreshToken) {
                throw new AsabException('INVALID_TOKEN', 'Refresh token not found', 'رمز التحديث غير موجود', 401);
            }

            // Exact stored ability invariant: ['admin:refresh'] only
            if ($currentRefreshToken->abilities !== ['admin:refresh']) {
                throw new AsabException('INVALID_TOKEN', 'Invalid refresh token abilities', 'صلاحيات الرمز غير صحيحة', 401);
            }

            if ($currentRefreshToken->expires_at && $now->greaterThanOrEqualTo($currentRefreshToken->expires_at)) {
                throw new AsabException('SESSION_EXPIRED', 'Refresh token expired', 'انتهت صلاحية رمز التحديث', 401);
            }

            // Remaining absolute lifetime
            $remainingSeconds = (int) max(0, $now->diffInSeconds($session->expires_at, false));
            if ($remainingSeconds <= 0) {
                throw new AsabException('SESSION_EXPIRED', 'Session has expired', 'انتهت الجلسة', 401);
            }

            $expiresIn = (int) min(900, $remainingSeconds);
            $accessExpiresAt = $now->copy()->addSeconds($expiresIn);
            $refreshExpiresAt = $session->expires_at->copy();

            // Step 1: Invalidate former token pair
            foreach ($lockedTokens as $t) {
                $t->delete();
            }

            $this->invokeTestHook($testHook, 'after_delete_old_tokens');

            // Step 2: Issue successor Access Token with exact ability ['admin:access']
            $newAccessToken = $user->createToken('access', ['admin:access'], $accessExpiresAt);
            $newAccessToken->accessToken->forceFill(['session_id' => $session->id])->save();

            $this->invokeTestHook($testHook, 'after_create_access_token');

            // Step 3: Issue successor Refresh Token with exact ability ['admin:refresh']
            $newRefreshToken = $user->createToken('refresh', ['admin:refresh'], $refreshExpiresAt);
            $newRefreshToken->accessToken->forceFill(['session_id' => $session->id])->save();

            // Step 4: Advance generation once and update pointers
            $session->forceFill([
                'current_access_token_id' => $newAccessToken->accessToken->id,
                'current_refresh_token_id' => $newRefreshToken->accessToken->id,
                'generation' => $session->generation + 1,
                'last_active_at' => $now,
            ])->save();

            $this->invokeTestHook($testHook, 'after_update_session_pointers');
            $this->invokeTestHook($testHook, 'before_commit');

            return [
                'accessToken' => $newAccessToken->plainTextToken,
                'refreshToken' => $newRefreshToken->plainTextToken,
                'expiresIn' => $expiresIn,
                'sessionId' => $session->id,
                'expiresAt' => $accessExpiresAt->toIso8601String(),
                'sessionExpiresAt' => $session->expires_at->toIso8601String(),
                'user' => $this->userPayload($user),
            ];
        });
    }

    /**
     * Strictly guard fault-injection test hooks so they can NEVER be triggered in production.
     */
    private function invokeTestHook(?\Closure $testHook, string $point): void
    {
        if ($testHook === null) {
            return;
        }

        if (! app()->environment('testing')) {
            throw new \LogicException('Fault injection test hooks are strictly forbidden outside testing.');
        }

        $testHook($point);
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

        if ($supplier = $this->supplierIdentity($user)) {
            $payload['supplier'] = $supplier;
        }

        return $payload;
    }

    /**
     * A supplier login's COMMERCIAL identity. `name` above is the person who
     * signs in; the portal header shows the supplier company, and reading it
     * from the login meant a renamed supplier still displayed its old name
     * («اسم المورد لم يحدث في النظام», 2026-08-04). Read live from
     * asab_suppliers so a rename is visible on the next request.
     *
     * @return array<string, mixed>|null
     */
    private function supplierIdentity(AsabUser $user): ?array
    {
        $isSupplier = $user->roleAssignments->contains(fn ($r) => $r->role_key === 'supplier');
        if (! $isSupplier) {
            return null;
        }

        $records = \Modules\Admin\Models\AsabSupplier::withoutGlobalScope('tenant')
            ->where('user_id', $user->id)
            ->orderBy('name')
            ->get(['id', 'name', 'category', 'company_id', 'status']);

        if ($records->isEmpty()) {
            return null;
        }

        $primary = $records->first();

        return [
            'id' => $primary->id,
            // The display name for every supplier surface — header included.
            'name' => $primary->name,
            'category' => $primary->category,
            'companyId' => $primary->company_id,
            'status' => $primary->status,
            // A login may own several commercial records; the portal needs the
            // list to send `supplierId` when adding an item.
            'records' => $records->map(fn ($s) => [
                'id' => $s->id, 'name' => $s->name, 'status' => $s->status,
            ])->values()->all(),
        ];
    }

    /**
     * Slice 05 Atomic Family Logout via Bearer Access Token.
     */
    public function logout(AsabUser $user, ?PersonalAccessToken $token = null): void
    {
        if (! $token) {
            $token = $user->currentAccessToken();
        }

        if (! $token) {
            return;
        }

        // Case A: Genuinely familyless legacy token
        if ($token->session_id === null) {
            DB::transaction(function () use ($user, $token) {
                $lockedUser = AsabUser::where('id', $user->id)->lockForUpdate()->first();
                if (! $lockedUser) {
                    return;
                }

                // Include the presented credential in the same deterministic lock
                // pass even when it is unrelated to the legacy Admin token pair.
                $lockedTokens = $lockedUser->tokens()
                    ->where(function ($query) use ($token) {
                        $query->where('id', $token->id)
                            ->orWhere(function ($legacy) {
                                $legacy->whereNull('session_id')
                                    ->whereIn('name', ['access', 'refresh']);
                            });
                    })
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

                /** @var PersonalAccessToken|null $presentedToken */
                $presentedToken = $lockedTokens->firstWhere('id', $token->id);
                if (! $presentedToken
                    || (string) $presentedToken->tokenable_id !== (string) $lockedUser->id
                    || $presentedToken->tokenable_type !== $lockedUser->getMorphClass()
                    || ! hash_equals($token->token, $presentedToken->token)
                    || $presentedToken->session_id !== null) {
                    return;
                }

                if ($presentedToken->name !== 'access') {
                    // Preserve the legacy behavior for impersonation and other
                    // unrelated familyless credentials: revoke only the bearer.
                    $presentedToken->delete();

                    return;
                }

                $lockedTokens
                    ->filter(fn (PersonalAccessToken $lockedToken) => $lockedToken->session_id === null
                        && in_array($lockedToken->name, ['access', 'refresh'], true))
                    ->each->delete();
            });

            return;
        }

        // Case B: Family-linked token: User -> Session -> Tokens lock ordering
        DB::transaction(function () use ($user, $token) {
            $lockedUser = AsabUser::where('id', $user->id)->lockForUpdate()->first();
            if (! $lockedUser) {
                return;
            }

            $session = AdminSession::where('id', $token->session_id)
                ->where('user_id', $lockedUser->id)
                ->lockForUpdate()
                ->first();

            if ($session) {
                $session->forceFill(['revoked_at' => now()])->save();

                PersonalAccessToken::where('session_id', $session->id)
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get()
                    ->each->delete();
            } else {
                $token->delete();
            }
        });
    }

    /**
     * Slice 05 Expired-Access Logout (D08) via valid Refresh Token secret proof.
     */
    public function logoutWithRefreshToken(string $refreshToken, ?callable $onUserResolved = null): void
    {
        $candidateToken = PersonalAccessToken::findToken($refreshToken);

        if (! $candidateToken || $candidateToken->name !== 'refresh') {
            throw new AsabException('INVALID_TOKEN', 'Invalid refresh token', 'رمز التحديث غير صالح', 401);
        }

        $candidateUser = $candidateToken->tokenable;
        if (! $candidateUser instanceof AsabUser) {
            throw new AsabException('INVALID_TOKEN', 'Invalid refresh token', 'رمز التحديث غير صالح', 401);
        }

        // Case A: Legacy familyless token
        if ($candidateToken->session_id === null) {
            DB::transaction(function () use ($candidateToken, $candidateUser, $onUserResolved) {
                // Match refresh rotation and credential mutation: User -> Tokens.
                $user = AsabUser::where('id', $candidateUser->id)->lockForUpdate()->first();
                if (! $user) {
                    throw new AsabException('INVALID_TOKEN', 'User not found', 'المستخدم غير موجود', 401);
                }

                $lockedTokens = $user->tokens()
                    ->whereNull('session_id')
                    ->whereIn('name', ['access', 'refresh'])
                    ->orderBy('id', 'asc')
                    ->lockForUpdate()
                    ->get();

                /** @var PersonalAccessToken|null $refreshProof */
                $refreshProof = $lockedTokens->firstWhere('id', $candidateToken->id);
                if (! $refreshProof
                    || (string) $refreshProof->tokenable_id !== (string) $user->id
                    || $refreshProof->tokenable_type !== $user->getMorphClass()
                    || ! hash_equals($candidateToken->token, $refreshProof->token)
                    || $refreshProof->name !== 'refresh'
                    || $refreshProof->session_id !== null) {
                    throw new AsabException('INVALID_TOKEN', 'Refresh token already consumed or changed', 'رمز التحديث مستهلك أو غير صالح', 401);
                }

                if ($refreshProof->abilities !== ['*'] && ! in_array('admin:refresh', $refreshProof->abilities, true)) {
                    throw new AsabException('INVALID_TOKEN', 'Invalid refresh token abilities', 'صلاحيات الرمز غير صحيحة', 401);
                }

                if ($refreshProof->expires_at && now()->greaterThanOrEqualTo($refreshProof->expires_at)) {
                    throw new AsabException('SESSION_EXPIRED', 'Refresh token expired', 'انتهت صلاحية رمز التحديث', 401);
                }

                if ($onUserResolved) {
                    $onUserResolved($user);
                }

                $lockedTokens->each->delete();
            });

            return;
        }

        // Case B: Family-linked token: User -> Session -> Tokens lock ordering
        DB::transaction(function () use ($candidateToken, $candidateUser, $onUserResolved) {
            // Lock 1: User
            $user = AsabUser::where('id', $candidateUser->id)->lockForUpdate()->first();
            if (! $user) {
                throw new AsabException('INVALID_TOKEN', 'User not found', 'المستخدم غير موجود', 401);
            }

            if ($user->status !== 'active') {
                throw new AsabException('USER_INACTIVE', 'User account is not active', 'الحساب غير مُفعّل', 403);
            }

            if ($user->company_id !== null) {
                $company = AsabCompany::where('id', $user->company_id)->first();
                if (! $company || $company->status === 'suspended') {
                    throw new AsabException('USER_INACTIVE', 'Company is not active', 'الشركة غير نشطة', 403);
                }
            }

            // Lock 2: Session
            $session = AdminSession::where('id', $candidateToken->session_id)
                ->where('user_id', $user->id)
                ->lockForUpdate()
                ->first();

            if (! $session) {
                throw new AsabException('INVALID_TOKEN', 'Session family not found', 'جلسة الإدارة غير موجودة', 401);
            }

            if ($session->revoked_at !== null) {
                throw new AsabException('SESSION_EXPIRED', 'Session has been revoked', 'تم إلغاء الجلسة', 401);
            }

            $now = now();
            if ($now->greaterThanOrEqualTo($session->expires_at)) {
                throw new AsabException('SESSION_EXPIRED', 'Session has expired', 'انتهت الجلسة', 401);
            }

            // Stale check
            if ((int) $candidateToken->id !== (int) $session->current_refresh_token_id) {
                throw new AsabException('INVALID_TOKEN', 'Refresh token already rotated or consumed', 'رمز التحديث مستهلك أو غير صالح', 401);
            }

            // Lock 3: Tokens in deterministic ascending order
            $tokenIds = array_values(array_filter([$session->current_access_token_id, $session->current_refresh_token_id]));
            sort($tokenIds);

            $lockedTokens = PersonalAccessToken::whereIn('id', $tokenIds)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            /** @var PersonalAccessToken|null $currentRefreshToken */
            $currentRefreshToken = $lockedTokens->get($session->current_refresh_token_id);
            if (! $currentRefreshToken) {
                throw new AsabException('INVALID_TOKEN', 'Refresh token not found', 'رمز التحديث غير موجود', 401);
            }

            if ((int) $currentRefreshToken->id !== (int) $candidateToken->id
                || (string) $currentRefreshToken->tokenable_id !== (string) $user->id
                || $currentRefreshToken->tokenable_type !== $user->getMorphClass()
                || ! hash_equals($candidateToken->token, $currentRefreshToken->token)
                || $currentRefreshToken->name !== 'refresh'
                || (string) $currentRefreshToken->session_id !== (string) $session->id) {
                throw new AsabException('INVALID_TOKEN', 'Refresh token already consumed or changed', 'رمز التحديث مستهلك أو غير صالح', 401);
            }

            if ($currentRefreshToken->abilities !== ['admin:refresh']) {
                throw new AsabException('INVALID_TOKEN', 'Invalid refresh token abilities', 'صلاحيات الرمز غير صحيحة', 401);
            }

            if ($currentRefreshToken->expires_at && $now->greaterThanOrEqualTo($currentRefreshToken->expires_at)) {
                throw new AsabException('SESSION_EXPIRED', 'Refresh token expired', 'انتهت صلاحية رمز التحديث', 401);
            }

            if ($onUserResolved) {
                $onUserResolved($user);
            }

            // Mark session revoked
            $session->forceFill(['revoked_at' => $now])->save();

            // Invalidate all tokens belonging to this session
            PersonalAccessToken::where('session_id', $session->id)
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get()
                ->each->delete();
        });
    }

    public function changePassword(AsabUser $user, string $current, string $new): void
    {
        DB::transaction(function () use ($user, $current, $new) {
            // Lock 1: User
            $lockedUser = AsabUser::where('id', $user->id)->lockForUpdate()->first();
            if (! $lockedUser) {
                throw new AsabException('USER_INACTIVE', 'User not found', 'المستخدم غير موجود', 404);
            }

            if (! Hash::check($current, $lockedUser->password)) {
                throw new AsabException('INVALID_PASSWORD', 'Current password is incorrect', 'كلمة المرور الحالية غير صحيحة', 422);
            }

            $lockedUser->forceFill(['password' => $new])->save();

            // Lock 2: Ordered Sessions
            $sessions = AdminSession::where('user_id', $lockedUser->id)
                ->whereNull('revoked_at')
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            foreach ($sessions as $session) {
                $session->forceFill(['revoked_at' => now()])->save();
            }

            // Lock 3: Ordered Tokens
            $tokens = $lockedUser->tokens()
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            foreach ($tokens as $token) {
                $token->delete();
            }

            $this->credentials->pushToMobile($lockedUser);
        });
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

        // The token row alone is not the contract: without this send the user
        // never receives anything and the reset flow is dead end-to-end.
        // CredentialMailer refuses the `log` mailer so the token cannot leak
        // into storage/logs, and swallows transport errors so a mail outage
        // still returns 204 (no account enumeration).
        $this->mailer->send($user, new PasswordResetLinkNotification($token));
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
        $candidateRow = DB::table('password_reset_tokens')->where('token', $token)->first();

        if (! $candidateRow) {
            throw new AsabException('INVALID_TOKEN', 'Reset token is invalid or expired', 'رمز إعادة التعيين غير صالح أو منتهي', 422);
        }

        $candidateUser = AsabUser::where('email', $candidateRow->email)->first();
        if (! $candidateUser) {
            throw new AsabException('INVALID_TOKEN', 'Reset token is invalid', 'رمز إعادة التعيين غير صالح', 422);
        }

        DB::transaction(function () use ($candidateUser, $token, $newPassword) {
            // Lock 1: User
            $lockedUser = AsabUser::where('id', $candidateUser->id)->lockForUpdate()->first();
            if (! $lockedUser) {
                throw new AsabException('INVALID_TOKEN', 'Reset token is invalid', 'رمز إعادة التعيين غير صالح', 422);
            }

            // Lock 2: Reset record by token
            $resetRecord = DB::table('password_reset_tokens')
                ->where('token', $token)
                ->lockForUpdate()
                ->first();

            if (! $resetRecord) {
                throw new AsabException('INVALID_TOKEN', 'Reset token is invalid or expired', 'رمز إعادة التعيين غير صالح أو منتهي', 422);
            }

            if ($resetRecord->email !== $lockedUser->email) {
                throw new AsabException('INVALID_TOKEN', 'Reset token is invalid', 'رمز إعادة التعيين غير صالح', 422);
            }

            // Exact boundary check: now >= created_at + 60 minutes
            $createdAt = \Illuminate\Support\Carbon::parse($resetRecord->created_at);
            if (now()->greaterThanOrEqualTo($createdAt->copy()->addMinutes(60))) {
                throw new AsabException('INVALID_TOKEN', 'Reset token is invalid or expired', 'رمز إعادة التعيين غير صالح أو منتهي', 422);
            }

            $lockedUser->forceFill(['password' => $newPassword])->save();

            // Lock 3: Ordered Sessions
            $sessions = AdminSession::where('user_id', $lockedUser->id)
                ->whereNull('revoked_at')
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            foreach ($sessions as $session) {
                $session->forceFill(['revoked_at' => now()])->save();
            }

            // Lock 4: Ordered Tokens
            $tokens = $lockedUser->tokens()
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            foreach ($tokens as $t) {
                $t->delete();
            }

            // Single-use: delete ONLY this specific token record
            DB::table('password_reset_tokens')->where('token', $token)->delete();

            $this->credentials->pushToMobile($lockedUser);
        });
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
        if (Str::isUuid($id)) {
            DB::transaction(function () use ($user, $id) {
                // Lock 1: User
                $lockedUser = AsabUser::where('id', $user->id)->lockForUpdate()->first();
                if (! $lockedUser) {
                    return;
                }

                // Lock 2: Session
                $session = AdminSession::where('id', $id)
                    ->where('user_id', $lockedUser->id)
                    ->lockForUpdate()
                    ->first();

                if ($session) {
                    $session->forceFill(['revoked_at' => now()])->save();
                    // Lock 3: Tokens (in deterministic ascending ID order)
                    PersonalAccessToken::where('session_id', $session->id)
                        ->orderBy('id', 'asc')
                        ->lockForUpdate()
                        ->get()
                        ->each->delete();
                }
            });

            return;
        }

        if (is_numeric($id)) {
            DB::transaction(function () use ($user, $id) {
                $numericId = (int) $id;

                // Lock 1: User
                $lockedUser = AsabUser::where('id', $user->id)->lockForUpdate()->first();
                if (! $lockedUser) {
                    return;
                }

                // Branch A: Check if numeric ID matches an immutable legacy_access_token_id alias for this user
                // Lock 2: Session
                $session = AdminSession::where('legacy_access_token_id', $numericId)
                    ->where('user_id', $lockedUser->id)
                    ->lockForUpdate()
                    ->first();

                if ($session) {
                    $session->forceFill(['revoked_at' => now()])->save();
                    // Lock 3: Tokens (in deterministic ascending ID order)
                    PersonalAccessToken::where('session_id', $session->id)
                        ->orderBy('id', 'asc')
                        ->lockForUpdate()
                        ->get()
                        ->each->delete();

                    return;
                }

                // Branch B: Target is an existing PersonalAccessToken for this user
                // To enforce D04 canonical lock order (User -> Session -> Token):
                // Look up token's session_id first without inverting lock tier
                $tokenPrelim = $lockedUser->tokens()->where('id', $numericId)->first();
                if (! $tokenPrelim) {
                    return; // Idempotent no-op
                }

                if ($tokenPrelim->session_id !== null) {
                    // Lock 2: Session
                    $linkedSession = AdminSession::where('id', $tokenPrelim->session_id)
                        ->where('user_id', $lockedUser->id)
                        ->lockForUpdate()
                        ->first();

                    if ($linkedSession) {
                        $linkedSession->forceFill(['revoked_at' => now()])->save();
                        // Lock 3: Tokens
                        PersonalAccessToken::where('session_id', $linkedSession->id)
                            ->orderBy('id', 'asc')
                            ->lockForUpdate()
                            ->get()
                            ->each->delete();
                    } else {
                        // Orphaned token fallback: Lock 3 Token
                        $token = $lockedUser->tokens()->where('id', $numericId)->lockForUpdate()->first();
                        $token?->delete();
                    }
                } else {
                    // Genuinely familyless token: Lock 3 Token
                    $token = $lockedUser->tokens()->where('id', $numericId)->lockForUpdate()->first();
                    $token?->delete();
                }
            });

            return;
        }
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
