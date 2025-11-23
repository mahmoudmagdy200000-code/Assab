<?php

namespace Modules\Expense\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Expense\Database\Factories\SupplierFactory;

/**
 * Supplier Model
 */
class Supplier extends Model
{
    use HasFactory , HasUuids;

    protected $fillable = [
        'name',
        'phone',
        'email',
        'tax_id',
        'address',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    protected static function newFactory()
    {
        return \Modules\Expense\database\factories\SupplierFactory::new();
    }


    public function expenses()
    {
        return $this->hasMany(Expense::class);
    }

    public function invoiceDetails()
    {
        return $this->hasMany(InvoiceDetail::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    
}
