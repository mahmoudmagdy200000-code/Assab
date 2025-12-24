<?php

namespace Modules\Expense\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Category Model
 */
class Category extends Model
{
    use HasFactory, HasUuids;

    protected static function newFactory()
    {
        return \Modules\Expense\Database\Factories\CategoryFactory::new();
    }

    protected $fillable = [
        'name',
        'parent_id',
        'type',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function parent()
    {
        return $this->belongsTo(Category::class, 'parent_id');
    }

    public function children()
    {
        return $this->hasMany(Category::class, 'parent_id');
    }

    public function items()
    {
        return $this->hasMany(ExpenseItem::class);
    }

    public function expenseLines()
    {
        return $this->hasMany(ExpenseLine::class);
    }

    // Scopes - Fixed naming to avoid conflicts
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopePurchase($query)
    {
        return $query->where('type', 'purchase');
    }

    public function scopeExpense($query)
    {
        return $query->where('type', 'expense');
    }

    // Renamed to avoid conflict with parent() relationship
    public function scopeParentOnly($query)
    {
        return $query->whereNull('parent_id');
    }

    // Alternative name that's also clear
    public function scopeRootCategories($query)
    {
        return $query->whereNull('parent_id');
    }
}
