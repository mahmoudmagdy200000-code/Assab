<?php

namespace Modules\Purchase\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\DB;
use Modules\Branch\Models\Branch;

class BranchItem extends Model
{
    use HasFactory, HasUuids;

    /**
     * The attributes that are mass assignable.
     */
    protected $table = 'branch_item';
    protected $fillable = [
        'branch_id',
        'item_name',
        'item_logo',
        'item_code',
        'item_unit',
        'item_price',
        'item_quantity',
        'category',
        'subcategory',
    ];
    protected $casts = [
        'item_logo' => 'array',
        'item_price' => 'decimal:2',
        'item_quantity' => 'decimal:3',
    ];

    protected $appends = [
        'item_logo_url',
    ];

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'branch_id');
    }

    /**
     * Get suppliers that offer this item
     * Through Expense module - suppliers that have expenses with items matching this item name
     */
    public function getSuppliersAttribute()
    {
        return \Modules\Expense\Models\Supplier::whereHas('expenses.items', function ($query) {
            $query->where('name', $this->item_name);
        })->get();
    }

    /**
     * Get item logo URL
     */
    public function getItemLogoUrlAttribute(): ?string
    {
        if (!$this->item_logo) {
            return null;
        }

        // If item_logo is array, get first image
        if (is_array($this->item_logo)) {
            $logo = $this->item_logo[0] ?? null;
        } else {
            $logo = $this->item_logo;
        }

        if (!$logo) {
            return null;
        }

        return str_starts_with($logo, 'http')
            ? $logo
            : asset('storage/' . $logo);
    }

    // Scopes
    public function scopeByBranch($query, string $branchId)
    {
        return $query->where('branch_id', $branchId);
    }

    public function scopeSearch($query, string $term)
    {
        return $query->where(function ($q) use ($term) {
            $q->where('item_name', 'like', "%{$term}%")
                ->orWhere('item_code', 'like', "%{$term}%");
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

    public function scopeBySupplier($query, string $supplierId)
    {
        // Filter items that are available from the specified supplier
        // Through Expense module - items that have been purchased from this supplier
        return $query->whereExists(function ($subQuery) use ($supplierId) {
            $subQuery->select(DB::raw(1))
                ->from('expenses')
                ->join('expense_items', 'expense_items.expense_id', '=', 'expenses.id')
                ->whereColumn('expense_items.name', 'branch_item.item_name')
                ->where('expenses.supplier_id', $supplierId);
        });
    }
}
