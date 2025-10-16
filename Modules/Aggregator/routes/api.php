<?php
use Illuminate\Support\Facades\Route;
use Modules\Aggregator\Http\Controllers\{
    AggregatorController,
    BranchAggregatorController,
    AggregatorReportController
};

/*
|--------------------------------------------------------------------------
| API Routes - Public
|--------------------------------------------------------------------------
*/

// Get available aggregators (public)
Route::get('aggregators/available', [AggregatorController::class, 'available']);

/*
|--------------------------------------------------------------------------
| API Routes - Admin/Manager
|--------------------------------------------------------------------------
*/

Route::prefix('aggregators')
    ->middleware(['auth:sanctum'])
    ->group(function () {

        // CRUD Operations
        Route::get('/', [AggregatorController::class, 'index']);
        Route::post('/', [AggregatorController::class, 'store']);
        Route::get('/{aggregator}', [AggregatorController::class, 'show']);
        Route::put('/{aggregator}', [AggregatorController::class, 'update']);
        Route::delete('/{aggregator}', [AggregatorController::class, 'destroy']);

        // Status Management
        Route::post('/{aggregator}/activate', [AggregatorController::class, 'activate']);
        Route::post('/{aggregator}/deactivate', [AggregatorController::class, 'deactivate']);

        // Statistics
        Route::get('/{aggregator}/statistics', [AggregatorController::class, 'statistics']);

        // Logo Upload
        Route::post('/{aggregator}/logo', [AggregatorController::class, 'uploadLogo']);

        // Reports
        Route::prefix('{aggregator}')->group(function () {
            Route::get('/sales-report', [AggregatorReportController::class, 'salesReport']);
            Route::get('/sales-by-branch', [AggregatorReportController::class, 'salesByBranch']);
            Route::get('/sales-by-date', [AggregatorReportController::class, 'salesByDate']);
            Route::get('/commission-report', [AggregatorReportController::class, 'commissionReport']);
            Route::get('/performance-metrics', [AggregatorReportController::class, 'performanceMetrics']);
        });
    });

/*
|--------------------------------------------------------------------------
| API Routes - Branch Manager
|--------------------------------------------------------------------------
*/

Route::prefix('branch-aggregators')
    ->middleware(['auth:sanctum', 'branch.manager'])
    ->group(function () {
        Route::get('/', [BranchAggregatorController::class, 'getBranchAggregators']);
        Route::post('/sync', [BranchAggregatorController::class, 'sync']);
        Route::post('/{aggregator}/enable', [BranchAggregatorController::class, 'enable']);
        Route::post('/{aggregator}/disable', [BranchAggregatorController::class, 'disable']);
    });
