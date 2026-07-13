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
      "uuid" => "pstn-active-1",
      "phone_number" => "+12092592594",
      "status" => "active",
    ]],
  ], 200),
]);
$svc = new ZoomApiService();
$st = $svc->hubLiveCallStatus("agent-uuid-xyz", "+12092592594");
echo json_encode($st, JSON_PRETTY_PRINT).PHP_EOL;
'
'''.replace("APP", REMOTE_APP)


def main() -> int:
    ssh = connect()
    print(sudo_run(ssh, PHP))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
