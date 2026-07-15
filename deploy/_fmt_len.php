<?php

require '/var/www/apexone/vendor/autoload.php';
$app = require '/var/www/apexone/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$z = app(App\Services\Integrations\ZoomApiService::class);
$opts = [
    'campaign_id' => '6c753496-2efd-4783-aa85-eb6ec73bc512',
    'caller_id_number' => '13133851223',
];

$results = [
    [
        'ok' => false,
        'error' => 'Extension 1020 is busy on Morpheus. Click Connect line again, or ask your admin to clear stuck calls on this extension.',
        'extension_busy' => true,
        'attempted' => ['POST /click-to-call'],
    ],
    [
        'ok' => false,
        'error' => 'Extension 1020 is still busy. Wait 10–15 seconds, click Connect line, then try again.',
        'extension_busy' => true,
        'attempted' => ['POST /click-to-call'],
    ],
    [
        'ok' => false,
        'error' => 'Morpheus could not route this number. Use Call (click-to-call) with Connect line Registered — do not dial the PSTN number directly from the browser.',
        'routing_error' => true,
        'attempted' => ['POST /click-to-call'],
    ],
    [
        'ok' => false,
        'error' => 'Could not place outbound call.',
        'attempted' => ['POST /click-to-call'],
    ],
    [
        'ok' => false,
        'error' => 'Morpheus accepted click-to-call but no call was created on the PBX.',
        'attempted' => ['POST /click-to-call'],
    ],
];

foreach ($results as $i => $r) {
    $fmt = $z->formatOriginateResponse($r, '1020', '+12722001232', $opts);
    $json = json_encode($fmt);
    echo "i=$i len=".strlen($json)."\n$json\n\n";
}

$offline = [
    'ok' => false,
    'extension_offline' => true,
    'webphone_required' => true,
    'error' => app(App\Services\Communications\CommunicationsAgentService::class)->extensionOfflineDialMessage('1020'),
];
$offlineJson = json_encode($offline);
echo 'offline len='.strlen($offlineJson)."\n$offlineJson\n\n";

$dest = [
    'ok' => false,
    'error' => 'Enter a valid phone number with at least 10 digits (e.g. +12722001232).',
];
echo 'dest len='.strlen(json_encode($dest))."\n";

// Capture live Morpheus click-to-call error without leaving a call? Use invalid ext or dry.
$ref = new ReflectionClass($z);
$m = $ref->getMethod('postOriginate');
$m->setAccessible(true);

// List active and hangup
$list = $ref->getMethod('listActiveCalls');
$list->setAccessible(true);
$calls = $list->invoke($z) ?: [];
echo 'active='.count($calls)."\n";
foreach ($calls as $call) {
    $uuid = (string) ($call['uuid'] ?? $call['call_uuid'] ?? '');
    echo json_encode($call)."\n";
    if ($uuid !== '') {
        try {
            echo 'hangup='.json_encode($z->hangupCall($uuid))."\n";
        } catch (Throwable $e) {
            echo 'hangup_err='.$e->getMessage()."\n";
        }
    }
}

// Check how Map fail response looks - call click-to-call with webphone ready skip, then immediately get formatted error from a known bad destination
$bad = $z->originateCall('1020', '000', [
    'campaign_id' => $opts['campaign_id'],
    'caller_id_number' => $opts['caller_id_number'],
    'webphone_ready' => true,
    'skip_line_clear' => true,
]);
$fmtBad = $z->formatOriginateResponse($bad, '1020', '000', $opts);
$j = json_encode($fmtBad);
echo 'bad_dest_originate len='.strlen($j)." status_busy=".json_encode($bad['extension_busy'] ?? null)."\n$j\n";
