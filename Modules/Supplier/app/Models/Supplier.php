<?php

namespace Modules\Supplier\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Modules\Notification\Traits\HasDeviceTokens;

class Supplier extends Authenticatable
{
    use HasApiTokens, HasDeviceTokens, HasFactory, HasUuids, Notifiable, SoftDeletes;

    /**
     * The attributes that are mass assignable.
     */
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

    /**
     * The attributes that should be hidden for serialization.
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
        'phone_verified_at' => 'datetime',
        'password' => 'hashed',
        'is_active' => 'boolean',
        'is_first_login' => 'boolean',
        'service_areas' => 'array',
        'working_hours' => 'array',
        'holiday_schedules' => 'array',
        'notification_preferences' => 'array',
        'contact_methods' => 'array',
        'categories' => 'array',
        'default_delivery_hours' => 'integer',
        'min_order_amount' => 'decimal:2',
        'average_response_time_hours' => 'decimal:2',
        'response_rate_percentage' => 'decimal:2',
        'rating' => 'decimal:2',
        'total_orders' => 'integer',
        'completed_orders' => 'integer',
        'created_by_admin_at' => 'datetime',
        'last_seen_at' => 'datetime',
    ];

    /**
     * The accessors to append to the model's array form.
     */
    protected $appends = [
        'image_url',
        'status_label',
        'status_color',
        'is_available',
    ];

    /**
     * Get the image URL attribute.
     */
    public function getImageUrlAttribute(): ?string
    {
        return $this->image ? asset('storage/'.$this->image) : null;
    }

    /**
     * Get the status label attribute.
     */
    public function getStatusLabelAttribute(): string
    {
        return match ($this->status) {
            'online' => 'Online',
            'offline' => 'Offline',
            'away' => 'Away',
            default => 'Unknown',
        };
    }

    /**
     * Get the status color attribute.
     */
    public function getStatusColorAttribute(): string
    {
        return match ($this->status) {
            'online' => 'green',
            'offline' => 'gray',
            'away' => 'yellow',
            default => 'gray',
        };
    }

    /**
     * Get the is available attribute.
     */
    public function getIsAvailableAttribute(): bool
    {
        return $this->is_active && $this->status === 'online';
    }

    /**
     * Check if this is the first login.
     */
    public function isFirstLogin(): bool
    {
        return $this->is_first_login;
    }

    /**
     * Check if account is active.
     */
    public function isActive(): bool
    {
        return $this->is_active;
    }

    /**
     * Update last seen timestamp.
     */
    public function updateLastSeen(): void
    {
        $this->update(['last_seen_at' => now()]);
    }

    // Relationships

    /**
     * Get the supplier users for the supplier.
     */
    public function users(): HasMany
    {
        return $this->hasMany(SupplierUser::class);
    }

    /**
     * Get the supplier products.
     */
    public function products(): HasMany
    {
        return $this->hasMany(SupplierProduct::class);
    }

    /**
     * Get the supplier inventory.
     */
    public function inventory(): HasMany
    {
        return $this->hasMany(SupplierInventory::class);
    }

    /**
     * Get the supplier messages.
     */
    public function messages(): HasMany
    {
        return $this->hasMany(SupplierMessage::class);
    }

    /**
     * Get the supplier notifications.
     */
    public function notifications(): HasMany
    {
        return $this->hasMany(SupplierNotification::class);
    }

    /**
     * Get the supplier invoices.
     */
    public function invoices(): HasMany
    {
        return $this->hasMany(SupplierInvoice::class);
    }

    /**
     * Get the supplier quality documents.
     */
    public function qualityDocuments(): HasMany
    {
        return $this->hasMany(SupplierQualityDocument::class);
    }

    /**
     * Get the purchase orders for the supplier.
     */
    public function purchaseOrders(): HasMany
    {
        return $this->hasMany(\Modules\Purchase\Models\PurchaseOrder::class, 'supplier_id');
    }

    /**
     * Get the supplier items for the supplier.
     */
    public function supplierItems(): HasMany
    {
        return $this->hasMany(\Modules\Purchase\Models\SupplierItem::class, 'supplier_id');
    }

    // Scopes

    /**
     * Scope a query to only include active suppliers.
     */
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    /**
     * Scope a query to only include online suppliers.
     */
    public function scopeOnline($query)
    {
        return $query->where('status', 'online');
    }

    /**
     * Scope a query to filter by category.
     */
    public function scopeByCategory($query, string $categoryId)
    {
        return $query->whereJsonContains('categories', $categoryId);
    }

    /**
     * Scope a query to suppliers that can take an order right now.
     *
     * These five scopes are called from the mobile order flow
     * (Purchase\SupplierController::index, OrderDataService::
     * getDirectSupplierItems) but were never defined here, so ANY filtered
     * supplier request — including the empty `search=` the picker sends on
     * every keystroke — died with BadMethodCallException and reached the app
     * as a 500 the list renders as «No Search Results Found».
     */
    public function scopeAvailable($query)
    {
        return $query->where('is_active', true)->where('status', 'online');
    }

    /**
     * @param  string|\BackedEnum  $status  Purchase\Enums\SupplierStatus or its raw value
     */
    public function scopeByStatus($query, $status)
    {
        return $query->where('status', $status instanceof \BackedEnum ? $status->value : $status);
    }

    public function scopeByDeliveryTime($query, int $maxHours)
    {
        return $query->where('default_delivery_hours', '<=', $maxHours);
    }

    public function scopeByRating($query, float $minRating)
    {
        return $query->where('rating', '>=', $minRating);
    }

    /**
     * Grouped so the ORs can never leak out of an outer where chain and widen
     * a brand/tenant filter.
     */
    public function scopeSearch($query, ?string $term)
    {
        $term = trim((string) $term);

        if ($term === '') {
            return $query;
        }

        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('company_name', 'like', "%{$term}%")
                ->orWhere('email', 'like', "%{$term}%")
                ->orWhere('phone', 'like', "%{$term}%");
        });
    }
}
