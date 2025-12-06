<?php

namespace Modules\PurchaseHistory\Policies;

use Illuminate\Auth\Access\HandlesAuthorization;

class GoodsReceiptPolicy
{
    use HandlesAuthorization;

    /**
     * Create a new policy instance.
     */
    public function __construct() {}
}
