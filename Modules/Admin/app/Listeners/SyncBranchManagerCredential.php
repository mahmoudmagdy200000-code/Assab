<?php

namespace Modules\Admin\Listeners;

use Modules\Admin\Models\AsabIdentityMap;
use Modules\Admin\Services\CredentialSyncService;
use Modules\BranchManagers\Events\PasswordChangedEvent;

/**
 * Two-worlds credential bridge: a password the branch manager set in the mobile
 * app also opens the dashboard. Provisioning marks the mobile row
 * is_first_login, so the app forces a reset on the first login — without this
 * listener the two worlds diverge immediately after onboarding.
 */
class SyncBranchManagerCredential
{
    public function __construct(private readonly CredentialSyncService $credentials) {}

    public function handle(PasswordChangedEvent $event): void
    {
        $this->credentials->pullFromMobile(AsabIdentityMap::ENTITY_BRANCH_MANAGER, $event->manager);
    }
}
