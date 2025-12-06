<?php

namespace Modules\PurchaseHistory\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;

class ReturnOrderPolicy
{
    use HandlesAuthorization;

    /**
     * Create a new policy instance.
     */
    public function __construct() {}
}
