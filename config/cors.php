<?php

/*
|--------------------------------------------------------------------------
| Cross-Origin Resource Sharing (CORS) Configuration
|--------------------------------------------------------------------------
|
| Laravel 11's default skeleton does NOT publish this file - the framework
| falls back to Illuminate\Http\Middleware\HandleCors's internal defaults
| if it's absent. This project needs a non-default, explicit origin
| allow-list (Architecture Baseline §14), so the file is added here.
|
| Bearer-token auth was approved over Sanctum's SPA/cookie mode
| (Architecture Baseline §3 / §7), so `supports_credentials` stays false
| and 'sanctum/csrf-cookie' is not needed in `paths`.
|
*/

return [

    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => array_filter(explode(',', env('CORS_ALLOWED_ORIGINS', 'http://localhost:5173'))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => [],

    'max_age' => 0,

    'supports_credentials' => false,

];
