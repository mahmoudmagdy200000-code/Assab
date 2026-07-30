<?php

namespace Modules\Admin\Listeners;

use Modules\Admin\Events\AssetAssignedToBranch;
use Modules\FixedAssets\Models\PendingReceipt;
use Modules\Notification\Contracts\NotificationServiceInterface;
use Modules\Notification\Enums\NotificationType;

/**
 * Meeting 2026-07-30: the mobile «طلبات الاستلام» screen was structurally
 * always empty — nothing in production ever created a PendingReceipt, and the
 * dashboard's only signal was a Pusher event no phone listens to. This bridge
 * materialises the receive request on the mobile side and pushes a real
 * notification to the branch manager.
 */
class BridgeAssetToBranchReceipt
{
    public function __construct(
        private readonly NotificationServiceInterface $notifications,
        private readonly \Psr\Log\LoggerInterface $log,
    ) {}

    public function handle(AssetAssignedToBranch $event): void
    {
        $asset = $event->asset;

        if ($asset->branch_id === null || $asset->status !== 'pending_branch') {
            return;
        }

        try {
            // Keyed on asab_asset_id — idempotent under retries/re-dispatch.
            $receipt = PendingReceipt::updateOrCreate(
                ['asab_asset_id' => $asset->id],
                [
                    'asset_name' => $asset->name,
                    'asset_code' => $asset->public_id,
                    'recipient_branch_id' => $asset->branch_id,
                    'source' => 'finance',
                    'status' => 'pending',
                ],
            );

            $this->notifications->sendToRole('branch_manager', NotificationType::ASSET_RECEIVE_REQUESTED, [
                'asab_asset_id' => $asset->id,
                'pending_receipt_id' => $receipt->id,
                'branch_id' => $asset->branch_id,
                'asset_name' => $asset->name,
                'public_id' => $asset->public_id,
            ], branchId: $asset->branch_id);
        } catch (\Throwable $e) {
            $this->log->warning('asset-bridge: receive request failed', [
                'asset_id' => $asset->id, 'branch_id' => $asset->branch_id, 'error' => $e->getMessage(),
            ]);
        }
    }
}
