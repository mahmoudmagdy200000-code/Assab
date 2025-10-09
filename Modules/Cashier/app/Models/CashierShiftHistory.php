<?php

namespace Modules\Cashier\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Factories\HasFactory;
// use Modules\Cashier\Database\Factories\CashierShiftHistoryFactory;

class CashierShiftHistory extends Model
{
    use HasFactory;

    /**
     * The attributes that are mass assignable.
     */
    protected $fillable = [];

    // protected static function newFactory(): CashierShiftHistoryFactory
    // {
    //     // return CashierShiftHistoryFactory::new();
    // }
}
