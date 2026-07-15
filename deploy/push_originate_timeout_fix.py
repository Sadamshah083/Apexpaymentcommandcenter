#!/usr/bin/env python3
"""Deploy originate timeout resilience fix."""

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

from deploy._ssh import REMOTE_APP, connect, set_env_vars, sudo_run, upload_files

FILES = [
    "app/Services/Integrations/ZoomApiService.php",
    "app/Services/Integrations/MorpheusCircuitBreaker.php",
    "config/integrations.php",
]


def main() -> int:
    ssh = connect()
    upload_files(ssh, [(ROOT / f, f) for f in FILES], REMOTE_APP)
    set_env_vars(
        ssh,
        {
            "COMMUNICATIONS_ORIGINATE_HTTP_TIMEOUT": "15",
        },
    )
    print(
        sudo_run(
            ssh,
            f"""
cd {REMOTE_APP}
php -l app/Services/Integrations/ZoomApiService.php
php -l app/Services/Integrations/MorpheusCircuitBreaker.php
php -l config/integrations.php
# Clear config so new originate_http_timeout_seconds is loaded
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan cache:clear || true
grep -n "connectTimeout(5)\\|originate_http_timeout\\|friendlyOriginateHttpError\\|Never block dialing" \\
  app/Services/Integrations/ZoomApiService.php \\
  app/Services/Integrations/MorpheusCircuitBreaker.php \\
  config/integrations.php | head -30
sudo -u www-data grep COMMUNICATIONS_ORIGINATE /var/www/apexone/.env
# Smoke: place then hang a click-to-call
sudo -u www-data php <<'PHP'
<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
$z = app(App\\Services\\Integrations\\ZoomApiService::class);
$r = $z->originateCall("1020", "15555550188", [
  "campaign_id" => (string) config("integrations.morpheus.default_campaign_id"),
  "caller_id_number" => "13133851223",
  "webphone_ready" => true,
  "skip_line_clear" => true,
]);
echo "ok=".json_encode($r["ok"] ?? null)." err=".($r["error"] ?? "")." uuid=".($r["call_uuid"] ?? "").PHP_EOL;
if (!empty($r["call_uuid"])) {{
  $ref = new ReflectionClass($z);
  $h = $ref->getMethod("hangup");
  $h->setAccessible(true);
  echo "hang=".json_encode($h->invoke($z, $r["call_uuid"])).PHP_EOL;
}}
echo "orig_timeout=".config("integrations.communications.originate_http_timeout_seconds").PHP_EOL;
PHP
""",
            check=False,
        )
    )
    ssh.close()
    print("Deployed originate timeout resilience.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
