<?php

/**
 * Verify Morpheus dialer pipeline: originate modes, call status, hangup, and app dialer path.
 *
 * Usage:
 *   php scripts/verify_click_to_call_ring.php [extension] [destination] [poll_seconds]
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Communications\CommunicationsAgentService;
use App\Services\Integrations\ZoomApiService;
use Illuminate\Support\Facades\Http;

$extension = preg_replace('/\D/', '', $argv[1] ?? (string) config('integrations.communications.default_caller_id', '1020')) ?: '1020';
$destination = $argv[2] ?? env('COMMUNICATIONS_DEFAULT_DIAL_DESTINATION', '+12722001232');
$pollSeconds = max(3, (int) ($argv[3] ?? 8));

$api = app(ZoomApiService::class);
$agents = app(CommunicationsAgentService::class);
$dialOptions = $agents->extensionDialOptions($extension);

$host = (string) config('integrations.morpheus.host');
$apiKey = (string) config('integrations.morpheus.api_key');
$campaignId = $dialOptions['campaign_id'] ?? config('integrations.morpheus.default_campaign_id');
$callerId = $dialOptions['caller_id_number'] ?? null;
$timeout = min(120, max(60, (int) config('integrations.morpheus.ring_timeout', 90)));
$originateMethod = (string) config('integrations.morpheus.originate_method', 'originate');
$customerFirst = (bool) config('integrations.morpheus.originate_customer_first', false);

$digits = preg_replace('/\D/', '', $destination) ?? '';
if (strlen($digits) === 10) {
    $digits = '1'.$digits;
}

if ($host === '' || $apiKey === '' || $digits === '') {
    fwrite(STDERR, "Missing MORPHEUS_HOST, MORPHEUS_API_KEY, or destination.\n");
    exit(1);
}

$base = "https://{$host}/api/v1/call-control";
$client = fn () => Http::timeout(20)->acceptJson()->withHeaders(['X-API-Key' => $apiKey]);

$payloadExtras = array_filter([
    'campaign_id' => $campaignId,
    'caller_id_number' => $callerId,
    'timeout_sec' => $timeout,
], fn ($v) => filled($v));

echo "Morpheus dialer verify\n";
echo "Host: {$host}\n";
echo "Extension: {$extension} | Destination: +{$digits} | Poll: {$pollSeconds}s\n";
echo "App config: originate_method={$originateMethod} customer_first=".($customerFirst ? 'true' : 'false')."\n";
echo str_repeat('-', 92)."\n";

function pollCall(ZoomApiService $api, string $uuid, int $seconds): array
{
    $timeline = [];
    $sawLive = false;
    $sawRinging = false;
    $maxBillsec = 0;
    $lastCause = null;
    $lastState = null;

    for ($i = 0; $i < $seconds; $i++) {
        $snap = $api->getCall($uuid) ?? [];
        $live = (bool) ($snap['live'] ?? false);
        $state = strtoupper((string) ($snap['state'] ?? $snap['status'] ?? ''));
        $billsec = (int) ($snap['billsec'] ?? $snap['duration_sec'] ?? 0);
        $cause = strtoupper((string) ($snap['hangup_cause'] ?? ''));

        $sawLive = $sawLive || $live;
        $sawRinging = $sawRinging || $live || in_array($state, ['RINGING', 'ACTIVE', 'EARLY'], true);
        $maxBillsec = max($maxBillsec, $billsec);
        $lastCause = $cause !== '' ? $cause : $lastCause;
        $lastState = $state !== '' ? $state : $lastState;

        $timeline[] = sprintf(
            't=%02ds live=%s state=%s billsec=%d cause=%s',
            $i + 1,
            $live ? 'yes' : 'no',
            $state !== '' ? $state : '-',
            $billsec,
            $cause !== '' ? $cause : '-',
        );

        if (! $live && $cause !== '' && $billsec < 1 && $i >= 2) {
            break;
        }

        sleep(1);
    }

    return compact('timeline', 'sawLive', 'sawRinging', 'maxBillsec', 'lastCause', 'lastState');
}

function verifyHangup(ZoomApiService $api, string $uuid): array
{
    $result = $api->hangup($uuid);
    sleep(1);
    $after = $api->getCall($uuid) ?? [];
    $live = (bool) ($after['live'] ?? false);

    return [
        'ok' => (bool) ($result['ok'] ?? false),
        'already_ended' => (bool) ($result['already_ended'] ?? false),
        'live_after' => $live,
        'cause_after' => strtoupper((string) ($after['hangup_cause'] ?? '')),
    ];
}

$modes = [
    'click-to-call' => [
        'path' => '/click-to-call',
        'body' => array_merge(['extension' => $extension, 'destination' => $digits], $payloadExtras),
    ],
    'calls/originate' => [
        'path' => '/calls/originate',
        'body' => array_merge(['from' => $extension, 'to' => $digits], $payloadExtras),
    ],
];

$results = [];

foreach ($modes as $label => $spec) {
    echo "\n=== {$label} ===\n";

    $response = $client()->post($base.$spec['path'], $spec['body']);
    $json = $response->json() ?? [];
    $uuid = (string) ($json['call_uuid'] ?? '');

    echo 'HTTP '.$response->status().' | call_uuid='.($uuid !== '' ? $uuid : 'none')."\n";
    if (isset($json['error'])) {
        echo "error: {$json['error']}\n";
    }

    if ($uuid === '') {
        $results[$label] = ['started' => false, 'hangup_ok' => false];
        continue;
    }

    $poll = pollCall($api, $uuid, $pollSeconds);
    foreach ($poll['timeline'] as $line) {
        echo "  {$line}\n";
    }

    $hangup = verifyHangup($api, $uuid);
    echo 'hangup: ok='.($hangup['ok'] ? 'yes' : 'no')
        .' already_ended='.($hangup['already_ended'] ? 'yes' : 'no')
        .' live_after='.($hangup['live_after'] ? 'yes' : 'no')
        .' cause_after='.($hangup['cause_after'] !== '' ? $hangup['cause_after'] : '-')."\n";

    $results[$label] = [
        'started' => true,
        'saw_ring' => $poll['sawRinging'],
        'saw_live' => $poll['sawLive'],
        'hangup_ok' => $hangup['ok'] && ! $hangup['live_after'],
        'last_cause' => $poll['lastCause'],
    ];

    sleep(2);
}

echo "\n=== app originateCall (dialer path) ===\n";
$appResult = $api->originateCall($extension, '+'.$digits, $dialOptions);
$appUuid = (string) ($appResult['call_uuid'] ?? '');
echo 'ok='.(($appResult['ok'] ?? false) ? 'yes' : 'no')
    .' outcome='.($appResult['outcome'] ?? '-')
    .' customer_first='.(($appResult['customer_first'] ?? false) ? 'yes' : 'no')
    .' uuid='.($appUuid !== '' ? $appUuid : 'none')."\n";
if (isset($appResult['error'])) {
    echo "error: {$appResult['error']}\n";
}
if (isset($appResult['attempted'])) {
    echo 'attempted: '.implode(', ', $appResult['attempted'])."\n";
}

$appHangupOk = false;
if ($appUuid !== '') {
    $poll = pollCall($api, $appUuid, $pollSeconds);
    foreach ($poll['timeline'] as $line) {
        echo "  {$line}\n";
    }
    $hangup = verifyHangup($api, $appUuid);
    echo 'hangup: ok='.($hangup['ok'] ? 'yes' : 'no')
        .' live_after='.($hangup['live_after'] ? 'yes' : 'no')."\n";
    $appHangupOk = $hangup['ok'] && ! $hangup['live_after'];
    $results['app-dialer'] = [
        'started' => true,
        'saw_ring' => $poll['sawRinging'],
        'saw_live' => $poll['sawLive'],
        'hangup_ok' => $appHangupOk,
        'last_cause' => $poll['lastCause'],
    ];
} else {
    $results['app-dialer'] = ['started' => false, 'hangup_ok' => false];
}

echo "\n".str_repeat('-', 92)."\n";
echo "SUMMARY\n";
printf("%-18s %-10s %-10s %-10s %-10s %s\n", 'MODE', 'STARTED', 'SAW_RING', 'SAW_LIVE', 'HANGUP_OK', 'LAST_CAUSE');
foreach ($results as $label => $row) {
    printf(
        "%-18s %-10s %-10s %-10s %-10s %s\n",
        $label,
        ($row['started'] ?? false) ? 'yes' : 'no',
        ($row['saw_ring'] ?? false) ? 'yes' : 'no',
        ($row['saw_live'] ?? false) ? 'yes' : 'no',
        ($row['hangup_ok'] ?? false) ? 'yes' : 'no',
        (string) ($row['last_cause'] ?? '-'),
    );
}

$ctc = $results['click-to-call'] ?? null;
$orig = $results['calls/originate'] ?? null;
if ($ctc && $orig && ($ctc['started'] ?? false) && ($orig['started'] ?? false)) {
    $sameRing = (bool) ($ctc['saw_ring'] ?? false) === (bool) ($orig['saw_ring'] ?? false);
    $sameLive = (bool) ($ctc['saw_live'] ?? false) === (bool) ($orig['saw_live'] ?? false);
    echo "\nRinging behavior match (raw APIs): ".($sameRing && $sameLive ? 'YES' : 'NO')."\n";
}

echo "\nActive calls after cleanup: ".count($api->listCalls()['calls'] ?? [])." remaining\n";
echo "Dialer UI uses: POST /communications/.../morpheus/calls/originate + GET status + POST hangup\n";
