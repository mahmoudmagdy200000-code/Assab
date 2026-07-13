<?php

namespace Modules\Admin\Console\Commands;

use Illuminate\Console\Command;
use Modules\Admin\Services\IdentityMapService;

/**
 * One-off (idempotent) seed of asab_identity_map from the existing linkage:
 * asab_employees.legacy_cashier_id, asab_suppliers.legacy_supplier_id, and the
 * brand-owner email match. Run once in production after the table migration;
 * safe to re-run (updateOrCreate).
 */
class BackfillIdentityMap extends Command
{
    protected $signature = 'asab:backfill-identity-map';

    protected $description = 'Seed asab_identity_map from existing legacy_* links and the brand-owner email match (WS2)';

    public function handle(IdentityMapService $identity): int
    {
        $counts = $identity->backfill();
        $this->info(sprintf(
            'Identity map backfilled — cashier: %d, supplier: %d, brand_owner: %d, skipped: %d.',
            $counts['cashier'], $counts['supplier'], $counts['brand_owner'], $counts['skipped'],
        ));

        return self::SUCCESS;
    }
}
