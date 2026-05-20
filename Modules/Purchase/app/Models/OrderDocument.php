<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Storage;
use Modules\Purchase\Enums\DocumentType;

class OrderDocument extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'documentable_type',
        'documentable_id',
        'type',
        'file_path',
        'file_name',
        'original_name',
        'mime_type',
        'file_size',
        'title',
        'description',
        'uploaded_by',
        'uploaded_by_type',
        'is_active',
    ];

    protected $casts = [
        'type' => DocumentType::class,
        'file_size' => 'integer',
        'is_active' => 'boolean',
    ];

    protected $appends = [
        'file_url',
        'type_label',
        'formatted_size',
    ];

    // Relationships
    public function documentable(): MorphTo
    {
        return $this->morphTo();
    }

    // Accessors
    public function getFileUrlAttribute(): ?string
    {
        if (! $this->file_path) {
            return null;
        }

        return str_starts_with($this->file_path, 'http')
            ? $this->file_path
            : asset('storage/'.$this->file_path);
    }

    public function getTypeLabelAttribute(): ?string
    {
        return $this->type?->label();
    }

    public function getFormattedSizeAttribute(): string
    {
        $bytes = $this->file_size;

        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2).' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2).' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2).' KB';
        }

        return $bytes.' bytes';
    }

    // Scopes
    public function scopeByType($query, DocumentType $type)
    {
        return $query->where('type', $type);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeInvoices($query)
    {
        return $query->where('type', DocumentType::INVOICE);
    }

    public function scopePhotos($query)
    {
        return $query->where('type', DocumentType::PHOTO);
    }

    public function scopeCertificates($query)
    {
        return $query->where('type', DocumentType::QUALITY_CERTIFICATE);
    }

    // Static Methods
    public static function upload(
        Model $model,
        $file,
        DocumentType $type,
        ?string $title = null,
        ?string $description = null
    ): self {
        $path = $file->store('purchase/documents', 'public');
        $actor = auth()->user();

        return static::create([
            'documentable_type' => get_class($model),
            'documentable_id' => $model->id,
            'type' => $type,
            'file_path' => $path,
            'file_name' => $file->hashName(),
            'original_name' => $file->getClientOriginalName(),
            'mime_type' => $file->getMimeType(),
            'file_size' => $file->getSize(),
            'title' => $title,
            'description' => $description,
            'uploaded_by' => $actor?->id,
            'uploaded_by_type' => $actor ? get_class($actor) : null,
            'is_active' => true,
        ]);
    }

    // Methods
    public function deactivate(): void
    {
        $this->update(['is_active' => false]);
    }

    public function delete(): bool
    {
        // Delete file from storage
        if ($this->file_path && Storage::disk('public')->exists($this->file_path)) {
            Storage::disk('public')->delete($this->file_path);
        }

        return parent::delete();
    }
}
