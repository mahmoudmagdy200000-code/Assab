<?php

namespace Modules\Admin\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Admin\Models\Asset;

/**
 * A dashboard asset was assigned to a branch — the branch manager must receive
 * a mobile «طلب استلام» and confirm receipt (meeting 2026-07-30).
 */
class AssetAssignedToBranch
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public Asset $asset
    ) {}
}
