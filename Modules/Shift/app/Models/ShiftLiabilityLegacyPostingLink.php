<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShiftLiabilityLegacyPostingLink extends Model
{
    use HasUuids;

    protected $table = 'shift_liability_legacy_posting_links';

    protected $guarded = ['id'];

    protected $casts = [
        'signed_halalas' => 'integer',
        'provenance' => 'array',
    ];

    public function cashierShift(): BelongsTo
    {
        return $this->belongsTo(CashierShift::class, 'cashier_shift_id');
    }
}
