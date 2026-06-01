<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Facades\Log;
use Modules\Admin\Events\AsabRealtimeEvent;
use Modules\Admin\Models\AsabNotification;
use Modules\Admin\Models\AsabSubscription;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Asset;
use Modules\Admin\Models\ErpBatch;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\Reminder;

/**
 * Central, fail-safe real-time dispatcher (BACKEND_API_SPEC.md §8).
 * Every push is wrapped so a broadcaster (Pusher) outage can never break the
 * API request that triggered it — the DB transaction has already committed.
 */
class RealtimeBroadcaster
{
    /** @var array<string, ?string> branchId → brandId resolution cache (per request) */
    private array $brandCache = [];

    public function notificationNew(AsabNotification $n): void
    {
        $this->safe(fn () => $this->emit(
            'notifications.user.'.$n->user_id,
            'notification.new',
            $this->notificationPayload($n),
        ));
    }

    public function operationStatusChanged(Operation $op, string $oldStatus, string $newStatus, AsabUser $actor): void
    {
        $this->safe(fn () => $this->emit(
            'operations.brand.'.$this->brandIdForBranch($op->branch_id),
            'operation.status_changed',
            [
                'operationId' => $op->public_id,
                'oldStatus' => $oldStatus,
                'newStatus' => $newStatus,
                'actor' => ['id' => $actor->id, 'name' => $actor->name],
            ],
        ));
    }

    public function operationCreated(Operation $op): void
    {
        $this->safe(fn () => $this->emit(
            'operations.brand.'.$this->brandIdForBranch($op->branch_id),
            'operation.created',
            [
                'operationId' => $op->public_id,
                'id' => $op->id,
                'moduleKey' => $op->module_key,
                'branchId' => $op->branch_id,
                'amount' => $op->amount,
                'match' => $op->match,
                'origin' => $op->origin,
                'status' => $op->status,
                'operationDate' => optional($op->operation_date)->toIso8601String(),
            ],
        ));
    }

    public function approvalPending(string $companyId, string $accountantId, int $count): void
    {
        $this->safe(fn () => $this->emit(
            'operations.company.'.$companyId,
            'approval.pending',
            ['accountantId' => $accountantId, 'count' => $count],
        ));
    }

    public function erpBatchCompleted(ErpBatch $batch): void
    {
        $this->safe(fn () => $this->emit(
            'operations.company.'.$batch->company_id,
            'erp.batch.completed',
            ['batchId' => $batch->batch_id, 'status' => $batch->status],
        ));
    }

    public function reminderResponded(Reminder $reminder): void
    {
        $this->safe(fn () => $this->emit(
            'reminders.branch.'.$reminder->branch_id,
            'reminder.responded',
            ['reminderId' => $reminder->id, 'response' => $reminder->response],
        ));
    }

    public function assetConfirmationNeeded(Asset $asset): void
    {
        $this->safe(fn () => $this->emit(
            'reminders.branch.'.$asset->branch_id,
            'asset.confirmation_needed',
            ['assetId' => $asset->id, 'branchId' => $asset->branch_id],
        ));
    }

    public function moduleChanged(string $companyId, string $moduleKey, bool $isActive): void
    {
        $this->safe(fn () => $this->emit(
            'operations.company.'.$companyId,
            'module.changed',
            ['moduleKey' => $moduleKey, 'isActive' => $isActive],
        ));
    }

    public function subscriptionExpiring(AsabSubscription $sub, int $daysLeft): void
    {
        $this->safe(fn () => $this->emit(
            'operations.company.'.$sub->company_id,
            'subscription.expiring',
            ['subscriptionId' => $sub->id, 'daysLeft' => $daysLeft],
        ));
    }

    /** Resolve a branch's owning brand id (spec channel is keyed by brand). */
    public function brandIdForBranch(?string $branchId): string
    {
        if (! $branchId) {
            return 'unknown';
        }
        if (! array_key_exists($branchId, $this->brandCache)) {
            $this->brandCache[$branchId] = \Modules\Branch\Models\Branch::query()
                ->whereKey($branchId)
                ->value('asab_brand_id');
        }

        return $this->brandCache[$branchId] ?? 'unknown';
    }

    /** @return array<string, mixed> */
    private function notificationPayload(AsabNotification $n): array
    {
        return [
            'id' => $n->id,
            'type' => $n->type,
            'title' => $n->title,
            'body' => $n->body,
            'link' => $n->link,
            'refType' => $n->ref_type,
            'refId' => $n->ref_id,
            'readAt' => optional($n->read_at)->toIso8601String(),
            'createdAt' => optional($n->created_at)->toIso8601String(),
        ];
    }

    private function emit(string $channel, string $event, array $payload): void
    {
        broadcast(new AsabRealtimeEvent($channel, $event, $payload));
    }

    private function safe(callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            // Broadcasting is best-effort; never fail the originating request.
            Log::warning('ASAB realtime broadcast failed: '.$e->getMessage());
        }
    }
}
