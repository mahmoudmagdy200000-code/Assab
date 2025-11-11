<?php

namespace Modules\Shift\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Aggregator\Models\Aggregator;

class ShiftSalesBreakdown extends Model
{
    use HasFactory , HasUuids;

    protected $table = 'shift_sales_breakdown';

    protected $fillable = [
        'cashier_shift_id',
        'aggregator_id',
        'amount',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function cashierShift()
    {
        return $this->belongsTo(CashierShift::class);
    }

    public function aggregator()
    {
        return $this->belongsTo(Aggregator::class);
    }
}
