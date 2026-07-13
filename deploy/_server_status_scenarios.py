#!/usr/bin/env python3
"""Check production logs + simulate status with array cache on server."""

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

$cases = [
  "ringing" => [["uuid"=>"p1","phone_number"=>"+12092592594","status"=>"ringing","age_sec"=>12]],
  "active" => [["uuid"=>"p1","phone_number"=>"+12092592594","status"=>"active"]],
  "up" => [["uuid"=>"p1","phone_number"=>"+12092592594","status"=>"up"]],
  "talking" => [["uuid"=>"p1","phone_number"=>"+12092592594","status"=>"talking"]],
  "in_progress" => [["uuid"=>"p1","phone_number"=>"+12092592594","status"=>"in_progress"]],
  "agent_active_no_phone" => [["uuid"=>"agent-uuid","status"=>"active","extension"=>"1020"]],
  "agent_plus_pstn_ring" => [
    ["uuid"=>"agent-uuid","status"=>"active","extension"=>"1020","bridge_uuid"=>"p1"],
    ["uuid"=>"p1","phone_number"=>"+12092592594","status"=>"ringing","bridge_uuid"=>"agent-uuid"],
  ],
  "agent_plus_pstn_active" => [
    ["uuid"=>"agent-uuid","status"=>"active","extension"=>"1020","bridge_uuid"=>"p1"],
    ["uuid"=>"p1","phone_number"=>"+12092592594","status"=>"active","bridge_uuid"=>"agent-uuid"],
  ],
];

foreach ($cases as $name => $calls) {
  Http::fake([
    "https://apexone.morpheus.cx/api/v1/call-control/calls/agent-uuid" => Http::response([], 404),
    "https://apexone.morpheus.cx/api/v1/call-control/calls" => Http::response(["calls"=>$calls], 200),
    "https://apexone.morpheus.cx/api/v1/call-control/cdr*" => Http::response(["cdr"=>[]], 200),
  ]);
  $svc = new ZoomApiService();
  $ref = new ReflectionClass($svc);
  foreach (["activeCallsCache"=>null,"activeCallsCacheAt"=>0.0] as $prop=>$val) {
    $p=$ref->getProperty($prop); $p->setAccessible(true); $p->setValue($svc,$val);
  }
  $s = $svc->hubLiveCallStatus("agent-uuid", "+12092592594");
  echo $name." outcome=".($s["outcome"]??"")." connected=".json_encode($s["destination_connected"]??null)." state=".($s["state"]??"")." src=".($s["source"]??"")." to=".($s["to"]??"")."\n";
}
'
'''.replace("APP", REMOTE_APP)


def main() -> int:
    ssh = connect()
    print("=== scenarios ===")
    print(sudo_run(ssh, PHP))
    print("=== recent logs ===")
    print(sudo_run(ssh, f"grep -E 'Morpheus webhook|destination_answered|hubLive|call status' {REMOTE_APP}/storage/logs/laravel.log 2>/dev/null | tail -n 40 || true"))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
