#!/usr/bin/env python3
from __future__ import annotations

import base64
import shlex
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT))

from deploy._ssh import REMOTE_APP, connect, restart_queue_workers, sudo_run

VERIFY = r"""<?php
require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$rows = [
    ["Niche: Auto Repair\nState: GA", '', '', '', ''],
    ['City', 'Business Name', 'Contact Number', 'Address', 'Owner Name'],
    ['East Dublin', 'Rickys', '+1 478', '209 Ave', 'Ricky'],
];
$d = App\Support\SpreadsheetHeaderDetector::detect($rows);
$m = (new App\Services\Workflow\WorkflowAiMapper(
    app(App\Services\BusinessResearch\GeminiClient::class)
))->heuristicMap($d['headers']);

echo json_encode([
    'index' => $d['index'],
    'headers' => $d['headers'],
    'business_name' => $m['business_name'],
    'input_phone' => $m['input_phone'],
    'owner_name' => $m['owner_name'],
    'dash' => App\Support\SpreadsheetText::normalize("ΓÇö"),
], JSON_UNESCAPED_UNICODE), PHP_EOL;
"""


def main() -> int:
    ssh = connect()
    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data ./vendor/bin/phpunit tests/Unit/Support/SpreadsheetImportEncodingTest.php", check=False))

    remote = f"{REMOTE_APP}/storage/app/_verify_spreadsheet.php"
    b64 = base64.b64encode(VERIFY.encode()).decode()
    sudo_run(ssh, f"printf %s {shlex.quote(b64)} | base64 -d > {remote} && chown www-data:www-data {remote}")
    print(sudo_run(ssh, f"cd {REMOTE_APP} && sudo -u www-data php storage/app/_verify_spreadsheet.php"))
    sudo_run(ssh, f"rm -f {remote}", check=False)

    try:
        restart_queue_workers(ssh)
    except RuntimeError as exc:
        print(f"Warning: queue restart skipped ({exc})")

    ssh.close()
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
