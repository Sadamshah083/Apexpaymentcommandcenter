#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run


PROBE = r'''
php -r '
require "/var/www/apexone/vendor/autoload.php";
$app = require "/var/www/apexone/bootstrap/app.php";
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$events = app(App\Services\Communications\MorpheusCallEventService::class);
$monitoring = app(App\Services\Communications\CallMonitoringService::class);

// Cleanup any leftover probe states
foreach (["probe-dup-a","probe-dup-b","probe-dup-c","probe-conn-1"] as $u) {
    $events->markCallEnded($u, "CLEANUP", 0);
}

// Dedupe: three watches same leg should leave one live, then one row
$events->watchCall("probe-dup-a", "1015", "2092592594");
$events->watchCall("probe-dup-b", "1015", "12092592594");
$events->watchCall("probe-dup-c", "1015", "+12092592594");

$a = $events->getCallState("probe-dup-a");
$b = $events->getCallState("probe-dup-b");
$c = $events->getCallState("probe-dup-c");
echo "live_a=" . (($a["live"] ?? false) ? "1" : "0") . "\n";
echo "live_b=" . (($b["live"] ?? false) ? "1" : "0") . "\n";
echo "live_c=" . (($c["live"] ?? false) ? "1" : "0") . "\n";

$snap = $monitoring->snapshot(null, light: true, probeConnected: false);
$legRows = array_values(array_filter($snap["rows"] ?? [], fn($r) => str_contains((string)($r["destination"] ?? ""), "2092592594") || str_contains((string)($r["destination"] ?? ""), "92592594")));
echo "dup_rows=" . count($legRows) . "\n";
echo "ringing_summary=" . ($snap["summary"]["ringing"] ?? -1) . "\n";

// Connected promote
$events->watchCall("probe-conn-1", "1015", "2092592594");
$events->markDestinationConnected("probe-conn-1", "2092592594", 12, "probe", now()->subSeconds(30)->toIso8601String());
$snap2 = $monitoring->snapshot(null, light: true, probeConnected: false);
$row = collect($snap2["rows"])->firstWhere("id", "probe-conn-1");
echo "conn_bucket=" . ($row["bucket"] ?? "missing") . "\n";
echo "conn_timer=" . ($row["timer_sec"] ?? -1) . "\n";
echo "short_count=" . count($snap2["tables"]["incall_short"] ?? []) . "\n";

foreach (["probe-dup-a","probe-dup-b","probe-dup-c","probe-conn-1"] as $u) {
    $events->markCallEnded($u, "CLEANUP", 0);
}

echo "nginx_buffering=" . (str_contains(file_get_contents("/etc/nginx/sites-enabled/apexone"), "fastcgi_buffering off") ? "1" : "0") . "\n";
echo "OK\n";
'
'''


def main() -> int:
    ssh = connect()
    print("--- unit tests ---")
    print(
        sudo_run(
            ssh,
            f"cd {REMOTE_APP} && sudo -u www-data ./vendor/bin/phpunit --filter=CallMonitoringServiceTest 2>&1 | tail -n 50",
            check=False,
        )
    )
    print("--- live probe ---")
    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data bash -lc {PROBE!r}", check=False))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
