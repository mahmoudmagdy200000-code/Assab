<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Feature flags
    |--------------------------------------------------------------------------
    |
    | Server-side switches for surfaces that are built but intentionally not
    | exposed yet. Routes behind a disabled flag are not registered at all.
    |
    */

    // ASAB dashboard supplier portal (/v1/asab/supplier/*). Hidden per the
    // client meeting until the supplier module is unified with the mobile
    // portal; the legacy /v1/supplier/* mobile API is unaffected.
    'asab_supplier_portal' => (bool) env('FEATURE_ASAB_SUPPLIER_PORTAL', false),

];
