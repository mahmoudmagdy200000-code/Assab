<?php

namespace Modules\Custody\Services;

use App\Http\Resources\UnifiedTimelineResource;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
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
            $request = CustodyRequest::create([
                'branch_manager_id' => $data['branch_manager_id'],
                'branch_id' => $data['branch_id'],
                'requested_amount' => $data['requestedAmount'],
                'purpose' => $data['purpose'],
                'preferred_receipt_method' => $data['preferredReceiptMethod'],
                'additional_notes' => $data['additionalNotes'] ?? null,
                'status' => 'Pending',
            ]);

            // Handle attachments
            if (!empty($data['attachments'])) {
                $this->storeAttachments($request, $data['attachments']);
            }

            // Create timeline entry for submission
            $this->createTimelineEntry($request, 'Submit Case', 'Submitted', $data['branch_manager_id']);

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
        $request = CustodyRequest::with(['attachments', 'timeline', 'approvedBy', 'rejectedBy'])
            ->findOrFail($requestId);

        $timeline = UnifiedTimelineResource::collection($request->timeline)->resolve();

        $approval = null;
        if ($request->status === 'Approved' && $request->approvedBy) {
            $approval = [
                'branchManagerName' => $request->approvedBy->name,
                'profileImage' => $request->approvedBy->image ? asset('storage/' . $request->approvedBy->image) : null,
                'dateTime' => $request->approved_at->toIso8601String(),
                'status' => 'Approved',
            ];
        }

        return [
            'requestId' => $request->id,
            'details' => [
                'requestedAmount' => (float) $request->requested_amount,
                'purpose' => $request->purpose,
                'preferredReceiptMethod' => $request->preferred_receipt_method,
                'attachments' => $request->attachments->map(function ($attachment) {
                    return [
                        'filename' => $attachment->original_name,
                        'url' => $attachment->url,
                        'uploadedAt' => $attachment->created_at->toIso8601String(),
                    ];
                })->values(),
                'additionalNotes' => $request->additional_notes,
            ],
            'timeline' => $timeline,
            'approval' => $approval,
        ];
    }

    /**
     * List all requests for branch manager
     */
    public function listRequests(string $branchManagerId): array
    {
        $requests = CustodyRequest::where('branch_manager_id', $branchManagerId)
            ->orderBy('created_at', 'desc')
            ->get();

        return $requests->map(function ($request) {
            return [
                'id' => $request->id,
                'type' => 'Custody Request',
                'submittedBy' => 'Me (Branch Manager)',
                'dateTime' => $request->created_at->toIso8601String(),
                'status' => $request->status,
                'amount' => (float) $request->requested_amount,
            ];
        })->toArray();
    }

    /**
     * Store attachments
     */
    private function storeAttachments(CustodyRequest $request, array $files): void
    {
        foreach ($files as $file) {
            $filename = 'custody_request_' . $request->id . '_' . time() . '_' . uniqid() . '.' . $file->getClientOriginalExtension();
            $path = $file->storeAs('custody-requests/' . $request->id, $filename, 'public');

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
        $branchManager = \Modules\BranchManagers\Models\BranchManager::find($actorId);

        CustodyRequestTimeline::create([
            'custody_request_id' => $request->id,
            'stage' => $stage,
            'status' => $status,
            'actor_id' => $actorId,
            'actor_type' => $actorType,
            'actor_name' => $actorName ?? $branchManager->name ?? 'Unknown',
            'actor_profile_image' => $actorProfileImage ?? $branchManager->image ?? null,
            'action_date' => now(),
        ]);
    }
}
