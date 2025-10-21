<?php

use Illuminate\Support\Facades\Route;
use Modules\Expense\Http\Controllers\ExpenseController;

Route::middleware(['auth', 'verified'])->group(function () {
    Route::resource('expenses', ExpenseController::class)->names('expense');
});
