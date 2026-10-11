<?php

namespace Modules\Shift\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Modules\Admin\Models\AsabUser;
use Modules\Admin\Models\Employee;
use Modules\BranchManagers\Models\BranchManager;
use Modules\Cashier\Models\Cashier;
use Modules\Shift\Jobs\DispatchShiftReportCorrectionNotificationsJob;
use Modules\Shift\Models\CashierShift;
use Modules\Shift\Models\ShiftLiabilityAllocation;
use Modules\Shift\Models\ShiftReportCorrectionNotification;
use Modules\Shift\Models\ShiftReportRevision;
use Modules\Shift\Notifications\ShiftReportCorrectionNotificationMessage;
use Throwable;

class ShiftReportCorrectionNotificationService
{
    /**
     * Records notification intents inside the correction transaction and schedules post-commit dispatch.
     */
    public function enqueue(
        ShiftReportRevision $revision,
        CashierShift $shift,
        ?ShiftReportRevision $previousRevision,
        ?ShiftLiabilityAllocation $previousAllocation,
        ?ShiftLiabilityAllocation $newAllocation,
        string $reason
    ): void {
        $notifications = [];

        // 1. Shift owner cashier notification
        $notifications[] = [
            'id' => (string) Str::uuid(),
            'report_revision_id' => $revision->id,
            'event_type' => 'report_corrected',
            'recipient_type' => 'cashier',
            'recipient_id' => (string) $shift->cashier_id,
            'status' => 'pending',
            'payload' => json_encode([
                'shift_id' => $shift->id,
                'revision_number' => $revision->revision_number,
                'reason' => $reason,
            ], JSON_THROW_ON_ERROR),
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ];

        // 2. Shareholder notifications for shares that changed between previous and new allocations
        $oldShares = [];
        if ($previousAllocation !== null) {
            foreach ($previousAllocation->shares as $share) {
                $key = "{$share->responsible_type}:{$share->responsible_id}";
                $oldShares[$key] = [
                    'type' => $share->responsible_type,
                    'id' => $share->responsible_id,
                    'amount' => (int) $share->amount_halalas,
                ];
            }
        }

        $newShares = [];
        if ($newAllocation !== null) {
            foreach ($newAllocation->shares as $share) {
                $key = "{$share->responsible_type}:{$share->responsible_id}";
                $newShares[$key] = [
                    'type' => $share->responsible_type,
                    'id' => $share->responsible_id,
                    'amount' => (int) $share->amount_halalas,
                ];
            }
        }

        $allKeys = array_unique(array_merge(array_keys($oldShares), array_keys($newShares)));
        foreach ($allKeys as $key) {
            $oldAmount = $oldShares[$key]['amount'] ?? 0;
            $newAmount = $newShares[$key]['amount'] ?? 0;

            if ($oldAmount !== $newAmount) {
                [$rType, $rId] = explode(':', $key, 2);
                $notifications[] = [
                    'id' => (string) Str::uuid(),
                    'report_revision_id' => $revision->id,
                    'event_type' => 'liability_share_changed',
                    'recipient_type' => $rType,
                    'recipient_id' => $rId,
                    'status' => 'pending',
                    'payload' => json_encode([
                        'shift_id' => $shift->id,
                        'revision_number' => $revision->revision_number,
                        'old_amount_halalas' => $oldAmount,
                        'new_amount_halalas' => $newAmount,
                        'reason' => $reason,
                    ], JSON_THROW_ON_ERROR),
                    'attempts' => 0,
                    'created_at' => now(),
                    'updated_at' => now(),
                ];
            }
        }

        foreach ($notifications as $data) {
            DB::table('shift_report_correction_notifications')->insertOrIgnore($data);
        }

        $revId = $revision->id;
        DB::afterCommit(function () use ($revId) {
            try {
                dispatch(new DispatchShiftReportCorrectionNotificationsJob($revId));
            } catch (Throwable $e) {
                Log::warning('Shift report correction notification job dispatch failed after commit', [
                    'revision_id' => $revId,
                    'error' => $e->getMessage(),
                ]);
            }
        });
    }

    /**
     * Dispatches pending notifications with exact-once local inbox semantics.
     */
    public function dispatchPending(?string $revisionId = null): int
    {
        $query = ShiftReportCorrectionNotification::query()
            ->where('status', 'pending');

        if ($revisionId !== null) {
            $query->where('report_revision_id', $revisionId);
        }

        $pending = $query->get();
        $dispatchedCount = 0;

        foreach ($pending as $item) {
            $item->increment('attempts');

            try {
                $notifiable = $this->resolveNotifiable($item->recipient_type, $item->recipient_id);

                if ($notifiable === 'BLOCKED') {
                    $item->update([
                        'status' => 'blocked',
                        'last_error' => 'IDENTITY_MAPPING_MISSING',
                    ]);

                    continue;
                }

                if ($notifiable === null) {
                    $item->update([
                        'status' => 'blocked',
                        'last_error' => 'RECIPIENT_NOT_FOUND',
                    ]);

                    continue;
                }

                // Check for duplicate inbox delivery using event ID
                $alreadyDelivered = DB::table('notifications')
                    ->where('id', $item->id)
                    ->exists();

                if (! $alreadyDelivered) {
                    $payload = is_array($item->payload) ? $item->payload : json_decode((string) $item->payload, true);
                    $notifiable->notify(new ShiftReportCorrectionNotificationMessage($item->id, $item->event_type, $payload));
                }

                $item->update([
                    'status' => 'dispatched',
                    'dispatched_at' => now(),
                    'last_error' => null,
                ]);

                $dispatchedCount++;
            } catch (Throwable $e) {
                $item->update([
                    'last_error' => $e->getMessage(),
                ]);
                Log::error('Notification dispatch failure', [
                    'notification_id' => $item->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $dispatchedCount;
    }

    private function resolveNotifiable(string $type, string $id): mixed
    {
        if ($type === 'cashier') {
            return Cashier::find($id);
        }

        if ($type === 'branch_manager') {
            return BranchManager::find($id);
        }

        if ($type === 'accountant') {
            return AsabUser::find($id);
        }

        if ($type === 'employee') {
            $employee = Employee::find($id);
            if ($employee === null || $employee->asab_user_id === null) {
                return 'BLOCKED';
            }

            return AsabUser::find($employee->asab_user_id) ?? 'BLOCKED';
        }

        return null;
    }
}
