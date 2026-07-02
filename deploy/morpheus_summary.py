#!/usr/bin/env python3
"""Fetch Morpheus campaigns/extensions summary from production."""

from __future__ import annotations

import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import connect, sudo_run

PHP = r"""<?php
require 'vendor/autoload.php';
$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$hub = app(App\Services\Communications\MorpheusHubService::class);
foreach ($hub->extensions() as $ext) {
    echo 'EXT '.($ext['extension_num'] ?? '?').' id='.($ext['id'] ?? '?').' user='.($ext['user_id'] ?? '-')."\n";
}
foreach ($hub->campaigns() as $camp) {
    echo 'CAMP '.($camp['id'] ?? '?').' name='.($camp['name'] ?? '?')."\n";
}
"""


def main() -> int:
    ssh = connect()
    tmp = "/tmp/morpheus-summary.php"
    sftp = ssh.open_sftp()
    with sftp.file(tmp, "w") as remote:
        remote.write(PHP)
    sftp.close()
    out = sudo_run(ssh, f"cd /var/www/apexone && sudo -u www-data php {tmp}")
    print(out)
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
