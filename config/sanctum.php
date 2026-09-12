<?php

use Laravel\Sanctum\Sanctum;

return [

    /*
    |--------------------------------------------------------------------------
    | Stateful Domains
    |--------------------------------------------------------------------------
    |
    | Unused by this project - Architecture Baseline §3/§7 approved plain
    | bearer-token auth, not Sanctum's SPA/cookie mode. Left at Sanctum's
    | own default rather than removed, since it's harmless when unused and
    | matches what a genuine `vendor:publish` would produce.
    |
    */

    'stateful' => explode(',', env('SANCTUM_STATEFUL_DOMAINS', sprintf(
        '%s%s',
        'localhost,localhost:3000,127.0.0.1,127.0.0.1:8000,::1',
        Sanctum::currentApplicationUrlWithPort()
    ))),

    'guard' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Expiration Minutes
    |--------------------------------------------------------------------------
    |
    | The one setting that actually matters for this project. Reads
    | SANCTUM_EXPIRATION, reserved in .env.example since STEP 02
    | (10080 minutes = 7 days). Never hardcoded in a controller.
    |
    */

    'expiration' => env('SANCTUM_EXPIRATION', 10080),

    'token_prefix' => env('SANCTUM_TOKEN_PREFIX', ''),

    /*
    |--------------------------------------------------------------------------
    | Sanctum Middleware
    |--------------------------------------------------------------------------
    |
    | Also unused by this project (SPA/cookie mode only) - kept for parity
    | with a genuine Sanctum install rather than stripped out.
    |
    */

    'middleware' => [
        'authenticate_session' => Laravel\Sanctum\Http\Middleware\AuthenticateSession::class,
        'encrypt_cookies' => Illuminate\Cookie\Middleware\EncryptCookies::class,
        'validate_csrf_token' => Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
    ],

];
