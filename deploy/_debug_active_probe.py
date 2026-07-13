#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run  # noqa: E402

PHP = r'''
cd APP
sudo -u www-data php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\Http;
use App\Services\Integrations\ZoomApiService;

config(["integrations.morpheus.host"=>"apexone.morpheus.cx","integrations.morpheus.api_key"=>"test-key"]);
Http::fake([
  "https://apexone.morpheus.cx/api/v1/call-control/calls/*" => Http::response([], 404),
  "https://apexone.morpheus.cx/api/v1/call-control/calls" => Http::response([
    "calls" => [[
      "uuid" => "pstn-1",
      "phone_number" => "+12092592594",
      "status" => "active",
      "direction" => "outbound",
    ]],
  ], 200),
]);

$svc = new ZoomApiService();
$ref = new ReflectionClass($svc);

$m = $ref->getMethod("listActiveCalls");
$m->setAccessible(true);
$calls = $m->invoke($svc);
echo "list=".json_encode($calls).PHP_EOL;

$p = $ref->getMethod("probeDestinationOnActiveCalls");
$p->setAccessible(true);
$probe = $p->invoke($svc, "+12092592594");
echo "probe=".json_encode($probe).PHP_EOL;

$n = $ref->getMethod("normalizeActiveCallRow");
$n->setAccessible(true);
$norm = $n->invoke($svc, $calls[0]);
echo "norm=".json_encode($norm).PHP_EOL;

$s = $ref->getMethod("activeCallState");
$s->setAccessible(true);
echo "state=".$s->invoke($svc, $norm).PHP_EOL;

$url = $ref->getMethod("url");
$url->setAccessible(true);
echo "url_calls=".$url->invoke($svc, "/calls").PHP_EOL;

$recorded = Http::recorded();
echo "http_count=".count($recorded).PHP_EOL;
foreach ($recorded as $i => $pair) {
  echo "req$i=".$pair[0]->url().PHP_EOL;
}
'
'''.replace("APP", REMOTE_APP)


def main() -> int:
    ssh = connect()
    print(sudo_run(ssh, PHP))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
