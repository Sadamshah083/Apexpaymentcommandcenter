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
cd /var/www/apexone
# Hang up accidental probe call
php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$api = app(App\Services\Integrations\ZoomApiService::class);
echo json_encode($api->hangup("d0b08464-3771-47d7-a4c8-1bc22f3d253d"))."\n";
$agents = app(App\Services\Communications\CommunicationsAgentService::class);
foreach (["1020","1001","1015"] as $ext) {
  $payload = [
    "ok" => false,
    "extension_offline" => true,
    "webphone_required" => true,
    "error" => $agents->extensionOfflineDialMessage($ext),
  ];
  $json = response()->json($payload, 422)->getContent();
  echo "ext=$ext len=".strlen($json)." body=$json\n";
  $payload2 = [
    "ok" => false,
    "extension_offline" => true,
    "error" => $agents->extensionOfflineDialMessage($ext),
  ];
  $json2 = response()->json($payload2, 422)->getContent();
  echo "ext=$ext no_req len=".strlen($json2)."\n";
}
'
""",
        check=False,
    )
)
ssh.close()
