<?php

require '/var/www/apexone/vendor/autoload.php';
$app = require '/var/www/apexone/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$z = app(App\Services\Integrations\ZoomApiService::class);
$opts = [
    'campaign_id' => '6c753496-2efd-4783-aa85-eb6ec73bc512',
    'caller_id_number' => '13133851223',
];

function dumpFmt($label, $z, $r, $from, $to, $opts) {
    $fmt = $z->formatOriginateResponse($r, $from, $to, $opts);
    $j = json_encode($fmt);
    echo "$label len=".strlen($j)." busy=".json_encode($r['extension_busy'] ?? null)."\n$j\n\n";
}

// Case A: dialing with DID as "extension" (mis-synced hidden field)
$r = $z->originateCall('13133851223', '12722001232', [
    'campaign_id' => $opts['campaign_id'],
    'caller_id_number' => $opts['caller_id_number'],
    'webphone_ready' => true,
    'skip_line_clear' => true,
]);
dumpFmt('did_as_ext', $z, $r, '13133851223', '12722001232', $opts);
if (!empty($r['call_uuid'])) {
    try { $z->hangupCall($r['call_uuid']); } catch (Throwable $e) {}
}

// Case B: empty-ish / weird
$r2 = $z->originateCall('1020', '12722001232', [
    'campaign_id' => $opts['campaign_id'],
    'caller_id_number' => $opts['caller_id_number'],
    'webphone_ready' => false, // force line prep path
    'skip_line_clear' => false,
]);
dumpFmt('no_webphone_ready', $z, $r2, '1020', '12722001232', $opts);
if (!empty($r2['call_uuid'])) {
    try { $z->hangupCall($r2['call_uuid']); } catch (Throwable $e) {}
}

// Case C: list recent nginx sizes around fail — generate lengths for morpheus raw errors
$post = (new ReflectionClass($z))->getMethod('postOriginate');
$post->setAccessible(true);
$raw = $post->invoke($z, '/click-to-call', array_merge([
    'extension' => '1020',
    'destination' => '12722001232',
    'timeout_sec' => 30,
], [
    'campaign_id' => $opts['campaign_id'],
    'caller_id_number' => $opts['caller_id_number'],
    'caller_id_name' => 'ApexOne Payments',
]));
echo "raw_click=".json_encode($raw)."\n";
if (!empty($raw['call_uuid'])) {
    try { echo 'hang='.json_encode($z->hangupCall($raw['call_uuid']))."\n"; } catch (Throwable $e) {}
}

// Search all format response permutations against 411/473 by taking error from Morpheus if fail
$targets = [411, 473];
$base = [
    'ok' => false,
    'action' => 'originate',
    'campaign_id' => $opts['campaign_id'],
    'from' => '1020',
    'caller_id_number' => $opts['caller_id_number'],
    'internal_from' => true,
    'to' => '12722001232',
    'attempted' => ['POST /click-to-call'],
];

// Pull recent access logs after 17:31 for more context
$lines = @file('/var/log/nginx/access.log') ?: [];
foreach (array_slice($lines, -80) as $line) {
    if (str_contains($line, 'originate')) {
        echo trim($line)."\n";
    }
}
