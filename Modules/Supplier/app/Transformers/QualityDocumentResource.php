<?php

namespace Modules\Supplier\Transformers;

use Illuminate\Http\Resources\Json\JsonResource;

class QualityDocumentResource extends JsonResource
{
    public function toArray($request): array
    {
        return [
            'id' => $this->id,
            'document_type' => $this->document_type,
            'title' => $this->title,
            'description' => $this->description,
            'file_path' => $this->file_path ? asset('storage/' . $this->file_path) : null,
            'file_name' => $this->file_name,
            'file_type' => $this->file_type,
            'issue_date' => $this->issue_date?->toDateString(),
            'expiry_date' => $this->expiry_date?->toDateString(),
            'issuing_authority' => $this->issuing_authority,
            'certificate_number' => $this->certificate_number,
            'metadata' => $this->metadata ?? [],
            'is_active' => $this->is_active,
            'is_expired' => $this->isExpired(),
            'created_at' => $this->created_at?->toDateTimeString(),
        ];
    }
}

