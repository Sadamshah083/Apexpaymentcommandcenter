<?php

require '/var/www/apexone/vendor/autoload.php';
$app = require '/var/www/apexone/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$z = app(App\Services\Integrations\ZoomApiService::class);
$opts = [
    'campaign_id' => '6c753496-2efd-4783-aa85-eb6ec73bc512',
    'caller_id_number' => '13133851223',
];

// Success length baseline
$ok = $z->formatOriginateResponse([
    'ok' => true,
    'outcome' => 'initiated',
    'call_uuid' => '00000000-0000-0000-0000-000000000000',
    'customer_first' => false,
    'attempted' => ['POST /click-to-call'],
], '1020', '+12722001232', $opts);
$okJson = json_encode($ok);
echo "success_example len=".strlen($okJson)."\n$okJson\n\n";

// What does a real Morpheus failure look like for a blocked number?
$ref = new ReflectionClass($z);
$post = $ref->getMethod('postOriginate');
$post->setAccessible(true);

// Simulate with obscure but valid-looking number that Morpheus may reject quickly
$r = $z->originateCall('1020', '15555550100', [
    'campaign_id' => $opts['campaign_id'],
    'caller_id_number' => $opts['caller_id_number'],
    'webphone_ready' => true,
    'skip_line_clear' => true,
]);
$fmt = $z->formatOriginateResponse($r, '1020', '15555550100', $opts);
$j = json_encode($fmt);
echo "live_fail len=".strlen($j)." http_would=".(($r['extension_busy'] ?? false) ? 409 : 422)."\n";
echo "raw=".json_encode($r)."\n";
echo "fmt=$j\n\n";

if (!empty($r['call_uuid'])) {
    try {
        echo 'hangup='.json_encode($z->hangupCall($r['call_uuid']))."\n";
    } catch (Throwable $e) {
        echo $e->getMessage()."\n";
    }
}

// Also dump recent access log lines with size and estimate from env APP
echo "MATCH_411_473 search\n";
$candidates = [];
$msgs = [
    'Click-to-call failed: extension not registered',
    'USER_BUSY',
    'Destination not found',
    'Campaign not found',
    'Invalid destination',
    'Forbidden',
    'Unauthorized',
];

// Read recent morpheus HTTP logs if any
$log = @file_get_contents('/var/www/apexone/storage/logs/laravel.log');
if ($log) {
    $tail = substr($log, -200000);
    foreach (preg_split("/\n/", $tail) as $line) {
        if (stripos($line, 'click-to-call') !== false || stripos($line, 'originate') !== false) {
            if (stripos($line, '2026-07-14') !== false || stripos($line, '17:3') !== false) {
                echo substr($line, 0, 500)."\n";
            }
        }
    }
}
