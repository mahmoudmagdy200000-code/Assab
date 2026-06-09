<?php

namespace Modules\Admin\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Services\TwoFactorService;

/**
 * Two-factor enrolment + management (FE completion request §3.1). The login
 * step-up itself lives in AuthController::login / AuthService.
 */
class TwoFactorController extends AsabController
{
    public function __construct(private readonly TwoFactorService $twoFactor) {}

    /** POST /auth/2fa/setup — body { method: totp|sms } */
    public function setup(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate(['method' => 'required|in:totp,sms']);

            return $this->ok($this->twoFactor->setup($request->user(), $data['method']));
        });
    }

    /** POST /auth/2fa/verify — body { code } → { backupCodes } */
    public function verify(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate(['code' => 'required|string']);

            return $this->ok($this->twoFactor->confirm($request->user(), $data['code']));
        });
    }

    /** POST /auth/2fa/disable — body { code } → 204 */
    public function disable(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate(['code' => 'required|string']);
            $this->twoFactor->disable($request->user(), $data['code']);

            return $this->noContent();
        });
    }

    /** GET /users/me/2fa-status */
    public function status(Request $request): JsonResponse
    {
        return $this->run(fn () => $this->ok($this->twoFactor->status($request->user())));
    }
}
