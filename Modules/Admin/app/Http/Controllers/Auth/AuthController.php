<?php

namespace Modules\Admin\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Services\AuthService;
use Modules\Notification\Enums\DeviceApp;
use Modules\Notification\Http\Concerns\RegistersDeviceTokens;

class AuthController extends AsabController
{
    use RegistersDeviceTokens;

    public function __construct(private readonly AuthService $auth) {}

    public function login(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'email' => 'required_without:twoFactorToken|email',
                'password' => 'required_without:twoFactorToken|string',
                'code' => 'sometimes|nullable|string',
                'twoFactorToken' => 'sometimes|nullable|string',
                'rememberMe' => 'sometimes|boolean',
                // Optional FCM device registration.
                ...self::deviceTokenLoginRules(),
            ]);

            $result = $this->auth->login(
                $data['email'] ?? '',
                $data['password'] ?? '',
                $data['code'] ?? null,
                $data['twoFactorToken'] ?? null,
            );

            // Only register once sign-in is actually complete. A 2FA challenge
            // response carries no user, and binding a device to a half-finished
            // login would push to whoever holds the phone before they prove
            // possession of the second factor.
            if (isset($result['user']['id'])) {
                $this->registerLoginDevice(
                    $request,
                    AsabUser::query()->find($result['user']['id']),
                    DeviceApp::DASHBOARD,
                );
            }

            return $this->ok($result);
        });
    }

    public function refresh(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate(['refreshToken' => 'required|string']);

            return $this->ok($this->auth->refresh($data['refreshToken']));
        });
    }

    public function me(Request $request): JsonResponse
    {
        /** @var AsabUser $user */
        $user = $request->user();

        return $this->ok($this->auth->userPayload($user, withPermissions: true));
    }

    public function logout(Request $request): JsonResponse
    {
        // Release the device before the access token dies, or the client can no
        // longer reach the device-token endpoint to clean up.
        $this->revokeLoginDevice($request, $request->user());

        $token = $request->user()?->currentAccessToken();
        if ($token) {
            $token->delete();
        }

        return $this->noContent();
    }

    public function changePassword(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'currentPassword' => 'required|string',
                'newPassword' => 'required|string|min:8',
            ]);
            $this->auth->changePassword($request->user(), $data['currentPassword'], $data['newPassword']);

            return $this->noContent();
        });
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $data = $request->validate(['email' => 'required|email']);
        $this->auth->forgotPassword($data['email']); // always 204 (no enumeration)

        return $this->noContent();
    }

    public function forgotPasswordResend(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate(['email' => 'required|email']);

            return $this->ok($this->auth->resendForgotPassword($data['email']));
        });
    }

    public function resetPassword(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'token' => 'required|string',
                'newPassword' => 'required|string|min:8',
            ]);
            $this->auth->resetPassword($data['token'], $data['newPassword']);

            return $this->ok(['message' => 'Password reset']);
        });
    }

    public function sessions(Request $request): JsonResponse
    {
        return $this->listResponse($this->auth->sessions($request->user()));
    }

    public function deleteSession(Request $request, string $id): JsonResponse
    {
        $this->auth->revokeSession($request->user(), $id);

        return $this->noContent();
    }
}
