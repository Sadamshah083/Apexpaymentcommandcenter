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

foreach (["ringing"=>false,"active"=>true,"talking"=>true,"up"=>true] as $st=>$want) {
  Http::fake([
    "https://apexone.morpheus.cx/api/v1/call-control/calls/*" => Http::response([], 404),
    "https://apexone.morpheus.cx/api/v1/call-control/calls" => Http::response([
      "calls"=>[["uuid"=>"p1","phone_number"=>"+12092592594","status"=>$st]],
    ], 200),
  ]);
  $svc = new ZoomApiService();
  $s = $svc->hubLiveCallStatus("agent", "+12092592594");
  $ok = ((bool)($s["destination_connected"]??false)) === $want;
  echo ($ok?"PASS":"FAIL")." status=$st connected=".json_encode($s["destination_connected"]??null)." outcome=".($s["outcome"]??"")."\n";
}
'
'''.replace("APP", REMOTE_APP)


def main() -> int:
    ssh = connect()
    print("code markers:")
    print(sudo_run(ssh, f"grep -c answeredActiveCallStates {REMOTE_APP}/app/Services/Integrations/ZoomApiService.php"))
    print(sudo_run(ssh, f"grep -c tickRingTimer {REMOTE_APP}/resources/js/communications-webphone.js"))
    print(sudo_run(ssh, f"grep -c dispatchCallEndedOnce {REMOTE_APP}/resources/js/communications-webphone.js"))
    print(sudo_run(ssh, f"grep -c webrtc-rtp-confirmed {REMOTE_APP}/public/build/assets/communications-webphone-*.js || true"))
    print("health:", sudo_run(ssh, "curl -fsS -o /dev/null -w '%{http_code}' https://crm.apexonepayments.com/up || true"))
    print(sudo_run(ssh, PHP))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
