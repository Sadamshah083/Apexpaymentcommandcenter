#!/usr/bin/env python3
from __future__ import annotations

import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
_pw = Path(__file__).with_name(".deploy_password")
if _pw.exists() and not os.environ.get("DEPLOY_PASSWORD"):
    os.environ["DEPLOY_PASSWORD"] = _pw.read_text(encoding="utf-8").strip()
import deploy._ssh as m
m.PASSWORD = os.environ.get("DEPLOY_PASSWORD", "") or m.PASSWORD
from deploy._ssh import connect, sudo_run

SCRIPT = r"""
sudo -u www-data php <<'PHP'
<?php
require "/var/www/apexone/vendor/autoload.php";
$app = require "/var/www/apexone/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$z = app(App\Services\Integrations\ZoomApiService::class);
$opts = ["campaign_id"=>"6c753496-2efd-4783-aa85-eb6ec73bc512","caller_id_number"=>"13133851223"];

$errors = [
  'Connection failed: cURL error 28: Operation timed out after 2001 milliseconds with 0 bytes received (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://apexone.morpheus.cx/api/v1/call-control/click-to-call',
  'Connection failed: cURL error 28: Failed to connect to apexone.morpheus.cx port 443 after 1002 ms: Timeout was reached (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://apexone.morpheus.cx/api/v1/call-control/click-to-call',
  'Connection failed: cURL error 28: SSL connection timeout (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://apexone.morpheus.cx/api/v1/call-control/click-to-call',
  'cURL error 28: Operation timed out after 2001 milliseconds with 0 bytes received (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://apexone.morpheus.cx/api/v1/call-control/click-to-call',
  'cURL error 28: SSL connection timeout (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://apexone.morpheus.cx/api/v1/call-control/click-to-call',
  'cURL error 28: Failed to connect to apexone.morpheus.cx port 443 after 1002 ms: Timeout was reached (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://apexone.morpheus.cx/api/v1/call-control/click-to-call',
  'Morpheus telephony is temporarily unavailable. The rest of ApexOne is unaffected.',
];

foreach ($errors as $err) {
  foreach ([false, null] as $lr) {
    $r = ["ok"=>false,"error"=>$err,"attempted"=>["POST /click-to-call"]];
    if ($lr !== null) $r["line_reset"] = $lr;
    $c = response()->json($z->formatOriginateResponse($r,"1020","12722001232",$opts))->getContent();
    $n = strlen($c);
    if ($n === 411 || $n === 473 || abs($n-411)<=2 || abs($n-473)<=2) {
      echo "n=$n lr=".json_encode($lr)."\n$c\n\n";
    } else {
      echo "n=$n lr=".json_encode($lr)." err=".substr($err,0,60)."...\n";
    }
  }
}

// Http exception Validation?
$val = json_encode([
  "message" => "The given data was invalid.",
  "errors" => [
    "destination" => ["The destination field must not be greater than 32 characters."],
    "from_extension" => ["The from extension field is required."],
  ],
]);
echo "val_multi=".strlen($val)." $val\n";

// Check originate client timeout config
echo "originate_timeout=".json_encode(config("integrations.morpheus.originate_timeout")).PHP_EOL;
echo "http_timeout=".json_encode(config("integrations.morpheus.http_timeout")).PHP_EOL;
echo "connect_timeout=".json_encode(config("integrations.morpheus")).PHP_EOL;
PHP

# Also show current timeout settings in ZoomApiService originateClient
grep -n "originateClient\|timeout\|connectTimeout\|2000\|2\.0" /var/www/apexone/app/Services/Integrations/ZoomApiService.php | head -40
grep -n "timeout\|originate" /var/www/apexone/config/integrations.php | head -40
"""

ssh = connect()
print(sudo_run(ssh, SCRIPT, check=False))
ssh.close()
