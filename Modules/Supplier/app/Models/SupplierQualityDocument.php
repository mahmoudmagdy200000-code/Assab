<?php

namespace Modules\Supplier\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class SupplierQualityDocument extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'supplier_id',
        'order_id',
        'product_id',
        'document_type',
        'title',
        'description',
        'file_path',
        'file_name',
        'file_type',
        'issue_date',
        'expiry_date',
        'issuing_authority',
        'certificate_number',
        'metadata',
        'is_active',
    ];

    protected $casts = [
        'issue_date' => 'date',
        'expiry_date' => 'date',
        'metadata' => 'array',
        'is_active' => 'boolean',
    ];

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByType($query, string $type)
    {
        return $query->where('document_type', $type);
    }

    public function scopeExpired($query)
    {
        return $query->where('expiry_date', '<', now());
    }

    public function isExpired(): bool
    {
        return $this->expiry_date && $this->expiry_date->isPast();
    }
}
