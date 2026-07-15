#!/usr/bin/env python3
"""Find which originate error payloads match nginx 411/473; hang orphaned probe."""

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

PHP = r"""<?php
require "/var/www/apexone/vendor/autoload.php";
$app = require "/var/www/apexone/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$z = app(App\Services\Integrations\ZoomApiService::class);
$opts = [
  "campaign_id" => (string) config("integrations.morpheus.default_campaign_id"),
  "caller_id_number" => preg_replace("/\D/", "", (string) config("integrations.communications.default_outbound_did")),
];

$targets = [411, 473];

function show($label, $fmt, $targets) {
  $c = response()->json($fmt)->getContent();
  $n = strlen($c);
  $mark = in_array($n, $targets, true) ? " <<<< MATCH" : "";
  echo "$label len=$n$mark\n$c\n\n";
}

$agents = app(App\Services\Communications\CommunicationsAgentService::class);

// Known controller JSON bodies
show("offline", [
  "ok" => false,
  "extension_offline" => true,
  "webphone_required" => true,
  "error" => $agents->extensionOfflineDialMessage("1020"),
], $targets);

show("dest_invalid", [
  "ok" => false,
  "error" => "Enter a valid phone number with at least 10 digits (e.g. +12722001232).",
], $targets);

$msgs = [
  ["busy_full", ["ok"=>false,"error"=>"Extension 1020 is busy on Morpheus. Click Connect line again, or ask your admin to clear stuck calls on this extension.","extension_busy"=>true,"attempted"=>["POST /click-to-call"]]],
  ["still_busy", ["ok"=>false,"error"=>"Extension 1020 is still busy. Wait 10–15 seconds, click Connect line, then try again.","extension_busy"=>true,"attempted"=>["pre-check-busy"]]],
  ["reject_sip", ["ok"=>false,"outcome"=>"extension_busy","extension_busy"=>true,"error"=>"Extension 1020 rejected the ring (SIP 486 USER_BUSY). Close other calls, disable Do Not Disturb, then re-register your softphone to Morpheus.","hangup_cause"=>"USER_BUSY","sip_code"=>"486","attempted"=>["POST /click-to-call"]]],
  ["routing", ["ok"=>false,"routing_error"=>true,"error"=>"Morpheus could not route this number. Use Call (click-to-call) with Connect line Registered — do not dial the PSTN number directly from the browser.","attempted"=>["POST /click-to-call"]]],
  ["trunk_route", ["ok"=>false,"routing_error"=>true,"error"=>"Morpheus could not route this outbound call. Verify trunk/DID routing in the Morpheus admin portal.","hangup_cause"=>"NO_ROUTE_DESTINATION","attempted"=>["POST /click-to-call"]]],
  ["no_pbx", ["ok"=>false,"error"=>"Morpheus accepted click-to-call but no call was created on the PBX.","attempted"=>["POST /click-to-call"]]],
  ["api_perm", ["ok"=>false,"error"=>"API key lacks calls:originate permission.","attempted"=>["POST /click-to-call"]]],
  ["http_fail", ["ok"=>false,"error"=>"Originate failed (HTTP 400).","attempted"=>["POST /click-to-call"]]],
];

foreach ($msgs as [$label, $r]) {
  show($label, $z->formatOriginateResponse($r, "1020", "+12722001232", $opts), $targets);
}

// Live Morpheus failures without leaving a destination ringing forever — hang immediately
$ref = new ReflectionClass($z);
$hang = null;
foreach (["hangup", "hangUp", "killCall", "releaseCall"] as $name) {
  if ($ref->hasMethod($name)) { $hang = $ref->getMethod($name); $hang->setAccessible(true); echo "hang_method=$name\n"; break; }
}

// Hang the earlier probe uuid
if ($hang) {
  try {
    echo "hang_probe=".json_encode($hang->invoke($z, "534c7418-f62b-46c5-86e7-06064dad773f")).PHP_EOL;
  } catch (Throwable $e) {
    echo "hang_probe_err=".$e->getMessage().PHP_EOL;
  }
}

// Brute: call click-to-call with empty campaign to see morphs error size
$post = $ref->getMethod("postOriginate");
$post->setAccessible(true);
$raw = $post->invoke($z, "/click-to-call", [
  "extension" => "1020",
  "destination" => "12722001232",
  "timeout_sec" => 15,
  "campaign_id" => "00000000-0000-0000-0000-000000000000",
  "caller_id_number" => $opts["caller_id_number"],
]);
echo "bad_campaign_raw=".json_encode($raw).PHP_EOL;
show("bad_campaign", $z->formatOriginateResponse(array_merge($raw, ["attempted"=>["POST /click-to-call"]]), "1020", "12722001232", $opts), $targets);
if (!empty($raw["call_uuid"]) && $hang) {
  $hang->invoke($z, $raw["call_uuid"]);
}

// Missing extension
$raw2 = $post->invoke($z, "/click-to-call", [
  "extension" => "9999",
  "destination" => "12722001232",
  "timeout_sec" => 15,
  "campaign_id" => $opts["campaign_id"],
  "caller_id_number" => $opts["caller_id_number"],
]);
echo "bad_ext_raw=".json_encode($raw2).PHP_EOL;
show("bad_ext", $z->formatOriginateResponse(array_merge($raw2, ["attempted"=>["POST /click-to-call"]]), "9999", "12722001232", $opts), $targets);
if (!empty($raw2["call_uuid"]) && $hang) {
  $hang->invoke($z, $raw2["call_uuid"]);
}

// Scan laravel log / php-fpm for 17:31
echo "---- LOG HIT ----\n";
"""

SCRIPT = r"""
sudo -u www-data php /tmp/_match_422_www.php
echo "---- LOG ----"
# Look near the failure times in nginx for any app log
grep -E "2026-07-14 17:3[0-5]" /var/www/apexone/storage/logs/laravel.log | tail -40 || true
grep -iE "SQLSTATE|originate|click-to-call|circuit_open|no such table" /var/www/apexone/storage/logs/laravel.log | tail -40 || true
# Temporary: add request logging? hang active calls via reflection in php
sudo -u www-data php -r '
require "/var/www/apexone/vendor/autoload.php";
$app=require "/var/www/apexone/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$z=app(App\Services\Integrations\ZoomApiService::class);
$ref=new ReflectionClass($z);
foreach(["listActiveCalls","activeCalls","getActiveCalls"] as $n){
  if($ref->hasMethod($n)){ $m=$ref->getMethod($n); $m->setAccessible(true); $calls=$m->invoke($z)?:[]; echo "method=$n count=".count($calls).PHP_EOL; foreach($calls as $c){ echo json_encode($c).PHP_EOL; $uuid=$c["uuid"]??$c["call_uuid"]??""; if($uuid && $ref->hasMethod("hangup")){ $h=$ref->getMethod("hangup"); $h->setAccessible(true); echo "hang=".json_encode($h->invoke($z,$uuid)).PHP_EOL; } } break; }
}
'
"""


def main() -> int:
    ssh = connect()
    sftp = ssh.open_sftp()
    with sftp.file("/tmp/_match_422_www.php", "w") as f:
        f.write(PHP)
    sftp.close()
    print(sudo_run(ssh, SCRIPT, check=False))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
