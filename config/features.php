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

    // ASAB dashboard supplier portal (/v1/asab/supplier/*). Was hidden per the
    // 2026-07 meeting; the client now runs the portal (suppliers manage their
    // own «الأصناف والأسعار»), and with the flag off the routes are not
    // registered at all — every call 404s and the SPA reports «تعذر الاتصال
    // بالخادم» (2026-08-04). Default ON; set FEATURE_ASAB_SUPPLIER_PORTAL=false
    // to hide it again. The legacy /v1/supplier/* mobile API is unaffected.
    'asab_supplier_portal' => (bool) env('FEATURE_ASAB_SUPPLIER_PORTAL', true),

    // Mobile «activate Account»: force a password change before the account can
    // be used. Unlike the flag above this one is REQUEST-TIME behaviour — the
    // endpoints stay registered, they only change what they answer.
    //
    // OFF (default, 2026-07-26): a successful sign-in with the admin-issued
    // password completes activation itself. Chosen because the app's activation
    // screen posts no proof of identity (neither the first-login token nor the
    // default password), which left every dashboard-created account permanently
    // locked out.
    //
    // Flip ON only once the app is confirmed to send one of the accepted proofs
    // — turning it on is retroactive for every row that still carries
    // `is_first_login`, and reinstates that lock-out if the app cannot activate.
    'mobile_force_first_login_reset' => (bool) env('MOBILE_FORCE_FIRST_LOGIN_RESET', false),

    // PKG-7 «did not work» (POST /api/v1/branch-manager/shifts/{shift}/mark-not-worked).
    // REQUEST-TIME gate (routes stay registered; the guard middleware answers 404
    // SHIFT_NOT_WORKED_FEATURE_DISABLED and the `can_mark_not_worked` /
    // `actions_available.mark_not_worked` hints report false).
    //
    // OFF by default (P-01 release guard): the eligibility predicate has no date
    // cutoff, so a branch manager can irreversibly close a future-dated untouched
    // assignment, and Mohamed has not yet ruled whether that is permitted. Turn
    // ON (FEATURE_SHIFT_DID_NOT_WORK=true) only after the policy owner decides
    // or Mahmoud records an interim acceptance. This flag does not encode any
    // cutoff rule and does not alter data, history, locks or the BRC-11 fence.
    'shift_did_not_work' => (bool) env('FEATURE_SHIFT_DID_NOT_WORK', false),

];
