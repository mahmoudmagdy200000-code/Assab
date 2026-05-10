<?php

namespace Modules\FixedAssets\Services;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Modules\BranchManagers\Models\BranchManager;
use Modules\FixedAssets\Models\Attachment;

class AttachmentService
{
    public function store(UploadedFile $file, string $kind, Model $attachable, ?BranchManager $uploader = null): Attachment
    {
        $directory = "fixed-assets/{$kind}";
        $path = $file->store($directory, 'public');

        return Attachment::create([
            'attachable_type' => $attachable->getMorphClass(),
            'attachable_id' => $attachable->getKey(),
            'kind' => $kind,
            'file_name' => $file->getClientOriginalName(),
            'file_type' => $file->getClientMimeType(),
            'file_size' => $file->getSize(),
            'path' => $path,
            'uploaded_by_id' => $uploader?->id,
            'uploaded_at' => now(),
        ]);
    }

    public function url(?Attachment $attachment): string
    {
        if (! $attachment || ! $attachment->path) {
            return '';
        }

        return asset('storage/'.$attachment->path);
    }

    public function urlFromPath(?string $path): string
    {
        if (! $path) {
            return '';
        }

        return asset('storage/'.$path);
    }
}
