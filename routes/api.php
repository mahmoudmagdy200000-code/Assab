<?php

use Illuminate\Support\Facades\Route;
use Mcamara\LaravelLocalization\Facades\LaravelLocalization;

Route::group(
    [
        'prefix' => LaravelLocalization::setLocale(),
        'middleware' => [
            'localize',
            'localizationRedirect',
            'localeSessionRedirect',
            'localeCookieRedirect',
            'apilocale', 
        ],
    ],
    function () {
        foreach (glob(base_path('Modules/*/Routes/api.php')) as $moduleRoutes) {
            require $moduleRoutes;
        }
    }
);
