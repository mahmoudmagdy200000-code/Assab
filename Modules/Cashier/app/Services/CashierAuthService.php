<?php

namespace Modules\Cashier\Services;

use App\Services\FirstLoginActivationResolver;
use App\Services\FirstLoginPolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Hashing\Hasher;
use Modules\Cashier\Models\Cashier;

/**
 * The cashier half of the shared mobile first-login contract.
 *
 * Until now the cashier was the only one of the four mobile account types with
 * no `first-login` endpoint at all: its screen had to post
 * `identifier + default_password + password` in a single shot, which meant an
 * app build that only knows the other three's two-step flow (sign in → receive
 * a `first-login-token` → set the password) could not activate a cashier.
 *
 * Same semantics as BranchManagers/BrandOwner/Supplier `AuthService`:
 * signing in with the issued password COMPLETES activation unless
 * FirstLoginPolicy forces the reset screen — the flag must never be able to
 * lock an account out with no exit (the 2026-07-26 lock-out).
 */
class CashierAuthService
{
    public function __construct(
        private readonly FirstLoginPolicy $firstLoginPolicy,
        private readonly Hasher $hasher,
    ) {}

    /**
     * @return array{cashier: Cashier, token: string, requiresPasswordReset: bool}
     *
     * @throws AuthenticationException wrong identifier or password
     * @throws AuthorizationException the account was deactivated
     */
    public function firstLogin(string $identifier, string $password): array
    {
        $cashier = $this->findByIdentifier($identifier);

        // One message for «no such account» and «wrong password» alike: the
        // cashier surface used to answer 404 for an unknown identifier, which
        // turns the endpoint into an account enumerator.
        if ($cashier === null || ! $this->hasher->check($password, $cashier->password)) {
            throw new AuthenticationException('Invalid credentials or inactive account.');
        }

        if ($cashier->isDeactivated()) {
            throw new AuthorizationException('Your account is not active. Please contact your manager.');
        }

        if (! $this->firstLoginPolicy->forcesReset()) {
            $this->completeActivation($cashier);
            $cashier->save();
        }

        return [
            'cashier' => $cashier,
            'token' => $cashier->createToken(FirstLoginActivationResolver::FIRST_LOGIN_TOKEN_NAME)->plainTextToken,
            'requiresPasswordReset' => $this->firstLoginPolicy->forcesReset(),
        ];
    }

    /**
     * Set the password the «activate Account» screen collected, and make sure
     * the account is usable afterwards.
     *
     * `password` is assigned in the clear on purpose — the model's `hashed`
     * cast owns the hashing (and passes an already-hashed value through), so
     * there is exactly one hashing path.
     */
    public function setFirstLoginPassword(Cashier $cashier, string $newPassword): Cashier
    {
        $cashier->forceFill(['password' => $newPassword]);
        $this->completeActivation($cashier);
        $cashier->save();

        return $cashier;
    }

    /**
     * Fill in the «activated» state once the issued credential has been proven.
     * Idempotent, and it does NOT save: the caller owns the write, so a password
     * change and the activation land in ONE update (and therefore one
     * CashierActivatedEvent from the observer).
     */
    private function completeActivation(Cashier $cashier): void
    {
        if (! $cashier->isPending()) {
            return;
        }

        $cashier->forceFill([
            'status' => 'active',
            'activated_at' => $cashier->activated_at ?? now(),
        ]);
    }

    private function findByIdentifier(string $identifier): ?Cashier
    {
        $field = filter_var($identifier, FILTER_VALIDATE_EMAIL) ? 'email' : 'phone';

        return Cashier::where($field, $identifier)->first();
    }
}
