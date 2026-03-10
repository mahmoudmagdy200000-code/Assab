<?php

namespace Modules\Custody\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Cashier\Models\Cashier;

class CustodyHandoverRequest extends Model
{
    use HasUuids;

    protected $table = 'custody_handover_requests';

    protected $fillable = [
        'from_cashier_id',
        'to_cashier_id',
        'amount',
        'additional_notes',
        'status',
        'responded_at',
    ];

    protected $casts = [
        'amount'        => 'decimal:2',
        'responded_at'  => 'datetime',
    ];

    public function fromCashier(): BelongsTo
    {
        return $this->belongsTo(Cashier::class, 'from_cashier_id');
    }

    public function toCashier(): BelongsTo
    {
        return $this->belongsTo(Cashier::class, 'to_cashier_id');
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }
}
