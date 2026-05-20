<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Purchase\Enums\SupplierStatus;

/**
 * @deprecated This model is deprecated. Use Modules\Supplier\Models\Supplier instead.
 * This class is kept for backward compatibility during migration.
 * The table name is set to 'suppliers' to reference the new unified suppliers table.
 */
class PurchaseSupplier extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'suppliers'; // Reference new suppliers table

    protected $fillable = [
        'name',
        'email',
        'phone',
        'image',
        'address',
        'tax_id',
        'status',
        'is_active',
        'contact_methods',
        'default_delivery_hours',
        'min_order_amount',
        'average_response_time_hours',
        'response_rate_percentage',
        'rating',
        'total_orders',
        'completed_orders',
        'categories',
        'last_seen_at',
    ];

    protected $casts = [
        'status' => SupplierStatus::class,
        'is_active' => 'boolean',
        'contact_methods' => 'array',
        'categories' => 'array',
        'default_delivery_hours' => 'integer',
        'min_order_amount' => 'decimal:2',
        'average_response_time_hours' => 'decimal:2',
        'response_rate_percentage' => 'decimal:2',
        'rating' => 'decimal:2',
        'total_orders' => 'integer',
        'completed_orders' => 'integer',
        'last_seen_at' => 'datetime',
    ];

    protected $appends = [
        'image_url',
        'status_label',
        'status_color',
        'is_available',
    ];

    // Relationships
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(PurchaseOrder::class, 'supplier_id');
    }

    public function supplierItems(): HasMany
    {
        return $this->hasMany(SupplierItem::class, 'supplier_id');
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(PurchaseInvoice::class, 'supplier_id');
    }

    public function returnOrders(): HasMany
    {
        return $this->hasMany(ReturnOrder::class, 'supplier_id');
    }

    // Accessors
    public function getImageUrlAttribute(): ?string
    {
        if (! $this->image) {
            return null;
        }

        return str_starts_with($this->image, 'http')
            ? $this->image
            : asset('storage/'.$this->image);
    }

    public function getStatusLabelAttribute(): string
    {
        return $this->status?->label() ?? 'Unknown';
    }

    public function getStatusColorAttribute(): string
    {
        return $this->status?->color() ?? '#6B7280';
    }

    public function getIsAvailableAttribute(): bool
    {
        return $this->status?->isAvailable() ?? false;
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOnline($query)
    {
        return $query->where('status', SupplierStatus::ONLINE);
    }

    public function scopeAvailable($query)
    {
        return $query->where('status', '!=', SupplierStatus::OFFLINE);
    }

    public function scopeByStatus($query, SupplierStatus $status)
    {
        return $query->where('status', $status);
    }

    public function scopeByDeliveryTime($query, int $maxHours)
    {
        return $query->where('default_delivery_hours', '<=', $maxHours);
    }

    public function scopeByRating($query, float $minRating)
    {
        return $query->where('rating', '>=', $minRating);
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%");
        });
    }

    // Methods
    public function updateResponseStatistics(): void
    {
        $completedOrders = $this->purchaseOrders()
            ->whereIn('status', ['closed', 'delivered'])
            ->count();

        $totalOrders = $this->purchaseOrders()->count();

        $responseRate = $totalOrders > 0
            ? ($completedOrders / $totalOrders) * 100
            : 0;

        $this->update([
            'total_orders' => $totalOrders,
            'completed_orders' => $completedOrders,
            'response_rate_percentage' => round($responseRate, 2),
        ]);
    }

    public function incrementOrderCount(): void
    {
        $this->increment('total_orders');
    }

    public function incrementCompletedOrderCount(): void
    {
        $this->increment('completed_orders');
    }

    public function setOnline(): void
    {
        $this->update([
            'status' => SupplierStatus::ONLINE,
            'last_seen_at' => now(),
        ]);
    }

    public function setOffline(): void
    {
        $this->update(['status' => SupplierStatus::OFFLINE]);
    }

    public function setAway(): void
    {
        $this->update(['status' => SupplierStatus::AWAY]);
    }
}
