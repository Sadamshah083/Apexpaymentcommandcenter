#!/usr/bin/env python3
"""Fix scraper enablement, harden state fallback, start a proper Birmingham quick job."""

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

CREATE = r"""<?php
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo 'ENABLED='.var_export(config('maps_scraper.enabled'), true).PHP_EOL;
app(App\Services\MapsScraper\MapsScraperService::class)->assertReady();
echo "ASSERT_OK\n";

$workspaceId = App\Models\MapsScrapeJob::query()->value('workspace_id')
    ?? App\Models\Workspace::query()->value('id');
$userId = App\Models\MapsScrapeJob::query()->value('user_id');

$job = App\Models\MapsScrapeJob::create([
    'workspace_id' => $workspaceId,
    'user_id' => $userId,
    'job_mode' => 'quick',
    'search_query' => 'locksmith shop in Birmingham, Alabama, USA',
    'scrape_mode' => 'city',
    'per_search' => 20,
    'small_business_only' => true,
    'status' => 'pending',
    'progress_message' => 'Queued',
    'meta' => ['source_mode' => 'quick', 'retry_of' => 1],
]);

App\Jobs\RunMapsScrapeJob::dispatch($job->id);
echo 'CREATED_JOB='.$job->id.PHP_EOL;
"""

STATUS = r"""<?php
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$j = App\Models\MapsScrapeJob::query()->latest('id')->first();
echo json_encode([
  'id'=>$j->id,
  'mode'=>$j->job_mode,
  'status'=>$j->status,
  'pct'=>$j->progress_pct,
  'msg'=>$j->progress_message,
  'err'=>substr((string)$j->error_message,0,250),
  'rows'=>$j->row_count,
  'files'=>$j->file_count,
], JSON_UNESCAPED_SLASHES).PHP_EOL;
"""


def main() -> int:
    (ROOT / "deploy/_create_quick.php").write_text(CREATE, encoding="utf-8")
    (ROOT / "deploy/_status_latest.php").write_text(STATUS, encoding="utf-8")
    ssh = connect()
    try:
        upload_files(
            ssh,
            [
                (ROOT / "config/maps_scraper.php", "config/maps_scraper.php"),
                (ROOT / "app/Services/MapsScraper/MapsScraperService.php", "app/Services/MapsScraper/MapsScraperService.php"),
                (ROOT / "resources/views/maps-scraper/show.blade.php", "resources/views/maps-scraper/show.blade.php"),
                (ROOT / "tools/google-maps-scraper/apex_bridge.py", "tools/google-maps-scraper/apex_bridge.py"),
                (ROOT / "tools/google-maps-scraper/data/cities/alabama_cities.txt", "tools/google-maps-scraper/data/cities/alabama_cities.txt"),
                (ROOT / "deploy/_create_quick.php", "storage/app/_create_quick.php"),
                (ROOT / "deploy/_status_latest.php", "storage/app/_status_latest.php"),
            ],
            app_root=REMOTE_APP,
        )
        out = sudo_run_batch(ssh, [
            f"cd {REMOTE_APP} && sudo -u www-data php artisan config:clear",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan config:cache",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan view:clear",
            f"cd {REMOTE_APP} && sudo -u www-data php artisan queue:restart",
            "sleep 2",
            f"chown -R www-data:www-data {REMOTE_APP}/tools/google-maps-scraper {REMOTE_APP}/storage/app/maps-scraper",
            f"chown www-data:www-data {REMOTE_APP}/storage/app/_create_quick.php {REMOTE_APP}/storage/app/_status_latest.php",
            f"cd {REMOTE_APP} && sudo -u www-data php storage/app/_create_quick.php",
            "sleep 12",
            f"cd {REMOTE_APP} && sudo -u www-data php storage/app/_status_latest.php",
            f"rm -f {REMOTE_APP}/storage/app/_create_quick.php {REMOTE_APP}/storage/app/_status_latest.php",
        ])
        print(out.encode("ascii", "replace").decode("ascii"))
        print("OK")
        return 0
    finally:
        ssh.close()
        for name in ("_create_quick.php", "_status_latest.php"):
            p = ROOT / "deploy" / name
            if p.exists():
                p.unlink()


if __name__ == "__main__":
    raise SystemExit(main())
