#!/usr/bin/env python3
"""Verify CDR from-field fix on production."""

from __future__ import annotations

import base64
import json
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, sudo_run

PHP = r"""<?php
require "vendor/autoload.php";
$app = require "bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$zoom = app(App\Services\Integrations\ZoomApiService::class);

$dest = $zoom->normalizeOriginateDestination('482983#+12722001232');
$cdr = $zoom->listCdr(['limit' => 5, 'search' => '12722001232']);

$sample = [];
foreach (($cdr['logs'] ?? []) as $log) {
    $sample[] = [
        'from' => $log['from'] ?? null,
        'from_phone' => $log['from_phone'] ?? null,
        'agent_extension' => $log['agent_extension'] ?? null,
        'to' => $log['to'] ?? null,
        'result' => $log['result'] ?? null,
    ];
}

echo json_encode([
    'normalize_dest' => $dest,
    'recent_cdr' => $sample,
], JSON_PRETTY_PRINT);
"""


def main() -> int:
    ssh = connect()
    enc = base64.b64encode(PHP.encode()).decode()
    print(sudo_run(ssh, f"cd {REMOTE_APP} && echo {enc} | base64 -d | sudo -u www-data php"))
    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
