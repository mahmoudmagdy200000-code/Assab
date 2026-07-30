<?php

use App\Http\Controllers\BroadcastAuthController;
use Illuminate\Support\Facades\Route;

// Broadcasting auth for Sanctum token users
Route::post('/broadcasting/auth', [BroadcastAuthController::class, 'authenticate'])
    ->middleware(['auth:sanctum']);

// API routes should not have locale prefixes in URL
// Locale is determined from Accept-Language header via ApiLocaleMiddleware
Route::group(
    [
        'middleware' => [
            'apilocale', // Only use API locale middleware for header-based locale detection
        ],
    ],
    function () {
        // Both casings on purpose: most modules track lowercase routes/ while
        // BranchManagers/Cashier track Routes/. On the case-sensitive production
        // host a single capital-R glob silently skipped 14 modules, leaving them
        // reachable only at their provider prefix (/api/v1) and OUTSIDE the
        // apilocale group. realpath-dedupe keeps Windows (case-insensitive)
        // from loading the same file twice.
        $moduleRoutes = array_unique(array_filter(array_map('realpath', array_merge(
            glob(base_path('Modules/*/Routes/api.php')) ?: [],
            glob(base_path('Modules/*/routes/api.php')) ?: [],
        ))));

        foreach ($moduleRoutes as $file) {
            require $file;
        }
    }
);
