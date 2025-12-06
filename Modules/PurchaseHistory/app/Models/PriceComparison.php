<?php

namespace Modules\PurchaseHistory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\PurchaseHistory\Database\Factories\PriceComparisonFactory;

class PriceComparison extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [];

    // protected static function newFactory(): PriceComparisonFactory
    // {
    //     // return PriceComparisonFactory::new();
    // }
}
