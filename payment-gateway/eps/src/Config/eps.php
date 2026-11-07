<?php

return [
    /*
    |--------------------------------------------------------------------------
    | EPS Payment Gateway Mode
    |--------------------------------------------------------------------------
    | Set to true for Sandbox / Demo testing, false for Live production.
    */
    'sandbox' => env('EPS_SANDBOX', true),

    /*
    |--------------------------------------------------------------------------
    | API Credentials
    |--------------------------------------------------------------------------
    */
    'store_id' => env('EPS_STORE_ID', '35b518f6-aab7-4af1-b16c-335052e9a55c'),
    'merchant_id' => env('EPS_MERCHANT_ID', ''),
    'username' => env('EPS_USERNAME', 'xyz.eps@gmail.com'),
    'password' => env('EPS_PASSWORD', 'Emon258@'),
    'hash_key' => env('EPS_HASH_KEY', 'BKXUD5z0NQgPDQMcZuPL2dSUwo5oSdvBpzz2xbkxikB7KfYV0kZIF8sW6udvSqOTZNUJ5VHnMTSJP3oxDABpJQ=='),

    /*
    |--------------------------------------------------------------------------
    | EPS Base URLs
    |--------------------------------------------------------------------------
    */
    'api_base_url' => [
        'sandbox' => env('EPS_SANDBOX_API_URL', 'https://sandboxpgapi.eps.com.bd'),
        'live' => env('EPS_LIVE_API_URL', 'https://pgapi.eps.com.bd'),
    ],
    'gateway_base_url' => [
        'sandbox' => env('EPS_SANDBOX_GATEWAY_URL', 'https://sandboxpg.eps.com.bd'),
        'live' => env('EPS_LIVE_GATEWAY_URL', 'https://pg.eps.com.bd'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Redirect URLs (Server Callbacks)
    |--------------------------------------------------------------------------
    | These are the server endpoints that EPS redirects the user to upon payment completion.
    */
    'callback' => [
        'success' => env('EPS_SUCCESS_CALLBACK', '/eps/success'),
        'fail' => env('EPS_FAIL_CALLBACK', '/eps/fail'),
        'cancel' => env('EPS_CANCEL_CALLBACK', '/eps/cancel'),
        'ipn' => env('EPS_IPN_CALLBACK', '/api/eps/ipn'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Frontend Destination URLs
    |--------------------------------------------------------------------------
    | Where the user will finally land on the Next.js client after verification.
    */
    'frontend' => [
        'base_url' => env('FRONTEND_URL', 'http://localhost:3000'),
        'success_path' => env('EPS_FRONTEND_SUCCESS_PATH', '/payment/success'),
        'failed_path' => env('EPS_FRONTEND_FAILED_PATH', '/payment/failed'),
        'cancel_path' => env('EPS_FRONTEND_CANCEL_PATH', '/payment/failed'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Transaction defaults
    |--------------------------------------------------------------------------
    */
    'currency' => 'BDT',
    'version' => '1',
    'transaction_type_id' => 10,
    'shipping_method' => 'NO',
];

