<?php

namespace Modules\Admin\Listeners;

use Modules\Admin\Models\AsabIdentityMap;
use Modules\Admin\Services\CredentialSyncService;
use Modules\Supplier\Events\SupplierPasswordChanged;

/**
 * Two-worlds credential bridge: a password the supplier set in the mobile app
 * also opens the dashboard. Provisioning marks the mobile row is_first_login,
 * so the app forces a reset on the first login — without this listener the two
 * worlds diverge immediately after onboarding.
 */
class SyncSupplierCredential
{
    public function __construct(private readonly CredentialSyncService $credentials) {}

    public function handle(SupplierPasswordChanged $event): void
    {
        $this->credentials->pullFromMobile(AsabIdentityMap::ENTITY_SUPPLIER_USER, $event->supplier);
    }
}
