<?php

namespace Modules\Admin\Services;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Modules\Admin\Models\Attachment;
use Modules\Admin\Models\Operation;

/**
 * Persists multipart `attachments[]` on the public disk and links each to an
 * Operation, keeping `attachment_count` in sync. Extracted so both the company
 * and the platform branch-upload surfaces share one implementation (BRM-2.1).
 */
class OperationAttachmentService
{
    /**
     * @return array<int, array<string, mixed>> presented attachment rows
     */
    public function store(Request $request, Operation $op, string $ownerType = 'operation'): array
    {
        $files = $request->file('attachments', []);
        if (! is_array($files)) {
            $files = $files ? [$files] : [];
        }
        $files = array_values(array_filter($files));
        if (empty($files)) {
            return [];
        }

        $companyId = $request->user()->company_id ?? 'platform';
        $rows = DB::transaction(function () use ($files, $op, $ownerType, $companyId, $request) {
            $created = [];
            foreach ($files as $file) {
                $path = $file->store($companyId.'/'.$ownerType, 'public');
                $created[] = Attachment::create([
                    'owner_type' => $ownerType,
                    'owner_id' => $op->id,
                    'filename' => $file->getClientOriginalName(),
                    'mime_type' => $file->getClientMimeType(),
                    'size' => $file->getSize(),
                    'storage_key' => $path,
                    'public_url' => Storage::disk('public')->url($path),
                    'label' => $op->module_key,
                    'uploaded_by_id' => $request->user()->id,
                    'uploaded_at' => now(),
                ]);
            }
            $op->update(['attachment_count' => (int) $op->attachment_count + count($created)]);

            return $created;
        });

        return array_map(fn (Attachment $a) => [
            'id' => $a->id, 'filename' => $a->filename, 'mimeType' => $a->mime_type,
            'size' => $a->size, 'publicUrl' => $a->public_url,
            'uploadedAt' => optional($a->uploaded_at)->toIso8601String(),
        ], $rows);
    }
}
