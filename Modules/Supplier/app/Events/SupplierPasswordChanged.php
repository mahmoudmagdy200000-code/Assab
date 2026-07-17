<?php

namespace Modules\Supplier\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Supplier\Models\Supplier;

/**
 * A supplier set a new password in the mobile app (first-login setup, an OTP
 * reset, or a self-service change). The dashboard world keeps its own
 * credential in asab_users, so the Admin module listens for this to keep the
 * two in step — without it, the shared password diverges on the supplier's very
 * first mobile login, which provisioning forces.
 */
class SupplierPasswordChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Supplier $supplier) {}
}
