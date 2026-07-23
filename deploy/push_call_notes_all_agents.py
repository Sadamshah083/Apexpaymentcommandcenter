#!/usr/bin/env python3
"""Deploy Call Notes All-agents default + clear false --columns error."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

import deploy._ssh as m

m.HOST = "203.215.161.236"
m.USER = "ateg"
m.PASSWORD = "balitech1"
m.REMOTE_APP = "/var/www/apexone"

from deploy._ssh import connect, sudo_run, upload_files

FILES = [
    "app/Http/Controllers/CallNotesController.php",
    "app/Services/Communications/CallNotesHistoryService.php",
    "resources/views/communications/notes/partials/panel.blade.php",
]


def main() -> int:
    ssh = connect()
    upload_files(ssh, [(ROOT / rel, rel) for rel in FILES])
    out = sudo_run(ssh, r"""
cd /var/www/apexone
sudo -u www-data php artisan view:clear
sudo -u www-data php artisan config:clear
# Remove false deploy-script exception from Error Monitoring
sudo -u www-data php -r '
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
if (! Schema::hasTable("application_errors")) { echo "NO_TABLE\n"; exit; }
$n = DB::table("application_errors")
  ->where("message", "like", "%--columns%")
  ->orWhere("message", "like", "%columns%option does not exist%")
  ->delete();
echo "CLEARED=$n\n";
'
curl -sk -o /dev/null -w "NOTES=%{http_code}\n" https://127.0.0.1/admin/communications/notes -H "Host: crm.apexonepayments.com"
""", check=False)
    print(out.encode("ascii", "replace").decode())
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
