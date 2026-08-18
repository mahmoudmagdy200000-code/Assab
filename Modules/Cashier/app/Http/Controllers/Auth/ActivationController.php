<?php

namespace Modules\Cashier\Http\Controllers\Auth;

use App\Http\Controllers\BaseController;
use App\Services\FirstLoginActivationResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Modules\Cashier\Http\Requests\Auth\FirstLoginRequest;
use Modules\Cashier\Http\Requests\Auth\ResetPasswordFirstLoginRequest;
use Modules\Cashier\Models\Cashier;
use Modules\Cashier\Services\CashierAuthService;
use Modules\Notification\Http\Concerns\RegistersDeviceTokens;

/**
 * Cashier first-login + «activate Account» (3.2.1.1).
 *
 * Brought onto the same contract as the branch manager, brand owner and
 * supplier surfaces (meeting 2026-08-15). The cashier used to have only the
 * one-shot `/activate` — no `first-login` step, no first-login token — so an
 * app build that implements the shared two-step flow could not activate one.
 */
class ActivationController extends BaseController
{
    use RegistersDeviceTokens;

    public function __construct(
        private readonly CashierAuthService $authService,
        private readonly FirstLoginActivationResolver $activation,
    ) {}

    /**
     * POST cashier/auth/first-login — sign in with the password the branch
     * manager issued and receive the `first-login-token`.
     *
     * With FirstLoginPolicy off (the default) this sign-in COMPLETES the
     * activation and `requires_password_reset` is false, so the app must not
     * route to «activate Account». The two-step flow stays available either way.
     */
    public function firstLogin(FirstLoginRequest $request): JsonResponse
    {
        try {
            $result = $this->authService->firstLogin($request->identifier, $request->password);
            /** @var Cashier $cashier */
            $cashier = $result['cashier'];

            // Optional `fcm_token` makes the handset push-addressable straight
            // away. Never fails the sign-in.
            $this->registerLoginDevice($request, $cashier);

            return $this->successResponse([
                'user' => [
                    'id' => $cashier->id,
                    'name' => $cashier->name,
                    'email' => $cashier->email,
                    'phone' => $cashier->phone,
                    'image' => $cashier->image_url ?? null,
                    'created_at' => $cashier->created_at?->format('Y-m-d H:i:s'),
                ],
                'token' => $result['token'],
                'requires_password_reset' => $result['requiresPasswordReset'],
            ], 'Login successful');
        } catch (AuthorizationException $e) {
            return $this->errorResponse($e->getMessage(), 403);
        } catch (AuthenticationException $e) {
            return $this->errorResponse($e->getMessage(), 401);
        }
    }

    /**
     * POST cashier/auth/reset-password-first-login  (alias: cashier/auth/activate)
     *
     * Accepts any proof the shared resolver recognises: the first-login token in
     * the header or body, or `identifier` + `default_password` — the shape the
     * existing cashier screen posts, which keeps working unchanged.
     */
    public function activate(ResetPasswordFirstLoginRequest $request): JsonResponse
    {
        try {
            $activation = $this->activation->resolveFromRequest(Cashier::class, $request);
            /** @var Cashier $cashier */
            $cashier = $activation->account;

            if ($cashier->isDeactivated()) {
                return $this->errorResponse('Your account is not active. Please contact your manager.', 403);
            }

            // Already activated: setting a password here is a change-password, so
            // it takes the CURRENT password — a bare session token must not
            // rotate an active account's credential.
            if (! $activation->maySetPassword($cashier->isFirstLogin())) {
                return $this->errorResponse('Account is already activated. Send the current password, or use the change-password endpoint.', 400);
            }

            $this->authService->setFirstLoginPassword($cashier, $request->password);

            return $this->successResponse([
                'message' => 'Account activated successfully. Please log in with your new password.',
                'redirect_to_login' => true,
            ], 'Account activated successfully. Please log in with your new password.');
        } catch (AuthenticationException) {
            return $this->errorResponse(
                'Sign in again to activate the account, or send the default password with the request.',
                401
            );
        }
    }
}
