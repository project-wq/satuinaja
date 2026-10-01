<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS)
    |--------------------------------------------------------------------------
    |
    | SPA React boleh berada di domain berbeda (mis. Vercel) dari backend
    | (mis. VPS). Karena auth pakai cookie session HttpOnly, kita WAJIB:
    |   - allowed_origins  : daftar domain frontend (JANGAN pakai "*")
    |   - supports_credentials : true  (izinkan cookie ikut terkirim)
    |
    | Atur domainnya di .env:
    |   FRONTEND_URLS=https://satuinaja.com,https://www.satuinaja.com
    */

    'paths' => ['api/*', 'sanctum/csrf-cookie', 'storage/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_values(array_filter(array_map(
        'trim',
        explode(',', (string) env('FRONTEND_URLS', 'http://localhost:5173,http://127.0.0.1:5173'))
    ))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    // Wajib true kalau frontend & backend beda domain (auth cookie).
    'supports_credentials' => true,

];
