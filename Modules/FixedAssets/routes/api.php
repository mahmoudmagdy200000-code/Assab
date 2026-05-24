<?php

use Illuminate\Support\Facades\Route;
use Modules\FixedAssets\Http\Controllers\AssetController;
use Modules\FixedAssets\Http\Controllers\AssetHistoryController;
use Modules\FixedAssets\Http\Controllers\AssetSearchController;
use Modules\FixedAssets\Http\Controllers\DisposalRequestController;
use Modules\FixedAssets\Http\Controllers\HandoverController;
use Modules\FixedAssets\Http\Controllers\HandoverIncludedZonesController;
use Modules\FixedAssets\Http\Controllers\HandoverSessionController;
use Modules\FixedAssets\Http\Controllers\HandoverSignatureController;
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
        Route::match(['patch', 'post'], 'assets/{assetId}/settings', [AssetController::class, 'updateSettings'])
            ->name('assets.update-settings');

        Route::get('assets/{assetId}/history', [AssetHistoryController::class, 'show'])
            ->name('assets.history.show');
        Route::post('assets/{assetId}/history/report', [AssetHistoryController::class, 'generateReport'])
            ->name('assets.history.report');

        Route::post('assets/search', [AssetSearchController::class, 'search'])
            ->name('assets.search');
        Route::post('assets/search-image', [AssetSearchController::class, 'searchByImage'])
            ->name('assets.search-image');

        Route::get('receive-assets', [ReceiveAssetsController::class, 'index'])
            ->name('receive-assets.index');
        Route::get('receive-assets/{requestId}', [ReceiveAssetsController::class, 'show'])
            ->name('receive-assets.show');
        Route::post('receive-assets/confirm', [ReceiveAssetsController::class, 'confirm'])
            ->name('receive-assets.confirm');
        Route::post('receive-assets/confirm/{requestId}', [ReceiveAssetsController::class, 'confirm'])
            ->name('receive-assets.confirm.legacy');

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

        Route::post('requests/transfers/items/{itemId}/approve', [TransferRequestController::class, 'approveItemDest'])
            ->name('requests.transfers.items.approve');
        Route::post('requests/transfers/items/{itemId}/reject', [TransferRequestController::class, 'rejectItemDest'])
            ->name('requests.transfers.items.reject');

        Route::get('requests/disposals', [DisposalRequestController::class, 'index'])
            ->name('requests.disposals.index');
        Route::get('requests/disposals/{requestId}', [DisposalRequestController::class, 'show'])
            ->name('requests.disposals.show');

        Route::get('requests/handovers', [HandoverController::class, 'index'])
            ->name('requests.handovers.index');

        /*
        |----------------------------------------------------------------------
        | Fixed Assets Handover
        |----------------------------------------------------------------------
        */
        Route::prefix('handover')
            ->name('handover.')
            ->group(function () {
                Route::get('included-zones', [HandoverIncludedZonesController::class, 'index'])
                    ->name('included-zones');

                Route::post('start', [HandoverController::class, 'start'])
                    ->name('start');

                Route::prefix('sessions/{sessionId}')->group(function () {
                    Route::get('join-details', [HandoverSessionController::class, 'joinDetails'])
                        ->name('sessions.join-details');
                    Route::post('join', [HandoverSessionController::class, 'join'])
                        ->name('sessions.join');

                    Route::get('details', [HandoverSessionController::class, 'details'])
                        ->name('sessions.details');
                    Route::post('approve-zone', [HandoverSessionController::class, 'approveZone'])
                        ->name('sessions.approve-zone');
                    Route::post('approve-all', [HandoverSessionController::class, 'approveAll'])
                        ->name('sessions.approve-all');

                    Route::get('signature', [HandoverSignatureController::class, 'state'])
                        ->name('sessions.signature.state');
                    Route::post('signature/receiver', [HandoverSignatureController::class, 'receiver'])
                        ->name('sessions.signature.receiver');
                    Route::post('signature/sender', [HandoverSignatureController::class, 'sender'])
                        ->name('sessions.signature.sender');

                    Route::get('preview', [HandoverSessionController::class, 'preview'])
                        ->name('sessions.preview');
                    Route::get('summary', [HandoverSessionController::class, 'summary'])
                        ->name('sessions.summary');
                    Route::post('complete', [HandoverSessionController::class, 'complete'])
                        ->name('sessions.complete');
                });

                Route::get('{handoverId}', [HandoverController::class, 'show'])
                    ->name('show');
            });
    });
