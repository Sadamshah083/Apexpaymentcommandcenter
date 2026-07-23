<?php

return [
    /*
    |--------------------------------------------------------------------------
    | Member JWT (signed access tokens for team accounts)
    |--------------------------------------------------------------------------
    |
    | JWT_SECRET must be a long random string in .env (never commit the real value).
    | Tokens expire after JWT_TTL_HOURS (default 9).
    |
    */
    'secret' => env('JWT_SECRET'),
    'ttl_hours' => (int) env('JWT_TTL_HOURS', 9),
    'issuer' => env('JWT_ISSUER', env('APP_URL', 'apexone')),
    'algo' => 'HS256',
];
