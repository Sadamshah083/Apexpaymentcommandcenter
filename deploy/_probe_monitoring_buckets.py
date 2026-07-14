#!/usr/bin/env python3
"""Live prod probe: Ringing -> In-call <=2m -> In-call >2m buckets."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run  # noqa: E402

PHP = r'''<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Services\Communications\CallMonitoringService;
use App\Services\Communications\MorpheusCallEventService;

$events = app(MorpheusCallEventService::class);
$monitoring = app(CallMonitoringService::class);
$uuidRing = "probe-mon-ring-" . uniqid();
$uuidShort = "probe-mon-short-" . uniqid();
$uuidLong = "probe-mon-long-" . uniqid();
$results = [];

function row_for(array $snapshot, string $uuid): ?array {
    foreach (($snapshot["rows"] ?? []) as $row) {
        if (($row["id"] ?? "") === $uuid) {
            return $row;
        }
    }
    return null;
}

function assert_case(array &$results, string $name, bool $ok, $detail): void {
    $results[] = ["name" => $name, "ok" => $ok, "detail" => $detail];
}

function table_has(array $snapshot, string $bucket, string $uuid): bool {
    foreach (($snapshot["tables"][$bucket] ?? []) as $row) {
        if (($row["id"] ?? "") === $uuid) {
            return true;
        }
    }
    return false;
}

try {
    // 1) Ringing — timer must stay 0
    $events->watchCall($uuidRing, "1015", "2092592594");
    $snap = $monitoring->snapshot(null);
    $row = row_for($snap, $uuidRing);
    assert_case($results, "ringing_present", $row !== null, $row);
    assert_case($results, "ringing_bucket", ($row["bucket"] ?? null) === "ringing", $row);
    assert_case($results, "ringing_timer_zero", ((int) ($row["timer_sec"] ?? -1)) === 0, $row);
    assert_case($results, "ringing_in_table", table_has($snap, "ringing", $uuidRing), "tables.ringing");

    // 2) Connected <= 2 min via destination_answered webhook
    $events->watchCall($uuidShort, "1016", "2092592595");
    $events->ingestWebhook([
        "event" => "destination_answered",
        "call_uuid" => $uuidShort,
        "destination_number" => "2092592595",
        "billsec" => 1,
        "bridged_to" => "leg-b-short",
    ]);
    $state = $events->getCallState($uuidShort) ?? [];
    $state["connected_at"] = now()->subSeconds(41)->toIso8601String();
    $state["destination_answered"] = true;
    $state["destination_connected"] = true;
    $state["live"] = true;
    $ref = new ReflectionClass($events);
    $put = $ref->getMethod("putState");
    $put->setAccessible(true);
    $put->invoke($events, $uuidShort, $state);

    $snap = $monitoring->snapshot(null);
    $row = row_for($snap, $uuidShort);
    assert_case($results, "short_present", $row !== null, $row);
    assert_case($results, "short_bucket", ($row["bucket"] ?? null) === "incall_short", $row);
    $timer = (int) ($row["timer_sec"] ?? 0);
    assert_case($results, "short_timer_running", $timer >= 40 && $timer <= 55, $row);
    assert_case($results, "short_in_short_table", table_has($snap, "incall_short", $uuidShort), "tables.incall_short");
    assert_case($results, "short_not_in_ringing", !table_has($snap, "ringing", $uuidShort), "not ringing");

    // 3) Connected > 2 min
    $events->watchCall($uuidLong, "1017", "2092592596");
    $events->ingestWebhook([
        "event" => "destination_answered",
        "call_uuid" => $uuidLong,
        "destination_number" => "2092592596",
        "billsec" => 150,
        "bridged_to" => "leg-b-long",
    ]);
    $state = $events->getCallState($uuidLong) ?? [];
    $state["connected_at"] = now()->subSeconds(150)->toIso8601String();
    $state["destination_answered"] = true;
    $state["destination_connected"] = true;
    $state["live"] = true;
    $put->invoke($events, $uuidLong, $state);

    $snap = $monitoring->snapshot(null);
    $row = row_for($snap, $uuidLong);
    assert_case($results, "long_present", $row !== null, $row);
    assert_case($results, "long_bucket", ($row["bucket"] ?? null) === "incall_long", $row);
    assert_case($results, "long_timer_over_120", ((int) ($row["timer_sec"] ?? 0)) > 120, $row);
    assert_case($results, "long_in_long_table", table_has($snap, "incall_long", $uuidLong), "tables.incall_long");

    // 4) Snapshot shape
    assert_case($results, "has_three_tables", isset($snap["tables"]["ringing"], $snap["tables"]["incall_short"], $snap["tables"]["incall_long"]), array_keys($snap["tables"] ?? []));
    assert_case($results, "summary_keys", isset($snap["summary"]["in_call_short"], $snap["summary"]["in_call_long"], $snap["summary"]["ringing"]), $snap["summary"] ?? []);

    // 5) Wallboard HTML has three boards (view-only; no auth DB quirks)
    try {
        $html = view("communications.monitoring.partials.wallboard", [
            "routePrefix" => "admin.",
            "snapshot" => $snap,
            "pollUrl" => "/admin/communications/monitoring/live",
            "streamUrl" => "/admin/communications/monitoring/stream",
        ])->render();
        assert_case($results, "page_has_ringing_board", str_contains($html, 'data-call-monitoring-board="ringing"'), "ringing");
        assert_case($results, "page_has_short_board", str_contains($html, 'data-call-monitoring-board="incall_short"'), "short");
        assert_case($results, "page_has_long_board", str_contains($html, 'data-call-monitoring-board="incall_long"'), "long");
        assert_case($results, "page_has_probe_rows", str_contains($html, $uuidShort) && str_contains($html, $uuidLong), "probe rows rendered");
    } catch (Throwable $e) {
        assert_case($results, "page_render", false, $e->getMessage());
    }

    // 6) Built JS contains three-table logic
    $jsFiles = glob(base_path("public/build/assets/call-monitoring-*.js")) ?: [];
    $js = $jsFiles ? file_get_contents($jsFiles[0]) : "";
    assert_case($results, "js_has_incall_short", str_contains($js, "incall_short"), "js short");
    assert_case($results, "js_has_incall_long", str_contains($js, "incall_long"), "js long");
    assert_case($results, "js_timer_gate", str_contains($js, "0:00") || str_contains($js, "formatTimer"), "timer helper");

} finally {
    foreach ([$uuidRing, $uuidShort, $uuidLong] as $u) {
        try { $events->markCallEnded($u, "PROBE_CLEANUP", 0); } catch (Throwable) {}
    }
}

$failed = array_values(array_filter($results, fn ($r) => ! $r["ok"]));
echo json_encode([
    "ok" => $failed === [],
    "passed" => count($results) - count($failed),
    "failed_count" => count($failed),
    "failed" => $failed,
    "results" => $results,
], JSON_PRETTY_PRINT) . PHP_EOL;
exit($failed === [] ? 0 : 1);
'''


def main() -> int:
    ssh = connect()
    tmp = "/tmp/_probe_monitoring_buckets.php"
    remote = f"{REMOTE_APP}/storage/app/_probe_monitoring_buckets.php"
    sftp = ssh.open_sftp()
    with sftp.file(tmp, "w") as fh:
        fh.write(PHP)
    sftp.close()
    out = sudo_run(
        ssh,
        f"cp {tmp} {remote} && chown www-data:www-data {remote} && "
        f"cd {REMOTE_APP} && sudo -u www-data php {remote}; echo EXIT:$?; "
        f"rm -f {remote} {tmp}",
        check=False,
    )
    print(out)
    ssh.close()
    return 0 if "EXIT:0" in out else 1


if __name__ == "__main__":
    raise SystemExit(main())
