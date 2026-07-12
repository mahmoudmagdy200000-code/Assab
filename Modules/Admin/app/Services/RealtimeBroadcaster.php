<?php

namespace Modules\Admin\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Modules\Admin\Events\AsabRealtimeEvent;
use Modules\Admin\Models\AsabNotification;
use Modules\Admin\Models\AsabSubscription;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\AsabUserRole;
use Modules\Admin\Models\Asset;
use Modules\Admin\Models\AssetDraft;
use Modules\Admin\Models\BillingInvoice;
use Modules\Admin\Models\CompanyInvitation;
use Modules\Admin\Models\CompanySubscription;
use Modules\Admin\Models\CompanyUser;
use Modules\Admin\Models\ErpBatch;
use Modules\Admin\Models\Operation;
use Modules\Admin\Models\Reminder;
use Modules\Admin\Models\Shift;
use Modules\Admin\Models\TicketMessage;

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
        // Spec §8: emitted to BOTH company and brand rooms; payload keys from/to/by.
        $payload = [
            'operationId' => $op->public_id,
            'from' => $oldStatus,
            'to' => $newStatus,
            'by' => ['id' => $actor->id, 'name' => $actor->name],
        ];
        $this->safe(fn () => $this->emit('operations.company.'.$op->company_id, 'operation.status_changed', $payload));
        $this->safe(fn () => $this->emit('operations.brand.'.$this->brandIdForBranch($op->branch_id), 'operation.status_changed', $payload));
    }

    public function operationCreated(Operation $op): void
    {
        // Spec §8: operation.created → company room.
        $this->safe(fn () => $this->emit(
            'operations.company.'.$op->company_id,
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
        // Spec §8: erp.batch.completed → the head accountant(s).
        $payload = ['batchId' => $batch->batch_id, 'status' => $batch->status, 'companyId' => $batch->company_id];
        $this->safe(fn () => $this->toUsers($this->roleUserIds($batch->company_id, 'head'), 'erp.batch.completed', $payload));
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
        // Spec §8: subscription.expiring → admin user(s); payload { daysRemaining }.
        $this->safe(fn () => $this->toUsers(
            $this->roleUserIds($sub->company_id, 'company-admin'),
            'subscription.expiring',
            ['subscriptionId' => $sub->id, 'daysRemaining' => $daysLeft],
        ));
    }

    // ── Company-dashboard §8 events (COMPANY_DASHBOARD_API_SPEC.md §8.2) ─────

    public function subscriptionUpdated(CompanySubscription $sub): void
    {
        $this->safe(fn () => $this->emit('operations.company.'.$sub->company_id, 'subscription.updated', $this->subscriptionPayload($sub)));
    }

    public function subscriptionSuspended(CompanySubscription $sub): void
    {
        $this->safe(fn () => $this->emit('operations.company.'.$sub->company_id, 'subscription.suspended', $this->subscriptionPayload($sub)));
    }

    // ── FE completion-request §1 / §2 events ───────────────────────────────

    /** Admin-layer (per-restaurant/brand) subscription change — FE request §1.1. */
    public function asabSubscriptionUpdated(\Modules\Admin\Models\AsabSubscription $sub): void
    {
        $this->safe(fn () => $this->emit('operations.company.'.$sub->company_id, 'subscription.updated', [
            'id' => $sub->id,
            'companyId' => $sub->company_id,
            'brandId' => $sub->brand_id,
            'restaurantId' => $sub->restaurant_id,
            'plan' => $sub->plan,
            'status' => $sub->status,
            'expiresAt' => optional($sub->expires_at)->toIso8601String(),
        ]));
    }

    /** Brand bulk-upload progress tick — FE request §1.7 (Option B). */
    public function brandUploadProgress(
        string $companyId,
        string $brandId,
        string $type,
        string $status,
        int $progressPct,
        int $parsedRows,
        int $failedRows
    ): void {
        $this->safe(fn () => $this->emit('operations.company.'.$companyId, 'brand.upload.progress', [
            'brandId' => $brandId,
            'type' => $type,
            'status' => $status,
            'progressPct' => $progressPct,
            'parsedRows' => $parsedRows,
            'failedRows' => $failedRows,
        ]));
    }

    /** Cross-admin permission-matrix change — FE request §2.3 / §4.4. */
    public function permissionsMatrixUpdated(?string $companyId): void
    {
        $this->safe(fn () => $this->emit('operations.company.'.($companyId ?: 'platform'), 'permissions.matrix.updated', [
            'updatedAt' => now()->toIso8601String(),
        ]));
    }

    // ── Live support chat — FE request §2.1 (channel chat.session.{id}) ─────

    public function chatMessageNew(\Modules\Admin\Models\SupportChatMessage $msg): void
    {
        $this->safe(fn () => $this->emit('chat.session.'.$msg->session_id, 'message.new', [
            'id' => $msg->id,
            'authorType' => $msg->author_type,
            'text' => $msg->text,
            'sentAt' => optional($msg->sent_at)->toIso8601String(),
        ]));
    }

    public function chatAgentJoined(string $sessionId, string $agentName): void
    {
        $this->safe(fn () => $this->emit('chat.session.'.$sessionId, 'agent.joined', ['agentName' => $agentName]));
    }

    public function chatSessionClosed(string $sessionId, string $closedBy, ?string $reason = null): void
    {
        $this->safe(fn () => $this->emit('chat.session.'.$sessionId, 'session.closed', ['closedBy' => $closedBy, 'reason' => $reason]));
    }

    public function invoiceCreated(BillingInvoice $inv): void
    {
        $this->safe(fn () => $this->toUsers($this->roleUserIds($inv->company_id, 'company-admin'), 'invoice.created', $this->invoicePayload($inv)));
    }

    public function invoicePaid(BillingInvoice $inv): void
    {
        $this->safe(fn () => $this->toUsers($this->roleUserIds($inv->company_id, 'company-admin'), 'invoice.paid', $this->invoicePayload($inv)));
    }

    public function invoicePaymentFailed(BillingInvoice $inv): void
    {
        $this->safe(fn () => $this->toUsers($this->roleUserIds($inv->company_id, 'company-admin'), 'invoice.payment_failed', $this->invoicePayload($inv)));
    }

    public function quotaWarning(string $companyId, string $resource, int $used, int $max): void
    {
        $this->safe(fn () => $this->toUsers($this->roleUserIds($companyId, 'company-admin'), 'quota.warning', compact('resource', 'used', 'max')));
    }

    public function quotaExceeded(string $companyId, string $resource, int $used, int $max): void
    {
        $this->safe(fn () => $this->toUsers($this->roleUserIds($companyId, 'company-admin'), 'quota.exceeded', compact('resource', 'used', 'max')));
    }

    public function userInvited(string $companyId, CompanyInvitation $inv): void
    {
        $this->safe(fn () => $this->toUsers($this->roleUserIds($companyId, 'company-admin'), 'user.invited', [
            'id' => $inv->id, 'email' => $inv->email, 'roleKey' => $inv->role_key, 'status' => $inv->status,
        ]));
    }

    /** @param  'joined'|'role_changed'|'suspended'  $action */
    public function userLifecycle(string $companyId, string $action, CompanyUser $member): void
    {
        $user = $member->user_id ? AsabUser::find($member->user_id) : null;
        $payload = [
            'id' => $member->id, 'userId' => $member->user_id, 'name' => $user?->name,
            'email' => $user?->email, 'roleKey' => $member->role_key, 'status' => $member->status,
        ];
        $this->safe(fn () => $this->toUsers($this->roleUserIds($companyId, 'company-admin'), 'user.'.$action, $payload));
    }

    public function supportTicketReplied(TicketMessage $msg, string $openerUserId): void
    {
        $this->safe(fn () => $this->toUsers([$openerUserId], 'support.ticket_replied', [
            'id' => $msg->id, 'ticketId' => $msg->ticket_id, 'body' => $msg->body,
            'authorType' => $msg->author_type, 'createdAt' => optional($msg->created_at)->toIso8601String(),
        ]));
    }

    /** @param  'created'|'updated'  $action */
    public function branchChanged(string $companyId, string $action, string $branchId, ?string $name = null): void
    {
        $this->safe(fn () => $this->emit('operations.company.'.$companyId, 'branch.'.$action, ['branchId' => $branchId, 'name' => $name]));
    }

    /** @param  'opened'|'closed'  $action */
    public function shiftChanged(Shift $shift, string $action): void
    {
        $payload = [
            'id' => $shift->id, 'branchId' => $shift->branch_id, 'status' => $shift->status,
            'supervisor' => $shift->supervisor_name,
            'startedAt' => optional($shift->started_at)->toIso8601String(),
            'endedAt' => optional($shift->ended_at)->toIso8601String(),
        ];
        // Branch room + the brand's accountants (spec §8).
        $this->safe(fn () => $this->emit('reminders.branch.'.$shift->branch_id, 'shift.'.$action, $payload));
        $this->safe(fn () => $this->emit('operations.brand.'.$this->brandIdForBranch($shift->branch_id), 'shift.'.$action, $payload));
    }

    public function purchaseRequestNew(string $companyId, array $payload): void
    {
        $this->safe(fn () => $this->toUsers($this->roleUserIds($companyId, 'procurement'), 'purchase_request.new', $payload));
    }

    public function inventoryFlagSent(string $branchId, array $items): void
    {
        $this->safe(fn () => $this->emit('reminders.branch.'.$branchId, 'inventory.flag_sent', ['branchId' => $branchId, 'items' => $items]));
    }

    /** ACC-4.5 / MOB-1.2 — the branch's daily count list was changed on the dashboard. */
    public function inventoryDailyListUpdated(string $branchId, int $itemCount): void
    {
        $this->safe(fn () => $this->emit('reminders.branch.'.$branchId, 'inventory.daily_list_updated', [
            'branchId' => $branchId, 'itemCount' => $itemCount, 'at' => now()->toIso8601String(),
        ]));
    }

    /** Daily inventory variance allocated to employees (MISSING_Dashboard §9.2). */
    public function inventoryVarianceAllocated(string $branchId, string $date, int $totalValueHalalas): void
    {
        $this->safe(fn () => $this->emit(
            'operations.brand.'.$this->brandIdForBranch($branchId),
            'inventory.variance_allocated',
            ['branchId' => $branchId, 'date' => $date, 'totalValueHalalas' => $totalValueHalalas],
        ));
    }

    public function assetDraftCreated(AssetDraft $draft, ?string $branchId): void
    {
        $this->safe(fn () => $this->emit(
            'operations.brand.'.$this->brandIdForBranch($branchId),
            'asset_draft.created',
            ['id' => $draft->id, 'draftId' => $draft->draft_id, 'name' => $draft->asset_name, 'status' => $draft->status],
        ));
    }

    public function assetDraftConfirmed(AssetDraft $draft, ?string $branchId): void
    {
        $this->safe(fn () => $this->emit(
            'reminders.branch.'.$branchId,
            'asset_draft.confirmed',
            ['id' => $draft->id, 'draftId' => $draft->draft_id, 'status' => $draft->status],
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

    /** Emit one event onto each user's private notifications channel. */
    private function toUsers(iterable $userIds, string $event, array $payload): void
    {
        foreach ($userIds as $uid) {
            $this->emit('notifications.user.'.$uid, $event, $payload);
        }
    }

    /** User ids holding a role within a company. */
    private function roleUserIds(string $companyId, string $roleKey): Collection
    {
        return AsabUserRole::query()
            ->where('role_key', $roleKey)
            ->whereHas('user', fn ($q) => $q->where('company_id', $companyId))
            ->pluck('user_id')->unique()->values();
    }

    /** @return array<string, mixed> */
    private function subscriptionPayload(CompanySubscription $sub): array
    {
        return [
            'id' => $sub->id, 'companyId' => $sub->company_id, 'planId' => $sub->plan_id,
            'status' => $sub->status, 'billingCycle' => $sub->billing_cycle,
            'currentPeriodEnd' => optional($sub->current_period_end)->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function invoicePayload(BillingInvoice $inv): array
    {
        return [
            'id' => $inv->id, 'publicId' => $inv->public_id, 'companyId' => $inv->company_id,
            'totalHalalas' => $inv->total, 'amountDueHalalas' => $inv->amount_due, 'status' => $inv->status,
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
