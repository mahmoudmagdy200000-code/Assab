<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Notifications\BrandOwnerWelcomeNotification;
use Modules\BrandOwner\Models\BrandOwner as MobileBrandOwner;

/**
 * Provisioning side-effects for admin "Add Brand" with an ownerEmail (B-A6):
 * ensure a brand-owner login exists and is linked to the brand, and email a
 * one-time password for freshly-created accounts. The same credentials are
 * provisioned into the mobile app's brand_owners table so the emailed
 * password works on BOTH the dashboard and the mobile app (client meeting:
 * "brand owner receives login credentials via email to access the mobile app").
 * Mirrors CompanyProvisioningService; kept out of the controller so the HTTP
 * layer stays thin (SRP). Runs inside the caller's DB transaction.
 */
class BrandOwnerProvisioningService
{
    /**
     * @return array{user: AsabUser, emailSent: bool}
     *
     * @throws AsabException 422 when the email belongs to a user in a different company.
     */
    public function provision(AsabBrand $brand, string $email, ?string $name): array
    {
        $existing = AsabUser::where('email', $email)->first();

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

        // One shared one-time password when either account is being created;
        // accounts that already exist keep their current password.
        $oneTimePassword = (! $existing || ! $mobileOwner || $mobileOwner->trashed())
            ? Str::password(12)
            : null;

        if ($existing) {
            $user = $existing;
        } else {
            $user = AsabUser::create([
                'company_id' => $brand->company_id,
                'name' => $displayName,
                'email' => $email,
                'password' => $oneTimePassword, // hashed by the model's 'hashed' cast
                'status' => 'active',
                'default_page' => 'brand-owner-dashboard',
            ]);
        }

        $this->provisionMobileOwner($mobileOwner, $email, $displayName, $oneTimePassword);

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

        // Best-effort delivery: a mail-transport outage must not fail brand creation.
        $emailSent = false;
        try {
            $user->notify(new BrandOwnerWelcomeNotification($brand->name, $oneTimePassword));
            $emailSent = true;
        } catch (\Throwable $e) {
            Log::warning('Brand-owner welcome email failed: '.$e->getMessage());
        }

        return ['user' => $user, 'emailSent' => $emailSent];
    }

    /**
     * Ensure the mobile app can authenticate this owner: create (or restore)
     * the legacy brand_owners row with the shared one-time password and the
     * first-login flag so the app forces a password reset. An existing live
     * account keeps its current password.
     */
    private function provisionMobileOwner(?MobileBrandOwner $mobileOwner, string $email, string $displayName, ?string $oneTimePassword): void
    {
        if ($mobileOwner && ! $mobileOwner->trashed()) {
            return;
        }

        if ($mobileOwner) {
            $mobileOwner->restore();
            $mobileOwner->update([
                'password' => $oneTimePassword, // hashed by the model's 'hashed' cast
                'is_active' => true,
                'is_first_login' => true,
                'status' => 'active',
            ]);

            return;
        }

        MobileBrandOwner::create([
            'name' => $displayName,
            'email' => $email,
            'password' => $oneTimePassword, // hashed by the model's 'hashed' cast
            'is_active' => true,
            'is_first_login' => true,
            'status' => 'active',
        ]);
    }
}
