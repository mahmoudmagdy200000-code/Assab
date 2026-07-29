<?php

namespace Modules\Admin\Listeners;

use Illuminate\Support\Facades\Log;
use Modules\Admin\Services\MobileCashierMirrorService;
use Modules\Cashier\Events\CashierCreatedEvent;

/**
 * Two-worlds cashier bridge, mobile → dashboard: a cashier the branch manager
 * adds in the mobile app gets its `asab_employees` counterpart so the dashboard
 * branch screen lists them and their shift closes can be bridged.
 *
 * Best-effort: a mirroring failure must never fail the mobile creation the
 * manager is standing in front of — the branch directory reads unmirrored
 * cashiers through anyway.
 */
class MirrorMobileCashierToEmployee
{
    public function __construct(private readonly MobileCashierMirrorService $mirror) {}

    public function handle(CashierCreatedEvent $event): void
    {
        try {
            $this->mirror->mirror($event->cashier);
        } catch (\Throwable $e) {
            Log::warning('Mobile cashier mirror failed: '.$e->getMessage(), ['cashierId' => $event->cashier->id]);
        }
    }
}
