#!/usr/bin/env python3
"""Post-deploy smoke: confirm dialer status code + Morpheus API reachability."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run  # noqa: E402

PHP = r"""
cd APP_ROOT
sudo -u www-data php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$svc = app(App\Services\Integrations\ZoomApiService::class);
$ref = new ReflectionClass($svc);
$m = $ref->getMethod("listActiveCalls");
$m->setAccessible(true);
$calls = $m->invoke($svc);
echo "active_calls_count=" . count($calls) . PHP_EOL;
if (count($calls) > 0) {
  $c = $calls[0];
  echo "sample_keys=" . implode(",", array_keys($c)) . PHP_EOL;
  echo "sample_status=" . ($c["status"] ?? $c["state"] ?? "") . PHP_EOL;
}
$probe = $svc->probeDestinationOnActiveCalls("+15551234567");
echo "probe_miss=" . ($probe === null ? "null" : json_encode($probe)) . PHP_EOL;
$fmt = $ref->getMethod("formatDisplayPhone");
$fmt->setAccessible(true);
echo "display_10=" . $fmt->invoke($svc, "2092592594") . PHP_EOL;
echo "display_11=" . $fmt->invoke($svc, "12092592594") . PHP_EOL;
echo "display_e164=" . $fmt->invoke($svc, "+12092592594") . PHP_EOL;
'
""".replace("APP_ROOT", REMOTE_APP)


def main() -> int:
    ssh = connect()
    print("formatDisplayPhone lines:", sudo_run(ssh, f"grep -c formatDisplayPhone {REMOTE_APP}/app/Services/Integrations/ZoomApiService.php"))
    print("health:", sudo_run(ssh, "curl -fsS -o /dev/null -w '%{http_code}' https://crm.apexonepayments.com/up || true"))
    print(sudo_run(ssh, PHP))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
