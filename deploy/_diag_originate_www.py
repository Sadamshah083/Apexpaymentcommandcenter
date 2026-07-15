#!/usr/bin/env python3
"""Diagnose originate 422 using sudo as www-data (real .env)."""

from __future__ import annotations

import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

_pw_file = Path(__file__).with_name(".deploy_password")
if _pw_file.exists() and not os.environ.get("DEPLOY_PASSWORD"):
    os.environ["DEPLOY_PASSWORD"] = _pw_file.read_text(encoding="utf-8").strip()

# Reload module password if already imported elsewhere
import deploy._ssh as ssh_mod

ssh_mod.PASSWORD = os.environ.get("DEPLOY_PASSWORD", "") or ssh_mod.PASSWORD

from deploy._ssh import connect, sudo_run

SCRIPT = r"""
set -e
cd /var/www/apexone
sudo -u www-data php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo "cache=".config("cache.default").PHP_EOL;
echo "db=".config("database.default").PHP_EOL;
echo "CACHE_STORE=".env("CACHE_STORE","").PHP_EOL;
echo "CACHE_DRIVER=".env("CACHE_DRIVER","").PHP_EOL;
echo "DB_CONNECTION=".env("DB_CONNECTION","").PHP_EOL;
try {
  Illuminate\Support\Facades\Cache::put("probe_ok", 1, 30);
  echo "cache_put=ok value=".Illuminate\Support\Facades\Cache::get("probe_ok").PHP_EOL;
} catch (Throwable $e) {
  echo "cache_put_fail=".$e->getMessage().PHP_EOL;
}
'

echo "---- ENV ----"
sudo -u www-data grep -E "^(CACHE_|DB_CONNECTION|SESSION_DRIVER|REDIS_)" .env | sed "s/\(PASSWORD\|SECRET\|KEY\)=.*/\1=***/"

echo "---- ORIGINATE PROBE ----"
sudo -u www-data php /tmp/_originate_probe_www.php

echo "---- NGINX RECENT ----"
grep originate /var/log/nginx/access.log | tail -15
"""


def main() -> int:
    ssh = connect()
    probe = r"""<?php
require "/var/www/apexone/vendor/autoload.php";
$app = require "/var/www/apexone/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$z = app(App\Services\Integrations\ZoomApiService::class);
$opts = [
  "campaign_id" => (string) config("integrations.morpheus.default_campaign_id"),
  "caller_id_number" => (string) config("integrations.communications.default_outbound_did"),
];

echo "campaign=".($opts["campaign_id"] ?: "(empty)").PHP_EOL;
echo "did=".($opts["caller_id_number"] ?: "(empty)").PHP_EOL;

$r = $z->originateCall("1020", "15555550199", [
  "campaign_id" => $opts["campaign_id"],
  "caller_id_number" => $opts["caller_id_number"],
  "webphone_ready" => true,
  "skip_line_clear" => true,
]);
$fmt = $z->formatOriginateResponse($r, "1020", "15555550199", $opts);
$laravel = response()->json($fmt)->getContent();
echo "ok=".json_encode($r["ok"] ?? null).PHP_EOL;
echo "error=".($r["error"] ?? "").PHP_EOL;
echo "busy=".json_encode($r["extension_busy"] ?? null).PHP_EOL;
echo "uuid=".($r["call_uuid"] ?? "").PHP_EOL;
echo "fmt_len=".strlen($laravel)." status_would=".(($r["extension_busy"] ?? false) ? 409 : (($r["ok"] ?? false) ? 200 : 422)).PHP_EOL;
echo "fmt=".$laravel.PHP_EOL;

if (!empty($r["call_uuid"])) {
  try { echo "hangup=".json_encode($z->hangupCall($r["call_uuid"])).PHP_EOL; }
  catch (Throwable $e) { echo "hangup_err=".$e->getMessage().PHP_EOL; }
}
"""
    sftp = ssh.open_sftp()
    with sftp.file("/tmp/_originate_probe_www.php", "w") as f:
        f.write(probe)
    sftp.close()

    print(sudo_run(ssh, SCRIPT, check=False))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
