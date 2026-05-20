<?php

namespace Modules\Custody\Services;

use App\Http\Resources\UnifiedTimelineResource;
use Illuminate\Support\Facades\DB;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\Custody\Models\CustodyRequest;
use Modules\Custody\Models\CustodyRequestAttachment;
use Modules\Custody\Models\CustodyRequestTimeline;

class CustodyRequestService
{
    /**
     * Create new custody request
     */
    public function createRequest(array $data): CustodyRequest
    {
        return DB::transaction(function () use ($data) {
            $isBrandOwner = ! empty($data['created_by_brand_owner_id']);

            $request = CustodyRequest::create([
                'branch_manager_id' => $data['branch_manager_id'] ?? null,
                'branch_id' => $data['branch_id'] ?? null,
                'created_by_brand_owner_id' => $data['created_by_brand_owner_id'] ?? null,
                'requested_amount' => $data['requestedAmount'],
                'purpose' => $data['purpose'],
                'preferred_receipt_method' => $data['preferredReceiptMethod'],
                'additional_notes' => $data['additionalNotes'] ?? null,
                'status' => 'Pending',
            ]);

            if (! empty($data['attachments'])) {
                $this->storeAttachments($request, $data['attachments']);
            }

            $actorId = $isBrandOwner ? $data['created_by_brand_owner_id'] : $data['branch_manager_id'];
            $actorType = $isBrandOwner ? 'brand_owner' : 'branch_manager';
            $this->createTimelineEntry($request, 'Submit Case', 'Submitted', $actorId, $actorType);

            return $request->load(['attachments', 'timeline']);
        });
    }

    /**
     * Get request history for reuse
     */
    public function getRequestHistory(string $branchManagerId, int $limit = 10): array
    {
        $requests = CustodyRequest::where('branch_manager_id', $branchManagerId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return $requests->map(function ($request) {
            return [
                'id' => $request->id,
                'requestedAmount' => (float) $request->requested_amount,
                'purpose' => $request->purpose,
                'preferredReceiptMethod' => $request->preferred_receipt_method,
                'createdAt' => $request->created_at->toIso8601String(),
            ];
        })->toArray();
    }

    /**
     * Get request details with timeline
     */
    public function getRequestDetails(string $requestId): array
    {
        $request = CustodyRequest::with(['attachments', 'timeline'])
            ->findOrFail($requestId);

        $timeline = UnifiedTimelineResource::collection($request->timeline)->resolve();

        $approval = $this->buildApprovalBlock($request);

        return [
            'requestId' => $request->id,
            'status' => strtolower((string) $request->status),
            'details' => [
                'requestedAmount' => (float) $request->requested_amount,
                'purpose' => $request->purpose,
                'preferredReceiptMethod' => $this->methodToSnake($request->preferred_receipt_method),
                'attachments' => $request->attachments->map(function ($attachment) {
                    return [
                        'filename' => $attachment->original_name,
                        'url' => $attachment->url,
                        'uploadedAt' => $attachment->created_at->toIso8601String(),
                    ];
                })->values(),
                'additionalNotes' => $request->additional_notes,
            ],
            'timelines' => $timeline,
            'timeline' => $timeline,
            'approval' => $approval,
            'cancellation' => $this->buildCancellationBlock($request),
        ];
    }

    private function methodToSnake(?string $value): ?string
    {
        if (! $value) {
            return null;
        }

        return match ($value) {
            'Cash Handover' => 'cash_handover',
            'Bank Transfer' => 'bank_transfer',
            default => strtolower(str_replace(' ', '_', $value)),
        };
    }

    private function buildCancellationBlock(CustodyRequest $request): ?array
    {
        if (strcasecmp((string) $request->status, 'Rejected') !== 0) {
            return null;
        }

        $actor = $this->resolveActor($request->rejected_by, $request->rejected_by_type);

        return [
            'cancellation_reason' => $request->rejection_reason,
            'cancelled_at' => $request->rejected_at?->toIso8601String(),
            'cancelled_by' => [
                'id' => $request->rejected_by,
                'name' => $actor['name'] ?? null,
                'type' => $request->rejected_by_type,
            ],
        ];
    }

    private function buildApprovalBlock(CustodyRequest $request): ?array
    {
        if (strcasecmp((string) $request->status, 'Approved') === 0) {
            $actor = $this->resolveActor($request->approved_by, $request->approved_by_type);

            return [
                'status' => 'approved',
                'approvedBy' => $actor['name'] ?? null,
                'profileImage' => $actor['image'] ?? null,
                'approvedAt' => $request->approved_at?->toIso8601String(),
                'dateTime' => $request->approved_at?->toIso8601String(),
                'rejectedReason' => null,
            ];
        }

        if (strcasecmp((string) $request->status, 'Rejected') === 0) {
            $actor = $this->resolveActor($request->rejected_by, $request->rejected_by_type);

            return [
                'status' => 'rejected',
                'approvedBy' => $actor['name'] ?? null,
                'profileImage' => $actor['image'] ?? null,
                'approvedAt' => $request->rejected_at?->toIso8601String(),
                'dateTime' => $request->rejected_at?->toIso8601String(),
                'rejectedReason' => $request->rejection_reason,
            ];
        }

        return null;
    }

    private function resolveActor(?string $actorId, ?string $actorType): array
    {
        if (! $actorId) {
            return ['name' => null, 'image' => null];
        }

        if ($actorType === 'brand_owner') {
            $bo = BrandOwner::find($actorId);

            return [
                'name' => $bo?->name,
                'image' => $bo?->image ? asset('storage/'.$bo->image) : null,
            ];
        }

        $bm = BranchManager::find($actorId);

        return [
            'name' => $bm?->name,
            'image' => $bm?->image ? asset('storage/'.$bm->image) : null,
        ];
    }

    /**
     * List all requests for branch manager or for brand owner (all requests across managers).
     */
    public function listRequests(
        ?string $branchManagerId,
        ?string $timePeriod = null,
        ?string $status = null,
        ?string $preferredReceiptMethod = null,
        bool $isBrandOwner = false
    ): array {
        $query = $isBrandOwner
            ? CustodyRequest::query()
            : CustodyRequest::where('branch_manager_id', $branchManagerId);

        // Status filter
        if (! empty($status)) {
            $query->where('status', $status);
        }

        // Preferred receipt method filter
        if (! empty($preferredReceiptMethod)) {
            // Normalize the value to match database values exactly
            $preferredReceiptMethod = trim($preferredReceiptMethod);
            // Use exact match to ensure we get the right records
            $query->where('preferred_receipt_method', '=', $preferredReceiptMethod);
        }

        // Time period filter
        if (! empty($timePeriod)) {
            $startDate = $this->getTimePeriodStartDate($timePeriod);
            $query->where('created_at', '>=', $startDate);
        }

        // Debug: Log the query for troubleshooting
        // \Log::info('CustodyRequest Query', [
        //     'branch_manager_id' => $branchManagerId,
        //     'status' => $status,
        //     'preferred_receipt_method' => $preferredReceiptMethod,
        //     'timePeriod' => $timePeriod,
        //     'sql' => $query->toSql(),
        //     'bindings' => $query->getBindings(),
        // ]);

        $requests = $query->with(['branchManager:id,name'])->orderBy('created_at', 'desc')->get();

        return $requests->map(function ($request) use ($isBrandOwner) {
            $submittedBy = $isBrandOwner
                ? ($request->branchManager?->name ?? 'Branch Manager')
                : 'Me (Branch Manager)';

            return [
                'id' => $request->id,
                'type' => 'Custody Request',
                'submittedBy' => $submittedBy,
                'dateTime' => $request->created_at->toIso8601String(),
                'status' => strtolower((string) $request->status),
                'amount' => (float) $request->requested_amount,
                'preferredReceiptMethod' => $request->preferred_receipt_method,
                'purpose' => $request->purpose,
            ];
        })->toArray();
    }

    /**
     * Get start date based on time period
     */
    private function getTimePeriodStartDate(string $timePeriod): \Carbon\Carbon
    {
        return match ($timePeriod) {
            'last_24_hours' => now()->subHours(24),
            'last_7_days' => now()->subDays(7),
            'last_30_days' => now()->subDays(30),
            'last_90_days' => now()->subDays(90),
            'last_365_days' => now()->subDays(365),
            default => now()->subDays(30), // Default to last 30 days
        };
    }

    /**
     * Store attachments
     */
    private function storeAttachments(CustodyRequest $request, array $files): void
    {
        foreach ($files as $file) {
            $filename = 'custody_request_'.$request->id.'_'.time().'_'.uniqid().'.'.$file->getClientOriginalExtension();
            $path = $file->storeAs('custody-requests/'.$request->id, $filename, 'public');

            CustodyRequestAttachment::create([
                'custody_request_id' => $request->id,
                'file_name' => $filename,
                'original_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'file_type' => $file->getClientOriginalExtension(),
                'file_size' => $file->getSize(),
                'mime_type' => $file->getMimeType(),
            ]);
        }
    }

    /**
     * Create timeline entry
     */
    private function createTimelineEntry(
        CustodyRequest $request,
        string $stage,
        string $status,
        string $actorId,
        ?string $actorType = 'branch_manager',
        ?string $actorName = null,
        ?string $actorProfileImage = null
    ): void {
        $actor = null;
        if ($actorType === 'brand_owner') {
            $actor = BrandOwner::find($actorId);
        } else {
            $actor = BranchManager::find($actorId);
        }

        CustodyRequestTimeline::create([
            'custody_request_id' => $request->id,
            'stage' => $stage,
            'status' => $status,
            'actor_id' => $actorId,
            'actor_type' => $actorType,
            'actor_name' => $actorName ?? $actor->name ?? 'Unknown',
            'actor_profile_image' => $actorProfileImage ?? $actor->image ?? null,
            'action_date' => now(),
        ]);
    }

    /**
     * History for Brand Owner Owner Payment Form: requests created by this brand owner.
     */
    public function getBrandOwnerRequestHistory(string $brandOwnerId, int $limit = 10): array
    {
        $requests = CustodyRequest::where('created_by_brand_owner_id', $brandOwnerId)
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();

        return $requests->map(function ($request) {
            return [
                'id' => $request->id,
                'requestedAmount' => (float) $request->requested_amount,
                'amount' => (float) $request->requested_amount,
                'purpose' => $request->purpose,
                'preferredReceiptMethod' => $request->preferred_receipt_method,
                'handoverDate' => $request->handover_date?->toIso8601String(),
                'status' => strtolower((string) $request->status),
                'createdAt' => $request->created_at->toIso8601String(),
            ];
        })->toArray();
    }

    /**
     * Approve a custody request (Brand Owner).
     */
    public function approveRequest(string $requestId, BrandOwner $actor): CustodyRequest
    {
        return DB::transaction(function () use ($requestId, $actor) {
            $request = CustodyRequest::lockForUpdate()->findOrFail($requestId);

            if (strcasecmp((string) $request->status, 'Pending') !== 0) {
                throw new \RuntimeException('Only pending custody requests can be approved.');
            }

            $request->update([
                'status' => 'Approved',
                'approved_by' => $actor->id,
                'approved_by_type' => 'brand_owner',
                'approved_at' => now(),
            ]);

            $this->createTimelineEntry($request, 'Approve Case', 'Approved', $actor->id, 'brand_owner');

            return $request->fresh(['attachments', 'timeline']);
        });
    }

    /**
     * Reject a custody request (Brand Owner).
     */
    public function rejectRequest(string $requestId, BrandOwner $actor, string $reason): CustodyRequest
    {
        return DB::transaction(function () use ($requestId, $actor, $reason) {
            $request = CustodyRequest::lockForUpdate()->findOrFail($requestId);

            if (strcasecmp((string) $request->status, 'Pending') !== 0) {
                throw new \RuntimeException('Only pending custody requests can be rejected.');
            }

            $request->update([
                'status' => 'Rejected',
                'rejected_by' => $actor->id,
                'rejected_by_type' => 'brand_owner',
                'rejected_at' => now(),
                'rejection_reason' => $reason,
            ]);

            $this->createTimelineEntry($request, 'Reject Case', 'Rejected', $actor->id, 'brand_owner');

            return $request->fresh(['attachments', 'timeline']);
        });
    }
}
