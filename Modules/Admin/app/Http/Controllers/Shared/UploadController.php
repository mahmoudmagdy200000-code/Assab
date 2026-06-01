<?php

namespace Modules\Admin\Http\Controllers\Shared;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Modules\Admin\Http\Controllers\AsabController;
use Modules\Admin\Models\Attachment;

/**
 * File uploads & attachments (BACKEND_API_SPEC.md §7.3). Uses the local public
 * disk; the presigned-URL flow points back at the direct-upload endpoint so the
 * client contract matches an S3 deployment (swap the disk for s3 in prod).
 */
class UploadController extends AsabController
{
    public function presignedUrl(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $data = $request->validate([
                'filename' => 'required|string|max:255',
                'mimeType' => 'required|string|max:80',
                'size' => 'required|integer|min:1',
                'ownerType' => 'required|string|max:32',
                'ownerId' => 'nullable|string',
                'label' => 'nullable|string|max:80',
            ]);

            $storageKey = ($request->user()->company_id ?? 'platform').'/'.$data['ownerType'].'/'.Str::uuid().'-'.$data['filename'];

            $attachment = Attachment::create([
                'owner_type' => $data['ownerType'],
                'owner_id' => $data['ownerId'] ?? (string) Str::uuid(),
                'filename' => $data['filename'],
                'mime_type' => $data['mimeType'],
                'size' => $data['size'],
                'storage_key' => $storageKey,
                'label' => $data['label'] ?? null,
                'uploaded_by_id' => $request->user()->id,
            ]);

            return $this->ok([
                'uploadUrl' => url('/api/v1/uploads/direct'),
                'storageKey' => $storageKey,
                'attachmentId' => $attachment->id,
                'expiresIn' => 600,
            ]);
        });
    }

    public function confirm(string $attachmentId): JsonResponse
    {
        return $this->run(function () use ($attachmentId) {
            $attachment = Attachment::findOrFail($attachmentId);
            $attachment->update(['uploaded_at' => now()]);

            return $this->ok($this->present($attachment));
        });
    }

    public function direct(Request $request): JsonResponse
    {
        return $this->run(function () use ($request) {
            $request->validate([
                'file' => 'required|file|max:10240',
                'ownerType' => 'required|string|max:32',
                'ownerId' => 'nullable|string',
                'label' => 'nullable|string|max:80',
            ]);

            $file = $request->file('file');
            $dir = ($request->user()->company_id ?? 'platform').'/'.$request->input('ownerType');
            $path = $file->store($dir, 'public');

            $attachment = Attachment::create([
                'owner_type' => $request->input('ownerType'),
                'owner_id' => $request->input('ownerId') ?? (string) Str::uuid(),
                'filename' => $file->getClientOriginalName(),
                'mime_type' => $file->getClientMimeType(),
                'size' => $file->getSize(),
                'storage_key' => $path,
                'public_url' => Storage::disk('public')->url($path),
                'label' => $request->input('label'),
                'uploaded_by_id' => $request->user()->id,
                'uploaded_at' => now(),
            ]);

            return $this->created($this->present($attachment));
        });
    }

    public function show(string $id): JsonResponse
    {
        return $this->run(fn () => $this->ok($this->present(Attachment::findOrFail($id))));
    }

    public function download(string $id): JsonResponse
    {
        return $this->run(function () use ($id) {
            $a = Attachment::findOrFail($id);
            $url = $a->public_url ?: (Storage::disk('public')->exists($a->storage_key) ? Storage::disk('public')->url($a->storage_key) : null);

            return $this->ok(['url' => $url, 'expiresIn' => 600]);
        });
    }

    public function verify(Request $request, string $id): JsonResponse
    {
        return $this->run(function () use ($request, $id) {
            $a = Attachment::findOrFail($id);
            $a->update(['verified_at' => now(), 'verified_by_id' => $request->user()->id]);

            return $this->ok($this->present($a));
        });
    }

    public function destroy(string $id): JsonResponse
    {
        return $this->run(function () use ($id) {
            $a = Attachment::findOrFail($id);
            if ($a->storage_key && Storage::disk('public')->exists($a->storage_key)) {
                Storage::disk('public')->delete($a->storage_key);
            }
            $a->delete();

            return $this->noContent();
        });
    }

    private function present(Attachment $a): array
    {
        return [
            'id' => $a->id,
            'ownerType' => $a->owner_type,
            'ownerId' => $a->owner_id,
            'filename' => $a->filename,
            'mimeType' => $a->mime_type,
            'size' => $a->size,
            'storageKey' => $a->storage_key,
            'publicUrl' => $a->public_url,
            'label' => $a->label,
            'verifiedAt' => optional($a->verified_at)->toIso8601String(),
            'uploadedAt' => optional($a->uploaded_at)->toIso8601String(),
        ];
    }
}
