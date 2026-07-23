#!/usr/bin/env python3
from __future__ import annotations

import os
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))
os.environ.setdefault("DEPLOY_PASSWORD", "balitech1")

import deploy._ssh as m

m.HOST = "203.215.161.236"
m.USER = "ateg"
m.PASSWORD = "balitech1"
m.REMOTE_APP = "/var/www/apexone"

from deploy._ssh import REMOTE_APP, connect, sudo_run, upload_files

PHP = r"""<?php
require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

echo "campaigns=". (Schema::hasTable('campaigns') ? 'yes' : 'no') ."\n";
echo "lead_campaigns=". (Schema::hasTable('lead_campaigns') ? 'yes' : 'no') ."\n";
echo "telescope_entries=". (Schema::hasTable('telescope_entries') ? 'yes' : 'no') ."\n";
echo "communication_call_logs=". (Schema::hasTable('communication_call_logs') ? 'yes' : 'no') ."\n";

if (Schema::hasTable('communication_call_logs')) {
    foreach (DB::select("SHOW COLUMNS FROM communication_call_logs LIKE 'disposition'") as $c) {
        echo "disp_col type={$c->Type} null={$c->Null}\n";
    }
}

echo "TELESCOPE_ENABLED=".env('TELESCOPE_ENABLED')."\n";
echo "OPENROUTER_MODEL=".env('OPENROUTER_MODEL')."\n";
echo "OPENROUTER_FALLBACK=".env('OPENROUTER_FALLBACK_MODELS')."\n";
"""

CMD = r"""
echo === recording_status around 128 ===
sed -n '105,170p' /var/www/apexone/app/Services/Communications/CommunicationsDataService.php
echo === role around 310 ===
sed -n '300,330p' /var/www/apexone/app/Services/Workspace/WorkspaceMemberService.php
echo === openrouter config ===
sed -n '1,80p' /var/www/apexone/config/openrouter.php
echo === env telescope openrouter ===
grep -E '^(TELESCOPE_|OPENROUTER_)' /var/www/apexone/.env | sed 's/=.*/=***/' 
grep -E '^(TELESCOPE_|OPENROUTER_MODEL|OPENROUTER_FALLBACK)' /var/www/apexone/.env || true
"""


def main() -> int:
    (ROOT / "deploy/_probe_exceptions.php").write_text(PHP, encoding="utf-8")
    ssh = connect()
    try:
        upload_files(ssh, [(ROOT / "deploy/_probe_exceptions.php", "scripts/_probe_exceptions.php")])
        out1 = sudo_run(ssh, CMD, check=False)
        out2 = sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php scripts/_probe_exceptions.php", check=False)
        out3 = sudo_run(ssh, f"rm -f {REMOTE_APP}/scripts/_probe_exceptions.php", check=False)
        sys.stdout.buffer.write((out1 or "").encode("utf-8", "replace"))
        sys.stdout.buffer.write(b"\n")
        sys.stdout.buffer.write((out2 or "").encode("utf-8", "replace"))
        sys.stdout.buffer.write(b"\n")
        return 0
    finally:
        ssh.close()
        p = ROOT / "deploy/_probe_exceptions.php"
        if p.exists():
            p.unlink()


if __name__ == "__main__":
    raise SystemExit(main())
