<?php

namespace Modules\Custody\Services;

use Modules\BranchManagers\Models\BranchManager;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\Custody\Models\CustodyRequest;
use Modules\Notification\Contracts\NotificationServiceInterface;
use Modules\Notification\Enums\NotificationPriority;
use Modules\Notification\Enums\NotificationType;

/**
 * Meeting 2026-08-03 «لا يوجد إشعار»: the whole custody-request lifecycle
 * (create → approve/reject → receive) ran without emitting a single
 * notification — the copy keys existed in Notification/resources/lang, nothing
 * ever addressed them. Every send is best-effort: a push channel failure must
 * never roll back money movement, so failures are logged and swallowed.
 */
class CustodyNotifier
{
    public function __construct(
        private readonly NotificationServiceInterface $notifications,
        private readonly \Psr\Log\LoggerInterface $log,
    ) {}

    /** Owner → manager transfer: the recipient must know cash is on its way. */
    public function transferSentToManager(CustodyRequest $request): void
    {
        $manager = $this->manager($request);
        if ($manager === null) {
            return;
        }

        $this->send(
            fn () => $this->notifications->send(
                $manager,
                NotificationType::CUSTODY_CASH_TRANSFER,
                $this->payload($request) + ['owner_id' => $request->created_by_brand_owner_id],
                NotificationPriority::HIGH,
            ),
            $request,
            'transfer_sent',
        );
    }

    /** Manager → owner request: the approver must know it is waiting. */
    public function requestAwaitingOwner(CustodyRequest $request): void
    {
        $this->send(
            fn () => $this->notifications->sendToRole(
                'brand_owner',
                NotificationType::CUSTODY_REQUEST_CREATED,
                $this->payload($request),
                NotificationPriority::MEDIUM,
                $request->branch_id,
            ),
            $request,
            'request_created',
        );
    }

    public function requestApproved(CustodyRequest $request): void
    {
        $manager = $this->manager($request);
        if ($manager === null) {
            return;
        }

        $this->send(
            fn () => $this->notifications->send(
                $manager,
                NotificationType::CUSTODY_REQUEST_APPROVED,
                $this->payload($request),
                NotificationPriority::HIGH,
            ),
            $request,
            'request_approved',
        );
    }

    public function requestRejected(CustodyRequest $request, string $reason): void
    {
        $manager = $this->manager($request);
        if ($manager === null) {
            return;
        }

        $this->send(
            fn () => $this->notifications->send(
                $manager,
                NotificationType::CUSTODY_REQUEST_REJECTED,
                $this->payload($request) + ['reason' => $reason],
                NotificationPriority::HIGH,
            ),
            $request,
            'request_rejected',
        );
    }

    /** Receipt confirmed: the payer (owner) closes the loop on their side. */
    public function receiptConfirmed(CustodyRequest $request): void
    {
        $owner = $request->created_by_brand_owner_id
            ? BrandOwner::find($request->created_by_brand_owner_id)
            : null;

        if ($owner === null) {
            return;
        }

        $this->send(
            fn () => $this->notifications->send(
                $owner,
                NotificationType::CUSTODY_CASH_TRANSFER,
                $this->payload($request) + ['received_at' => $request->received_at?->toIso8601String()],
                NotificationPriority::MEDIUM,
            ),
            $request,
            'receipt_confirmed',
        );
    }

    private function manager(CustodyRequest $request): ?BranchManager
    {
        return $request->branch_manager_id ? BranchManager::find($request->branch_manager_id) : null;
    }

    /** @return array<string, mixed> */
    private function payload(CustodyRequest $request): array
    {
        return [
            'custody_request_id' => $request->id,
            'branch_id' => $request->branch_id,
            'amount' => (float) $request->requested_amount,
            'preferred_receipt_method' => $request->preferred_receipt_method,
        ];
    }

    private function send(callable $fn, CustodyRequest $request, string $event): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            $this->log->warning('custody-notify: '.$event.' failed', [
                'custody_request_id' => $request->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
