<?php

use Illuminate\Support\Facades\Route;
use Modules\FixedAssets\Http\Controllers\AssetController;
use Modules\FixedAssets\Http\Controllers\AssetSearchController;
use Modules\FixedAssets\Http\Controllers\DisposalRequestController;
use Modules\FixedAssets\Http\Controllers\ModificationRequestController;
use Modules\FixedAssets\Http\Controllers\OverviewController;
use Modules\FixedAssets\Http\Controllers\ReceiveAssetsController;
use Modules\FixedAssets\Http\Controllers\ReferenceDataController;
use Modules\FixedAssets\Http\Controllers\TransferDisposalController;
use Modules\FixedAssets\Http\Controllers\TransferRequestController;

/*
|--------------------------------------------------------------------------
| API Routes - Fixed Assets (Branch Manager)
|--------------------------------------------------------------------------
| Base: /api/v1/branch-manager/fixed-assets
| Auth: Bearer token (Sanctum) + branch.manager middleware
*/

Route::middleware(['auth:sanctum', 'branch.manager'])
    ->prefix('v1/branch-manager/fixed-assets')
    ->name('branch-manager.fixed-assets.')
    ->group(function () {

        Route::get('overview', [OverviewController::class, 'index'])
            ->name('overview');

        Route::get('zones', [ReferenceDataController::class, 'zones'])->name('zones');
        Route::get('types', [ReferenceDataController::class, 'types'])->name('types');
        Route::get('employees', [ReferenceDataController::class, 'employees'])->name('employees');
        Route::get('branches', [ReferenceDataController::class, 'branches'])->name('branches');
        Route::get('assets', [ReferenceDataController::class, 'assets'])->name('assets.index');

        Route::get('assets/{assetId}', [AssetController::class, 'show'])
            ->name('assets.show');
        Route::patch('assets/{assetId}/settings', [AssetController::class, 'updateSettings'])
            ->name('assets.update-settings');

        Route::post('assets/search', [AssetSearchController::class, 'search'])
            ->name('assets.search');
        Route::post('assets/search-image', [AssetSearchController::class, 'searchByImage'])
            ->name('assets.search-image');

        Route::get('receive-assets', [ReceiveAssetsController::class, 'index'])
            ->name('receive-assets.index');
        Route::post('receive-assets/confirm', [ReceiveAssetsController::class, 'confirm'])
            ->name('receive-assets.confirm');

        Route::post('requests/transfer-disposal', [TransferDisposalController::class, 'store'])
            ->name('requests.transfer-disposal');

        Route::get('requests/modifications', [ModificationRequestController::class, 'index'])
            ->name('requests.modifications.index');
        Route::post('requests/modifications', [ModificationRequestController::class, 'store'])
            ->name('requests.modifications.store');
        Route::get('requests/modifications/{requestId}', [ModificationRequestController::class, 'show'])
            ->name('requests.modifications.show');

        Route::get('requests/transfers', [TransferRequestController::class, 'index'])
            ->name('requests.transfers.index');
        Route::get('requests/transfers/{requestId}', [TransferRequestController::class, 'show'])
            ->name('requests.transfers.show');
        Route::post('requests/transfers/{requestId}/approve', [TransferRequestController::class, 'approve'])
            ->name('requests.transfers.approve');

        Route::get('requests/disposals', [DisposalRequestController::class, 'index'])
            ->name('requests.disposals.index');
        Route::get('requests/disposals/{requestId}', [DisposalRequestController::class, 'show'])
            ->name('requests.disposals.show');
    });
