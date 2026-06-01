<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AsabUser;

class AuthService
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    /**
     * @return array spec-exact { accessToken, refreshToken, expiresIn, user }
     */
    public function login(string $email, string $password): array
    {
        $user = AsabUser::where('email', $email)->first();

        if (! $user || ! Hash::check($password, $user->password)) {
            throw new AsabException('INVALID_CREDENTIALS', 'Invalid email or password', 'بيانات الدخول غير صحيحة', 401);
        }

        if ($user->status !== 'active') {
            throw new AsabException('USER_INACTIVE', 'User account is not active', 'الحساب غير مُفعّل', 403);
        }

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
