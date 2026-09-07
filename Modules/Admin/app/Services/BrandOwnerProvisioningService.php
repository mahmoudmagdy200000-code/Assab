<?php

namespace Modules\Admin\Services;

use App\Support\TemporaryPassword;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Notifications\BrandOwnerWelcomeNotification;
use Modules\BrandOwner\Models\BrandOwner as MobileBrandOwner;

/**
 * Provisioning side-effects for admin "Add Brand" with an ownerEmail (B-A6):
 * ensure a brand-owner login exists and is linked to the brand. The same
 * credential is provisioned into the mobile app's brand_owners table so the
 * emailed password works on BOTH the dashboard and the mobile app (client
 * meeting: "brand owner receives login credentials via email to access the
 * mobile app"). Mirrors CompanyProvisioningService; kept out of the controller
 * so the HTTP layer stays thin (SRP).
 *
 * Runs inside the caller's DB transaction, so it BUILDS the welcome
 * notification and hands it back rather than sending it — see provision().
 */
class BrandOwnerProvisioningService
{
    public function __construct(private readonly IdentityMapService $identity) {}

    /**
     * @return array{user: AsabUser, notification: BrandOwnerWelcomeNotification}
     *
     * @throws AsabException 422 when the email belongs to a user in a different company.
     */
    public function provision(AsabBrand $brand, string $email, ?string $name): array
    {
        // withTrashed: asab_users.email is uniquely indexed regardless of
        // deleted_at, so a soft-deleted row still owns the address and a
        // create() would hit the index instead of finding it.
        $existing = AsabUser::withTrashed()->where('email', $email)->first();

        // Multi-tenancy guard: an email already tied to another company cannot own this brand.
        if ($existing && $existing->company_id && $existing->company_id !== $brand->company_id) {
            throw new AsabException(
                'EMAIL_CONFLICT',
                'Email already belongs to a user in another company',
                'البريد الإلكتروني مسجّل مسبقاً لمستخدم في شركة أخرى',
                422,
            );
        }

        $displayName = $name ?: ($brand->owner ?: $brand->name);
        $mobileOwner = MobileBrandOwner::withTrashed()->where('email', $email)->first();

        $dashboardLive = $existing && ! $existing->trashed();
        $mobileLive = $mobileOwner && ! $mobileOwner->trashed();

        // A one-time password is minted only when NEITHER world holds a usable
        // credential. Where one side is live, its hash is copied to the other
        // instead: resetting a live account would lock out a working login, and
        // emailing a password that was written to only one of the two tables is
        // what made the welcome mail's promise false.
        $oneTimePassword = (! $dashboardLive && ! $mobileLive) ? TemporaryPassword::generate() : null;

        $user = $this->ensureDashboardUser($existing, $brand, $displayName, $email, $oneTimePassword, $mobileLive ? $mobileOwner->password : null);
        $legacyOwner = $this->provisionMobileOwner($mobileOwner, $email, $displayName, $oneTimePassword, $user->password);
        $this->identity->linkBrandOwner($user->id, $legacyOwner->id, $brand->company_id, $email);

        // Upsert the brand-owner role assignment, merging this brand into brand_ids.
        $assignment = AsabUserRole::firstOrNew([
            'user_id' => $user->id,
            'role_key' => 'brand-owner',
        ]);
        $brandIds = $assignment->brand_ids ?? [];
        $brandIds[] = $brand->id;
        $assignment->scope = 'brand';
        $assignment->brand_ids = array_values(array_unique($brandIds));
        $assignment->save();

        // Built, not sent: this runs inside the caller's transaction, and mail
        // dispatched here would survive a rollback as a welcome for a brand that
        // does not exist. The caller sends it after the commit.
        return [
            'user' => $user,
            'notification' => new BrandOwnerWelcomeNotification($brand->name, $oneTimePassword),
        ];
    }

    /**
     * Resolve the dashboard login: reuse a live account, restore a soft-deleted
     * one, or create it. `$mobilePassword` carries the live mobile hash when the
     * dashboard side is the one being (re)built, so both worlds end up on one
     * credential without either being reset.
     */
    private function ensureDashboardUser(
        ?AsabUser $existing,
        AsabBrand $brand,
        string $displayName,
        string $email,
        ?string $oneTimePassword,
        ?string $mobilePassword,
    ): AsabUser {
        if ($existing && ! $existing->trashed()) {
            return $existing;
        }

        if ($existing) {
            $existing->restore();
            $existing->forceFill([
                'company_id' => $brand->company_id,
                'name' => $displayName,
                'password' => $oneTimePassword ?? $mobilePassword ?? $existing->password,
                'status' => 'active',
                'default_page' => 'brand-owner-dashboard',
            ])->save();

            return $existing;
        }

        return AsabUser::create([
            'company_id' => $brand->company_id,
            'name' => $displayName,
            'email' => $email,
            'password' => $oneTimePassword ?? $mobilePassword, // hashed by the model's 'hashed' cast
            'status' => 'active',
            'default_page' => 'brand-owner-dashboard',
        ]);
    }

    /**
     * Ensure the mobile app can authenticate this owner: create (or restore)
     * the legacy brand_owners row. It takes the shared one-time password when
     * one was minted, otherwise it inherits the dashboard account's existing
     * hash so a single credential opens both worlds. An existing live account
     * keeps its own password. Returns the ensured owner so the caller can
     * record the cross-world identity link.
     *
     * is_first_login is set only alongside a one-time password: it makes the
     * app force a reset, which is right for an admin-issued temporary password
     * but would lock an owner out of a password they already chose.
     */
    private function provisionMobileOwner(
        ?MobileBrandOwner $mobileOwner,
        string $email,
        string $displayName,
        ?string $oneTimePassword,
        string $dashboardPassword,
    ): MobileBrandOwner {
        if ($mobileOwner && ! $mobileOwner->trashed()) {
            return $mobileOwner;
        }

        // hashed by the model's 'hashed' cast, which passes an already-hashed
        // dashboard password through untouched rather than double-hashing it.
        $password = $oneTimePassword ?? $dashboardPassword;

        if ($mobileOwner) {
            $mobileOwner->restore();
            $mobileOwner->forceFill([
                'password' => $password,
                'is_active' => true,
                'is_first_login' => $oneTimePassword !== null,
                'status' => 'active',
            ])->save();

            return $mobileOwner;
        }

        return MobileBrandOwner::create([
            'name' => $displayName,
            'email' => $email,
            'password' => $password,
            'is_active' => true,
            'is_first_login' => $oneTimePassword !== null,
            'status' => 'active',
        ]);
    }
}
