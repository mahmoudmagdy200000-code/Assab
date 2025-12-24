<?php

namespace Modules\Expense\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Expense\Database\Factories\SupplierFactory;

/**
 * Supplier Model
 *
 * @deprecated This model is deprecated. Use Modules\Supplier\Models\Supplier instead.
 * This class now references the unified suppliers table from Supplier module.
 */
class Supplier extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'suppliers'; // Reference new suppliers table from Supplier module

    protected $fillable = [
        'name',
        'email',
        'phone',
        'image',
        'address',
        'tax_id',
        'password',
        'is_active',
        'is_first_login',
        'email_verified_at',
        'phone_verified_at',
        'company_name',
        'service_areas',
        'working_hours',
        'holiday_schedules',
        'language',
        'theme',
        'notification_preferences',
        'status',
        'contact_methods',
        'default_delivery_hours',
        'min_order_amount',
        'average_response_time_hours',
        'response_rate_percentage',
        'rating',
        'total_orders',
        'completed_orders',
        'categories',
        'created_by_admin_at',
        'last_seen_at',
    ];

    protected static function newFactory()
    {
        return \Modules\Expense\Database\Factories\SupplierFactory::new();
    }

    // Relationships from Expense module
    public function expenses()
    {
        return $this->hasMany(Expense::class, 'supplier_id');
    }

    public function invoiceDetails()
    {
        return $this->hasMany(InvoiceDetail::class, 'supplier_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }
}
