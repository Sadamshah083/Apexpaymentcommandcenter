#!/usr/bin/env python3
from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import connect, sudo_run

ssh = connect()
print(
    sudo_run(
        ssh,
        r"""
# Latest originate 422 lines with surrounding access log timestamp; check PHP-FPM / app request log
grep -E 'calls/originate' /var/log/nginx/access.log | tail -5
echo ---
# Try click-to-call against a bad destination to see error shape/len
cd /var/www/apexone
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$api = app(App\Services\Integrations\ZoomApiService::class);
$agents = app(App\Services\Communications\CommunicationsAgentService::class);
$opts = $agents->extensionDialOptions("1020");
foreach ([["1020","123"], ["1020","+15551234567"], ["", "+12722001232"]] as $pair) {
  [$ext,$dest] = $pair;
  $result = $api->originateCall($ext ?: "1020", $dest, array_merge($opts, ["webphone_ready"=>true,"skip_line_clear"=>true]));
  $formatted = $api->formatOriginateResponse($result, $ext ?: "1020", $dest, $opts);
  $json = json_encode($formatted);
  echo "dest=$dest ok=".json_encode($result["ok"]??null)." err=".substr((string)($result["error"]??""),0,120)." len=".strlen($json)."\n";
  if (!($result["ok"]??false) && ($result["call_uuid"]??null)) {
    $api->hangup($result["call_uuid"]);
  }
  if (($result["ok"]??false) && ($result["call_uuid"]??null)) {
    $api->hangupWithContext($result["call_uuid"], "1020", $dest);
  }
}
'
""",
        check=False,
    )
)
ssh.close()
