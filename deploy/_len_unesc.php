<?php

require '/var/www/apexone/vendor/autoload.php';
$app = require '/var/www/apexone/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$z = app(App\Services\Integrations\ZoomApiService::class);
$opts = [
    'campaign_id' => '6c753496-2efd-4783-aa85-eb6ec73bc512',
    'caller_id_number' => '13133851223',
];

$err = 'SQLSTATE[HY000]: General error: 1 no such table: cache (Connection: sqlite, Database: /var/www/apexone/database/database.sqlite, SQL: select * from "cache" where "key" in (laravel-cache-integrations.morpheus.circuit_open))';

foreach (['12722001232', '15555550100', '13135551212', '18005551212'] as $to) {
    $fmt = $z->formatOriginateResponse([
        'ok' => false,
        'error' => $err,
        'attempted' => ['POST /click-to-call'],
        'line_reset' => false,
    ], '1020', $to, $opts);
    $plain = json_encode($fmt);
    $slash = json_encode($fmt, JSON_UNESCAPED_SLASHES);
    $resp = response()->json($fmt);
    $content = $resp->getContent();
    echo "to=$to plain=".strlen($plain)." unesc=".strlen($slash)." laravel=".strlen($content)."\n";
}
