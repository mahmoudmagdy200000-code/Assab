<?php

namespace Modules\BrandOwner\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\BrandOwner\Models\BrandOwner;

/**
 * A brand owner set a new password in the mobile app (first-login setup or an
 * OTP reset). The dashboard world keeps its own credential in asab_users, so
 * the Admin module listens for this to keep the two in step — without it, the
 * shared password diverges on the owner's very first mobile login.
 */
class BrandOwnerPasswordChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly BrandOwner $owner) {}
}
