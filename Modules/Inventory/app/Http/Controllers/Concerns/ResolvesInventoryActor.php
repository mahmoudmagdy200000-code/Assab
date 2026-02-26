<?php

namespace Modules\Inventory\Http\Controllers\Concerns;

use Modules\Inventory\Support\InventoryActor;

trait ResolvesInventoryActor
{
    protected function resolveInventoryActor(): InventoryActor
    {
        return InventoryActor::resolve();
    }
}
