<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Modules\Branch\Models\Branch;

/**
 * BranchItem - Pivot model for many-to-many relationship between Branch and Item
 *
 * This represents a branch-specific configuration for an item (price, quantity)
 * The actual item data (name, code, logo, etc.) is stored in the Item model
 */
class BranchItem extends Model
{
    use HasFactory, HasUuids;

    protected $table = 'branch_item';

    protected $fillable = [
        'branch_id',
        'item_id',
        'price',
        'quantity',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'quantity' => 'decimal:3',
    ];

    // Relationships
    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(Item::class, 'item_id');
    }

    // Accessors for backward compatibility (maps to item model)
    public function getItemNameAttribute(): ?string
    {
        return $this->item?->name;
    }

    public function getItemCodeAttribute(): ?string
    {
        return $this->item?->code;
    }

    public function getItemLogoAttribute()
    {
        return $this->item?->logo;
    }

    public function getItemUnitAttribute(): ?string
    {
        return $this->item?->unit;
    }

    public function getItemPriceAttribute(): ?float
    {
        return $this->price;
    }

    public function getItemQuantityAttribute(): ?float
    {
        return $this->quantity;
    }

    public function getCategoryAttribute(): ?string
    {
        return $this->item?->category;
    }

    public function getSubcategoryAttribute(): ?string
    {
        return $this->item?->subcategory;
    }

    public function getItemLogoUrlAttribute(): ?string
    {
        return $this->item?->logo_url;
    }

    // Scopes
    public function scopeByBranch($query, string $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeByItem($query, string $itemId)
    {
        return $query->where('item_id', $itemId);
    }

    public function scopeSearch($query, string $term)
    {
        return $query->whereHas('item', function ($q) use ($term) {
            $q->where('name', 'like', "%{$term}%")
                ->orWhere('code', 'like', "%{$term}%");
        });
    }

    public function scopeByCategory($query, string $category)
    {
        return $query->whereHas('item', function ($q) use ($category) {
            $q->where('category', $category);
        });
    }

    public function scopeBySubcategory($query, string $subcategory)
    {
        return $query->whereHas('item', function ($q) use ($subcategory) {
            $q->where('subcategory', $subcategory);
        });
    }

    /**
     * Filter branch items to those that the given supplier can supply.
     * Uses supplier_products (Supplier module) and supplier_items (legacy) with item_id match.
     */
    public function scopeBySupplier($query, string $supplierId)
    {
        return $query->where(function ($q) use ($supplierId) {
            // Item is supplied by this supplier via SupplierProduct (new) or SupplierItem (legacy)
            $q->whereExists(function ($sub) use ($supplierId) {
                $sub->select(DB::raw(1))
                    ->from('supplier_products')
                    ->whereColumn('supplier_products.item_id', 'branch_item.item_id')
                    ->where('supplier_products.supplier_id', $supplierId)
                    ->where('supplier_products.is_available', true)
                    ->whereNull('supplier_products.deleted_at');
            })->orWhereExists(function ($sub) use ($supplierId) {
                $sub->select(DB::raw(1))
                    ->from('supplier_items')
                    ->whereColumn('supplier_items.item_id', 'branch_item.item_id')
                    ->where('supplier_items.supplier_id', $supplierId)
                    ->where('supplier_items.is_available', true);
            });
        });
    }
}
