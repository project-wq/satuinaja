<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Gateway AI (9Router / OpenAI-compatible) untuk generator caption
    |--------------------------------------------------------------------------
    */
    'ai' => [
        'base_url' => env('AI_BASE_URL', 'http://127.0.0.1:20128/v1'),
        'key' => env('AI_API_KEY', ''),
        'model' => env('AI_MODEL', 'Ai'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Jasa kirim: Biteship (multi-kurir: cek ongkir, order + resi, tracking)
    |--------------------------------------------------------------------------
    */
    'biteship' => [
        'key' => env('BITESHIP_API_KEY', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Cek ongkir (legacy — digantikan Biteship)
    |--------------------------------------------------------------------------
    */
    'rajaongkir' => [
        'key' => env('RAJAONGKIR_API_KEY', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Lacak resi
    |--------------------------------------------------------------------------
    */
    'binderbyte' => [
        'key' => env('BINDERBYTE_API_KEY', ''),
    ],

    /*
    |--------------------------------------------------------------------------
    | Payment gateway: Midtrans Snap
    |--------------------------------------------------------------------------
    */
    'midtrans' => [
        'server_key' => env('MIDTRANS_SERVER_KEY', ''),
        'client_key' => env('MIDTRANS_CLIENT_KEY', ''),
        'sandbox' => env('MIDTRANS_SANDBOX', true),
    ],

];
