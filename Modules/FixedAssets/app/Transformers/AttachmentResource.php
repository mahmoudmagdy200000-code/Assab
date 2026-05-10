<?php

namespace Modules\FixedAssets\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;
use Modules\FixedAssets\Models\Attachment;

class AttachmentResource extends JsonResource
{
    public function toArray($request): array
    {
        /** @var Attachment $a */
        $a = $this->resource;

        return [
            'id' => (string) $a->id,
            'file_name' => (string) $a->file_name,
            'file_type' => (string) $a->file_type,
            'file_size' => (int) $a->file_size,
            'url' => $a->path ? asset('storage/'.$a->path) : '',
            'uploaded_at' => $a->uploaded_at?->toIso8601String() ?? '',
        ];
    }
}
