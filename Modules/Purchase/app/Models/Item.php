<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Modules\Branch\Models\Branch;

class Item extends Model
{
    use HasFactory, HasUuids, SoftDeletes;

    protected $table = 'items';

    protected $fillable = [
        'name',
        'logo',
        'code',
        'unit',
        'category',
        'subcategory',
        'description',
        'is_active',
    ];

    protected $casts = [
        'logo' => 'array',
        'is_active' => 'boolean',
    ];

    protected $appends = [
        'logo_url',
    ];

    /**
     * Branches that have this item (many-to-many relationship)
     */
    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'branch_item')
            ->withPivot(['price', 'quantity'])
            ->withTimestamps();
    }

    /**
     * Branch items pivot relationship
     */
    public function branchItems(): HasMany
    {
        return $this->hasMany(BranchItem::class);
    }

    /**
     * Inventories for this item across all branches
     */
    public function inventories(): HasMany
    {
        return $this->hasMany(BranchInventory::class);
    }

    /**
     * Supplier items for this item
     */
    public function supplierItems(): HasMany
    {
        return $this->hasMany(SupplierItem::class);
    }

    /**
     * Get logo URL
     */
    public function getLogoUrlAttribute(): ?string
    {
        if (!$this->logo) {
            return null;
        }

        // If logo is array, get first image
        if (is_array($this->logo)) {
            $logo = $this->logo[0] ?? null;
        } else {
            $logo = $this->logo;
        }

        if (!$logo) {
            return null;
        }

        return str_starts_with($logo, 'http')
            ? $logo
            : asset('storage/' . $logo);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('code', 'like', "%{$term}%");
        });
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->where('category', $category);
    }

    public function scopeBySubcategory($query, string $subcategory)
    {
        return $query->where('subcategory', $subcategory);
    }
}
