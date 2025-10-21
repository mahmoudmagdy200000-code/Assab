<?php

namespace Modules\Expense\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;

/**
 * Category Model
 */
class Category extends Model
{
    use HasFactory;

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

    // Scopes
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

    public function scopeParents($query)
    {
        return $query->whereNull('parent_id');
    }
}
