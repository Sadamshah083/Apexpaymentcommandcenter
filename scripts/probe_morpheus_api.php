<?php

/**
 * Probe Morpheus CX Call-Control + platform APIs against current .env credentials.
 * Usage: php scripts/probe_morpheus_api.php
 */

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\Http;

$host = (string) config('integrations.morpheus.host');
$apiKey = (string) config('integrations.morpheus.api_key');

if ($host === '' || $apiKey === '') {
    fwrite(STDERR, "MORPHEUS_HOST / MORPHEUS_API_KEY not configured.\n");
    exit(1);
}

$callControlBase = "https://{$host}/api/v1/call-control";
$platformBase = "https://{$host}/api/v1";

$client = fn (string $base) => Http::timeout(12)
    ->acceptJson()
    ->withHeaders(['X-API-Key' => $apiKey]);

$endpoints = [
    // Call control — documented
    ['GET', '/users', 'users:read', 'call-control', ['limit' => 1]],
    ['GET', '/calls', 'calls:read', 'call-control', ['limit' => 5]],
    ['GET', '/cdr', 'cdr:read', 'call-control', ['limit' => 5]],
    ['GET', '/recordings', 'recordings:read', 'call-control', ['limit' => 5]],
    ['GET', '/voicemails', 'voicemails:read', 'call-control', ['limit' => 5]],
    ['GET', '/queues', 'queues:read', 'call-control', []],
    ['GET', '/conferences', 'conferences:read', 'call-control', []],
    ['GET', '/leads', 'leads:read', 'call-control', ['limit' => 5]],
    ['GET', '/campaigns', 'campaigns:read', 'call-control', ['limit' => 5]],
    ['GET', '/lists', 'lists:read', 'call-control', ['limit' => 5]],
    ['GET', '/extensions', 'extensions:read', 'call-control', ['limit' => 5]],
    // Write probes (OPTIONS or dry — use POST with invalid body to detect 403 vs 404 vs 422)
    ['POST', '/calls/originate', 'calls:originate', 'call-control', ['from' => '000', 'to' => '000']],
    ['POST', '/click-to-call', 'calls:originate', 'call-control', ['extension' => '000', 'destination' => '000']],
    // Not in official docs — our SMS/chat fallbacks
    ['GET', '/sms/sessions', 'sms (undocumented)', 'platform', ['limit' => 5]],
    ['GET', '/sms', 'sms (undocumented)', 'platform', ['limit' => 5]],
    ['GET', '/chat', 'team chat (undocumented)', 'platform', ['limit' => 5]],
    ['GET', '/chats', 'team chat (undocumented)', 'platform', ['limit' => 5]],
];

echo "Host: {$host}\n";
echo str_repeat('-', 100)."\n";
printf("%-8s %-28s %-22s %-6s %s\n", 'METHOD', 'PATH', 'SCOPE', 'STATUS', 'RESULT');
echo str_repeat('-', 100)."\n";

$summary = ['ok' => 0, 'forbidden' => 0, 'not_found' => 0, 'error' => 0, 'other' => 0];

foreach ($endpoints as [$method, $path, $scope, $api, $query]) {
    $base = $api === 'call-control' ? $callControlBase : $platformBase;
    $url = $base.$path;

    try {
        $response = match (strtoupper($method)) {
            'GET' => $client($base)->get($url, $query),
            'POST' => $client($base)->post($url, $query),
            default => throw new RuntimeException("Unsupported method {$method}"),
        };
    } catch (Throwable $e) {
        printf("%-8s %-28s %-22s %-6s %s\n", $method, $path, $scope, 'EXC', substr($e->getMessage(), 0, 60));
        $summary['error']++;
        continue;
    }

    $status = $response->status();
    $bucket = match (true) {
        $status >= 200 && $status < 300 => 'ok',
        $status === 403 => 'forbidden',
        $status === 404 => 'not_found',
        $status >= 400 && $status < 500 => 'other',
        default => 'error',
    };
    $summary[$bucket]++;

    $body = $response->json() ?? [];
    $detail = '';
    if ($status === 403) {
        $detail = (string) ($body['error'] ?? $body['message'] ?? 'Forbidden — missing scope');
    } elseif ($status === 404) {
        $detail = 'Not found — endpoint may not exist on this tenant';
    } elseif ($status >= 200 && $status < 300) {
        $keys = array_keys($body);
        $count = 0;
        foreach (['users', 'calls', 'cdr', 'recordings', 'voicemails', 'queues', 'conferences', 'leads', 'campaigns', 'lists', 'extensions', 'sessions', 'channels', 'chats', 'messages'] as $k) {
            if (isset($body[$k]) && is_array($body[$k])) {
                $count = count($body[$k]);
                $detail = "{$k}: {$count} items";
                break;
            }
        }
        if ($detail === '') {
            $detail = 'OK keys: '.implode(', ', array_slice($keys, 0, 5));
        }
    } elseif (in_array($status, [400, 409, 422], true)) {
        $detail = (string) ($body['error'] ?? $body['message'] ?? 'Rejected (endpoint exists, bad payload)');
    } else {
        $detail = substr($response->body(), 0, 80);
    }

    printf("%-8s %-28s %-22s %-6s %s\n", $method, $path, $scope, (string) $status, $detail);
}

echo str_repeat('-', 100)."\n";
echo 'Summary: '.json_encode($summary)."\n";

$zoom = app(App\Services\Integrations\ZoomApiService::class);
echo "\nApp connectionStatus: ".json_encode($zoom->connectionStatus())."\n";
echo "App connectionDiagnostics: ".json_encode($zoom->connectionDiagnostics())."\n";
