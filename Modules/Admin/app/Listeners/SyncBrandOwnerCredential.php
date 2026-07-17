<?php

namespace Modules\Admin\Listeners;

use Modules\Admin\Models\AsabIdentityMap;
use Modules\Admin\Services\CredentialSyncService;
use Modules\BrandOwner\Events\BrandOwnerPasswordChanged;

/**
 * Two-worlds credential bridge: a password the owner set in the mobile app also
 * opens the dashboard. Provisioning marks the mobile row is_first_login, so the
 * app forces a reset on the first login — without this listener the two worlds
 * diverge immediately after onboarding, which is exactly the state the client
 * reported ("same password on mobile and dashboard").
 */
class SyncBrandOwnerCredential
{
    public function __construct(private readonly CredentialSyncService $credentials) {}

    public function handle(BrandOwnerPasswordChanged $event): void
    {
        $this->credentials->pullFromMobile(AsabIdentityMap::ENTITY_BRAND_OWNER, $event->owner);
    }
}
