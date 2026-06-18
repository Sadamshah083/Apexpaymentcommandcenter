<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Outbound HTTPS verification
    |--------------------------------------------------------------------------
    |
    | Windows/local PHP installs often lack a system CA bundle, which causes
    | cURL error 60. Point CURL_CA_BUNDLE at storage/certs/cacert.pem (default)
    | or set HTTP_SSL_VERIFY=false for local-only debugging.
    |
    */

    'verify' => env('HTTP_SSL_VERIFY', true),

    'ca_bundle' => env('CURL_CA_BUNDLE', storage_path('certs/cacert.pem')),

];
