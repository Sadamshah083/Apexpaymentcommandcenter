<?php

/**
 * Communications Hub + Morpheus health monitor.
 * Run via cron/systemd every minute. Append-only log for ops review.
 */

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\CommunicationCallLog;
use App\Services\Communications\CommunicationsAgentService;
use App\Services\Integrations\ZoomApiService;

$logFile = storage_path('logs/comm-hub-monitor.log');
$ts = now()->toIso8601String();
$lines = ["[$ts]"];

try {
    $api = app(ZoomApiService::class);
    $agents = app(CommunicationsAgentService::class);

    $status = $api->connectionStatus();
    $apiOk = (bool) ($status['connected'] ?? false);
    $lines[] = 'morpheus_api='.($apiOk ? 'ok' : 'fail').' '.($status['message'] ?? '');

    $extras = [];
    $ref = new ReflectionClass($api);
    $m = $ref->getMethod('originatePayloadExtras');
    $m->setAccessible(true);
    $extras = $m->invoke($api, $agents->extensionDialOptions('1001'));
    $lines[] = 'originate_extras='.json_encode($extras);

    $wss = (string) config('integrations.morpheus.sip_wss_url');
    $wssOk = false;
    if ($wss !== '') {
        $probe = str_replace('wss://', 'https://', rtrim($wss, '/'));
        if (! str_ends_with($probe, '/ws')) {
            $probe .= '/ws';
        }
        $cmd = sprintf(
            'curl -sk --http1.1 -o /dev/null -w %%{http_code} --max-time 6 -H %s -H %s -H %s -H %s %s 2>/dev/null',
            escapeshellarg('Connection: Upgrade'),
            escapeshellarg('Upgrade: websocket'),
            escapeshellarg('Sec-WebSocket-Version: 13'),
            escapeshellarg('Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ=='),
            escapeshellarg($probe),
        );
        $code = trim((string) @shell_exec($cmd));
        $wssOk = $code === '101';
    }
    $lines[] = 'wss='.($wssOk ? 'ok' : 'fail').' url='.$wss;

    $recent = CommunicationCallLog::orderByDesc('id')->limit(3)->get();
    foreach ($recent as $row) {
        $uuid = $row->morpheus_call_uuid ?: 'none';
        $lines[] = "call_log id={$row->id} ext={$row->from_extension} dest={$row->destination} uuid={$uuid} at={$row->created_at}";
        if ($uuid !== 'none') {
            $cdr = $api->getCall($uuid);
            if ($cdr) {
                $cause = $cdr['hangup_cause'] ?? $cdr['call_outcome'] ?? 'live';
                $dest = $cdr['destination_number'] ?? '';
                $lines[] = "  cdr cause={$cause} dest={$dest} billsec=".($cdr['billsec'] ?? 0);
            }
        }
    }

    $nginxTail = @shell_exec('tail -5 /var/log/nginx/access.log 2>/dev/null | grep -E "webphone/prepare|calls/originate" || true');
    if (filled($nginxTail)) {
        $lines[] = 'nginx_recent='.str_replace(["\r", "\n"], ' | ', trim($nginxTail));
    }

    $fpmWarn = @shell_exec('grep -c "max_children" /var/log/php8.3-fpm.log 2>/dev/null | tail -1');
    if (filled($fpmWarn) && (int) trim($fpmWarn) > 0) {
        $lines[] = 'php_fpm_max_children_hits='.trim($fpmWarn).' (check pool sizing)';
    }

    $level = ($apiOk && $wssOk) ? 'OK' : 'WARN';
    $lines[0] = "[$ts] level=$level";
} catch (Throwable $e) {
    $lines[0] = "[$ts] level=ERROR";
    $lines[] = 'error='.$e->getMessage();
}

file_put_contents($logFile, implode("\n", $lines)."\n", FILE_APPEND | LOCK_EX);

if (str_contains($lines[0], 'level=ERROR') || str_contains($lines[0], 'level=WARN')) {
  Illuminate\Support\Facades\Log::warning('Comm hub monitor: '.implode(' | ', array_slice($lines, 1)));
}

echo implode("\n", $lines)."\n";
