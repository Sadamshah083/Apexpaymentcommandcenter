#!/usr/bin/env python3
"""Confirm deployed status codes + exact 473/411 payloads."""

from __future__ import annotations

import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

_pw = Path(__file__).with_name(".deploy_password")
if _pw.exists() and not os.environ.get("DEPLOY_PASSWORD"):
    os.environ["DEPLOY_PASSWORD"] = _pw.read_text(encoding="utf-8").strip()

import deploy._ssh as ssh_mod

ssh_mod.PASSWORD = os.environ.get("DEPLOY_PASSWORD", "") or ssh_mod.PASSWORD

from deploy._ssh import connect, sudo_run

SCRIPT = r"""
echo "---- CONTROLLER STATUS ----"
grep -n "extension_busy\|422\|409" /var/www/apexone/app/Http/Controllers/MorpheusHubController.php | head -40

echo "---- EXACT LENS ----"
sudo -u www-data php <<'PHP'
<?php
require "/var/www/apexone/vendor/autoload.php";
$app = require "/var/www/apexone/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$z = app(App\Services\Integrations\ZoomApiService::class);
$opts = [
  "campaign_id" => (string) config("integrations.morpheus.default_campaign_id"),
  "caller_id_number" => "13133851223",
];

$candidates = [];

$r = [
  "ok" => false,
  "outcome" => "extension_busy",
  "extension_busy" => true,
  "error" => "Extension 1020 rejected the ring (SIP 486 USER_BUSY). Close other calls, disable Do Not Disturb, then re-register your softphone to Morpheus.",
  "hangup_cause" => "USER_BUSY",
  "sip_code" => "486",
  "attempted" => ["POST /click-to-call"],
  "line_reset" => false,
];
$fmt = $z->formatOriginateResponse($r, "1020", "+12722001232", $opts);
$c = response()->json($fmt)->getContent();
echo "reject+line_reset len=".strlen($c)."\n$c\n\n";

$r2 = $r;
$r2["error"] = "Extension 1020 is busy on Morpheus. Click Connect line again, or ask your admin to clear stuck calls on this extension.";
unset($r2["outcome"], $r2["hangup_cause"], $r2["sip_code"]);
$fmt2 = $z->formatOriginateResponse($r2, "1020", "+12722001232", $opts);
$c2 = response()->json($fmt2)->getContent();
echo "busy_full+line_reset len=".strlen($c2)."\n$c2\n\n";

// Curl timeout as error
$timeouts = [
  "Connection failed: cURL error 28: Operation timed out after 2001 milliseconds with 0 bytes received (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://apexone.morpheus.cx/api/v1/call-control/click-to-call",
  "Connection failed: cURL error 28: SSL connection timeout (see https://curl.haxx.se/libcurl/c/libcurl-errors.html) for https://apexone.morpheus.cx/api/v1/call-control/click-to-call",
  "Morpheus telephony is temporarily unavailable. The rest of ApexOne is unaffected.",
];
foreach ($timeouts as $i => $err) {
  $fmt = $z->formatOriginateResponse(["ok"=>false,"error"=>$err,"attempted"=>["POST /click-to-call"],"line_reset"=>false], "1020", "+12722001232", $opts);
  $c = response()->json($fmt)->getContent();
  echo "timeout$i len=".strlen($c)."\n";
  if (in_array(strlen($c), [411,473], true)) echo "$c\n";
}

// Vary dest length for reject+line_reset targeting 473 and 411
$baseErr = "Extension 1020 rejected the ring (SIP 486 USER_BUSY). Close other calls, disable Do Not Disturb, then re-register your softphone to Morpheus.";
foreach (["12722001232","15551234567","18005551212","13135550123","2722001232"] as $to) {
  $fmt = $z->formatOriginateResponse([
    "ok"=>false,"outcome"=>"extension_busy","extension_busy"=>true,
    "error"=>$baseErr,"hangup_cause"=>"USER_BUSY","sip_code"=>"486",
    "attempted"=>["POST /click-to-call"],"line_reset"=>false,
  ], "1020", $to, $opts);
  $c = response()->json($fmt)->getContent();
  if (in_array(strlen($c), [411,473], true) || abs(strlen($c)-473)<5 || abs(strlen($c)-411)<5) {
    echo "to=$to len=".strlen($c)."\n";
  }
}

// Validation exception via HttpKernel? skip
// Could be JSON with warning HTML? 

// Search for len 411 by shortening messages
$msgs = [
  'Extension 1020 rejected the ring (SIP 486 USER_BUSY). Close other calls, disable Do Not Disturb, then re-register your softphone to Morpheus.',
  'Extension 1020 is busy on Morpheus. Click Connect line again, or ask your admin to clear stuck calls on this extension.',
  'Morpheus could not route this number. Use Call (click-to-call) with Connect line Registered — do not dial the PSTN number directly from the browser.',
  'Your browser extension did not answer the Morpheus ring. Click Connect line, wait for Registered, then dial again.',
];
foreach ($msgs as $err) {
  foreach ([true, false] as $busy) {
    foreach ([true, false, null] as $lr) {
      $r = ["ok"=>false,"error"=>$err,"attempted"=>["POST /click-to-call"]];
      if ($busy) $r["extension_busy"] = true;
      if ($lr !== null) $r["line_reset"] = $lr;
      $fmt = $z->formatOriginateResponse($r, "1020", "12722001232", $opts);
      $c = response()->json($fmt)->getContent();
      if (in_array(strlen($c), [411, 473], true)) {
        echo "MATCH ".strlen($c)." busy=".json_encode($busy)." lr=".json_encode($lr)."\n$c\n\n";
      }
    }
  }
}
PHP
"""


def main() -> int:
    ssh = connect()
    print(sudo_run(ssh, SCRIPT, check=False))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
