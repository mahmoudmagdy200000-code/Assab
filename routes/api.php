<?php

use Illuminate\Support\Facades\Route;

// API routes should not have locale prefixes in URL
// Locale is determined from Accept-Language header via ApiLocaleMiddleware
Route::group(
    [
        'middleware' => [
            'apilocale', // Only use API locale middleware for header-based locale detection
        ],
    ],
    function () {
        foreach (glob(base_path('Modules/*/Routes/api.php')) as $moduleRoutes) {
            require $moduleRoutes;
        }
    }
);
