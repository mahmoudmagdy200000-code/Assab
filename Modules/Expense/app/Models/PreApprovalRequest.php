<?php

namespace Modules\Expense\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Pre-Approval Request Model
 */
class PreApprovalRequest extends Model
{
    use HasFactory , HasUuids;

    protected static function newFactory()
    {
        return \Modules\Expense\Database\Factories\PreApprovalRequestFactory::new();
    }

    protected $fillable = [
        'expense_id',
        'purpose',
        'estimated_amount',
        'priority',
        'payment_supplier_id',
    ];

    protected $casts = [
        'estimated_amount' => 'decimal:2',
    ];

    public function expense()
    {
        return $this->belongsTo(Expense::class);
    }

    public function paymentSupplier()
    {
        return $this->belongsTo(Supplier::class, 'payment_supplier_id');
    }
}
