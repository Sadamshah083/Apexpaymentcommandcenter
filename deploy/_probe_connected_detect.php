<?php

require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Communications\CallMonitoringService;
use App\Services\Communications\MorpheusCallEventService;

$events = app(MorpheusCallEventService::class);
$monitoring = app(CallMonitoringService::class);
$uuid = 'probe-conn-'.uniqid();
$results = [];

function assert_case(array &$results, string $name, bool $ok, $detail = null): void
{
    $results[] = ['name' => $name, 'ok' => $ok, 'detail' => $detail];
}

try {
    $events->watchCall($uuid, '1015', '2092592594');
    $state = $events->getCallState($uuid);
    assert_case($results, 'watch_live', (bool) ($state['live'] ?? false), $state);
    assert_case($results, 'watch_not_answered', ! ($state['destination_answered'] ?? false), $state);

    $snapRing = $monitoring->snapshot(null);
    $rowRing = null;
    foreach ($snapRing['rows'] as $candidate) {
        if (($candidate['id'] ?? '') === $uuid) {
            $rowRing = $candidate;
            break;
        }
    }
    assert_case($results, 'initially_ringing', ($rowRing['bucket'] ?? null) === 'ringing', $rowRing);
    assert_case($results, 'initially_timer_zero', ((int) ($rowRing['timer_sec'] ?? -1)) === 0, $rowRing);

    // Simulate agent dialer reporting both sides connected.
    $events->markDestinationConnected($uuid, '2092592594', 3, 'agent');
    $state = $events->getCallState($uuid);
    assert_case($results, 'marked_answered', (bool) ($state['destination_answered'] ?? false), $state);
    assert_case($results, 'has_connected_at', filled($state['connected_at'] ?? null), $state);

    $snap = $monitoring->snapshot(null);
    $row = null;
    foreach ($snap['rows'] as $candidate) {
        if (($candidate['id'] ?? '') === $uuid) {
            $row = $candidate;
            break;
        }
    }

    assert_case($results, 'row_present', $row !== null, $row);
    assert_case($results, 'bucket_incall_short', ($row['bucket'] ?? null) === 'incall_short', $row);
    assert_case($results, 'timer_running', ((int) ($row['timer_sec'] ?? 0)) >= 1, $row);
    assert_case($results, 'not_in_ringing', ! collect($snap['tables']['ringing'] ?? [])->contains(fn ($r) => ($r['id'] ?? '') === $uuid), 'tables');
    assert_case($results, 'in_short_table', collect($snap['tables']['incall_short'] ?? [])->contains(fn ($r) => ($r['id'] ?? '') === $uuid), 'tables');

    $route = app('router')->getRoutes()->getByName('admin.communications.morpheus.calls.destination-connected');
    assert_case($results, 'route_registered', $route !== null, $route?->uri());

    $jsHit = false;
    foreach (glob(base_path('public/build/assets/communications-*.js')) ?: [] as $file) {
        $chunk = file_get_contents($file) ?: '';
        if (str_contains($chunk, 'destination-connected') || str_contains($chunk, 'reportDestinationConnected')) {
            $jsHit = true;
            break;
        }
    }
    assert_case($results, 'js_reports_connected', $jsHit, 'js');
} finally {
    try {
        $events->markCallEnded($uuid, 'PROBE_CLEANUP', 0);
    } catch (Throwable) {
    }
}

$failed = array_values(array_filter($results, fn ($r) => ! $r['ok']));
echo json_encode([
    'ok' => $failed === [],
    'passed' => count($results) - count($failed),
    'failed_count' => count($failed),
    'failed' => $failed,
    'results' => $results,
], JSON_PRETTY_PRINT).PHP_EOL;

exit($failed === [] ? 0 : 1);
