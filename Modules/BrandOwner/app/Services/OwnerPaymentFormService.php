<?php

namespace Modules\BrandOwner\Services;

use Illuminate\Support\Facades\DB;
use Modules\BranchManagers\Models\BranchManager;
use Modules\BrandOwner\Models\BrandOwner;
use Modules\Custody\Models\CustodyRequest;
use Modules\Custody\Models\CustodyRequestAttachment;
use Modules\Custody\Models\CustodyRequestTimeline;
use Modules\Custody\Services\CustodyNotifier;

class OwnerPaymentFormService
{
    public function __construct(private readonly CustodyNotifier $notifier) {}

    /**
     * Submit the Owner Payment Form. Persists as a custody request created by the brand owner.
     */
    public function submit(BrandOwner $brandOwner, array $data): CustodyRequest
    {
        $request = DB::transaction(function () use ($brandOwner, $data) {
            $recipient = ! empty($data['recipient_employee_id'])
                ? BranchManager::find($data['recipient_employee_id'])
                : null;

            $request = CustodyRequest::create([
                'branch_manager_id' => $recipient?->id,
                'branch_id' => $recipient?->branch_id,
                'created_by_brand_owner_id' => $brandOwner->id,
                'recipient_employee_id' => $data['recipient_employee_id'] ?? null,
                'requested_amount' => $data['amount'] ?? 0,
                'purpose' => $data['purpose'] ?? $data['note'] ?? null,
                'preferred_receipt_method' => $data['preferred_receipt_method'] ?? null,
                'additional_notes' => $data['note'] ?? null,
                'handover_date' => $data['handover_date'] ?? null,
                'transfer_date' => $data['transfer_date'] ?? null,
                'status' => 'Pending',
            ]);

            if (! empty($data['attachments'])) {
                $this->storeAttachments($request, $data['attachments']);
            }

            CustodyRequestTimeline::create([
                'custody_request_id' => $request->id,
                'stage' => 'Submit Case',
                'status' => 'Submitted',
                'actor_id' => $brandOwner->id,
                'actor_type' => 'brand_owner',
                'actor_name' => $brandOwner->name ?? 'Brand Owner',
                'actor_profile_image' => $brandOwner->image ?? null,
                'action_date' => now(),
            ]);

            return $request->fresh(['attachments', 'timeline']);
        });

        // AFTER commit: the recipient manager gets a real push/in-app entry and
        // an «استلام» action on the request. Before this the transfer appeared
        // only as a silent Pending row on their custody screen (2026-08-03).
        $this->notifier->transferSentToManager($request);

        return $request;
    }

    private function storeAttachments(CustodyRequest $request, array $files): void
    {
        foreach ($files as $file) {
            $filename = 'owner_payment_'.$request->id.'_'.time().'_'.uniqid().'.'.$file->getClientOriginalExtension();
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
}
