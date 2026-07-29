<?php

namespace Modules\Admin\Console\Commands;

use Illuminate\Console\Command;
use Modules\Admin\Services\MobileCashierMirrorService;
use Modules\Cashier\Models\Cashier;

/**
 * Backfill for cashiers created in the mobile app BEFORE the reverse bridge
 * existed: give each one its `asab_employees` mirror + identity link, so the
 * dashboard branch roster and the shift-close bridge can resolve them.
 * Idempotent — an already-mirrored cashier is skipped.
 */
class MirrorMobileCashiers extends Command
{
    protected $signature = 'asab:mirror-mobile-cashiers';

    protected $description = 'Mirror mobile-app cashiers into asab_employees (branch roster + shift bridge)';

    public function handle(MobileCashierMirrorService $mirror): int
    {
        $mirrored = 0;
        $skipped = 0;

        Cashier::query()->orderBy('created_at')->chunkById(200, function ($cashiers) use ($mirror, &$mirrored, &$skipped) {
            foreach ($cashiers as $cashier) {
                try {
                    $mirror->mirror($cashier) === null ? $skipped++ : $mirrored++;
                } catch (\Throwable $e) {
                    $skipped++;
                    $this->warn("Cashier {$cashier->id}: {$e->getMessage()}");
                }
            }
        });

        $this->info("Mobile cashiers processed — linked (or already linked): {$mirrored}, skipped (branch has no dashboard company): {$skipped}.");

        return self::SUCCESS;
    }
}
