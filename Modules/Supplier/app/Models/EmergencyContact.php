<?php

namespace Modules\Supplier\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Branch\Models\Branch;

class EmergencyContact extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $fillable = [
        'supplier_id',
        'branch_id',
        'contact_name',
        'contact_phone',
        'contact_email',
        'is_after_hours',
        'escalation_level',
    ];

    protected $casts = [
        'is_after_hours' => 'boolean',
    ];

    /**
     * Get the supplier that owns this emergency contact
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * Get the branch associated with this emergency contact
     */
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}

