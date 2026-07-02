<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Admin\Exceptions\AsabException;
use Modules\Admin\Models\AsabBrand;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Notifications\BrandOwnerWelcomeNotification;

/**
 * Provisioning side-effects for admin "Add Brand" with an ownerEmail (B-A6):
 * ensure a brand-owner login exists and is linked to the brand, and email a
 * one-time password for freshly-created accounts. Mirrors CompanyProvisioningService;
 * kept out of the controller so the HTTP layer stays thin (SRP). Runs inside the
 * caller's DB transaction.
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

        // Fresh account -> generate a one-time password; existing account -> link only.
        $oneTimePassword = null;
        if ($existing) {
            $user = $existing;
        } else {
            $oneTimePassword = Str::password(12);
            $user = AsabUser::create([
                'company_id' => $brand->company_id,
                'name' => $name ?: ($brand->owner ?: $brand->name),
                'email' => $email,
                'password' => $oneTimePassword, // hashed by the model's 'hashed' cast
                'status' => 'active',
                'default_page' => 'brand-owner-dashboard',
            ]);
        }

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
}
