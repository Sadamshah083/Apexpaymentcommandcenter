#!/usr/bin/env python3
"""Restart queue workers and re-dispatch Maps scrape job #1."""

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

from deploy._ssh import REMOTE_APP, connect, sudo_run_batch, upload_files

DISPATCH = r"""<?php
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo 'ENABLED='.var_export(config('maps_scraper.enabled'), true).PHP_EOL;
app(App\Services\MapsScraper\MapsScraperService::class)->assertReady();
echo "ASSERT_OK\n";
$job = App\Models\MapsScrapeJob::find(1);
$job->update([
  'status' => 'pending',
  'progress_pct' => 0,
  'progress_message' => 'Queued',
  'error_message' => null,
  'row_count' => 0,
  'file_count' => 0,
  'export_zip_path' => null,
]);
App\Jobs\RunMapsScrapeJob::dispatch($job->id);
echo "DISPATCHED\n";
"""

STATUS = r"""<?php
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$j = App\Models\MapsScrapeJob::find(1);
echo ($j->status ?? '?').'|'.($j->progress_pct ?? 0).'|'.($j->progress_message ?? '').'|'.substr((string)($j->error_message ?? ''),0,200).PHP_EOL;
"""


def main() -> int:
    (ROOT / "deploy/_d.php").write_text(DISPATCH, encoding="utf-8")
    (ROOT / "deploy/_s.php").write_text(STATUS, encoding="utf-8")
    ssh = connect()
    try:
        upload_files(
            ssh,
            [
                (ROOT / "config/maps_scraper.php", "config/maps_scraper.php"),
                (ROOT / "app/Services/MapsScraper/MapsScraperService.php", "app/Services/MapsScraper/MapsScraperService.php"),
                (ROOT / "deploy/_d.php", "storage/app/_d.php"),
                (ROOT / "deploy/_s.php", "storage/app/_s.php"),
            ],
            app_root=REMOTE_APP,
        )
        out = sudo_run_batch(ssh, [
            f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan config:cache",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan queue:restart",
            # Also hard-restart supervisor/systemd workers if present
            "systemctl restart apexone-queue 2>/dev/null || systemctl restart laravel-worker 2>/dev/null || supervisorctl restart all 2>/dev/null || true",
            "sleep 2",
            "ps aux | grep 'queue:work' | grep -v grep | head -5 || echo NO_WORKER",
            f"chown www-data:www-data {REMOTE_APP}/storage/app/_d.php {REMOTE_APP}/storage/app/_s.php",
            f"cd {REMOTE_APP} && sudo -u www-data php storage/app/_d.php",
            "sleep 8",
            f"cd {REMOTE_APP} && sudo -u www-data php storage/app/_s.php",
            f"rm -f {REMOTE_APP}/storage/app/_d.php {REMOTE_APP}/storage/app/_s.php",
        ])
        print(out.encode("ascii", "replace").decode("ascii"))
        print("OK")
        return 0
    finally:
        ssh.close()
        for name in ("_d.php", "_s.php"):
            p = ROOT / "deploy" / name
            if p.exists():
                p.unlink()


if __name__ == "__main__":
    raise SystemExit(main())
